<?php
require_once __DIR__.'/config.php';

try {
	// 1. Подключение к MySQL
	$pdo = new PDO(
		"mysql:host=".DB_HOST.";charset=".DB_CHARSET,
		DB_USER,
		DB_PASS,
		[
			PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
			PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
			PDO::ATTR_EMULATE_PREPARES => false
		]
	);

	// 2. Создание базы данных
	$pdo->exec("CREATE DATABASE IF NOT EXISTS `".DB_NAME."` 
                CHARACTER SET ".DB_CHARSET." 
                COLLATE ".DB_CHARSET."_unicode_ci");
	$pdo->exec("USE `".DB_NAME."`");

	// 3. Создание таблицы players
	$pdo->exec("CREATE TABLE IF NOT EXISTS `players` (
        `player_id` VARCHAR(36) NOT NULL PRIMARY KEY,
        `nickname` VARCHAR(50) NOT NULL,
        `vpip` DECIMAL(5,2) DEFAULT 0.00,
        `pfr` DECIMAL(5,2) DEFAULT 0.00,
        `af` DECIMAL(5,2) DEFAULT 0.00,
        `afq` DECIMAL(5,2) DEFAULT 0.00,
        `three_bet` DECIMAL(5,2) DEFAULT 0.00,
        `wtsd` DECIMAL(5,2) DEFAULT 0.00,
        `hands_played` INT UNSIGNED DEFAULT 0,
        `showdowns` INT UNSIGNED DEFAULT 0,
        `preflop_raises` INT UNSIGNED DEFAULT 0,
        `postflop_raises` INT UNSIGNED DEFAULT 0,
        `check_raises` INT UNSIGNED DEFAULT 0,
        `cbet` DECIMAL(5,2) DEFAULT 0.00,
        `fold_to_cbet` DECIMAL(5,2) DEFAULT 0.00,
        `aggressive_actions` INT UNSIGNED DEFAULT 0,
        `passive_actions` INT UNSIGNED DEFAULT 0,
        `steal_attempt` DECIMAL(5,2) DEFAULT 0.00,
        `steal_success` DECIMAL(5,2) DEFAULT 0.00,
        `postflop_raise_pct` DECIMAL(5,2) DEFAULT 0.00,
        `check_raise_pct` DECIMAL(5,2) DEFAULT 0.00,
        `preflop_aggression` DECIMAL(5,2) DEFAULT 0.00,
        `flop_aggression` DECIMAL(5,2) DEFAULT 0.00,
        `turn_aggression` DECIMAL(5,2) DEFAULT 0.00,
        `river_aggression` DECIMAL(5,2) DEFAULT 0.00,
        `last_seen` DATETIME DEFAULT CURRENT_TIMESTAMP,
        `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
        INDEX `idx_player_nickname` (`nickname`),
        INDEX `idx_player_activity` (`last_seen`, `hands_played`)
    ) ENGINE=InnoDB DEFAULT CHARSET=".DB_CHARSET);

	// 4. Создание таблицы hands
	$pdo->exec("CREATE TABLE IF NOT EXISTS `hands` (
        `hand_id` INT AUTO_INCREMENT PRIMARY KEY,
        `hero_position` VARCHAR(10),
        `hero_stack` DECIMAL(15,2),
        `hero_cards` VARCHAR(10),
        `board` VARCHAR(30),
        `is_completed` BOOLEAN DEFAULT FALSE,
        `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
        `updated_at` DATETIME ON UPDATE CURRENT_TIMESTAMP,
        INDEX `idx_hand_completion` (`is_completed`),
        INDEX `idx_hand_time` (`created_at`)
    ) ENGINE=InnoDB DEFAULT CHARSET=".DB_CHARSET);

	// 5. Создание таблицы actions
	$pdo->exec("CREATE TABLE IF NOT EXISTS `actions` (
        `action_id` INT AUTO_INCREMENT PRIMARY KEY,
        `hand_id` INT NOT NULL,
        `player_id` VARCHAR(36) NOT NULL,
        `position` ENUM('BTN','SB','BB','UTG','UTG+1','MP','HJ','CO') NOT NULL,
        `street` ENUM('preflop','flop','turn','river') NOT NULL,
        `action_type` ENUM('fold','check','call','bet','raise','all-in') NOT NULL,
        `amount` DECIMAL(15,2) NULL,
        `sequence_num` SMALLINT UNSIGNED NOT NULL,
        `is_voluntary` BOOLEAN DEFAULT TRUE,
        `is_aggressive` BOOLEAN DEFAULT FALSE,
        `is_first_action` BOOLEAN DEFAULT FALSE,
        `is_cbet` BOOLEAN DEFAULT FALSE,
        `is_steal` BOOLEAN DEFAULT FALSE,
        `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (`hand_id`) REFERENCES `hands`(`hand_id`) ON DELETE CASCADE,
        FOREIGN KEY (`player_id`) REFERENCES `players`(`player_id`) ON DELETE CASCADE,
        UNIQUE KEY `uk_action_sequence` (`hand_id`, `sequence_num`),
        INDEX `idx_action_composite` (`hand_id`, `player_id`, `street`),
        INDEX `idx_action_type` (`action_type`),
        INDEX `idx_action_cbet` (`is_cbet`),
        INDEX `idx_action_steal` (`is_steal`)
    ) ENGINE=InnoDB DEFAULT CHARSET=".DB_CHARSET);

	// 6. Создание таблицы showdown
	$pdo->exec("CREATE TABLE IF NOT EXISTS `showdown` (
        `showdown_id` INT AUTO_INCREMENT PRIMARY KEY,
        `hand_id` INT NOT NULL,
        `player_id` VARCHAR(36) NOT NULL,
        `cards` VARCHAR(10) NOT NULL,
        `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (`hand_id`) REFERENCES `hands`(`hand_id`) ON DELETE CASCADE,
        FOREIGN KEY (`player_id`) REFERENCES `players`(`player_id`) ON DELETE CASCADE,
        UNIQUE KEY `uk_showdown_player` (`hand_id`, `player_id`),
        INDEX `idx_showdown_hand` (`hand_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=".DB_CHARSET);

	echo "Все таблицы успешно созданы\n";

	// 7. Удаление старых триггеров
	$triggers = [
		'update_last_seen', 'update_check_raises', 'update_vpip', 'update_pfr',
		'update_three_bet', 'update_wtsd', 'update_af', 'update_afq',
		'update_postflop_raises', 'update_cbet', 'update_fold_to_cbet',
		'update_steal_attempt', 'update_steal_success', 'update_aggressive_actions',
		'update_passive_actions', 'update_hands_played', 'update_showdowns',
		'update_postflop_raise_pct', 'update_check_raise_pct', 'update_preflop_aggression',
		'update_flop_aggression', 'update_turn_aggression', 'update_river_aggression'
	];

	foreach ($triggers as $trigger) {
		try {
			$pdo->exec("DROP TRIGGER IF EXISTS $trigger");
		} catch (PDOException $e) {
			echo "Ошибка при удалении триггера $trigger: ".$e->getMessage()."\n";
		}
	}

	// 8. Создание триггеров
	// 8.1 Триггер для обновления времени последней активности
	$pdo->exec("CREATE TRIGGER update_last_seen AFTER INSERT ON actions FOR EACH ROW
    BEGIN
        UPDATE players SET last_seen = NOW() WHERE player_id = NEW.player_id;
    END");

	// 8.2 Триггер для подсчета чек-рейзов
	$pdo->exec("CREATE TRIGGER update_check_raises AFTER INSERT ON actions FOR EACH ROW
	BEGIN
		DECLARE prev_check_seq INT DEFAULT NULL;
		DECLARE opponent_aggressive_action_exists INT DEFAULT 0;
		
		-- Только для рейзов/алл-инов на постфлопе
		IF NEW.action_type IN ('raise', 'all-in') AND NEW.street IN ('flop', 'turn', 'river') THEN
			-- Находим последний чек этого игрока на этой улице перед рейзом
			SELECT MAX(sequence_num) INTO prev_check_seq
			FROM actions 
			WHERE hand_id = NEW.hand_id 
			  AND player_id = NEW.player_id 
			  AND street = NEW.street 
			  AND action_type = 'check'
			  AND sequence_num < NEW.sequence_num;
			
			-- Если чек был найден
			IF prev_check_seq IS NOT NULL THEN
				-- Проверяем, были ли после чека агрессивные действия оппонентов (бет/рейз/алл-ин)
				-- перед нашим рейзом
				SELECT COUNT(*) INTO opponent_aggressive_action_exists
				FROM actions
				WHERE hand_id = NEW.hand_id
				  AND player_id != NEW.player_id
				  AND street = NEW.street
				  AND action_type IN ('bet', 'raise', 'all-in')
				  AND sequence_num > prev_check_seq
				  AND sequence_num < NEW.sequence_num
				  -- Убедимся, что это было первое агрессивное действие после чека
				  AND NOT EXISTS (
					  SELECT 1 FROM actions a2
					  WHERE a2.hand_id = actions.hand_id
						AND a2.street = actions.street
						AND a2.action_type IN ('bet', 'raise', 'all-in')
						AND a2.sequence_num > prev_check_seq
						AND a2.sequence_num < actions.sequence_num
				  );
				
				-- Если между чеком и рейзом было агрессивное действие оппонента
				IF opponent_aggressive_action_exists > 0 THEN
					-- Дополнительная проверка: чек должен быть первым действием игрока на улице
					-- (исключаем случаи, когда игрок сначала коллирует, потом чекает, потом рейзит)
					IF NOT EXISTS (
						SELECT 1 FROM actions
						WHERE hand_id = NEW.hand_id
						  AND player_id = NEW.player_id
						  AND street = NEW.street
						  AND action_type != 'check'
						  AND sequence_num < prev_check_seq
					) THEN
						UPDATE players 
						SET check_raises = check_raises + 1 
						WHERE player_id = NEW.player_id;
					END IF;
				END IF;
			END IF;
		END IF;
	END");

	// 8.3 Триггер для VPIP
	$pdo->exec("CREATE TRIGGER update_vpip AFTER INSERT ON actions FOR EACH ROW
    BEGIN
        DECLARE vpip_count INT DEFAULT 0;
        DECLARE total_hands INT DEFAULT 0;
        
        IF NEW.street = 'preflop' THEN
            SELECT COUNT(DISTINCT hand_id) INTO vpip_count FROM actions 
            WHERE player_id = NEW.player_id AND street = 'preflop' 
            AND action_type IN ('call','bet','raise','all-in');
            
            SELECT COUNT(DISTINCT hand_id) INTO total_hands FROM actions 
            WHERE player_id = NEW.player_id AND street = 'preflop';
            
            UPDATE players SET vpip = IF(total_hands>0, ROUND((vpip_count*100)/total_hands, 2), 0) 
            WHERE player_id = NEW.player_id;
        END IF;
    END");

	// 8.4 Триггер для PFR
	$pdo->exec("CREATE TRIGGER update_pfr AFTER INSERT ON actions FOR EACH ROW
	BEGIN
		DECLARE pfr_count INT DEFAULT 0;
		DECLARE total_hands INT DEFAULT 0;
		
		IF NEW.street = 'preflop' THEN
			SELECT COUNT(DISTINCT hand_id) INTO pfr_count FROM actions 
			WHERE player_id = NEW.player_id AND street = 'preflop' 
			AND action_type IN ('bet', 'raise','all-in');
			
			SELECT COUNT(DISTINCT hand_id) INTO total_hands FROM actions 
			WHERE player_id = NEW.player_id AND street = 'preflop';
			
			UPDATE players SET 
				pfr = IF(total_hands>0, ROUND((pfr_count*100)/total_hands, 2), 0),
				preflop_raises = pfr_count
			WHERE player_id = NEW.player_id;
		END IF;
	END");

	// 8.5 Триггер для 3-bet
	$pdo->exec("CREATE TRIGGER update_three_bet AFTER INSERT ON actions FOR EACH ROW
	BEGIN
		DECLARE three_bets INT DEFAULT 0;
		DECLARE raise_opps INT DEFAULT 0;
		DECLARE total_3bet_opps INT DEFAULT 0;
		
		IF NEW.street = 'preflop' THEN
			-- Подсчет реальных 3-bet (когда игрок действительно сделал рейз после открытия)
			SELECT COUNT(DISTINCT a1.hand_id) INTO three_bets 
			FROM actions a1
			JOIN (
				-- Находим первое открытие в раздаче (не считая блайнды)
				SELECT hand_id, MIN(sequence_num) as min_seq
				FROM actions
				WHERE street = 'preflop'
				  AND (action_type = 'bet' OR action_type = 'raise' OR action_type = 'all-in')
				  AND (position NOT IN ('SB', 'BB') OR action_type != 'bet')
				GROUP BY hand_id
			) first_raise ON a1.hand_id = first_raise.hand_id
			JOIN actions a2 ON a1.hand_id = a2.hand_id 
				AND a2.sequence_num = first_raise.min_seq
			WHERE a1.player_id = NEW.player_id 
			  AND a1.street = 'preflop'
			  AND (a1.action_type = 'raise' OR a1.action_type = 'all-in')
			  AND a2.player_id != NEW.player_id
			  AND a1.sequence_num > a2.sequence_num
			  AND NOT EXISTS (
				  -- Игрок не делал рейзов до этого в этой раздаче
				  SELECT 1 FROM actions a3
				  WHERE a3.hand_id = a1.hand_id
					AND a3.player_id = NEW.player_id
					AND a3.street = 'preflop'
					AND (a3.action_type = 'raise' OR a3.action_type = 'all-in')
					AND a3.sequence_num < a1.sequence_num
			  );
			
			-- Подсчет ВСЕХ возможностей для 3-bet (включая фолды и коллы)
			SELECT COUNT(DISTINCT fr.hand_id) INTO raise_opps 
			FROM (
				-- Все первые открытия в раздачах
				SELECT hand_id, MIN(sequence_num) as min_seq
				FROM actions
				WHERE street = 'preflop'
				  AND (action_type = 'bet' OR action_type = 'raise' OR action_type = 'all-in')
				  AND (position NOT IN ('SB', 'BB') OR action_type != 'bet')
				GROUP BY hand_id
			) fr
			JOIN actions a ON fr.hand_id = a.hand_id
			WHERE a.player_id = NEW.player_id
			  AND a.street = 'preflop'
			  AND a.sequence_num > fr.min_seq
			  AND NOT EXISTS (
				  -- Игрок не делал рейзов до этого в этой раздаче
				  SELECT 1 FROM actions a3
				  WHERE a3.hand_id = a.hand_id
					AND a3.player_id = NEW.player_id
					AND a3.street = 'preflop'
					AND (a3.action_type = 'raise' OR a3.action_type = 'all-in')
					AND a3.sequence_num < a.sequence_num
			  )
			  AND EXISTS (
				  -- Убедимся, что игрок действовал после открытия (не обязательно рейзом)
				  SELECT 1 FROM actions a4
				  WHERE a4.hand_id = a.hand_id
					AND a4.player_id = NEW.player_id
					AND a4.street = 'preflop'
					AND a4.sequence_num > fr.min_seq
			  );
			
			-- Обновляем статистику
			UPDATE players 
			SET three_bet = IF(raise_opps > 0, ROUND((three_bets * 100) / raise_opps, 2), 0),
				preflop_raises = three_bets
			WHERE player_id = NEW.player_id;
		END IF;
	END");

	// 8.6 Триггер для WTSD
	$pdo->exec("CREATE TRIGGER update_wtsd AFTER INSERT ON actions FOR EACH ROW
	BEGIN
		DECLARE wtsd_count INT DEFAULT 0;
		DECLARE eligible_hands INT DEFAULT 0;  -- Раздачи, учитываемые в статистике
		
		-- 1. Считаем раздачи, где игрок дошел до шоудауна (не фолдил до вскрытия)
		SELECT COUNT(DISTINCT h.hand_id) INTO wtsd_count 
		FROM hands h
		JOIN actions a ON h.hand_id = a.hand_id
		WHERE a.player_id = NEW.player_id 
		  AND a.street = 'river'
		  AND NOT EXISTS (
			  SELECT 1 FROM actions f
			  WHERE f.hand_id = h.hand_id
				AND f.player_id = NEW.player_id
				AND f.action_type = 'fold'
		  );
		
		-- 2. Считаем только валидные раздачи (где игрок хотя бы дошел до флопа)
		SELECT COUNT(DISTINCT h.hand_id) INTO eligible_hands
		FROM hands h
		JOIN actions a ON h.hand_id = a.hand_id
		WHERE a.player_id = NEW.player_id
		  AND a.street IN ('flop', 'turn', 'river')
		  AND h.hand_id IN (
			  -- Раздачи, где игрок сделал хотя бы одно действие (не только блайнды)
			  SELECT DISTINCT hand_id 
			  FROM actions 
			  WHERE player_id = NEW.player_id
				AND (action_type != 'check' OR position IN ('SB', 'BB'))
		  )
		  AND NOT EXISTS (
			  -- Исключаем раздачи, где игрок фолдил префлоп
			  SELECT 1 FROM actions f
			  WHERE f.hand_id = h.hand_id
				AND f.player_id = NEW.player_id
				AND f.street = 'preflop'
				AND f.action_type = 'fold'
		  );
		
		-- 3. Обновляем статистику только если есть валидные раздачи
		UPDATE players 
		SET wtsd = IF(eligible_hands > 0, ROUND((wtsd_count * 100) / eligible_hands, 0), 0),
			last_seen = NOW() 
		WHERE player_id = NEW.player_id;
	END");

	// 8.8 Триггер для AF
	$pdo->exec("CREATE TRIGGER update_af AFTER INSERT ON actions FOR EACH ROW
	BEGIN
		DECLARE aggressive_actions INT DEFAULT 0;
		DECLARE passive_actions INT DEFAULT 0;
		
		-- Подсчет агрессивных действий (беты, рейзы, алл-ины)
		SELECT COUNT(*) INTO aggressive_actions FROM actions
		WHERE player_id = NEW.player_id
		AND action_type IN ('bet','raise','all-in');
		
		-- Подсчет пассивных действий (коллы, чеки)
		-- Исключаем обязательные чеки в блайндах без действий оппонентов
		SELECT COUNT(*) INTO passive_actions FROM actions
		WHERE player_id = NEW.player_id
		AND action_type IN ('call','check')
		AND NOT (
			action_type = 'check' 
			AND position IN ('SB', 'BB') 
			AND is_first_action = TRUE
		);
		
		-- Обновляем статистику
		UPDATE players SET 
			af = IF(passive_actions > 0, 
				   LEAST(99.99, ROUND(aggressive_actions/passive_actions, 2)),
				   IF(aggressive_actions > 0, 99.99, 0)),
			aggressive_actions = aggressive_actions,
			passive_actions = passive_actions
		WHERE player_id = NEW.player_id;
	END");

	// 8.9 Триггер для AFq
	$pdo->exec("CREATE TRIGGER update_afq AFTER INSERT ON actions FOR EACH ROW
	BEGIN
		DECLARE aggressive INT DEFAULT 0;
		DECLARE passive INT DEFAULT 0;
		DECLARE total_actions INT DEFAULT 0;
		
		-- Подсчет агрессивных действий
		SELECT COUNT(*) INTO aggressive FROM actions
		WHERE player_id = NEW.player_id
		AND action_type IN ('bet','raise','all-in');
		
		-- Подсчет пассивных действий
		-- Исключаем обязательные чеки в блайндах без действий оппонентов
		SELECT COUNT(*) INTO passive FROM actions
		WHERE player_id = NEW.player_id
		AND action_type IN ('call','check')
		AND NOT (
			action_type = 'check' 
			AND position IN ('SB', 'BB') 
			AND is_first_action = TRUE
		);
		
		SET total_actions = aggressive + passive;
		
		-- Обновляем статистику
		UPDATE players SET 
			afq = IF(total_actions > 0, 
					ROUND((aggressive*100)/total_actions, 2), 
					0)
		WHERE player_id = NEW.player_id;
	END");

	// 8.10 Триггер для postflop_raises
	$pdo->exec("CREATE TRIGGER update_postflop_raises AFTER INSERT ON actions FOR EACH ROW
    BEGIN
        IF NEW.street IN ('flop','turn','river') AND NEW.action_type IN ('raise','all-in') THEN
            UPDATE players SET postflop_raises = IFNULL(postflop_raises, 0) + 1
            WHERE player_id = NEW.player_id;
        END IF;
    END");

	// 8.11 Триггер для cbet
	$pdo->exec("CREATE TRIGGER mark_cbet_actions BEFORE INSERT ON actions FOR EACH ROW
	BEGIN
		-- Автоматически помечаем cbet только для бетов на флопе
		IF NEW.street = 'flop' AND NEW.action_type = 'bet' THEN
			-- Проверяем, был ли игрок последним агрессором префлопа
			IF EXISTS (
				SELECT 1 FROM actions a 
				WHERE a.hand_id = NEW.hand_id 
				  AND a.player_id = NEW.player_id
				  AND a.street = 'preflop'
				  AND a.action_type IN ('bet', 'raise', 'all-in')
				  -- Является ли это последним агрессивным действием игрока префлопа
				  AND NOT EXISTS (
					  SELECT 1 FROM actions later
					  WHERE later.hand_id = a.hand_id
						AND later.player_id = a.player_id
						AND later.street = 'preflop'
						AND later.action_type IN ('bet', 'raise', 'all-in')
						AND later.sequence_num > a.sequence_num
				  )
			) AND NOT EXISTS (
				-- Проверяем, не было ли рейзов других игроков после последнего агрессивного действия нашего игрока
				SELECT 1 FROM actions other
				WHERE other.hand_id = NEW.hand_id
				  AND other.player_id != NEW.player_id
				  AND other.street = 'preflop'
				  AND other.action_type IN ('raise', 'all-in')
				  AND other.sequence_num > (
					  SELECT MAX(a.sequence_num) 
					  FROM actions a 
					  WHERE a.hand_id = NEW.hand_id 
						AND a.player_id = NEW.player_id
						AND a.street = 'preflop'
						AND a.action_type IN ('bet', 'raise', 'all-in')
				  )
			) THEN
				SET NEW.is_cbet = 1;
			ELSE
				SET NEW.is_cbet = 0;
			END IF;
		END IF;
	END");

	$pdo->exec("CREATE TRIGGER update_cbet_stats AFTER INSERT ON actions FOR EACH ROW
	BEGIN
		DECLARE total_cbets INT DEFAULT 0;
		DECLARE successful_cbets INT DEFAULT 0;
		
		-- Обновляем статистику только для cbet действий
		IF NEW.is_cbet = 1 THEN
			-- Общее количество cbet попыток
			SELECT COUNT(DISTINCT hand_id) INTO total_cbets
			FROM actions
			WHERE player_id = NEW.player_id AND is_cbet = 1;
			
			-- Успешные cbet (когда следующий игрок фолдит)
			SELECT COUNT(DISTINCT cbet_actions.hand_id) INTO successful_cbets
			FROM actions cbet_actions
			JOIN actions next_actions ON 
				cbet_actions.hand_id = next_actions.hand_id AND
				cbet_actions.street = next_actions.street AND
				next_actions.sequence_num > cbet_actions.sequence_num
			WHERE cbet_actions.player_id = NEW.player_id 
			  AND cbet_actions.is_cbet = 1
			  AND next_actions.action_type = 'fold'
			  AND next_actions.player_id != NEW.player_id;
			
			-- Обновляем статистику игрока
			UPDATE players
			SET 
				cbet = IF(total_cbets > 0, ROUND((successful_cbets * 100) / total_cbets, 2), 0),
				last_seen = NOW()
			WHERE player_id = NEW.player_id;
		END IF;
	END");

	$pdo->exec("CREATE PROCEDURE recalculate_all_cbet_stats()
	BEGIN
		DECLARE done INT DEFAULT FALSE;
		DECLARE player_id_var VARCHAR(36);
		DECLARE player_cursor CURSOR FOR SELECT player_id FROM players;
		DECLARE CONTINUE HANDLER FOR NOT FOUND SET done = TRUE;
		
		OPEN player_cursor;
		
		read_loop: LOOP
			FETCH player_cursor INTO player_id_var;
			IF done THEN
				LEAVE read_loop;
			END IF;
			
			CALL update_player_cbet_stats(player_id_var);
		END LOOP;
		
		CLOSE player_cursor;
	END");

	$pdo->exec("CREATE PROCEDURE update_player_cbet_stats(IN p_player_id VARCHAR(36))
	BEGIN
		DECLARE total_cbets INT DEFAULT 0;
		DECLARE successful_cbets INT DEFAULT 0;
		
		-- Общее количество cbet попыток
		SELECT COUNT(DISTINCT hand_id) INTO total_cbets
		FROM actions
		WHERE player_id = p_player_id AND is_cbet = 1;
		
		-- Успешные cbet
		SELECT COUNT(DISTINCT cbet_actions.hand_id) INTO successful_cbets
		FROM actions cbet_actions
		JOIN actions next_actions ON 
			cbet_actions.hand_id = next_actions.hand_id AND
			cbet_actions.street = next_actions.street AND
			next_actions.sequence_num > cbet_actions.sequence_num
		WHERE cbet_actions.player_id = p_player_id 
		  AND cbet_actions.is_cbet = 1
		  AND next_actions.action_type = 'fold'
		  AND next_actions.player_id != p_player_id;
		
		-- Обновляем статистику игрока
		UPDATE players
		SET 
			cbet = IF(total_cbets > 0, ROUND((successful_cbets * 100) / total_cbets, 2), 0)
		WHERE player_id = p_player_id;
	END");

	// 8.12 Триггер для fold_to_cbet
	$pdo->exec("CREATE TRIGGER update_fold_to_cbet AFTER INSERT ON actions FOR EACH ROW
	BEGIN
		DECLARE folds INT DEFAULT 0;
		DECLARE cbet_opps INT DEFAULT 0;
		
		IF NEW.action_type = 'fold' AND NEW.street = 'flop' THEN
			-- Подсчитываем фолды непосредственно на cbet (без промежуточных действий)
			SELECT COUNT(DISTINCT cb.hand_id) INTO folds 
			FROM actions cb
			WHERE cb.hand_id = NEW.hand_id
			  AND cb.street = 'flop'
			  AND cb.is_cbet = 1
			  AND cb.player_id != NEW.player_id
			  AND cb.sequence_num < NEW.sequence_num
			  AND NOT EXISTS (
				  -- Проверяем, что между cbet и фолдом не было других действий
				  SELECT 1 FROM actions mid
				  WHERE mid.hand_id = NEW.hand_id
					AND mid.street = 'flop'
					AND mid.sequence_num > cb.sequence_num
					AND mid.sequence_num < NEW.sequence_num
			  );
			
			-- Подсчитываем все реальные возможности для фолда на cbet
			SELECT COUNT(DISTINCT cb.hand_id) INTO cbet_opps 
			FROM actions cb
			JOIN actions resp ON cb.hand_id = resp.hand_id
			WHERE resp.player_id = NEW.player_id
			  AND cb.street = 'flop'
			  AND cb.is_cbet = 1
			  AND cb.player_id != NEW.player_id
			  AND cb.sequence_num < resp.sequence_num
			  AND (
				  -- Либо это первый ответ игрока на этой улице
				  NOT EXISTS (
					  SELECT 1 FROM actions earlier
					  WHERE earlier.hand_id = resp.hand_id
						AND earlier.player_id = resp.player_id
						AND earlier.street = 'flop'
						AND earlier.sequence_num < resp.sequence_num
				  )
				  OR
				  -- Либо это первый ответ после cbet
				  NOT EXISTS (
					  SELECT 1 FROM actions between_act
					  WHERE between_act.hand_id = resp.hand_id
						AND between_act.player_id = resp.player_id
						AND between_act.street = 'flop'
						AND between_act.sequence_num > cb.sequence_num
						AND between_act.sequence_num < resp.sequence_num
				  )
			  );
			
			-- Обновляем статистику
			UPDATE players SET 
				fold_to_cbet = IF(cbet_opps>0, ROUND((folds*100)/cbet_opps, 2), 0)
			WHERE player_id = NEW.player_id;
		END IF;
	END");

	// 8.13 Триггер для steal_attempt
	$pdo->exec("CREATE TRIGGER mark_steal_attempt BEFORE INSERT ON actions FOR EACH ROW
	BEGIN
		DECLARE is_steal_attempt BOOLEAN DEFAULT FALSE;
		
		-- Определяем, является ли действие попыткой кражи
		IF NEW.street = 'preflop' AND NEW.action_type IN ('bet', 'raise', 'all-in') THEN
			-- Проверяем, что это действие с поздних позиций (CO, BTN, HJ)
			IF NEW.position IN ('CO', 'BTN', 'HJ') THEN
				-- Проверяем, что до этого были только фолды (кроме обязательных блайндов)
				SELECT NOT EXISTS (
					SELECT 1 FROM actions 
					WHERE hand_id = NEW.hand_id 
					AND street = 'preflop'
					AND action_type IN ('raise', 'all-in')
					AND sequence_num < NEW.sequence_num
					AND position NOT IN ('SB', 'BB')
				) INTO is_steal_attempt;
				
				-- Если это попытка кражи, помечаем действие
				IF is_steal_attempt THEN
					SET NEW.is_steal = 1;
				ELSE
					SET NEW.is_steal = 0;
				END IF;
			END IF;
		END IF;
	END");

	$pdo->exec("CREATE TRIGGER update_steal_attempt AFTER INSERT ON actions FOR EACH ROW
	BEGIN
		DECLARE steal_attempts INT DEFAULT 0;
		DECLARE total_hands INT DEFAULT 0;
		
		-- Если это попытка кражи, обновляем статистику
		IF NEW.is_steal = 1 THEN
			-- Подсчет уникальных раздач с попытками кражи
			SELECT COUNT(DISTINCT a.hand_id) INTO steal_attempts 
			FROM actions a
			WHERE a.player_id = NEW.player_id 
			AND a.street = 'preflop'
			AND a.action_type IN ('bet', 'raise', 'all-in')
			AND a.position IN ('CO', 'BTN', 'HJ')
			AND NOT EXISTS (
				SELECT 1 FROM actions earlier
				WHERE earlier.hand_id = a.hand_id
				AND earlier.street = 'preflop'
				AND earlier.action_type IN ('raise', 'all-in')
				AND earlier.sequence_num < a.sequence_num
				AND earlier.position NOT IN ('SB', 'BB')
			);
			
			-- Общее количество раздач игрока
			SELECT COUNT(DISTINCT hand_id) INTO total_hands 
			FROM actions 
			WHERE player_id = NEW.player_id;
			
			-- Обновление статистики
			UPDATE players SET 
				steal_attempt = IF(total_hands>0, ROUND((steal_attempts*100)/total_hands, 2), 0)
			WHERE player_id = NEW.player_id;
		END IF;
	END");

	// 8.14 Триггер для steal_success
	$pdo->exec("CREATE TRIGGER update_steal_success AFTER INSERT ON actions FOR EACH ROW
	BEGIN
		DECLARE steal_success INT DEFAULT 0;
		DECLARE steal_attempts INT DEFAULT 0;
		
		IF NEW.is_steal = 1 THEN
			-- Успешные попытки кражи: когда игрок сделал ставку/рейз и все оппоненты сфолдили
			SELECT COUNT(DISTINCT a.hand_id) INTO steal_success 
			FROM actions a
			WHERE a.player_id = NEW.player_id AND a.is_steal = 1
			AND NOT EXISTS (
				SELECT 1 FROM actions o
				WHERE o.hand_id = a.hand_id
				AND o.player_id != NEW.player_id
				AND o.street = 'preflop'
				AND o.action_type IN ('call', 'raise', 'all-in')
				AND o.sequence_num > a.sequence_num
			);
			
			SELECT COUNT(DISTINCT hand_id) INTO steal_attempts 
			FROM actions
			WHERE player_id = NEW.player_id AND is_steal = 1;
			
			UPDATE players SET 
				steal_success = IF(steal_attempts>0, ROUND((steal_success*100)/steal_attempts, 2), 0)
			WHERE player_id = NEW.player_id;
		END IF;
	END");

	// 8.15 Исправленный триггер для aggressive_actions
	$pdo->exec("CREATE TRIGGER update_aggressive_actions AFTER INSERT ON actions FOR EACH ROW
	BEGIN
		DECLARE is_blind_action BOOLEAN DEFAULT FALSE;
		DECLARE is_forced_action BOOLEAN DEFAULT FALSE;
		
		-- Проверяем, является ли действие обязательным (блайнды)
		IF NEW.street = 'preflop' AND NEW.position IN ('SB', 'BB') AND 
		   (NEW.action_type = 'bet' OR (NEW.action_type = 'check' AND NEW.position = 'BB')) THEN
			SET is_blind_action = TRUE;
		END IF;
		
		-- Проверяем, является ли действие вынужденным
		IF NEW.is_voluntary = FALSE THEN
			SET is_forced_action = TRUE;
		END IF;
		
		-- Учитываем только добровольные агрессивные действия, не являющиеся обязательными блайндами
		IF NEW.action_type IN ('bet','raise','all-in') AND 
		   NOT is_blind_action AND 
		   NOT is_forced_action THEN
			
			-- Обновляем агрессию для конкретной улицы (без увеличения общего счетчика)
			CASE NEW.street
				WHEN 'preflop' THEN
					UPDATE players SET 
						preflop_aggression = IFNULL(preflop_aggression, 0) + 1
					WHERE player_id = NEW.player_id;
				WHEN 'flop' THEN
					UPDATE players SET 
						flop_aggression = IFNULL(flop_aggression, 0) + 1
					WHERE player_id = NEW.player_id;
				WHEN 'turn' THEN
					UPDATE players SET 
						turn_aggression = IFNULL(turn_aggression, 0) + 1
					WHERE player_id = NEW.player_id;
				WHEN 'river' THEN
					UPDATE players SET 
						river_aggression = IFNULL(river_aggression, 0) + 1
					WHERE player_id = NEW.player_id;
			END CASE;
		END IF;
	END");

	// 8.16 Исправленный триггер для passive_actions
	$pdo->exec("CREATE TRIGGER update_passive_actions AFTER INSERT ON actions FOR EACH ROW
	BEGIN
		DECLARE is_blind_action BOOLEAN DEFAULT FALSE;
		DECLARE is_forced_action BOOLEAN DEFAULT FALSE;
		
		-- Проверяем, является ли действие обязательным (блайнды)
		IF NEW.street = 'preflop' AND NEW.position IN ('SB', 'BB') AND 
		   NEW.action_type = 'check' THEN
			SET is_blind_action = TRUE;
		END IF;
		
		-- Проверяем, является ли действие вынужденным
		IF NEW.is_voluntary = FALSE THEN
			SET is_forced_action = TRUE;
		END IF;
		
		-- Учитываем только добровольные пассивные действия, не являющиеся обязательными блайндами
		IF NEW.action_type IN ('call','check') AND 
		   NOT is_blind_action AND 
		   NOT is_forced_action THEN
			
			-- Не обновляем счетчик здесь, так как он обновляется в триггере update_af
			-- Просто обновляем last_seen
			UPDATE players SET 
				last_seen = NOW()
			WHERE player_id = NEW.player_id;
		END IF;
	END");

	// 8.17 Триггер для hands_played
	$pdo->exec("CREATE TRIGGER update_hands_played AFTER INSERT ON actions FOR EACH ROW
    BEGIN
        DECLARE total_hands INT DEFAULT 0;
        
        SELECT COUNT(DISTINCT hand_id) INTO total_hands FROM actions
        WHERE player_id = NEW.player_id;
        
        UPDATE players SET hands_played = total_hands
        WHERE player_id = NEW.player_id;
    END");

	// 8.18 Триггер для showdowns
	$pdo->exec("CREATE TRIGGER update_showdowns AFTER INSERT ON showdown FOR EACH ROW
    BEGIN
        DECLARE total_showdowns INT DEFAULT 0;
        
        SELECT COUNT(DISTINCT hand_id) INTO total_showdowns FROM showdown
        WHERE player_id = NEW.player_id;
        
        UPDATE players SET showdowns = total_showdowns
        WHERE player_id = NEW.player_id;
    END");

	// 8.19 Триггер для postflop_raise_pct
	$pdo->exec("CREATE TRIGGER update_postflop_raise_pct AFTER INSERT ON actions FOR EACH ROW
	BEGIN
		DECLARE postflop_raise_count INT DEFAULT 0;
		DECLARE postflop_raise_opps INT DEFAULT 0;
		
		IF NEW.street IN ('flop', 'turn', 'river') THEN
			-- 1. Подсчет количества рейзов на постфлопе
			SELECT COUNT(*) INTO postflop_raise_count 
			FROM actions 
			WHERE player_id = NEW.player_id 
			  AND street IN ('flop', 'turn', 'river') 
			  AND action_type IN ('raise', 'all-in');
			
			-- 2. Подсчет возможностей для рейза (когда перед игроком была ставка)
			SELECT COUNT(DISTINCT a.hand_id, a.street) INTO postflop_raise_opps
			FROM actions a
			JOIN actions opp_bet ON 
				a.hand_id = opp_bet.hand_id 
				AND a.street = opp_bet.street
				AND opp_bet.player_id != a.player_id
				AND opp_bet.action_type IN ('bet', 'raise', 'all-in')
				AND opp_bet.sequence_num < a.sequence_num
			WHERE a.player_id = NEW.player_id
			  AND a.street IN ('flop', 'turn', 'river')
			  AND (
				  -- Игрок сделал колл/фолд (не рейз)
				  a.action_type IN ('call', 'fold') 
				  -- ИЛИ он сделал рейз (учитываем и успешные рейзы)
				  OR a.action_type IN ('raise', 'all-in')
			  )
			  -- Убедимся, что это первое действие игрока в ответ на ставку
			  AND NOT EXISTS (
				  SELECT 1 FROM actions earlier
				  WHERE earlier.hand_id = a.hand_id
					AND earlier.street = a.street
					AND earlier.player_id = a.player_id
					AND earlier.sequence_num < a.sequence_num
			  );
			
			-- 3. Обновляем статистику
			UPDATE players SET 
				postflop_raise_pct = IF(
					postflop_raise_opps > 0, 
					ROUND((postflop_raise_count * 100) / postflop_raise_opps, 2), 
					0
				),
				postflop_raises = postflop_raise_count
			WHERE player_id = NEW.player_id;
		END IF;
	END");

	// 8.20 Триггер для check_raise_pct
	$pdo->exec("CREATE TRIGGER update_check_raise_pct AFTER INSERT ON actions FOR EACH ROW
	BEGIN
		DECLARE check_raise_count INT DEFAULT 0;
		DECLARE check_raise_opportunities INT DEFAULT 0;
		
		-- 1. Считаем выполненные чек-рейзы (с явным указанием кодировки)
		SELECT COUNT(*) INTO check_raise_count
		FROM (
			SELECT a.hand_id, a.street
			FROM actions a
			WHERE a.player_id COLLATE utf8mb4_unicode_ci = NEW.player_id COLLATE utf8mb4_unicode_ci
			  AND a.action_type IN ('raise', 'all-in')
			  AND a.street IN ('flop', 'turn', 'river')
			  AND EXISTS (
				  SELECT 1 FROM actions chk
				  WHERE chk.hand_id = a.hand_id
					AND chk.street COLLATE utf8mb4_unicode_ci = a.street COLLATE utf8mb4_unicode_ci
					AND chk.player_id COLLATE utf8mb4_unicode_ci = a.player_id COLLATE utf8mb4_unicode_ci
					AND chk.action_type = 'check'
					AND chk.sequence_num < a.sequence_num
			  )
			  AND EXISTS (
				  SELECT 1 FROM actions bet
				  WHERE bet.hand_id = a.hand_id
					AND bet.street COLLATE utf8mb4_unicode_ci = a.street COLLATE utf8mb4_unicode_ci
					AND bet.player_id COLLATE utf8mb4_unicode_ci != a.player_id COLLATE utf8mb4_unicode_ci
					AND bet.action_type IN ('bet', 'raise', 'all-in')
					AND bet.sequence_num < a.sequence_num
					AND bet.sequence_num > (
						SELECT MAX(sequence_num)
						FROM actions prev
						WHERE prev.hand_id = a.hand_id
						  AND prev.street COLLATE utf8mb4_unicode_ci = a.street COLLATE utf8mb4_unicode_ci
						  AND prev.player_id COLLATE utf8mb4_unicode_ci = a.player_id COLLATE utf8mb4_unicode_ci
						  AND prev.action_type = 'check'
					)
			  )
			GROUP BY a.hand_id, a.street
		) AS cr;
		
		-- 2. Считаем возможности для чек-рейза
		SELECT COUNT(*) INTO check_raise_opportunities
		FROM (
			SELECT chk.hand_id, chk.street
			FROM actions chk
			WHERE chk.player_id COLLATE utf8mb4_unicode_ci = NEW.player_id COLLATE utf8mb4_unicode_ci
			  AND chk.action_type = 'check'
			  AND chk.street IN ('flop', 'turn', 'river')
			  AND EXISTS (
				  SELECT 1 FROM actions bet
				  WHERE bet.hand_id = chk.hand_id
					AND bet.street COLLATE utf8mb4_unicode_ci = chk.street COLLATE utf8mb4_unicode_ci
					AND bet.player_id COLLATE utf8mb4_unicode_ci != chk.player_id COLLATE utf8mb4_unicode_ci
					AND bet.action_type IN ('bet', 'raise', 'all-in')
					AND bet.sequence_num > chk.sequence_num
			  )
			GROUP BY chk.hand_id, chk.street
		) AS opportunities;
		
		-- 3. Обновляем статистику
		UPDATE players
		SET 
			check_raises = check_raise_count,
			check_raise_pct = IF(check_raise_opportunities > 0, 
							   ROUND((check_raise_count * 100) / check_raise_opportunities, 2), 
							   0)
		WHERE player_id COLLATE utf8mb4_unicode_ci = NEW.player_id COLLATE utf8mb4_unicode_ci;
	END");

	// 8.21 Триггер для preflop_aggression
	$pdo->exec("CREATE TRIGGER update_preflop_aggression AFTER INSERT ON actions FOR EACH ROW
    BEGIN
        DECLARE aggressive_actions INT DEFAULT 0;
        DECLARE total_actions INT DEFAULT 0;
        
        IF NEW.street = 'preflop' THEN
            SELECT COUNT(*) INTO aggressive_actions FROM actions
            WHERE player_id = NEW.player_id AND street = 'preflop'
            AND action_type IN ('bet','raise','all-in');
            
            SELECT COUNT(*) INTO total_actions FROM actions
            WHERE player_id = NEW.player_id AND street = 'preflop';
            
            UPDATE players SET 
                preflop_aggression = IF(total_actions>0, ROUND((aggressive_actions*100)/total_actions, 2), 0)
            WHERE player_id = NEW.player_id;
        END IF;
    END");

	// 8.22 Триггер для flop_aggression
	$pdo->exec("CREATE TRIGGER update_flop_aggression AFTER INSERT ON actions FOR EACH ROW
    BEGIN
        DECLARE aggressive_actions INT DEFAULT 0;
        DECLARE total_actions INT DEFAULT 0;
        
        IF NEW.street = 'flop' THEN
            SELECT COUNT(*) INTO aggressive_actions FROM actions
            WHERE player_id = NEW.player_id AND street = 'flop'
            AND action_type IN ('bet','raise','all-in');
            
            SELECT COUNT(*) INTO total_actions FROM actions
            WHERE player_id = NEW.player_id AND street = 'flop';
            
            UPDATE players SET 
                flop_aggression = IF(total_actions>0, ROUND((aggressive_actions*100)/total_actions, 2), 0)
            WHERE player_id = NEW.player_id;
        END IF;
    END");

	// 8.23 Триггер для turn_aggression
	$pdo->exec("CREATE TRIGGER update_turn_aggression AFTER INSERT ON actions FOR EACH ROW
    BEGIN
        DECLARE aggressive_actions INT DEFAULT 0;
        DECLARE total_actions INT DEFAULT 0;
        
        IF NEW.street = 'turn' THEN
            SELECT COUNT(*) INTO aggressive_actions FROM actions
            WHERE player_id = NEW.player_id AND street = 'turn'
            AND action_type IN ('bet','raise','all-in');
            
            SELECT COUNT(*) INTO total_actions FROM actions
            WHERE player_id = NEW.player_id AND street = 'turn';
            
            UPDATE players SET 
                turn_aggression = IF(total_actions>0, ROUND((aggressive_actions*100)/total_actions, 2), 0)
            WHERE player_id = NEW.player_id;
        END IF;
    END");

	// 8.24 Триггер для river_aggression
	$pdo->exec("CREATE TRIGGER update_river_aggression AFTER INSERT ON actions FOR EACH ROW
    BEGIN
        DECLARE aggressive_actions INT DEFAULT 0;
        DECLARE total_actions INT DEFAULT 0;
        
        IF NEW.street = 'river' THEN
            SELECT COUNT(*) INTO aggressive_actions FROM actions
            WHERE player_id = NEW.player_id AND street = 'river'
            AND action_type IN ('bet','raise','all-in');
            
            SELECT COUNT(*) INTO total_actions FROM actions
            WHERE player_id = NEW.player_id AND street = 'river';
            
            UPDATE players SET 
                river_aggression = IF(total_actions>0, ROUND((aggressive_actions*100)/total_actions, 2), 0)
            WHERE player_id = NEW.player_id;
        END IF;
    END");

	echo "Все триггеры успешно созданы\n";
	echo "Инициализация базы данных успешно завершена!\n";

} catch (PDOException $e) {
	die("Ошибка базы данных: " . $e->getMessage());
}
?>
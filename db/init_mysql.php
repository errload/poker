<?php
require_once __DIR__.'/config.php';

try {
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

	// Создаем базу данных
	$pdo->exec("CREATE DATABASE IF NOT EXISTS `".DB_NAME."` 
                CHARACTER SET ".DB_CHARSET." 
                COLLATE ".DB_CHARSET."_unicode_ci");
	$pdo->exec("USE `".DB_NAME."`");

	// Структура таблиц (без изменений)
	$tables = [
		"players" => "
            CREATE TABLE IF NOT EXISTS `players` (
                `player_id` VARCHAR(36) NOT NULL PRIMARY KEY,
                `nickname` VARCHAR(50) NOT NULL,
                `vpip` DECIMAL(5,2) DEFAULT 0.00,
                `pfr` DECIMAL(5,2) DEFAULT 0.00,
                `af` DECIMAL(5,2) DEFAULT 0.00,
                `afq` DECIMAL(5,2) DEFAULT 0.00,
                `three_bet` DECIMAL(5,2) DEFAULT 0.00,
                `wtsd` DECIMAL(5,2) DEFAULT 0.00,
                `wsd` DECIMAL(5,2) DEFAULT 0.00,
                `hands_played` INT UNSIGNED DEFAULT 0,
                `showdowns` INT UNSIGNED DEFAULT 0,
                `preflop_raises` INT UNSIGNED DEFAULT 0,
                `aggressive_actions` INT UNSIGNED DEFAULT 0,
                `passive_actions` INT UNSIGNED DEFAULT 0,
                `last_seen` DATETIME DEFAULT CURRENT_TIMESTAMP,
                `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
                INDEX `idx_player_nickname` (`nickname`),
                INDEX `idx_player_activity` (`last_seen`, `hands_played`)
            ) ENGINE=InnoDB DEFAULT CHARSET=".DB_CHARSET,

		"hands" => "
            CREATE TABLE IF NOT EXISTS `hands` (
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
            ) ENGINE=InnoDB DEFAULT CHARSET=".DB_CHARSET,

		"actions" => "
            CREATE TABLE IF NOT EXISTS `actions` (
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
                `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (`hand_id`) REFERENCES `hands`(`hand_id`) ON DELETE CASCADE,
                FOREIGN KEY (`player_id`) REFERENCES `players`(`player_id`) ON DELETE CASCADE,
                UNIQUE KEY `uk_action_sequence` (`hand_id`, `sequence_num`),
                INDEX `idx_action_composite` (`hand_id`, `player_id`, `street`),
                INDEX `idx_action_type` (`action_type`)
            ) ENGINE=InnoDB DEFAULT CHARSET=".DB_CHARSET
	];

	// Создаем таблицы
	foreach ($tables as $name => $sql) {
		$pdo->exec($sql);
		echo "Таблица '$name' успешно создана\n";
	}

	// Триггер для обновления статистики
	$trigger_sql = "
    CREATE TRIGGER after_action_insert
		AFTER INSERT ON actions
		FOR EACH ROW
		BEGIN
			DECLARE total_hands INT;
			DECLARE total_showdowns INT;
			DECLARE prev_aggressive_count INT DEFAULT 0;
			DECLARE player_folded INT DEFAULT 0;
			DECLARE remaining_players INT DEFAULT 0;
			DECLARE players_in_hand INT DEFAULT 0;
			DECLARE is_new_hand BOOLEAN DEFAULT FALSE;
			
			-- Проверяем, первое ли это действие в раздаче для игрока
			SELECT COUNT(*) = 0 INTO is_new_hand
			FROM actions
			WHERE hand_id = NEW.hand_id AND player_id = NEW.player_id
			AND sequence_num < NEW.sequence_num;
			
			-- Обновляем время последней активности
			UPDATE players SET last_seen = NOW() WHERE player_id = NEW.player_id;
			
			-- Получаем общее количество рук игрока
			SELECT hands_played, showdowns INTO total_hands, total_showdowns
			FROM players WHERE player_id = NEW.player_id;
			
			-- VPIP (Voluntarily Put $ In Pot)
			IF NEW.street = 'preflop' AND NEW.is_voluntary 
			   AND NEW.action_type IN ('call', 'bet', 'raise', 'all-in') 
			   AND is_new_hand THEN
				UPDATE players 
				SET hands_played = hands_played + 1,
					vpip = (
						SELECT COUNT(DISTINCT h.hand_id) 
						FROM hands h
						JOIN actions a ON h.hand_id = a.hand_id
						WHERE a.player_id = NEW.player_id
						AND a.street = 'preflop'
						AND a.is_voluntary = 1
						AND a.action_type IN ('call', 'bet', 'raise', 'all-in')
					) * 100 / GREATEST(hands_played + 1, 1)
				WHERE player_id = NEW.player_id;
			END IF;
			
			-- PFR (Preflop Raise)
			IF NEW.street = 'preflop' AND NEW.is_aggressive 
			   AND NEW.action_type IN ('raise', 'all-in') 
			   AND is_new_hand THEN
				UPDATE players 
				SET pfr = (
						SELECT COUNT(DISTINCT h.hand_id)
						FROM hands h
						JOIN actions a ON h.hand_id = a.hand_id
						WHERE a.player_id = NEW.player_id
						AND a.street = 'preflop'
						AND a.is_aggressive = 1
						AND a.action_type IN ('raise', 'all-in')
					) * 100 / GREATEST(hands_played + 1, 1),
					preflop_raises = preflop_raises + 1
				WHERE player_id = NEW.player_id;
			END IF;
			
			-- Three Bet
			IF NEW.street = 'preflop' AND NEW.action_type IN ('raise', 'all-in') THEN
				SELECT COUNT(*) INTO prev_aggressive_count
				FROM actions
				WHERE hand_id = NEW.hand_id
				AND street = 'preflop'
				AND action_type IN ('bet', 'raise', 'all-in')
				AND sequence_num < NEW.sequence_num;
				
				IF prev_aggressive_count >= 1 THEN
					UPDATE players 
					SET three_bet = (
							SELECT COUNT(DISTINCT a.hand_id)
							FROM actions a
							JOIN actions prev ON a.hand_id = prev.hand_id
							WHERE a.player_id = NEW.player_id
							AND a.street = 'preflop'
							AND a.action_type IN ('raise', 'all-in')
							AND prev.street = 'preflop'
							AND prev.action_type IN ('bet', 'raise', 'all-in')
							AND prev.sequence_num < a.sequence_num
						) * 100 / GREATEST(hands_played + 1, 1)
					WHERE player_id = NEW.player_id;
				END IF;
			END IF;
			
			-- WTSD (Went to Showdown) и WSD (Won at Showdown)
			IF NEW.street = 'river' THEN
				-- Проверяем, сбросился ли игрок в этой раздаче
				SELECT COUNT(*) INTO player_folded
				FROM actions
				WHERE hand_id = NEW.hand_id
				AND player_id = NEW.player_id
				AND action_type = 'fold';
				
				-- Если игрок не сбросился
				IF player_folded = 0 THEN
					-- Считаем сколько игроков не сбросились
					SELECT COUNT(DISTINCT player_id) INTO remaining_players
					FROM actions
					WHERE hand_id = NEW.hand_id
					AND player_id NOT IN (
						SELECT player_id FROM actions 
						WHERE hand_id = NEW.hand_id AND action_type = 'fold'
					);
					
					-- Обновляем статистику
					UPDATE players 
					SET 
						-- Увеличиваем счетчик шоудаунов
						showdowns = showdowns + 1,
						-- WTSD = (Количество шоудаунов) / (Общее количество рук) * 100
						wtsd = (showdowns + 1) * 100 / GREATEST(hands_played, 1)
					WHERE player_id = NEW.player_id;
					
					-- Обновляем WSD, если это последний оставшийся игрок
					IF remaining_players = 1 THEN
						UPDATE players 
						SET wsd = (
							SELECT COUNT(*) 
							FROM (
								SELECT DISTINCT h.hand_id
								FROM hands h
								JOIN actions a ON h.hand_id = a.hand_id
								WHERE a.player_id = NEW.player_id
								AND a.street = 'river'
								AND NOT EXISTS (
									SELECT 1 FROM actions f
									WHERE f.hand_id = h.hand_id
									AND f.player_id = NEW.player_id
									AND f.action_type = 'fold'
								)
								AND (
									SELECT COUNT(DISTINCT p.player_id)
									FROM actions p
									WHERE p.hand_id = h.hand_id
									AND p.player_id != NEW.player_id
									AND NOT EXISTS (
										SELECT 1 FROM actions pf
										WHERE pf.hand_id = h.hand_id
										AND pf.player_id = p.player_id
										AND pf.action_type = 'fold'
									)
								) = 0
							) AS won_showdowns
						) * 100 / GREATEST(showdowns + 1, 1)
						WHERE player_id = NEW.player_id;
					END IF;
				END IF;
			END IF;
			
			-- Обновляем агрессивные/пассивные действия
			IF NEW.is_aggressive THEN
				UPDATE players 
				SET aggressive_actions = aggressive_actions + 1
				WHERE player_id = NEW.player_id;
			ELSEIF NEW.action_type IN ('call', 'check') THEN
				UPDATE players 
				SET passive_actions = passive_actions + 1
				WHERE player_id = NEW.player_id;
			END IF;
			
			-- Обновляем AF и AFq
			UPDATE players 
			SET 
				af = IF(passive_actions > 0, aggressive_actions / passive_actions, 
					   IF(aggressive_actions > 0, 99, 0)),
				afq = IF((aggressive_actions + passive_actions) > 0, 
						aggressive_actions * 100 / (aggressive_actions + passive_actions), 0)
			WHERE player_id = NEW.player_id;
		END;";

	try {
		$pdo->exec("DROP TRIGGER IF EXISTS after_action_insert");
		$pdo->exec($trigger_sql);
		echo "Триггер 'after_action_insert' успешно создан\n";
	} catch (PDOException $e) {
		echo "Ошибка при создании триггера: ".$e->getMessage()."\n";
	}

	echo "Инициализация базы данных успешно завершена!\n";

} catch (PDOException $e) {
	die("Ошибка базы данных: " . $e->getMessage());
}
?>
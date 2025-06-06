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
        `wsd` DECIMAL(5,2) DEFAULT 0.00,
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
        `won_amount` DECIMAL(15,2) DEFAULT 0.00,
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
		'update_three_bet', 'update_wtsd', 'update_wsd', 'update_af', 'update_afq',
		'update_postflop_raises', 'update_cbet', 'update_fold_to_cbet',
		'update_steal_attempt', 'update_steal_success', 'update_aggressive_actions',
		'update_passive_actions', 'update_hands_played', 'update_showdowns'
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
		DECLARE prev_check_exists INT DEFAULT 0;
		DECLARE opponent_bet_exists INT DEFAULT 0;
		
		-- Проверяем, является ли текущее действие raise или all-in
		IF NEW.action_type IN ('raise', 'all-in') THEN
			-- 1. Проверяем, был ли чек от этого игрока в текущей улице до этого действия
			SELECT COUNT(*) INTO prev_check_exists
			FROM actions 
			WHERE hand_id = NEW.hand_id 
			  AND player_id = NEW.player_id 
			  AND street = NEW.street 
			  AND action_type = 'check'
			  AND sequence_num < NEW.sequence_num;
			
			-- 2. Проверяем, была ли ставка оппонента между чеком и рейзом
			IF prev_check_exists > 0 THEN
				SELECT COUNT(*) INTO opponent_bet_exists
				FROM actions
				WHERE hand_id = NEW.hand_id
				  AND player_id != NEW.player_id
				  AND street = NEW.street
				  AND action_type IN ('bet', 'raise', 'all-in')
				  AND sequence_num > (
					  SELECT MAX(sequence_num) 
					  FROM actions 
					  WHERE hand_id = NEW.hand_id 
						AND player_id = NEW.player_id 
						AND street = NEW.street 
						AND action_type = 'check'
						AND sequence_num < NEW.sequence_num
				  )
				  AND sequence_num < NEW.sequence_num;
			END IF;
			
			-- Если оба условия выполнены, увеличиваем счетчик чек-рейзов
			IF prev_check_exists > 0 AND opponent_bet_exists > 0 THEN
				UPDATE players 
				SET check_raises = IFNULL(check_raises, 0) + 1 
				WHERE player_id = NEW.player_id;
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
            AND action_type IN ('raise','all-in');
            
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
        
        IF NEW.street = 'preflop' AND NEW.action_type IN ('raise','all-in') THEN
            SELECT COUNT(DISTINCT a1.hand_id) INTO three_bets FROM actions a1
            JOIN actions a2 ON a1.hand_id = a2.hand_id
            WHERE a1.player_id = NEW.player_id AND a1.street = 'preflop'
            AND a1.action_type IN ('raise','all-in')
            AND a2.street = 'preflop' AND a2.action_type IN ('bet','raise','all-in')
            AND a2.sequence_num < a1.sequence_num;
            
            SELECT COUNT(DISTINCT a2.hand_id) INTO raise_opps FROM actions a2
            WHERE a2.player_id = NEW.player_id AND a2.street = 'preflop'
            AND a2.action_type IN ('bet','raise','all-in');
            
            UPDATE players SET three_bet = IF(raise_opps>0, ROUND((three_bets*100)/raise_opps, 2), 0)
            WHERE player_id = NEW.player_id;
        END IF;
    END");

	// 8.6 Триггер для WTSD
	$pdo->exec("CREATE TRIGGER update_wtsd AFTER INSERT ON actions FOR EACH ROW
    BEGIN
        DECLARE wtsd_count INT DEFAULT 0;
        DECLARE total_hands INT DEFAULT 0;
        
        SELECT COUNT(DISTINCT h.hand_id) INTO wtsd_count FROM hands h
        JOIN actions a ON h.hand_id = a.hand_id
        WHERE a.player_id = NEW.player_id AND a.street = 'river'
        AND NOT EXISTS (
            SELECT 1 FROM actions f
            WHERE f.hand_id = h.hand_id
            AND f.player_id = NEW.player_id
            AND f.action_type = 'fold'
        );
        
        SELECT COUNT(DISTINCT hand_id) INTO total_hands FROM actions 
        WHERE player_id = NEW.player_id;
        
        UPDATE players SET wtsd = IF(total_hands>0, ROUND((wtsd_count*100)/total_hands, 2), 0)
        WHERE player_id = NEW.player_id;
    END");

	// 8.7 Триггер для WSD
	$pdo->exec("CREATE TRIGGER update_wsd AFTER INSERT ON showdown FOR EACH ROW
    BEGIN
        DECLARE wsd_count INT DEFAULT 0;
        DECLARE wtsd_count INT DEFAULT 0;
        
        SELECT COUNT(DISTINCT s.hand_id) INTO wsd_count FROM showdown s
        JOIN hands h ON s.hand_id = h.hand_id
        WHERE s.player_id = NEW.player_id AND s.won_amount > 0;
        
        SELECT COUNT(DISTINCT h.hand_id) INTO wtsd_count FROM hands h
        JOIN actions a ON h.hand_id = a.hand_id
        WHERE a.player_id = NEW.player_id AND a.street = 'river'
        AND NOT EXISTS (
            SELECT 1 FROM actions f
            WHERE f.hand_id = h.hand_id
            AND f.player_id = NEW.player_id
            AND f.action_type = 'fold'
        );
        
        UPDATE players SET wsd = IF(wtsd_count>0, ROUND((wsd_count*100)/wtsd_count, 2), 0)
        WHERE player_id = NEW.player_id;
    END");

	// 8.8 Триггер для AF
	$pdo->exec("CREATE TRIGGER update_af AFTER INSERT ON actions FOR EACH ROW
    BEGIN
        DECLARE aggressive INT DEFAULT 0;
        DECLARE passive INT DEFAULT 0;
        
        SELECT COUNT(*) INTO aggressive FROM actions
        WHERE player_id = NEW.player_id
        AND action_type IN ('bet','raise','all-in');
        
        SELECT COUNT(*) INTO passive FROM actions
        WHERE player_id = NEW.player_id
        AND action_type IN ('call','check');
        
        UPDATE players SET 
            af = IF(passive>0, ROUND(aggressive/passive, 2), 
                IF(aggressive>0, 99.99, 0))
        WHERE player_id = NEW.player_id;
    END");

	// 8.9 Триггер для AFq
	$pdo->exec("CREATE TRIGGER update_afq AFTER INSERT ON actions FOR EACH ROW
    BEGIN
        DECLARE aggressive INT DEFAULT 0;
        DECLARE passive INT DEFAULT 0;
        
        SELECT COUNT(*) INTO aggressive FROM actions
        WHERE player_id = NEW.player_id
        AND action_type IN ('bet','raise','all-in');
        
        SELECT COUNT(*) INTO passive FROM actions
        WHERE player_id = NEW.player_id
        AND action_type IN ('call','check');
        
        UPDATE players SET 
            afq = IF((aggressive+passive)>0, 
                    ROUND((aggressive*100)/(aggressive+passive), 2), 0)
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
	$pdo->exec("CREATE TRIGGER update_cbet AFTER INSERT ON actions FOR EACH ROW
    BEGIN
        DECLARE cbet_attempts INT DEFAULT 0;
        DECLARE cbet_success INT DEFAULT 0;
        
        IF NEW.is_cbet = 1 THEN
            SELECT COUNT(DISTINCT hand_id) INTO cbet_attempts FROM actions
            WHERE player_id = NEW.player_id AND is_cbet = 1;
            
            SELECT COUNT(DISTINCT a.hand_id) INTO cbet_success FROM actions a
            JOIN actions next ON a.hand_id = next.hand_id
            WHERE a.player_id = NEW.player_id AND a.is_cbet = 1
            AND next.street = a.street AND next.sequence_num > a.sequence_num
            AND next.action_type = 'fold' AND next.player_id != NEW.player_id;
            
            UPDATE players SET 
                cbet = IF(cbet_attempts>0, ROUND((cbet_success*100)/cbet_attempts, 2), 0)
            WHERE player_id = NEW.player_id;
        END IF;
    END");

	// 8.12 Триггер для fold_to_cbet
	$pdo->exec("CREATE TRIGGER update_fold_to_cbet AFTER INSERT ON actions FOR EACH ROW
    BEGIN
        DECLARE folds INT DEFAULT 0;
        DECLARE cbet_opps INT DEFAULT 0;
        
        IF NEW.action_type = 'fold' THEN
            SELECT COUNT(DISTINCT a.hand_id) INTO folds FROM actions a
            JOIN actions prev ON a.hand_id = prev.hand_id
            WHERE a.player_id = NEW.player_id AND a.action_type = 'fold'
            AND prev.is_cbet = 1 AND prev.street = a.street
            AND prev.sequence_num < a.sequence_num AND prev.player_id != NEW.player_id;
            
            SELECT COUNT(DISTINCT prev.hand_id) INTO cbet_opps FROM actions prev
            JOIN actions a ON prev.hand_id = a.hand_id
            WHERE a.player_id = NEW.player_id AND prev.is_cbet = 1
            AND prev.street = a.street AND prev.sequence_num < a.sequence_num
            AND prev.player_id != NEW.player_id;
            
            UPDATE players SET 
                fold_to_cbet = IF(cbet_opps>0, ROUND((folds*100)/cbet_opps, 2), 0)
            WHERE player_id = NEW.player_id;
        END IF;
    END");

	// 8.13 Триггер для steal_attempt
	$pdo->exec("CREATE TRIGGER update_steal_attempt AFTER INSERT ON actions FOR EACH ROW
    BEGIN
        DECLARE steal_attempts INT DEFAULT 0;
        DECLARE total_hands INT DEFAULT 0;
        
        IF NEW.is_steal = 1 THEN
            SELECT COUNT(DISTINCT hand_id) INTO steal_attempts FROM actions
            WHERE player_id = NEW.player_id AND is_steal = 1;
            
            SELECT COUNT(DISTINCT hand_id) INTO total_hands FROM actions
            WHERE player_id = NEW.player_id;
            
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
            SELECT COUNT(DISTINCT a.hand_id) INTO steal_success FROM actions a
            WHERE a.player_id = NEW.player_id AND a.is_steal = 1
            AND NOT EXISTS (
                SELECT 1 FROM actions o
                WHERE o.hand_id = a.hand_id
                AND o.player_id != NEW.player_id
                AND o.street = 'preflop'
                AND o.action_type IN ('raise','all-in')
            );
            
            SELECT COUNT(DISTINCT hand_id) INTO steal_attempts FROM actions
            WHERE player_id = NEW.player_id AND is_steal = 1;
            
            UPDATE players SET 
                steal_success = IF(steal_attempts>0, ROUND((steal_success*100)/steal_attempts, 2), 0)
            WHERE player_id = NEW.player_id;
        END IF;
    END");

	// 8.15 Триггер для aggressive_actions
	$pdo->exec("CREATE TRIGGER update_aggressive_actions AFTER INSERT ON actions FOR EACH ROW
    BEGIN
        IF NEW.action_type IN ('bet','raise','all-in') THEN
            UPDATE players SET aggressive_actions = IFNULL(aggressive_actions, 0) + 1
            WHERE player_id = NEW.player_id;
        END IF;
    END");

	// 8.16 Триггер для passive_actions
	$pdo->exec("CREATE TRIGGER update_passive_actions AFTER INSERT ON actions FOR EACH ROW
    BEGIN
        IF NEW.action_type IN ('call','check') THEN
            UPDATE players SET passive_actions = IFNULL(passive_actions, 0) + 1
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

	echo "Все триггеры успешно созданы\n";

	// 9. Создание представлений
	// 9.1 Представление для постфлоп статистики
	$pdo->exec("CREATE OR REPLACE VIEW player_postflop_stats AS
    SELECT 
        p.player_id,
        p.nickname,
        p.postflop_raises,
        p.check_raises,
        p.af,
        p.afq,
        p.cbet,
        p.fold_to_cbet,
        ROUND(100 * p.postflop_raises / NULLIF(p.hands_played, 0), 2) AS postflop_raise_pct,
        ROUND(100 * p.check_raises / NULLIF(p.hands_played, 0), 2) AS check_raise_pct
    FROM players p
    ORDER BY p.postflop_raises DESC");

	// 9.2 Представление для стил статистики
	$pdo->exec("CREATE OR REPLACE VIEW player_steal_stats AS
    SELECT 
        p.player_id,
        p.nickname,
        p.steal_attempt,
        p.steal_success,
        p.three_bet,
        p.vpip,
        p.pfr
    FROM players p
    ORDER BY p.steal_attempt DESC");

	// 9.3 Представление для агрессии по улицам
	$pdo->exec("CREATE OR REPLACE VIEW player_aggression_by_street AS
    SELECT 
        p.player_id,
        p.nickname,
        ROUND(100 * SUM(CASE WHEN a.street = 'preflop' AND a.is_aggressive THEN 1 ELSE 0 END) / 
            NULLIF(SUM(CASE WHEN a.street = 'preflop' THEN 1 ELSE 0 END), 0), 2) AS preflop_aggression,
        ROUND(100 * SUM(CASE WHEN a.street = 'flop' AND a.is_aggressive THEN 1 ELSE 0 END) / 
            NULLIF(SUM(CASE WHEN a.street = 'flop' THEN 1 ELSE 0 END), 0), 2) AS flop_aggression,
        ROUND(100 * SUM(CASE WHEN a.street = 'turn' AND a.is_aggressive THEN 1 ELSE 0 END) / 
            NULLIF(SUM(CASE WHEN a.street = 'turn' THEN 1 ELSE 0 END), 0), 2) AS turn_aggression,
        ROUND(100 * SUM(CASE WHEN a.street = 'river' AND a.is_aggressive THEN 1 ELSE 0 END) / 
            NULLIF(SUM(CASE WHEN a.street = 'river' THEN 1 ELSE 0 END), 0), 2) AS river_aggression
    FROM players p
    JOIN actions a ON p.player_id = a.player_id
    GROUP BY p.player_id, p.nickname");

	echo "Все представления успешно созданы\n";

	echo "Инициализация базы данных успешно завершена!\n";

} catch (PDOException $e) {
	die("Ошибка базы данных: " . $e->getMessage());
}
?>
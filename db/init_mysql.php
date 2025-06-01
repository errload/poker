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

	// Create database
	$pdo->exec("CREATE DATABASE IF NOT EXISTS `".DB_NAME."` 
                CHARACTER SET ".DB_CHARSET." 
                COLLATE ".DB_CHARSET."_unicode_ci");
	$pdo->exec("USE `".DB_NAME."`");

	// Tables structure
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
                `current_stack` DECIMAL(15,2) NULL,
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
            ) ENGINE=InnoDB DEFAULT CHARSET=".DB_CHARSET,

		"showdowns" => "
            CREATE TABLE IF NOT EXISTS `showdowns` (
                `showdown_id` INT AUTO_INCREMENT PRIMARY KEY,
                `hand_id` INT NOT NULL,
                `player_id` VARCHAR(36) NOT NULL,
                `cards` VARCHAR(10) NOT NULL,
                `won` BOOLEAN DEFAULT FALSE,
                `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (`hand_id`) REFERENCES `hands`(`hand_id`) ON DELETE CASCADE,
                FOREIGN KEY (`player_id`) REFERENCES `players`(`player_id`) ON DELETE CASCADE,
                UNIQUE KEY `uk_showdown_player` (`hand_id`, `player_id`),
                INDEX `idx_showdown_hand` (`hand_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=".DB_CHARSET
	];

	// Create tables
	foreach ($tables as $name => $sql) {
		$pdo->exec($sql);
		echo "Table '$name' created successfully\n";
	}

	// Create triggers for stats updates
	$triggers = [
		"after_action_insert" => "
            CREATE TRIGGER after_action_insert
            AFTER INSERT ON actions
            FOR EACH ROW
            BEGIN
                DECLARE v_hands_played INT;
                DECLARE v_showdowns INT;
                DECLARE v_won_showdowns INT;
                
                -- Update last seen
                UPDATE players SET last_seen = NOW() WHERE player_id = NEW.player_id;
                
                -- Get current stats
                SELECT hands_played, showdowns, 
                       (showdowns * wsd / 100) INTO v_hands_played, v_showdowns, v_won_showdowns
                FROM players 
                WHERE player_id = NEW.player_id;
                
                -- Update VPIP for voluntary preflop actions
                IF NEW.street = 'preflop' AND NEW.is_voluntary AND NOT EXISTS (
                    SELECT 1 FROM actions 
                    WHERE hand_id = NEW.hand_id AND player_id = NEW.player_id 
                    AND sequence_num < NEW.sequence_num
                ) THEN
                    UPDATE players 
                    SET hands_played = hands_played + 1,
                        vpip = ((vpip * hands_played) + 100) / (hands_played + 1)
                    WHERE player_id = NEW.player_id;
                END IF;
                
                -- Update PFR for first aggressive preflop action
                IF NEW.street = 'preflop' AND NEW.is_aggressive AND NOT EXISTS (
                    SELECT 1 FROM actions 
                    WHERE hand_id = NEW.hand_id AND player_id = NEW.player_id 
                    AND street = 'preflop' AND is_aggressive = 1
                    AND sequence_num < NEW.sequence_num
                ) THEN
                    UPDATE players 
                    SET preflop_raises = preflop_raises + 1,
                        pfr = ((pfr * hands_played) + 100) / (hands_played + 1)
                    WHERE player_id = NEW.player_id;
                END IF;
                
                -- Update aggression stats
                IF NEW.is_aggressive THEN
                    UPDATE players 
                    SET aggressive_actions = aggressive_actions + 1
                    WHERE player_id = NEW.player_id;
                ELSEIF NEW.action_type IN ('call', 'check') THEN
                    UPDATE players 
                    SET passive_actions = passive_actions + 1
                    WHERE player_id = NEW.player_id;
                END IF;
                
                -- Update AF and AFq
                UPDATE players 
                SET af = IF(passive_actions > 0, aggressive_actions / passive_actions, 
                           IF(aggressive_actions > 0, 99, 0)),
                    afq = IF((aggressive_actions + passive_actions) > 0, 
                            aggressive_actions * 100 / (aggressive_actions + passive_actions), 0)
                WHERE player_id = NEW.player_id;
            END"
	];

	foreach ($triggers as $name => $sql) {
		try {
			$pdo->exec("DROP TRIGGER IF EXISTS $name");
			$pdo->exec($sql);
			echo "Trigger '$name' created successfully\n";
		} catch (PDOException $e) {
			echo "Warning: Could not create trigger '$name': ".$e->getMessage()."\n";
		}
	}

	echo "Database initialization completed successfully!\n";

} catch (PDOException $e) {
	die("Database error: " . $e->getMessage());
}
?>
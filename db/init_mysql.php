<?php
// init_mysql.php - MySQL database initialization
require_once __DIR__.'/config.php';

try {
	// Connect to MySQL server
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

	// Create database if not exists
	$pdo->exec("CREATE DATABASE IF NOT EXISTS `".DB_NAME."` 
                CHARACTER SET ".DB_CHARSET." 
                COLLATE ".DB_CHARSET."_unicode_ci");

	// Select the database
	$pdo->exec("USE `".DB_NAME."`");

	// Tables structure with improved schema
	$tables = [
		"players" => "
            CREATE TABLE IF NOT EXISTS `players` (
                `player_id` VARCHAR(36) NOT NULL PRIMARY KEY,
                `nickname` VARCHAR(50) NOT NULL,
                `vpip` DECIMAL(5,2) DEFAULT 0.00 COMMENT 'Voluntarily Put $ In Pot %',
                `pfr` DECIMAL(5,2) DEFAULT 0.00 COMMENT 'Pre-Flop Raise %',
                `af` DECIMAL(5,2) DEFAULT 0.00 COMMENT 'Aggression Factor',
                `afq` DECIMAL(5,2) DEFAULT 0.00 COMMENT 'Aggression Frequency %',
                `three_bet` DECIMAL(5,2) DEFAULT 0.00 COMMENT '3-Bet %',
                `wtsd` DECIMAL(5,2) DEFAULT 0.00 COMMENT 'Went to Showdown %',
                `wsd` DECIMAL(5,2) DEFAULT 0.00 COMMENT 'Won at Showdown %',
                `hands_played` INT UNSIGNED DEFAULT 0,
                `showdowns` INT UNSIGNED DEFAULT 0,
                `preflop_raises` INT UNSIGNED DEFAULT 0,
                `last_seen` DATETIME DEFAULT CURRENT_TIMESTAMP,
                `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
                INDEX `idx_player_nickname` (`nickname`),
                INDEX `idx_player_activity` (`last_seen`, `hands_played`)
            ) ENGINE=InnoDB DEFAULT CHARSET=".DB_CHARSET,

		"hands" => "
            CREATE TABLE IF NOT EXISTS `hands` (
                `hand_id` INT AUTO_INCREMENT PRIMARY KEY,
                `hero_position` VARCHAR(10) COMMENT 'Position at table (BTN, SB, BB, etc)',
                `hero_stack` DECIMAL(15,2) COMMENT 'Hero starting stack',
                `hero_cards` VARCHAR(10) COMMENT 'Hero cards (AhKd format)',
                `board` VARCHAR(30) COMMENT 'Community cards (Jc7d2h 5s As format)',
                `stacks` TEXT COMMENT 'JSON array of starting stacks',
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
                `street` ENUM('preflop','flop','turn','river') NOT NULL,
                `action_type` ENUM('fold','check','call','bet','raise','all-in') NOT NULL,
                `amount` DECIMAL(15,2) NULL COMMENT 'Bet/raise amount',
                `current_stack` DECIMAL(15,2) NULL COMMENT 'Player stack after action',
                `sequence_num` SMALLINT UNSIGNED NOT NULL COMMENT 'Action order in hand',
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
                `cards` VARCHAR(10) NOT NULL COMMENT 'Player cards at showdown',
                `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (`hand_id`) REFERENCES `hands`(`hand_id`) ON DELETE CASCADE,
                FOREIGN KEY (`player_id`) REFERENCES `players`(`player_id`) ON DELETE CASCADE,
                UNIQUE KEY `uk_showdown_player` (`hand_id`, `player_id`),
                INDEX `idx_showdown_hand` (`hand_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=".DB_CHARSET
	];

	// Create tables
	foreach ($tables as $name => $sql) {
		try {
			$pdo->exec($sql);
			echo "Table '$name' created successfully\n";
		} catch (PDOException $e) {
			die("Error creating table '$name': ".$e->getMessage());
		}
	}

	// Additional procedures/functions if needed
	$procedures = [
		"update_player_stats" => "
            CREATE PROCEDURE update_player_stats(IN p_player_id VARCHAR(36), IN p_action_type VARCHAR(10), IN p_street VARCHAR(10))
            BEGIN
                DECLARE v_hands_played INT;
                DECLARE v_preflop_raises INT;
                
                SELECT hands_played, preflop_raises INTO v_hands_played, v_preflop_raises 
                FROM players WHERE player_id = p_player_id FOR UPDATE;
                
                -- Update VPIP
                IF p_street = 'preflop' AND p_action_type != 'fold' THEN
                    SET v_hands_played = v_hands_played + 1;
                    
                    UPDATE players 
                    SET hands_played = v_hands_played,
                        vpip = ((vpip * (v_hands_played - 1)) + 1) / v_hands_played,
                        last_seen = NOW()
                    WHERE player_id = p_player_id;
                END IF;
                
                -- Update PFR
                IF p_street = 'preflop' AND (p_action_type = 'raise' OR p_action_type = 'all-in') THEN
                    SET v_preflop_raises = v_preflop_raises + 1;
                    
                    UPDATE players 
                    SET preflop_raises = v_preflop_raises,
                        pfr = ((pfr * (v_hands_played - 1)) + 1) / v_hands_played,
                        last_seen = NOW()
                    WHERE player_id = p_player_id;
                END IF;
            END"
	];

	foreach ($procedures as $name => $sql) {
		try {
			$pdo->exec("DROP PROCEDURE IF EXISTS $name");
			$pdo->exec($sql);
			echo "Procedure '$name' created successfully\n";
		} catch (PDOException $e) {
			echo "Warning: Could not create procedure '$name': ".$e->getMessage()."\n";
		}
	}

	echo "Database initialization completed successfully!\n";

} catch (PDOException $e) {
	die("Database error: " . $e->getMessage());
}
?>
<?php
// init_mysql.php - MySQL database initialization

// Database configuration
$db_host = 'mysql';
$db_user = 'root';
$db_pass = 'root';
$db_name = 'poker_tracker';

// Connect to MySQL server
try {
	$pdo = new PDO("mysql:host=$db_host", $db_user, $db_pass);
	$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

	// Create database if not exists
	$pdo->exec("CREATE DATABASE IF NOT EXISTS `$db_name` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");

	// Select the database
	$pdo->exec("USE `$db_name`");

	// Create tables
	$tables = [
		"players" => "
        CREATE TABLE IF NOT EXISTS `players` (
			`player_id` INT AUTO_INCREMENT PRIMARY KEY,
			`nickname` VARCHAR(50) UNIQUE NOT NULL,
			`vpip` FLOAT DEFAULT 0 CHECK (`vpip` BETWEEN 0 AND 100),
			`pfr` FLOAT DEFAULT 0 CHECK (`pfr` BETWEEN 0 AND 100),
			`af` FLOAT DEFAULT 0 CHECK (`af` >= 0),
			`afq` FLOAT DEFAULT 0 CHECK (`afq` BETWEEN 0 AND 100),
			`three_bet` FLOAT DEFAULT 0 CHECK (`three_bet` BETWEEN 0 AND 100),
			`wtsd` FLOAT DEFAULT 0 CHECK (`wtsd` BETWEEN 0 AND 100),
			`wsd` FLOAT DEFAULT 0 CHECK (`wsd` BETWEEN 0 AND 100),
			`hands_played` INT DEFAULT 0,
			`hands_won` INT DEFAULT 0,
			`showdowns` INT DEFAULT 0,
			`showdowns_won` INT DEFAULT 0,
			`preflop_raises` INT DEFAULT 0,
			`preflop_opportunities` INT DEFAULT 0,
			`three_bet_opportunities` INT DEFAULT 0,
			`three_bet_made` INT DEFAULT 0,
			`last_seen` DATETIME DEFAULT CURRENT_TIMESTAMP,
			`created_at` DATETIME DEFAULT CURRENT_TIMESTAMP
		) ENGINE=InnoDB",

		"hands" => "
        CREATE TABLE IF NOT EXISTS `hands` (
			`hand_id` INT AUTO_INCREMENT PRIMARY KEY,
			`hero_cards` VARCHAR(5) NULL,
			`hero_position` ENUM('UTG', 'UTG+1', 'MP', 'HJ', 'CO', 'BTN', 'SB', 'BB') NOT NULL,
			`hero_stack` FLOAT NOT NULL CHECK (`hero_stack` > 0),
			`stacks` JSON NOT NULL COMMENT 'JSON всех стеков на начало раздачи',
			`board` VARCHAR(20) NULL,
			`is_completed` TINYINT(1) DEFAULT 0,
			`created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
			`updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
		) ENGINE=InnoDB",

		"actions" => "
        CREATE TABLE IF NOT EXISTS `actions` (
			`action_id` INT AUTO_INCREMENT PRIMARY KEY,
			`hand_id` INT NOT NULL,
			`player_id` INT NOT NULL,
			`street` ENUM('preflop', 'flop', 'turn', 'river') NOT NULL,
			`action_type` ENUM('fold', 'check', 'call', 'all-in', 'raise', 'show') NOT NULL,
			`amount` FLOAT NULL CHECK (`amount` IS NULL OR `amount` > 0),
			`current_stack` FLOAT NULL COMMENT 'Стек игрока после действия',
			`sequence_num` INT NOT NULL CHECK (`sequence_num` > 0),
			FOREIGN KEY (`hand_id`) REFERENCES `hands`(`hand_id`) ON DELETE CASCADE,
			FOREIGN KEY (`player_id`) REFERENCES `players`(`player_id`),
			UNIQUE (`hand_id`, `sequence_num`)
		) ENGINE=InnoDB",

		"showdowns" => "
        CREATE TABLE IF NOT EXISTS `showdowns` (
            `showdown_id` INT AUTO_INCREMENT PRIMARY KEY,
            `hand_id` INT NOT NULL,
            `player_id` INT NOT NULL,
            `cards` VARCHAR(5) NOT NULL,
            FOREIGN KEY (`hand_id`) REFERENCES `hands`(`hand_id`) ON DELETE CASCADE,
            FOREIGN KEY (`player_id`) REFERENCES `players`(`player_id`),
            UNIQUE (`hand_id`, `player_id`)
        ) ENGINE=InnoDB"
	];

	// Execute table creation
	foreach ($tables as $name => $sql) {
		$pdo->exec($sql);
		echo "Table '$name' created successfully\n";
	}

	// Create indexes
	$indexes = [
		"idx_actions_hand" => "CREATE INDEX `idx_actions_hand` ON `actions`(`hand_id`)",
		"idx_actions_player" => "CREATE INDEX `idx_actions_player` ON `actions`(`player_id`)",
		"idx_actions_sequence" => "CREATE INDEX `idx_actions_sequence` ON `actions`(`hand_id`, `sequence_num`)",
		"idx_actions_street" => "CREATE INDEX `idx_actions_street` ON `actions`(`street`)",
		"idx_showdowns_player" => "CREATE INDEX `idx_showdowns_player` ON `showdowns`(`player_id`)"
	];

	foreach ($indexes as $name => $sql) {
		$pdo->exec($sql);
		echo "Index '$name' created successfully\n";
	}

	// Insert test data (optional)
	if (isset($_GET['testdata'])) {
		// Clear old test data
		$pdo->exec("SET FOREIGN_KEY_CHECKS = 0");
		$pdo->exec("TRUNCATE TABLE `actions`");
		$pdo->exec("TRUNCATE TABLE `showdowns`");
		$pdo->exec("TRUNCATE TABLE `hands`");
		$pdo->exec("TRUNCATE TABLE `players`");
		$pdo->exec("SET FOREIGN_KEY_CHECKS = 1");

		// Insert test players
		$pdo->exec("
            INSERT INTO `players` (`nickname`, `vpip`, `pfr`, `af`) 
            VALUES 
                ('Hero', 25, 20, 2.1),
                ('TightPlayer', 15, 12, 1.8),
                ('LooseFish', 45, 5, 0.9)
        ");

		// Insert test hand
		$pdo->exec("
            INSERT INTO `hands` (`hero_cards`, `hero_position`, `hero_stack`, `board`, `is_completed`)
            VALUES ('AhKh', 'BTN', 50.5, 'Jc7d2h', 1)
        ");

		$hand_id = $pdo->lastInsertId();

		// Insert test actions
		$pdo->exec("
            INSERT INTO `actions` (`hand_id`, `player_id`, `street`, `action_type`, `amount`, `sequence_num`)
            VALUES 
                ($hand_id, 2, 'preflop', 'fold', NULL, 1),
                ($hand_id, 3, 'preflop', 'call', 2.5, 2),
                ($hand_id, 1, 'preflop', 'raise', 5.0, 3),
                ($hand_id, 3, 'flop', 'check', NULL, 4),
                ($hand_id, 1, 'flop', 'bet', 3.0, 5)
        ");

		// Insert showdown
		$pdo->exec("
            INSERT INTO `showdowns` (`hand_id`, `player_id`, `cards`)
            VALUES ($hand_id, 1, 'AhKh'),
                   ($hand_id, 3, 'QcQd')
        ");

		echo "Test data inserted successfully\n";
	}

	echo "MySQL database initialization complete!\n";

} catch (PDOException $e) {
	die("Database error: " . $e->getMessage());
}
?>
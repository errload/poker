<?php
// config.php - Database configuration

define('DB_HOST', 'mysql');
define('DB_NAME', 'poker_tracker');
define('DB_USER', 'root');
define('DB_PASS', 'root');
define('DB_CHARSET', 'utf8mb4');

// Enable error reporting
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
?>
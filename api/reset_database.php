<?php
header('Content-Type: application/json');
require_once __DIR__.'/../db/config.php'; // Файл с настройками БД

try {
	// Подключение к БД
	$pdo = new PDO(
		"mysql:host=".DB_HOST.";dbname=".DB_NAME.";charset=utf8mb4",
		DB_USER,
		DB_PASS,
		[
			PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
			PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
		]
	);

	// Проверка подтверждения
	$data = json_decode(file_get_contents('php://input'), true);
	if (!isset($data['confirm']) || $data['confirm'] !== true) {
		throw new Exception('Подтверждение не получено');
	}

	// Отключаем проверку внешних ключей для очистки таблиц
	$pdo->exec("SET FOREIGN_KEY_CHECKS = 0");

	// Получаем список только таблиц (исключая представления)
	$tables = $pdo->query("
        SELECT table_name 
        FROM information_schema.tables 
        WHERE table_schema = '".DB_NAME."' 
        AND table_type = 'BASE TABLE'
    ")->fetchAll(PDO::FETCH_COLUMN);

	// Очищаем каждую таблицу
	foreach ($tables as $table) {
		$pdo->exec("TRUNCATE TABLE `$table`");
	}

	// Включаем проверку внешних ключей обратно
	$pdo->exec("SET FOREIGN_KEY_CHECKS = 1");

	echo json_encode(['message' => 'База данных успешно очищена']);

} catch (Exception $e) {
	// Если что-то пошло не так, включаем проверку внешних ключей обратно
	if (isset($pdo)) {
		$pdo->exec("SET FOREIGN_KEY_CHECKS = 1");
	}
	http_response_code(500);
	echo json_encode(['error' => $e->getMessage()]);
}
?>
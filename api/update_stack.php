<?php
header('Content-Type: application/json');
require_once __DIR__.'/../db/config.php';

$response = ['success' => false, 'message' => ''];

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

	// Получаем данные
	$input = json_decode(file_get_contents('php://input'), true);
	$handId = $input['hand_id'] ?? null;
	$newStack = $input['new_stack'] ?? null;

	if (!$handId || $newStack === null) {
		throw new Exception('Необходимы hand_id и new_stack');
	}

	$newStack = (float)$newStack;

	// Просто обновляем стек героя в таблице hands
	$stmt = $pdo->prepare("
        UPDATE hands 
        SET hero_stack = ?
        WHERE hand_id = ?
    ");
	$stmt->execute([$newStack, $handId]);

	$response = [
		'success' => true,
		'message' => 'Стек героя успешно обновлен',
		'updated_stack' => $newStack
	];

} catch (Exception $e) {
	$response['message'] = $e->getMessage();
}

echo json_encode($response);
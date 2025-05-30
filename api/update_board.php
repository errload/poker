<?php
header('Content-Type: application/json');
require_once __DIR__.'/../db/config.php';

try {
	$pdo = new PDO(
		"mysql:host=".DB_HOST.";dbname=".DB_NAME.";charset=utf8mb4",
		DB_USER,
		DB_PASS,
		[
			PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
			PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
		]
	);

	$input = json_decode(file_get_contents('php://input'), true);
	if (!$input) throw new Exception('Invalid JSON input');

	// Проверяем обязательные поля (только hand_id и board)
	$required = ['hand_id', 'board'];
	foreach ($required as $field) {
		if (!isset($input[$field])) {
			throw new Exception("Missing required field: $field");
		}
	}

	// Проверяем формат board (должен быть пустым или содержать корректные карты)
	if ($input['board'] !== '' && !preg_match('/^([2-9TJQKA][cdhs]){2,5}( [2-9TJQKA][cdhs]){0,2}$/i', $input['board'])) {
		throw new Exception("Invalid board format. Use format like 'Jc7d2h' for flop, 'Jc7d2h 5s' for turn, 'Jc7d2h 5s As' for river");
	}

	// Проверяем существование hand_id
	$stmt = $pdo->prepare("SELECT 1 FROM hands WHERE hand_id = ?");
	$stmt->execute([$input['hand_id']]);
	if (!$stmt->fetch()) {
		throw new Exception("Hand with ID {$input['hand_id']} not found.");
	}

	// Обновляем только board
	$stmt = $pdo->prepare("
        UPDATE hands 
        SET board = :board,
            updated_at = NOW()
        WHERE hand_id = :hand_id
    ");

	$stmt->execute([
		':hand_id' => $input['hand_id'],
		':board' => $input['board'] === '' ? null : $input['board']
	]);

	echo json_encode([
		'success' => true,
		'hand_id' => $input['hand_id'],
		'board' => $input['board'],
		'message' => 'Board updated successfully'
	]);

} catch (Exception $e) {
	http_response_code(400);
	echo json_encode([
		'success' => false,
		'error' => $e->getMessage()
	]);
}
?>
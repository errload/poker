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

	// Проверяем обязательные поля
	$required = ['hand_id', 'hero_cards'];
	foreach ($required as $field) {
		if (!isset($input[$field])) {
			throw new Exception("Missing required field: $field");
		}
	}

	// Проверяем формат карт (2 символа - без масти, 4 символа - с мастью)
	if (!preg_match('/^[2-9TJQKA][cdhs][2-9TJQKA][cdhs]$|^[2-9TJQKA]{2}$/i', $input['hero_cards'])) {
		throw new Exception("Invalid cards format. Use format like 'AhKd' or 'AK'");
	}

	// Обновляем только если текущее значение NULL
	$stmt = $pdo->prepare("
        UPDATE hands 
        SET hero_cards = :cards,
            updated_at = NOW()
        WHERE hand_id = :hand_id 
        AND hero_cards IS NULL
    ");

	$stmt->execute([
		':hand_id' => $input['hand_id'],
		':cards' => $input['hero_cards']
	]);

	$rowsAffected = $stmt->rowCount();

	if ($rowsAffected === 0) {
		throw new Exception("No rows updated. Either hand not found or cards already set.");
	}

	echo json_encode([
		'success' => true,
		'hand_id' => $input['hand_id'],
		'hero_cards' => $input['hero_cards'],
		'rows_affected' => $rowsAffected
	]);

} catch (Exception $e) {
	http_response_code(400);
	echo json_encode([
		'success' => false,
		'error' => $e->getMessage()
	]);
}
?>
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
	if (!$input) throw new Exception('Неверный JSON-ввод');

	// Проверяем обязательные поля
	$required = ['hero_position', 'hero_stack'];
	foreach ($required as $field) {
		if (!isset($input[$field])) throw new Exception("Отсутствует обязательное поле: $field");
	}

	// Начинаем транзакцию
	$pdo->beginTransaction();

	try {
		// 1. Завершаем предыдущую активную раздачу (если есть)
		$stmt = $pdo->prepare("
            SELECT hand_id FROM hands 
            WHERE is_completed = 0 
            ORDER BY hand_id DESC 
            LIMIT 1
        ");
		$stmt->execute();
		$activeHand = $stmt->fetch();

		if ($activeHand) {
			$stmt = $pdo->prepare("
                UPDATE hands 
                SET is_completed = 1, 
                    updated_at = NOW() 
                WHERE hand_id = ?
            ");
			$stmt->execute([$activeHand['hand_id']]);
		}

		// 2. Создаем новую раздачу
		$stmt = $pdo->prepare("
            INSERT INTO `hands` (`hero_position`, `hero_stack`, `hero_cards`, `is_completed`)
            VALUES (:position, :stack, :cards, 0)
        ");

		$stmt->execute([
			':position' => $input['hero_position'],
			':stack' => (float)$input['hero_stack'],
			':cards' => $input['hero_cards'] ?? null
		]);

		$newHandId = $pdo->lastInsertId();

		// Фиксируем транзакцию
		$pdo->commit();

		echo json_encode([
			'success' => true,
			'hand_id' => $newHandId,
			'previous_hand_completed' => $activeHand ? $activeHand['hand_id'] : null
		]);

	} catch (Exception $e) {
		$pdo->rollBack();
		throw $e;
	}

} catch (Exception $e) {
	http_response_code(400);
	echo json_encode([
		'success' => false,
		'error' => $e->getMessage()
	]);
}
?>
<?php
header('Content-Type: application/json');
require_once __DIR__.'/../db/config.php';

try {
	// Подключение к БД с обработкой ошибок
	$pdo = new PDO(
		"mysql:host=".DB_HOST.";dbname=".DB_NAME.";charset=utf8mb4",
		DB_USER,
		DB_PASS,
		[
			PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
			PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
			PDO::ATTR_EMULATE_PREPARES => false
		]
	);

	// Получаем и валидируем входные данные
	$input = json_decode(file_get_contents('php://input'), true);
	if (!$input || !isset($input['player_id'])) {
		throw new Exception('Invalid request data: player_id is required');
	}

	$playerId = (int)$input['player_id'];
	if ($playerId <= 0) {
		throw new Exception('Invalid player ID');
	}

	// Начинаем транзакцию
	$pdo->beginTransaction();

	try {
		// 1. Проверяем существование игрока
		$stmt = $pdo->prepare("SELECT COUNT(*) FROM `players` WHERE `player_id` = ?");
		$stmt->execute([$playerId]);
		$playerExists = $stmt->fetchColumn();

		if (!$playerExists) {
			$pdo->commit();
			echo json_encode([
				'status' => 'success',
				'message' => 'Player does not exist, nothing to delete',
				'player_id' => $playerId
			]);
			exit;
		}

		// 2. Удаляем связанные данные из таблицы actions
		$stmt = $pdo->prepare("DELETE FROM `actions` WHERE `player_id` = ?");
		$stmt->execute([$playerId]);
		$deletedRelated = $stmt->rowCount();

		// 3. Удаляем самого игрока
		$stmt = $pdo->prepare("DELETE FROM `players` WHERE `player_id` = ?");
		$stmt->execute([$playerId]);

		// Фиксируем транзакцию
		$pdo->commit();

		// Успешный ответ
		echo json_encode([
			'status' => 'success',
			'message' => 'Player and all related data deleted successfully',
			'player_id' => $playerId,
			'deleted_related' => $deletedRelated
		]);

	} catch (Exception $e) {
		$pdo->rollBack();
		throw $e;
	}

} catch (PDOException $e) {
	http_response_code(500);
	echo json_encode([
		'status' => 'error',
		'error' => 'Database error',
		'message' => $e->getMessage()
	]);
} catch (Exception $e) {
	http_response_code(400);
	echo json_encode([
		'status' => 'error',
		'message' => $e->getMessage()
	]);
}
?>
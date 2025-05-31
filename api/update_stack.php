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
	$playerId = $input['player_id'] ?? null;
	$newStack = $input['new_stack'] ?? null;

	if (!$handId || !$playerId || $newStack === null) {
		throw new Exception('Необходимы hand_id, player_id и new_stack');
	}

	$newStack = (float)$newStack;

	$pdo->beginTransaction();

	try {
		// Получаем все действия игрока в раздаче в порядке их выполнения
		$stmt = $pdo->prepare("
            SELECT * FROM actions 
            WHERE hand_id = ? AND player_id = ?
            ORDER BY sequence_num ASC
        ");
		$stmt->execute([$handId, $playerId]);
		$actions = $stmt->fetchAll();

		if (empty($actions)) {
			throw new Exception('Действия игрока не найдены');
		}

		// Находим индекс последнего действия
		$lastActionIndex = count($actions) - 1;

		// Если нужно изменить стек последнего действия
		if ($actions[$lastActionIndex]['current_stack'] == $newStack) {
			$response = [
				'success' => true,
				'message' => 'Стек уже соответствует заданному',
				'updated_actions' => 0
			];
		} else {
			// Вычисляем разницу между новым и текущим стеком
			$stackDiff = $newStack - $actions[$lastActionIndex]['current_stack'];

			// Обновляем стеки для всех действий
			$updateStmt = $pdo->prepare("
                UPDATE actions SET current_stack = current_stack + ? 
                WHERE hand_id = ? AND player_id = ? AND sequence_num >= ?
            ");

			// Находим первое действие, где игрок сделал ставку (bet/raise/call/all-in)
			$firstBetIndex = null;
			foreach ($actions as $index => $action) {
				if (in_array($action['action_type'], ['bet', 'raise', 'call', 'all-in'])) {
					$firstBetIndex = $index;
					break;
				}
			}

			// Если ставок не было, обновляем все действия
			$updateFromSequence = $actions[0]['sequence_num'];

			// Если ставки были, обновляем начиная с первой ставки
			if ($firstBetIndex !== null) {
				$updateFromSequence = $actions[$firstBetIndex]['sequence_num'];
			}

			$updateStmt->execute([$stackDiff, $handId, $playerId, $updateFromSequence]);
			$updatedCount = $updateStmt->rowCount();

			$response = [
				'success' => true,
				'message' => 'Стеки успешно обновлены',
				'updated_actions' => $updatedCount,
				'stack_diff' => $stackDiff,
				'update_from_sequence' => $updateFromSequence
			];
		}

		$pdo->commit();

	} catch (Exception $e) {
		$pdo->rollBack();
		throw $e;
	}

} catch (Exception $e) {
	$response['message'] = $e->getMessage();
}

echo json_encode($response);
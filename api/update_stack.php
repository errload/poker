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
		// Получаем все действия игрока в раздаче в обратном порядке
		$stmt = $pdo->prepare("
            SELECT sequence_num, action_type, amount
            FROM actions 
            WHERE hand_id = ? AND player_id = ?
            ORDER BY sequence_num DESC
        ");
		$stmt->execute([$handId, $playerId]);
		$actions = $stmt->fetchAll();

		if (empty($actions)) {
			throw new Exception('Действия игрока не найдены');
		}

		$currentStack = $newStack;
		$updatedActions = 0;

		foreach ($actions as $action) {
			$actionType = $action['action_type'];
			$amount = (float)$action['amount'];
			$sequenceNum = $action['sequence_num'];

			// Рассчитываем стек для текущего действия
			$stackForThisAction = $currentStack;

			// Для всех ставок (кроме фолда и чека) добавляем сумму ставки
			if (!in_array($actionType, ['fold', 'check']) && $amount > 0) {
				$stackForThisAction += $amount;
			}

			// Проверяем, чтобы стек не ушел в минус
			if ($stackForThisAction < 0) {
				throw new Exception("Недостаточно средств в стеке для действия #$sequenceNum");
			}

			// Обновляем запись в базе данных
			$updateStmt = $pdo->prepare("
                UPDATE actions 
                SET current_stack = ?
                WHERE hand_id = ? AND player_id = ? AND sequence_num = ?
            ");
			$updateStmt->execute([
				$stackForThisAction,
				$handId,
				$playerId,
				$sequenceNum
			]);
			$updatedActions += $updateStmt->rowCount();

			$currentStack = $stackForThisAction;
		}

		$response = [
			'success' => true,
			'message' => 'Стеки успешно пересчитаны',
			'updated_actions' => $updatedActions,
			'initial_stack' => $newStack,
			'final_stack' => $currentStack
		];

		$pdo->commit();

	} catch (Exception $e) {
		$pdo->rollBack();
		throw $e;
	}

} catch (Exception $e) {
	$response['message'] = $e->getMessage();
}

echo json_encode($response);
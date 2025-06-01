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
			$pdo->commit();
			echo json_encode([
				'success' => true,
				'message' => 'Действия игрока не найдены',
				'updated_actions' => 0,
				'initial_stack' => $newStack,
				'final_stack' => $newStack
			]);
			exit;
		}

		$currentStack = $newStack;
		$updatedActions = 0;
		$isAllIn = false;

		foreach ($actions as $index => $action) {
			$actionType = $action['action_type'];
			$amount = (float)$action['amount'];
			$sequenceNum = $action['sequence_num'];

			// Проверяем условие для all-in (только для первого действия в цикле - последнего по времени)
			if ($index === 0 && $actionType !== 'check' && $amount > 0 && $currentStack < $amount) {
				// Это последняя ставка и новый стек меньше её суммы
				$isAllIn = true;
				$adjustedAmount = $currentStack;
				$stackForThisAction = 0;

				// Обновляем запись в базе данных для all-in
				$updateStmt = $pdo->prepare("
                    UPDATE actions 
                    SET current_stack = ?, amount = ?, action_type = 'all-in'
                    WHERE hand_id = ? AND player_id = ? AND sequence_num = ?
                ");
				$updateStmt->execute([
					$stackForThisAction,
					$adjustedAmount,
					$handId,
					$playerId,
					$sequenceNum
				]);
				$updatedActions += $updateStmt->rowCount();

				$currentStack = $stackForThisAction;
				continue;
			}

			// Рассчитываем стек для текущего действия
			$stackForThisAction = $currentStack;

			// Для всех ставок (кроме чека) добавляем сумму ставки
			if ($actionType !== 'check' && $amount > 0) {
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
			'message' => $isAllIn ? 'Стеки пересчитаны, последнее действие изменено на all-in' : 'Стеки успешно пересчитаны',
			'updated_actions' => $updatedActions,
			'initial_stack' => $newStack,
			'final_stack' => $currentStack,
			'was_all_in' => $isAllIn
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
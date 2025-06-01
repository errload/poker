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
            SELECT sequence_num, action_type, amount, current_stack, position, street
            FROM actions 
            WHERE hand_id = ? AND player_id = ?
            ORDER BY sequence_num ASC
        ");
		$stmt->execute([$handId, $playerId]);
		$actions = $stmt->fetchAll();

		if (empty($actions)) {
			throw new Exception('Действия игрока не найдены');
		}

		$currentStack = $newStack;
		$updatedActions = 0;
		$isFirstAction = true;
		$alreadyPosted = 0; // Сколько уже поставил игрок

		foreach ($actions as $action) {
			$actionType = $action['action_type'];
			$amount = (float)$action['amount'];
			$sequenceNum = $action['sequence_num'];
			$position = $action['position'];
			$street = $action['street'];
			$newStackValue = $currentStack;
			$newAmount = $amount;

			// Для всех действий (включая первое) вычитаем сумму из стека
			switch ($actionType) {
				case 'fold':
					// Для фолда в блайндах списываем соответствующую сумму
					if ($isFirstAction) {
						if ($position == 'SB') {
							$newAmount = 0.5;
							$newStackValue = $currentStack - 0.5;
							$alreadyPosted = 0.5;
						} elseif ($position == 'BB') {
							$newAmount = 1;
							$newStackValue = $currentStack - 1;
							$alreadyPosted = 1;
						}
					}
					// Для фолда вне блайндов сумма не списывается
					$newAmount = null;
					break;

				case 'check':
					// Для чека в BB списываем блайнд
					if ($isFirstAction && $position == 'BB') {
						$newAmount = 1;
						$newStackValue = $currentStack - 1;
						$alreadyPosted = 1;
					}
					// Для других чеков сумма не списывается
					$newAmount = null;
					break;

				case 'call':
					if ($amount > 0) {
						// Для SB/BB при последующих коллах вычитаем разницу между текущей ставкой и уже поставленными деньгами
						if (in_array($position, ['SB', 'BB']) && !$isFirstAction) {
							$amountToCall = max(0, $amount - $alreadyPosted);
							$newStackValue = $currentStack - $amountToCall;
							$alreadyPosted = $amount;
						} else {
							// Для других позиций или первого действия просто вычитаем всю сумму
							$newStackValue = $currentStack - $amount;
							if ($isFirstAction) {
								if ($position == 'SB') {
									$alreadyPosted = 1; // SB колл = полный блайнд
								} elseif ($position == 'BB') {
									$alreadyPosted = $amount; // BB может коллить рейз
								}
							}
						}
					}
					break;

				case 'bet':
				case 'raise':
				case 'all-in':
					// Для всех ставок вычитаем полную сумму
					if ($amount > 0) {
						$newStackValue = $currentStack - $amount;
					}
					break;
			}

			// Проверяем, чтобы стек не ушел в минус
			if ($newStackValue < 0) {
				throw new Exception("Недостаточно средств в стеке для действия #$sequenceNum");
			}

			// Обновляем запись в базе данных
			$updateStmt = $pdo->prepare("
                UPDATE actions 
                SET current_stack = ?, amount = ?
                WHERE hand_id = ? AND player_id = ? AND sequence_num = ?
            ");
			$updateStmt->execute([
				$newStackValue,
				$actionType != 'fold' && $actionType != 'check' ? $newAmount : null,
				$handId,
				$playerId,
				$sequenceNum
			]);
			$updatedActions += $updateStmt->rowCount();

			$currentStack = $newStackValue;
			$isFirstAction = false;
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
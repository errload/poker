<?php
header('Content-Type: application/json');
require_once __DIR__.'/../db/config.php';

$response = [
	'success' => false,
	'message' => ''
];

try {
	// Подключение к БД
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

	// Получаем данные из запроса
	$input = json_decode(file_get_contents('php://input'), true);

	$handId = $input['hand_id'] ?? null;
	$playerId = $input['player_id'] ?? null;
	$newStack = $input['new_stack'] ?? null;

	// Валидация входных данных
	if (!$handId || !$playerId || $newStack === null) {
		$response['message'] = 'Missing required parameters: hand_id, player_id and new_stack are required';
		echo json_encode($response);
		exit;
	}

	$handId = (int)$handId;
	$newStack = (float)$newStack;

	if ($handId <= 0) {
		$response['message'] = 'Invalid hand ID';
		echo json_encode($response);
		exit;
	}

	if ($newStack < 0) {
		$response['message'] = 'Stack cannot be negative';
		echo json_encode($response);
		exit;
	}

	// Начинаем транзакцию
	$pdo->beginTransaction();

	try {
		// Получаем все действия игрока в раздаче в порядке их выполнения
		$stmt = $pdo->prepare("
            SELECT action_id, action_type, amount, current_stack, sequence_num 
            FROM actions 
            WHERE hand_id = :hand_id AND player_id = :player_id 
            ORDER BY sequence_num ASC
        ");
		$stmt->execute([':hand_id' => $handId, ':player_id' => $playerId]);
		$actions = $stmt->fetchAll();

		if (empty($actions)) {
			$pdo->rollBack();
			$response['message'] = 'No actions found for this player in the specified hand';
			echo json_encode($response);
			exit;
		}

		// Находим последнее действие игрока в раздаче
		$lastAction = end($actions);
		$lastStack = $lastAction['current_stack'];

		// Разница между текущим стеком в БД и новым значением
		$stackDiff = $newStack - $lastStack;

		// Обновляем стеки во всех действиях игрока
		$currentStack = $actions[0]['current_stack'] + $stackDiff;
		$updatedActions = 0;

		foreach ($actions as $action) {
			// Для первого действия просто устанавливаем новый стек
			if ($action['sequence_num'] == $actions[0]['sequence_num']) {
				$newCurrentStack = $currentStack;
			} else {
				// Для последующих действий корректируем стек на основе предыдущего значения
				$prevActionIndex = array_search($action['sequence_num'] - 1, array_column($actions, 'sequence_num'));
				$prevAction = $actions[$prevActionIndex];

				// Определяем изменение стека для текущего действия
				$stackChange = 0;
				if (in_array($action['action_type'], ['bet', 'raise', 'call', 'all-in'])) {
					$stackChange = -$action['amount'];
				}

				$newCurrentStack = $prevAction['current_stack'] + $stackChange + $stackDiff;
			}

			// Обновляем запись в базе
			$updateStmt = $pdo->prepare("
                UPDATE actions 
                SET current_stack = :current_stack 
                WHERE action_id = :action_id
            ");
			$updateStmt->execute([
				':current_stack' => $newCurrentStack,
				':action_id' => $action['action_id']
			]);

			$updatedActions += $updateStmt->rowCount();

			// Обновляем текущее значение в массиве для следующих итераций
			$action['current_stack'] = $newCurrentStack;
		}

		// Фиксируем транзакцию
		$pdo->commit();

		$response = [
			'success' => true,
			'message' => 'Player stack updated successfully',
			'hand_id' => $handId,
			'player_id' => $playerId,
			'initial_stack' => $actions[0]['current_stack'],
			'final_stack' => $newStack,
			'updated_actions' => $updatedActions
		];

	} catch (Exception $e) {
		$pdo->rollBack();
		$response['message'] = $e->getMessage();
	}

} catch (PDOException $e) {
	$response['message'] = 'Database error: ' . $e->getMessage();
} catch (Exception $e) {
	$response['message'] = $e->getMessage();
}

echo json_encode($response);
?>
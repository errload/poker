<?php
header('Content-Type: application/json');
require_once __DIR__.'/../db/config.php';

$response = [
	'success' => false,
	'action_id' => null,
	'message' => '',
	'processed_action_type' => null,
	'current_max_bet' => null
];

try {
	$input = json_decode(file_get_contents('php://input'), true);
	if (!$input) {
		throw new Exception('Неверный JSON-ввод');
	}

	// Проверяем обязательные поля
	$required = ['hand_id', 'player_id', 'street', 'action_type', 'position'];
	foreach ($required as $field) {
		if (!isset($input[$field])) {
			throw new Exception("Отсутствует обязательное поле: $field");
		}
	}

	// Проверяем допустимые значения
	$validActions = ['fold', 'check', 'call', 'bet', 'raise', 'all-in'];
	$validPositions = ['BTN', 'SB', 'BB', 'UTG', 'UTG+1', 'MP', 'HJ', 'CO'];
	$validStreets = ['preflop', 'flop', 'turn', 'river'];

	if (!in_array($input['action_type'], $validActions)) {
		throw new Exception("Недопустимый тип действия: {$input['action_type']}");
	}

	if (!in_array($input['position'], $validPositions)) {
		throw new Exception("Недопустимая позиция: {$input['position']}");
	}

	if (!in_array($input['street'], $validStreets)) {
		throw new Exception("Недопустимая улица: {$input['street']}");
	}

	// Подключаемся к базе данных
	$pdo = new PDO(
		"mysql:host=".DB_HOST.";dbname=".DB_NAME.";charset=utf8mb4",
		DB_USER,
		DB_PASS,
		[
			PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
			PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
		]
	);

	$pdo->beginTransaction();

	try {
		// Проверяем существование раздачи
		$stmt = $pdo->prepare("SELECT 1 FROM hands WHERE hand_id = ?");
		$stmt->execute([$input['hand_id']]);
		if (!$stmt->fetch()) {
			throw new Exception("Раздача не найдена");
		}

		// Проверяем/создаем игрока
		$player_id = $input['player_id'];
		$stmt = $pdo->prepare("SELECT 1 FROM players WHERE player_id = ?");
		$stmt->execute([$player_id]);
		if (!$stmt->fetch()) {
			$nickname = "Игрок_" . substr($player_id, 0, 5);
			$stmt = $pdo->prepare("INSERT INTO players (player_id, nickname) VALUES (?, ?)");
			$stmt->execute([$player_id, $nickname]);
		}

		// Получаем следующий номер последовательности
		$stmt = $pdo->prepare("SELECT COALESCE(MAX(sequence_num), 0) + 1 FROM actions WHERE hand_id = ?");
		$stmt->execute([$input['hand_id']]);
		$nextSeq = $stmt->fetchColumn();

		// Получаем текущую максимальную ставку на этой улице
		$stmt = $pdo->prepare("
            SELECT COALESCE(MAX(amount), 0) 
            FROM actions 
            WHERE hand_id = ? AND street = ?
        ");
		$stmt->execute([$input['hand_id'], $input['street']]);
		$currentBet = (float)$stmt->fetchColumn();
		$response['current_max_bet'] = $currentBet;

		// Проверяем, первое ли это действие игрока в раздаче
		$stmt = $pdo->prepare("SELECT COUNT(*) FROM actions WHERE hand_id = ? AND player_id = ?");
		$stmt->execute([$input['hand_id'], $player_id]);
		$isFirstAction = $stmt->fetchColumn() == 0;

		// Обрабатываем действие
		$amount = 0;
		$finalActionType = $input['action_type'];
		$isVoluntary = true;
		$isAggressive = false;

		switch ($input['action_type']) {
			case 'fold':
				// Фолды по блайндам не считаются добровольными
				if ($isFirstAction && in_array($input['position'], ['SB', 'BB'])) {
					$isVoluntary = false;
				}
				break;

			case 'check':
				if ($currentBet > 0) {
					throw new Exception("Нельзя чековать, когда есть ставка");
				}
				break;

			case 'call':
				if (!isset($input['amount'])) {
					throw new Exception("Не указана сумма для колла");
				}
				$amount = (float)$input['amount'];
				break;

			case 'bet':
			case 'raise':
				if (!isset($input['amount'])) {
					throw new Exception("Не указана сумма для ставки/рейза");
				}
				$amount = (float)$input['amount'];

				// Проверяем, первая ли это ставка на улице
				$stmt = $pdo->prepare("
                    SELECT 1 FROM actions 
                    WHERE hand_id = ? AND street = ? 
                    AND action_type IN ('bet', 'raise', 'all-in')
                    LIMIT 1
                ");
				$stmt->execute([$input['hand_id'], $input['street']]);
				$isFirstBetOnStreet = !$stmt->fetch();

				// Если это первый рейз на улице, меняем на бет
				if ($isFirstBetOnStreet && $finalActionType == 'raise') {
					$finalActionType = 'bet';
				}

				$isAggressive = true;
				break;

			case 'all-in':
				if (!isset($input['amount'])) {
					throw new Exception("Не указана сумма для олл-ина");
				}
				$amount = (float)$input['amount'];
				$isAggressive = true;
				break;
		}

		// Вставляем действие
		$stmt = $pdo->prepare("
            INSERT INTO actions (
                hand_id, player_id, position, street, 
                action_type, amount, sequence_num,
                is_voluntary, is_aggressive, is_first_action
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
		$stmt->execute([
			$input['hand_id'],
			$player_id,
			$input['position'],
			$input['street'],
			$finalActionType,
			$amount > 0 ? $amount : null,
			$nextSeq,
			$isVoluntary ? 1 : 0,
			$isAggressive ? 1 : 0,
			$isFirstAction ? 1 : 0
		]);

		$action_id = $pdo->lastInsertId();

		// Обновляем время изменения раздачи
		$stmt = $pdo->prepare("UPDATE hands SET updated_at = NOW() WHERE hand_id = ?");
		$stmt->execute([$input['hand_id']]);

		$pdo->commit();

		$response = [
			'success' => true,
			'action_id' => $action_id,
			'message' => 'Действие успешно обработано',
			'processed_action_type' => $finalActionType,
			'current_max_bet' => max($currentBet, $amount)
		];

	} catch (Exception $e) {
		$pdo->rollBack();
		throw $e;
	}

} catch (Exception $e) {
	$response['message'] = $e->getMessage();
}

echo json_encode($response);
?>
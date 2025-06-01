<?php
header('Content-Type: application/json');
require_once __DIR__.'/../db/config.php';

$response = [
	'success' => false,
	'action_id' => null,
	'message' => '',
	'processed_action_type' => null,
	'new_stack' => null,
	'warnings' => []
];

try {
	// Получаем входные данные
	$input = json_decode(file_get_contents('php://input'), true);
	if (!$input) {
		$response['message'] = 'Неверный JSON-ввод';
		echo json_encode($response);
		exit;
	}

	// Проверяем обязательные поля
	$required = ['hand_id', 'player_id', 'street', 'action_type', 'position'];
	$missingFields = [];
	foreach ($required as $field) {
		if (!isset($input[$field])) {
			$missingFields[] = $field;
		}
	}
	if (!empty($missingFields)) {
		$response['message'] = "Отсутствуют обязательные поля: " . implode(', ', $missingFields);
		echo json_encode($response);
		exit;
	}

	// Валидация данных
	$validActions = ['fold', 'check', 'call', 'bet', 'raise', 'all-in'];
	$validPositions = ['BTN', 'SB', 'BB', 'UTG', 'UTG+1', 'MP', 'HJ', 'CO'];

	if (!in_array($input['action_type'], $validActions)) {
		$response['message'] = "Недопустимый тип действия: {$input['action_type']}";
		echo json_encode($response);
		exit;
	}

	if (!in_array($input['position'], $validPositions)) {
		$response['warnings'][] = "Недопустимая позиция: {$input['position']}";
	}

	// Подключение к базе данных
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
			throw new Exception("Раздача с ID {$input['hand_id']} не найдена");
		}

		// Проверяем/создаем игрока
		$player_id = $input['player_id'];
		$stmt = $pdo->prepare("SELECT * FROM players WHERE player_id = ? FOR UPDATE");
		$stmt->execute([$player_id]);
		$player = $stmt->fetch();

		if (!$player) {
			$nickname = "Player" . substr($player_id, 0, 5);
			$stmt = $pdo->prepare("
                INSERT INTO players 
                (player_id, nickname, last_seen, created_at) 
                VALUES (?, ?, NOW(), NOW())
            ");
			$stmt->execute([$player_id, $nickname]);
			$player = ['hands_played' => 0, 'vpip' => 0, 'pfr' => 0];
		}

		// Получаем следующий номер последовательности
		$stmt = $pdo->prepare("SELECT COALESCE(MAX(sequence_num), 0) + 1 FROM actions WHERE hand_id = ?");
		$stmt->execute([$input['hand_id']]);
		$nextSeq = $stmt->fetchColumn();

		// Получаем последнее действие игрока в этой раздаче
		$stmt = $pdo->prepare("
            SELECT current_stack, amount, action_type 
            FROM actions 
            WHERE hand_id = ? AND player_id = ? 
            ORDER BY sequence_num DESC 
            LIMIT 1
        ");
		$stmt->execute([$input['hand_id'], $player_id]);
		$lastPlayerAction = $stmt->fetch();

		// Получаем максимальную ставку в текущей улице
		$stmt = $pdo->prepare("
            SELECT COALESCE(MAX(amount), 0) as current_bet 
            FROM actions 
            WHERE hand_id = ? AND street = ?
        ");
		$stmt->execute([$input['hand_id'], $input['street']]);
		$currentBet = $stmt->fetchColumn();

		// Рассчитываем текущий стек
		$current_stack = isset($input['current_stack']) ? (float)$input['current_stack'] :
			($lastPlayerAction ? (float)$lastPlayerAction['current_stack'] : 100); // Дефолтный стек 100, если не указан

		// Проверяем, является ли это первым действием игрока в раздаче
		$stmt = $pdo->prepare("SELECT COUNT(*) FROM actions WHERE hand_id = ? AND player_id = ?");
		$stmt->execute([$input['hand_id'], $player_id]);
		$isFirstAction = $stmt->fetchColumn() == 0;

		// Обработка блайндов и ставок
		$amount = 0;
		$isBlindAction = false;
		$alreadyPosted = 0; // Сколько уже поставил игрок в этой раздаче

		if ($isFirstAction) {
			// Первое действие для SB
			if ($input['position'] == 'SB') {
				if ($input['action_type'] == 'fold') {
					$amount = 0.5;
					$current_stack -= 0.5;
					$isBlindAction = true;
					$alreadyPosted = 0.5;
				} elseif ($input['action_type'] == 'call') {
					$amount = 1;
					$current_stack -= 1;
					$isBlindAction = true;
					$alreadyPosted = 1;
				}
			}
			// Первое действие для BB
			elseif ($input['position'] == 'BB') {
				if ($input['action_type'] == 'fold') {
					$amount = 1;
					$current_stack -= 1;
					$isBlindAction = true;
					$alreadyPosted = 1;
				} elseif ($input['action_type'] == 'check') {
					$amount = 1;
					$current_stack -= 1;
					$isBlindAction = true;
					$alreadyPosted = 1;
				}
			}
		} else {
			// Для последующих действий получаем сколько уже поставил игрок
			$stmt = $pdo->prepare("
                SELECT COALESCE(SUM(amount), 0) as total_posted 
                FROM actions 
                WHERE hand_id = ? AND player_id = ?
            ");
			$stmt->execute([$input['hand_id'], $player_id]);
			$alreadyPosted = (float)$stmt->fetchColumn();
		}

		// Обработка не-блайнд действий
		if (!$isBlindAction) {
			switch ($input['action_type']) {
				case 'fold':
					// Для фолда ничего не меняем
					break;

				case 'check':
					// Для чека ничего не меняем
					break;

				case 'call':
					if (!isset($input['amount'])) {
						throw new Exception("Для call обязателен параметр amount");
					}
					$callAmount = (float)$input['amount'];

					// Для SB/BB при последующих коллах вычитаем разницу между текущей ставкой и уже поставленными деньгами
					if (in_array($input['position'], ['SB', 'BB'])) {
						$amountToCall = max(0, $callAmount - $alreadyPosted);
						$amount = $callAmount;
						$current_stack -= $amountToCall;
					} else {
						// Для других позиций просто вычитаем всю сумму
						$amount = $callAmount;
						$current_stack -= $callAmount;
					}
					break;

				case 'bet':
				case 'raise':
				case 'all-in':
					if (!isset($input['amount'])) {
						throw new Exception("Для {$input['action_type']} обязателен параметр amount");
					}
					$betAmount = (float)$input['amount'];

					// Для всех позиций вычитаем полную сумму ставки
					$amount = $betAmount;
					$current_stack -= $betAmount;
					break;
			}
		}

		// Проверяем, есть ли у игрока предыдущие ставки в этой раздаче
		$stmt = $pdo->prepare("
            SELECT 1 FROM actions 
            WHERE hand_id = ? AND player_id = ? 
            AND action_type IN ('bet', 'raise', 'all-in')
            LIMIT 1
        ");
		$stmt->execute([$input['hand_id'], $player_id]);
		$hasPreviousBets = $stmt->fetch();

		$finalActionType = $input['action_type'];
		if (!$hasPreviousBets && in_array($finalActionType, ['raise', 'all-in'])) {
			$finalActionType = 'bet';
		}

		// Проверка стека
		if ($current_stack < 0) {
			throw new Exception("Недостаточно средств в стеке");
		}

		// Вставляем действие
		$stmt = $pdo->prepare("
            INSERT INTO actions (
                hand_id, player_id, position, street, 
                action_type, amount, current_stack, sequence_num
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ");
		$stmt->execute([
			$input['hand_id'],
			$player_id,
			$input['position'],
			$input['street'],
			$finalActionType,
			$amount > 0 ? round($amount, 2) : null,
			round($current_stack, 2),
			$nextSeq
		]);

		$action_id = $pdo->lastInsertId();

		// Обновляем статистику игрока
		updatePlayerStats($pdo, $player_id, $input['action_type'], $input['street']);

		$pdo->commit();

		// Формируем успешный ответ
		$response = [
			'success' => true,
			'action_id' => $action_id,
			'message' => 'Действие успешно записано',
			'processed_action_type' => $finalActionType,
			'new_stack' => round($current_stack, 2),
			'warnings' => $response['warnings']
		];

	} catch (Exception $e) {
		$pdo->rollBack();
		$response['message'] = $e->getMessage();
	}

} catch (Exception $e) {
	$response['message'] = $e->getMessage();
}

echo json_encode($response);

function updatePlayerStats($pdo, $player_id, $action_type, $street) {
	$stmt = $pdo->prepare("SELECT * FROM players WHERE player_id = ? FOR UPDATE");
	$stmt->execute([$player_id]);
	$player = $stmt->fetch();

	if (!$player) return;

	$updateFields = ['last_seen' => date('Y-m-d H:i:s')];
	$hands_played = $player['hands_played'] ?? 0;

	if ($street == 'preflop') {
		if ($action_type != 'fold') {
			$new_hands_played = $hands_played + 1;
			$updateFields['hands_played'] = $new_hands_played;
			$updateFields['vpip'] = (($player['vpip'] * $hands_played) + 1) / $new_hands_played;
		}

		if (in_array($action_type, ['raise', 'all-in'])) {
			$new_preflop_raises = ($player['preflop_raises'] ?? 0) + 1;
			$updateFields['preflop_raises'] = $new_preflop_raises;
			$updateFields['pfr'] = $hands_played > 0
				? (($player['pfr'] * $hands_played) + 1) / $hands_played
				: 1;
		}
	}

	if (!empty($updateFields)) {
		$setParts = array_map(fn($f) => "$f = ?", array_keys($updateFields));
		$sql = "UPDATE players SET " . implode(', ', $setParts) . " WHERE player_id = ?";
		$stmt = $pdo->prepare($sql);
		$stmt->execute([...array_values($updateFields), $player_id]);
	}
}
<?php
header('Content-Type: application/json');
require_once __DIR__.'/../db/config.php';

$response = [
	'success' => false,
	'action_id' => null,
	'message' => '',
	'processed_action_type' => null,
	'new_stack' => null,
	'used_previous_stack' => null,
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
	$required = ['hand_id', 'player_id', 'street', 'action_type'];
	$missingFields = [];
	foreach ($required as $field) {
		if (!isset($input[$field])) {
			$missingFields[] = $field;
		}
	}
	if (!empty($missingFields)) {
		$response['message'] = "Обязательные поля отсутствуют: " . implode(', ', $missingFields);
		echo json_encode($response);
		exit;
	}

	// Валидация данных (только добавление предупреждений)
	$validActions = ['fold', 'check', 'call', 'bet', 'raise', 'all-in'];
	if (!in_array($input['action_type'], $validActions)) {
		$response['warnings'][] = "Недопустимый тип действия: {$input['action_type']}";
	}

	if (in_array($input['action_type'], ['bet', 'raise', 'all-in', 'call']) && !isset($input['amount'])) {
		$response['warnings'][] = "amount обязателен для действий типа {$input['action_type']}";
	}

	if ($input['action_type'] != 'fold' && !isset($input['current_stack'])) {
		$response['warnings'][] = "current_stack обязателен для действий, кроме fold";
	}

	if (isset($input['amount']) && (!is_numeric($input['amount']) || $input['amount'] <= 0)) {
		$response['warnings'][] = "Некорректная сумма ставки";
	}

	if (isset($input['current_stack']) && (!is_numeric($input['current_stack']) || $input['current_stack'] < 0)) {
		$response['warnings'][] = "Некорректный размер стека";
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
			$pdo->commit();
			$response['message'] = "Раздача с ID {$input['hand_id']} не найдена";
			$response['hand_id'] = $input['hand_id'];
			echo json_encode($response);
			exit;
		}

		// Проверяем/создаем игрока
		$player_id = $input['player_id'];
		$stmt = $pdo->prepare("SELECT * FROM players WHERE player_id = ? FOR UPDATE");
		$stmt->execute([$player_id]);
		$player = $stmt->fetch();

		if (!$player) {
			preg_match('/\d/', $player_id, $matches);
			$firstDigit = $matches[0] ?? '0';
			$nickname = "Player{$firstDigit}";

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

		// Определяем окончательный тип действия
		$finalActionType = $input['action_type'];

		// Проверяем, есть ли у игрока предыдущие ставки в этой раздаче
		$stmt = $pdo->prepare("
            SELECT 1 FROM actions 
            WHERE hand_id = ? AND player_id = ? 
            AND action_type IN ('bet', 'raise', 'all-in')
            LIMIT 1
        ");
		$stmt->execute([$input['hand_id'], $player_id]);
		$hasPreviousBets = $stmt->fetch();

		if (!$hasPreviousBets && in_array($finalActionType, ['raise', 'all-in'])) {
			$finalActionType = 'bet';
		}

		// Получаем последнее действие игрока в этой раздаче
		$stmt = $pdo->prepare("
            SELECT current_stack 
            FROM actions 
            WHERE hand_id = ? AND player_id = ? 
            ORDER BY sequence_num DESC 
            LIMIT 1
        ");
		$stmt->execute([$input['hand_id'], $player_id]);
		$lastAction = $stmt->fetch();

		// Рассчитываем новый стек
		$amount = isset($input['amount']) ? (float)$input['amount'] : 0;
		$current_stack = $lastAction ? (float)$lastAction['current_stack'] :
			(isset($input['current_stack']) ? (float)$input['current_stack'] : 0);

		if (in_array($finalActionType, ['bet', 'raise', 'all-in', 'call'])) {
			$current_stack -= $amount;
			$current_stack = max(0, $current_stack);
		}

		// Вставляем действие
		$stmt = $pdo->prepare("
            INSERT INTO actions (
                hand_id, player_id, street, action_type, amount, current_stack, sequence_num
            ) VALUES (?, ?, ?, ?, ?, ?, ?)
        ");
		$stmt->execute([
			$input['hand_id'],
			$player_id,
			$input['street'],
			$finalActionType,
			isset($input['amount']) ? round($amount, 2) : null,
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
			'new_stack' => $current_stack,
			'used_previous_stack' => $lastAction !== false,
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

	if ($street == 'preflop' && $action_type != 'fold') {
		$new_hands_played = $hands_played + 1;
		$updateFields['hands_played'] = $new_hands_played;
		$updateFields['vpip'] = (($player['vpip'] * $hands_played) + 1) / $new_hands_played;
	}

	if ($street == 'preflop' && in_array($action_type, ['raise', 'all-in'])) {
		$new_preflop_raises = ($player['preflop_raises'] ?? 0) + 1;
		$updateFields['preflop_raises'] = $new_preflop_raises;
		$updateFields['pfr'] = $hands_played > 0
			? (($player['pfr'] * $hands_played) + 1) / $hands_played
			: 1;
	}

	if (!empty($updateFields)) {
		$setParts = array_map(fn($f) => "$f = ?", array_keys($updateFields));
		$sql = "UPDATE players SET " . implode(', ', $setParts) . " WHERE player_id = ?";
		$stmt = $pdo->prepare($sql);
		$stmt->execute([...array_values($updateFields), $player_id]);
	}
}
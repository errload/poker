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

	// Получаем и проверяем входные данные
	$input = json_decode(file_get_contents('php://input'), true);
	if (!$input) {
		throw new Exception('Invalid JSON input');
	}

	// Проверяем обязательные поля
	$required = ['hand_id', 'player_id', 'street', 'action_type'];
	foreach ($required as $field) {
		if (!isset($input[$field])) {
			throw new Exception("Missing required field: $field");
		}
	}

	// Дополнительная проверка для не-fold действий
	if ($input['action_type'] != 'fold' && !isset($input['current_stack'])) {
		throw new Exception("current_stack is required for non-fold actions");
	}

	// Начинаем транзакцию
	$pdo->beginTransaction();

	try {
		// Проверяем существование раздачи
		$stmt = $pdo->prepare("SELECT 1 FROM hands WHERE hand_id = ?");
		$stmt->execute([$input['hand_id']]);
		if (!$stmt->fetch()) {
			$pdo->commit();
			echo json_encode([
				'success' => true,
				'message' => "Hand with ID {$input['hand_id']} not found, action not recorded",
				'hand_id' => $input['hand_id']
			]);
			exit;
		}

		// Проверяем существование игрока
		$player_id = $input['player_id'];
		$stmt = $pdo->prepare("SELECT 1 FROM players WHERE player_id = ?");
		$stmt->execute([$player_id]);

		if (!$stmt->fetch()) {
			$pdo->commit();
			echo json_encode([
				'success' => true,
				'message' => "Player with ID {$player_id} not found, action not recorded",
				'player_id' => $player_id
			]);
			exit;
		}

		// Получаем следующий sequence_num
		$stmt = $pdo->prepare("SELECT COALESCE(MAX(sequence_num), 0) + 1 FROM actions WHERE hand_id = ?");
		$stmt->execute([$input['hand_id']]);
		$nextSeq = $stmt->fetchColumn();

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
			$input['action_type'],
			$input['amount'] ?? null,
			$input['current_stack'] ?? null,
			$nextSeq
		]);

		// Получаем ID вставленной записи
		$action_id = $pdo->lastInsertId();
		if ($action_id == 0) {
			$stmt = $pdo->prepare("SELECT action_id FROM actions WHERE hand_id = ? AND sequence_num = ?");
			$stmt->execute([$input['hand_id'], $nextSeq]);
			$action = $stmt->fetch();
			$action_id = $action['action_id'] ?? 0;

			if ($action_id == 0) {
				throw new Exception("Failed to retrieve action ID");
			}
		}

		// Получаем все действия для этой раздачи
		$handActions = getHandActions($pdo, $input['hand_id']);

		// Обновляем статистику игрока
		updatePlayerStats($pdo, $player_id, $input['action_type'], $input['street'], $handActions);

		// Фиксируем транзакцию
		$pdo->commit();

		echo json_encode([
			'success' => true,
			'action_id' => $action_id,
			'message' => 'Action recorded successfully'
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

/**
 * Получает все действия для указанной раздачи
 */
function getHandActions($pdo, $hand_id) {
	$stmt = $pdo->prepare("
        SELECT street, action_type, player_id 
        FROM actions 
        WHERE hand_id = ?
        ORDER BY sequence_num
    ");
	$stmt->execute([$hand_id]);
	return $stmt->fetchAll();
}

/**
 * Обновляет статистику игрока
 */
function updatePlayerStats($pdo, $player_id, $current_action, $current_street, $handActions) {
	// Получаем текущие данные игрока с блокировкой строки
	$stmt = $pdo->prepare("
        SELECT * FROM players 
        WHERE player_id = ?
        FOR UPDATE
    ");
	$stmt->execute([$player_id]);
	$player = $stmt->fetch();

	// Если игрок не существует, просто выходим
	if (!$player) {
		return;
	}

	// Подготовка полей для обновления
	$updateFields = ['last_seen' => date('Y-m-d H:i:s')];

	// 1. Обновление VPIP (Voluntarily Put $ In Pot)
	if ($current_street == 'preflop' && $current_action != 'fold') {
		$new_hands_played = $player['hands_played'] + 1;
		$updateFields['hands_played'] = $new_hands_played;
		$updateFields['vpip'] = (($player['vpip'] * $player['hands_played']) + 1) / $new_hands_played;
	}

	// 2. Обновление PFR (Pre-Flop Raise)
	if ($current_street == 'preflop' && in_array($current_action, ['raise', 'all-in'])) {
		$new_preflop_raises = $player['preflop_raises'] + 1;
		$updateFields['preflop_raises'] = $new_preflop_raises;
		$updateFields['pfr'] = (($player['pfr'] * $player['hands_played']) + 1) / $player['hands_played'];
	}

	// 3. Обновление AF (Aggression Factor) и AFQ (Aggression Frequency)
	$aggressiveActions = ['bet', 'raise', 'all-in'];
	$passiveActions = ['call', 'check'];

	$aggressive = 0;
	$passive = 0;
	foreach ($handActions as $action) {
		if ($action['player_id'] == $player_id) {
			if (in_array($action['action_type'], $aggressiveActions)) $aggressive++;
			if (in_array($action['action_type'], $passiveActions)) $passive++;
		}
	}

	if ($passive > 0) {
		$updateFields['af'] = $aggressive / $passive;
	}

	$totalActions = $aggressive + $passive;
	if ($totalActions > 0) {
		$updateFields['afq'] = ($aggressive / $totalActions) * 100;
	}

	// 4. Обновление 3-bet статистики
	if ($current_street == 'preflop') {
		$raisesBefore = 0;
		foreach ($handActions as $action) {
			if ($action['street'] == 'preflop' && in_array($action['action_type'], ['raise', 'all-in'])) {
				$raisesBefore++;
			}
		}

		if ($raisesBefore >= 2) {
			$new_opportunities = $player['three_bet_opportunities'] + 1;
			$updateFields['three_bet_opportunities'] = $new_opportunities;

			if (in_array($current_action, ['raise', 'all-in'])) {
				$new_made = $player['three_bet_made'] + 1;
				$updateFields['three_bet_made'] = $new_made;
			}

			$updateFields['three_bet'] = ($updateFields['three_bet_made'] ?? $player['three_bet_made']) / $new_opportunities * 100;
		}
	}

	// Обновляем запись игрока, если есть что обновлять
	if (!empty($updateFields)) {
		$setParts = [];
		$params = [];
		foreach ($updateFields as $field => $value) {
			$setParts[] = "`$field` = ?";
			$params[] = $value;
		}
		$params[] = $player_id;

		$sql = "UPDATE `players` SET " . implode(', ', $setParts) . " WHERE `player_id` = ?";
		$stmt = $pdo->prepare($sql);
		$stmt->execute($params);
	}
}
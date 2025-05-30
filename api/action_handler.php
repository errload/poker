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

	$input = json_decode(file_get_contents('php://input'), true);
	if (!$input) throw new Exception('Invalid JSON input');

	// Проверяем обязательные поля
	$required = ['hand_id', 'player_id', 'street', 'action_type'];
	foreach ($required as $field) {
		if (!isset($input[$field])) {
			throw new Exception("Missing required field: $field");
		}
	}

	if ($input['action_type'] != 'fold' && !isset($input['current_stack'])) {
		throw new Exception("current_stack is required for non-fold actions");
	}

	// Проверяем существование раздачи
	$stmt = $pdo->prepare("SELECT 1 FROM hands WHERE hand_id = ?");
	$stmt->execute([$input['hand_id']]);
	if (!$stmt->fetch()) {
		throw new Exception("Hand with ID {$input['hand_id']} not found");
	}

	// Проверка/добавление игрока
	$stmt = $pdo->prepare("SELECT 1 FROM players WHERE player_id = ?");
	$stmt->execute([$input['player_id']]);
	if (!$stmt->fetch()) {
		$stmt = $pdo->prepare("INSERT INTO players (player_id, nickname) VALUES (?, ?)");
		$stmt->execute([$input['player_id'], $input['player_id']]);
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
		$input['player_id'],
		$input['street'],
		$input['action_type'],
		$input['amount'] ?? null,
		$input['current_stack'] ?? null,
		$nextSeq
	]);

	// Обновляем статистику игрока
	updatePlayerStats($pdo, $input['player_id'], $input['action_type'], $input['street'],
		getHandActions($pdo, $input['hand_id']));

	echo json_encode([
		'success' => true,
		'action_id' => $pdo->lastInsertId()
	]);

} catch (Exception $e) {
	http_response_code(400);
	echo json_encode([
		'success' => false,
		'error' => $e->getMessage()
	]);
}

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

// Улучшенная функция обновления статистики
function updatePlayerStats($pdo, $player_id, $current_action, $current_street, $handActions) {
	// Получаем текущие данные игрока
	$stmt = $pdo->prepare("
        SELECT * FROM `players` 
        WHERE `player_id` = ?
        FOR UPDATE
    ");
	$stmt->execute([$player_id]);
	$player = $stmt->fetch();

	if (!$player) {
		$pdo->prepare("INSERT INTO `players` (`player_id`, `nickname`) VALUES (?, ?)")
			->execute([$player_id, $player_id]);
		$player = [
			'vpip' => 0,
			'pfr' => 0,
			'af' => 0,
			'afq' => 0,
			'three_bet' => 0,
			'wtsd' => 0,
			'wsd' => 0,
			'hands_played' => 0,
			'hands_won' => 0,
			'showdowns' => 0,
			'showdowns_won' => 0,
			'preflop_raises' => 0,
			'preflop_opportunities' => 0,
			'three_bet_opportunities' => 0,
			'three_bet_made' => 0
		];
	}

	$updateFields = [
		'last_seen' => date('Y-m-d H:i:s')
	];

	// 1. Обновление VPIP (Voluntarily Put $ In Pot)
	if ($current_street == 'preflop' && $current_action != 'fold') {
		$player['hands_played']++;
		$updateFields['hands_played'] = $player['hands_played'];
		$updateFields['vpip'] = (($player['vpip'] * ($player['hands_played'] - 1)) + 1) / $player['hands_played'];
	}

	// 2. Обновление PFR (Pre-Flop Raise)
	if ($current_street == 'preflop' && in_array($current_action, ['raise', 'all-in'])) {
		$player['preflop_raises']++;
		$updateFields['preflop_raises'] = $player['preflop_raises'];
		$updateFields['pfr'] = (($player['pfr'] * ($player['hands_played'] - 1)) + 1) / $player['hands_played'];
	}

	// 3. Обновление AF (Aggression Factor) и AFQ (Aggression Frequency)
	$aggressiveActions = ['bet', 'raise', 'all-in'];
	$passiveActions = ['call', 'check'];

	// Подсчет агрессивных и пассивных действий за всю руку
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
		// Проверяем, является ли это 3-bet ситуацией
		$raisesBefore = 0;
		foreach ($handActions as $action) {
			if ($action['street'] == 'preflop' && in_array($action['action_type'], ['raise', 'all-in'])) {
				$raisesBefore++;
			}
		}

		if ($raisesBefore >= 2) { // Уже было как минимум 2 рейза (первый и второй)
			$player['three_bet_opportunities']++;
			if (in_array($current_action, ['raise', 'all-in'])) {
				$player['three_bet_made']++;
			}
			$updateFields['three_bet'] = ($player['three_bet_made'] / $player['three_bet_opportunities']) * 100;
			$updateFields['three_bet_opportunities'] = $player['three_bet_opportunities'];
			$updateFields['three_bet_made'] = $player['three_bet_made'];
		}
	}

	// Обновляем запись игрока
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
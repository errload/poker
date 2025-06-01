<?php
header('Content-Type: application/json');
require_once __DIR__.'/../db/config.php';

$response = [
	'success' => false,
	'action_id' => null,
	'message' => '',
	'processed_action_type' => null,
	'new_stack' => null,
	'current_max_bet' => null
];

try {
	$input = json_decode(file_get_contents('php://input'), true);
	if (!$input) {
		throw new Exception('Invalid JSON input');
	}

	// Validate required fields
	$required = ['hand_id', 'player_id', 'street', 'action_type', 'position'];
	foreach ($required as $field) {
		if (!isset($input[$field])) {
			throw new Exception("Missing required field: $field");
		}
	}

	// Validate action types and positions
	$validActions = ['fold', 'check', 'call', 'bet', 'raise', 'all-in'];
	$validPositions = ['BTN', 'SB', 'BB', 'UTG', 'UTG+1', 'MP', 'HJ', 'CO'];
	$validStreets = ['preflop', 'flop', 'turn', 'river'];

	if (!in_array($input['action_type'], $validActions)) {
		throw new Exception("Invalid action type: {$input['action_type']}");
	}

	if (!in_array($input['position'], $validPositions)) {
		throw new Exception("Invalid position: {$input['position']}");
	}

	if (!in_array($input['street'], $validStreets)) {
		throw new Exception("Invalid street: {$input['street']}");
	}

	// Connect to database
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
		// Check if hand exists
		$stmt = $pdo->prepare("SELECT 1 FROM hands WHERE hand_id = ?");
		$stmt->execute([$input['hand_id']]);
		if (!$stmt->fetch()) {
			throw new Exception("Hand not found");
		}

		// Check/create player
		$player_id = $input['player_id'];
		$stmt = $pdo->prepare("SELECT 1 FROM players WHERE player_id = ?");
		$stmt->execute([$player_id]);
		if (!$stmt->fetch()) {
			$nickname = "Player_" . substr($player_id, 0, 5);
			$stmt = $pdo->prepare("INSERT INTO players (player_id, nickname) VALUES (?, ?)");
			$stmt->execute([$player_id, $nickname]);
		}

		// Get next sequence number
		$stmt = $pdo->prepare("SELECT COALESCE(MAX(sequence_num), 0) + 1 FROM actions WHERE hand_id = ?");
		$stmt->execute([$input['hand_id']]);
		$nextSeq = $stmt->fetchColumn();

		// Get last player action in this hand
		$stmt = $pdo->prepare("
            SELECT current_stack, amount 
            FROM actions 
            WHERE hand_id = ? AND player_id = ? 
            ORDER BY sequence_num DESC 
            LIMIT 1
        ");
		$stmt->execute([$input['hand_id'], $player_id]);
		$lastAction = $stmt->fetch();
		$current_stack = $lastAction ? (float)$lastAction['current_stack'] : (float)$input['current_stack'];

		// Get current max bet on this street
		$stmt = $pdo->prepare("
            SELECT COALESCE(MAX(amount), 0) 
            FROM actions 
            WHERE hand_id = ? AND street = ?
        ");
		$stmt->execute([$input['hand_id'], $input['street']]);
		$currentBet = (float)$stmt->fetchColumn();
		$response['current_max_bet'] = $currentBet;

		// Check if this is player's first action in hand
		$stmt = $pdo->prepare("SELECT COUNT(*) FROM actions WHERE hand_id = ? AND player_id = ?");
		$stmt->execute([$input['hand_id'], $player_id]);
		$isFirstAction = $stmt->fetchColumn() == 0;

		// Process action
		$amount = 0;
		$finalActionType = $input['action_type'];
		$isVoluntary = true;
		$isAggressive = false;

		switch ($input['action_type']) {
			case 'fold':
				// Forced folds (blinds) are not voluntary
				if ($isFirstAction && in_array($input['position'], ['SB', 'BB'])) {
					$isVoluntary = false;
					$amount = $input['position'] == 'SB' ? 0.5 : 1;
				}
				break;

			case 'check':
				if ($currentBet > 0) {
					throw new Exception("Cannot check when there is a bet");
				}
				break;

			case 'call':
				if (!isset($input['amount'])) {
					throw new Exception("Call amount not specified");
				}
				$callAmount = (float)$input['amount'];
				$alreadyPosted = $lastAction ? (float)$lastAction['amount'] : 0;
				$amountToCall = max(0, $callAmount - $alreadyPosted);

				if ($current_stack <= $amountToCall) {
					$amount = $current_stack;
					$current_stack = 0;
					$finalActionType = 'all-in';
				} else {
					$amount = $amountToCall;
					$current_stack -= $amountToCall;
				}
				break;

			case 'bet':
			case 'raise':
				if (!isset($input['amount'])) {
					throw new Exception("Bet/raise amount not specified");
				}
				$betAmount = (float)$input['amount'];

				// Check if this is first bet on street
				$stmt = $pdo->prepare("
                    SELECT 1 FROM actions 
                    WHERE hand_id = ? AND street = ? 
                    AND action_type IN ('bet', 'raise', 'all-in')
                    LIMIT 1
                ");
				$stmt->execute([$input['hand_id'], $input['street']]);
				$isFirstBetOnStreet = !$stmt->fetch();

				// Convert raise to bet if first aggressive action on street
				if ($isFirstBetOnStreet && $finalActionType == 'raise') {
					$finalActionType = 'bet';
				}

				$isAggressive = true;

				if ($current_stack <= $betAmount) {
					$amount = $current_stack;
					$current_stack = 0;
					$finalActionType = 'all-in';
				} else {
					$amount = $betAmount;
					$current_stack -= $betAmount;
				}
				break;

			case 'all-in':
				if (!isset($input['amount'])) {
					throw new Exception("All-in amount not specified");
				}
				$amount = (float)$input['amount'];
				$current_stack = 0;
				$isAggressive = true;
				break;
		}

		// Insert action
		$stmt = $pdo->prepare("
            INSERT INTO actions (
                hand_id, player_id, position, street, 
                action_type, amount, current_stack, sequence_num,
                is_voluntary, is_aggressive, is_first_action
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
		$stmt->execute([
			$input['hand_id'],
			$player_id,
			$input['position'],
			$input['street'],
			$finalActionType,
			$amount > 0 ? $amount : null,
			$current_stack,
			$nextSeq,
			$isVoluntary ? 1 : 0,
			$isAggressive ? 1 : 0,
			$isFirstAction ? 1 : 0
		]);

		$action_id = $pdo->lastInsertId();

		// Update hand timestamp
		$stmt = $pdo->prepare("UPDATE hands SET updated_at = NOW() WHERE hand_id = ?");
		$stmt->execute([$input['hand_id']]);

		$pdo->commit();

		$response = [
			'success' => true,
			'action_id' => $action_id,
			'message' => 'Action processed successfully',
			'processed_action_type' => $finalActionType,
			'new_stack' => $current_stack,
			'current_max_bet' => $currentBet
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
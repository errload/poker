<?php
header('Content-Type: application/json');
require_once __DIR__.'/../db/config.php';

$response = ['success' => false, 'error' => null];

try {
	$input = json_decode(file_get_contents('php://input'), true);
	if (!$input) {
		throw new Exception('Invalid JSON input');
	}

	$required = ['hand_id', 'current_street', 'hero_position'];
	foreach ($required as $field) {
		if (!isset($input[$field])) {
			throw new Exception("Missing required field: $field");
		}
	}

	$validStreets = ['preflop', 'flop', 'turn', 'river'];
	if (!in_array($input['current_street'], $validStreets)) {
		throw new Exception("Invalid street: " . $input['current_street']);
	}

	$pdo = new PDO(
		"mysql:host=".DB_HOST.";dbname=".DB_NAME.";charset=utf8mb4",
		DB_USER,
		DB_PASS,
		[
			PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
			PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
		]
	);

	// 1. Get hand info
	$handStmt = $pdo->prepare("
        SELECT hero_position, hero_stack, hero_cards, board, is_completed 
        FROM hands 
        WHERE hand_id = ?
    ");
	$handStmt->execute([$input['hand_id']]);
	$handData = $handStmt->fetch();
	if (!$handData) throw new Exception("Hand not found");

	// 2. Get initial stacks and positions
	$stacksStmt = $pdo->prepare("
        SELECT 
            a1.player_id,
            CASE 
                WHEN a1.action_type IN ('check', 'fold') THEN a1.current_stack
                ELSE a1.current_stack + COALESCE(a1.amount, 0)
            END as initial_stack,
            a1.position
        FROM actions a1
        JOIN (
            SELECT player_id, MIN(sequence_num) as min_seq
            FROM actions
            WHERE hand_id = ?
            GROUP BY player_id
        ) a2 ON a1.player_id = a2.player_id AND a1.sequence_num = a2.min_seq
        WHERE a1.hand_id = ?
    ");
	$stacksStmt->execute([$input['hand_id'], $input['hand_id']]);
	$initialData = $stacksStmt->fetchAll();
	$initialStacks = array_column($initialData, 'initial_stack', 'player_id');
	$positions = array_column($initialData, 'position', 'player_id');

	// 3. Get active players with enhanced stats
	$playersStmt = $pdo->prepare("
        SELECT 
            p.player_id, p.nickname, 
            ROUND(p.vpip,1) as vpip, ROUND(p.pfr,1) as pfr,
            ROUND(p.af,1) as af, ROUND(p.afq,1) as afq,
            ROUND(p.three_bet,1) as three_bet,
            ROUND(p.wtsd,1) as wtsd, ROUND(p.wsd,1) as wsd,
            a.current_stack, a.action_type as last_action, a.position
        FROM players p
        JOIN actions a ON a.hand_id = ? AND a.player_id = p.player_id
        WHERE a.sequence_num = (
            SELECT MAX(sequence_num) 
            FROM actions 
            WHERE hand_id = ? AND player_id = p.player_id AND action_type != 'fold'
        )
        ORDER BY a.sequence_num
    ");
	$playersStmt->execute([$input['hand_id'], $input['hand_id']]);
	$players = $playersStmt->fetchAll();

	// 4. Get action history
	$actionsStmt = $pdo->prepare("
        SELECT 
            a.street, 
            SUBSTRING(a.action_type, 1, 1) as act,
            a.amount,
            a.player_id,
            p.nickname,
            a.sequence_num,
            a.position
        FROM actions a
        JOIN players p ON a.player_id = p.player_id
        WHERE a.hand_id = ?
        ORDER BY a.sequence_num
    ");
	$actionsStmt->execute([$input['hand_id']]);
	$actions = $actionsStmt->fetchAll();

	// Calculate pots
	$streetPots = ['preflop' => 0, 'flop' => 0, 'turn' => 0, 'river' => 0];
	$currentPot = 0;
	$actionHistory = [];

	foreach ($actions as $action) {
		if ($action['amount'] > 0) $currentPot += $action['amount'];
		$streetPots[$action['street']] = $currentPot;

		$actionHistory[] = [
			's' => substr($action['street'], 0, 1),
			'p' => (int)$action['player_id'],
			'a' => $action['act'],
			'v' => $action['amount'] ? round($action['amount'], 1) : null,
			'r' => $action['amount'] ? round($action['amount'] / $currentPot, 2) : null,
			'pos' => $action['position']
		];
	}

	// 5. Get player history with positions (increased to 20 hands)
	foreach ($players as &$player) {
		// Get last 20 hands with actions
		$historyStmt = $pdo->prepare("
            SELECT 
                h.hand_id,
                h.hero_position as pos,
                h.hero_cards as cards,
                (
                    SELECT GROUP_CONCAT(
                        CONCAT(
                            SUBSTRING(a.street, 1, 1), ':', 
                            SUBSTRING(a.action_type, 1, 1), ':',
                            IFNULL(a.amount, 0), ':',
                            a.position
                        ) 
                        ORDER BY a.sequence_num SEPARATOR '|'
                    )
                    FROM actions a 
                    WHERE a.hand_id = h.hand_id AND a.player_id = ?
                ) as acts
            FROM hands h
            WHERE h.is_completed = 1
            AND h.hand_id != ?
            AND EXISTS (
                SELECT 1 FROM actions a 
                WHERE a.hand_id = h.hand_id AND a.player_id = ?
            )
            ORDER BY h.hand_id DESC
            LIMIT 20
        ");
		$historyStmt->execute([$player['player_id'], $input['hand_id'], $player['player_id']]);
		$player['hist'] = $historyStmt->fetchAll();

		// Calculate position-specific stats from history
		$positionStats = [];
		foreach ($player['hist'] as $hand) {
			if (!empty($hand['acts'])) {
				$actions = explode('|', $hand['acts']);

				// Get current position from the first action
				$firstAction = explode(':', $actions[0]);
				$currentPosition = $firstAction[3] ?? null;

				if ($currentPosition) {
					if (!isset($positionStats[$currentPosition])) {
						$positionStats[$currentPosition] = [
							'hands' => 0,
							'vpip' => 0,
							'pfr' => 0,
							'af' => 0,
							'actions' => 0,
							'aggressive_actions' => 0
						];
					}

					$positionStats[$currentPosition]['hands']++;

					$streetActions = [];
					$preflopActions = [];
					$aggressiveActions = 0;
					$totalActions = 0;

					foreach ($actions as $actionStr) {
						$parts = explode(':', $actionStr);
						$street = $parts[0];
						$actionType = $parts[1];
						$amount = $parts[2];
						$position = $parts[3];

						if (!isset($streetActions[$street])) {
							$streetActions[$street] = [];
						}
						$streetActions[$street][] = $actionType;

						if ($street == 'p') {
							$preflopActions[] = $actionType;
						}

						$totalActions++;
						if (in_array($actionType, ['b', 'r', 'a'])) {
							$aggressiveActions++;
						}
					}

					// Check VPIP (any non-fold preflop action)
					if (!empty($preflopActions) && !in_array('f', $preflopActions)) {
						$positionStats[$currentPosition]['vpip']++;
					}

					// Check PFR (any raise preflop)
					if (in_array('r', $preflopActions) || in_array('a', $preflopActions)) {
						$positionStats[$currentPosition]['pfr']++;
					}

					// Calculate AF for this hand
					$passiveActions = $totalActions - $aggressiveActions;
					$positionStats[$currentPosition]['af'] += ($passiveActions > 0)
						? $aggressiveActions / $passiveActions
						: 0;

					$positionStats[$currentPosition]['actions'] += $totalActions;
					$positionStats[$currentPosition]['aggressive_actions'] += $aggressiveActions;
				}
			}
		}

		// Calculate averages for position stats
		foreach ($positionStats as $pos => &$stats) {
			if ($stats['hands'] > 0) {
				$stats['vpip_pct'] = round(($stats['vpip'] / $stats['hands']) * 100, 1);
				$stats['pfr_pct'] = round(($stats['pfr'] / $stats['hands']) * 100, 1);
				$stats['af'] = round($stats['af'] / $stats['hands'], 1);
			}
		}

		$player['position_stats'] = $positionStats;
	}
	unset($player);

	// Compact data for AI with enhanced stats
	$analysisData = [
		'i' => (int)$input['hand_id'],
		's' => substr($input['current_street'], 0, 1),
		'h' => [
			'p' => $handData['hero_position'],
			's' => round($handData['hero_stack'], 1),
			'c' => $handData['hero_cards']
		],
		'b' => $handData['board'],
		'p' => [
			'pf' => $streetPots['preflop'],
			'f' => $streetPots['flop'],
			't' => $streetPots['turn'],
			'r' => $streetPots['river']
		],
		'pl' => array_map(function($p) use ($initialStacks, $positions) {
			return [
				'i' => (int)$p['player_id'],
				'n' => $p['nickname'],
				's' => round($p['current_stack'], 1),
				'is' => round($initialStacks[$p['player_id']] ?? $p['current_stack']),
				'pos' => $p['position'],
				'st' => [
					'v' => $p['vpip'],
					'p' => $p['pfr'],
					'a' => $p['af'],
					'aq' => $p['afq'],
					't' => $p['three_bet'],
					'wtsd' => $p['wtsd'],
					'wsd' => $p['wsd']
				],
				'l' => substr($p['last_action'], 0, 1),
				'h' => array_map(function($h) {
					$a = [];
					if (!empty($h['acts'])) {
						foreach (explode('|', $h['acts']) as $act) {
							$parts = explode(':', $act);
							$a[] = [
								's' => $parts[0],
								'a' => $parts[1],
								'v' => $parts[2] > 0 ? (float)$parts[2] : null,
								'pos' => $parts[3]
							];
						}
					}
					return [
						'i' => (int)$h['hand_id'],
						'p' => $h['pos'],
						'c' => $h['cards'],
						'a' => $a
					];
				}, $p['hist'] ?? []),
				'pos_stats' => $p['position_stats']
			];
		}, $players),
		'a' => $actionHistory
	];

	// AI request
	$content = "Ты — профессиональный покерный AI в турнире Bounty 8 max. Стадия: " . ($input['stady'] ?? 'unknown') . ".\n";
	$content .= "Отвечай коротко: действие (если рейз, то сколько) | короткое описание (буквально несколько слов).\n";
	$content .= json_encode($analysisData, JSON_UNESCAPED_UNICODE);

	$api_key = 'sk-JBDhoWZZwZSn8q2xmqmi9zETz12StFzC';
	$url = 'https://api.proxyapi.ru/openai/v1/chat/completions';
	$headers = ['Content-Type: application/json', 'Authorization: Bearer ' . $api_key];

	$ch = curl_init($url);
	curl_setopt_array($ch, [
		CURLOPT_RETURNTRANSFER => true,
		CURLOPT_HTTPHEADER => $headers,
		CURLOPT_POST => true,
		CURLOPT_POSTFIELDS => json_encode([
			'model' => 'gpt-4.1',
			'messages' => [[ 'role' => 'user', 'content' => $content ]],
			'temperature' => 0.3
		])
	]);

	$apiResponse = curl_exec($ch);
	if (curl_errno($ch)) throw new Exception('CURL error: ' . curl_error($ch));

	$apiResponse = json_decode($apiResponse);
	if (!$apiResponse || !isset($apiResponse->choices[0]->message->content)) {
		throw new Exception('Invalid API response');
	}

	$response = [
		'success' => true,
		'content' => $content,
		'data' => $apiResponse->choices[0]->message->content,
		'analysis' => $analysisData
	];

} catch (Exception $e) {
	$response['error'] = $e->getMessage();
} finally {
	if (isset($ch)) curl_close($ch);
	echo json_encode($response, JSON_UNESCAPED_UNICODE);
}
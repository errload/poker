<?php
header('Content-Type: application/json');
require_once __DIR__.'/../db/config.php';

$response = ['success' => false, 'error' => null];

try {
	// Validate input
	$input = json_decode(file_get_contents('php://input'), true);
	if (!$input) throw new Exception('Invalid JSON input');

	$required = ['hand_id', 'current_street', 'hero_position'];
	foreach ($required as $field) {
		if (!isset($input[$field])) throw new Exception("Missing required field: $field");
	}

	$validStreets = ['preflop', 'flop', 'turn', 'river'];
	if (!in_array($input['current_street'], $validStreets)) {
		throw new Exception("Invalid street: " . $input['current_street']);
	}

	// Initialize database connection
	$pdo = new PDO(
		"mysql:host=".DB_HOST.";dbname=".DB_NAME.";charset=utf8mb4",
		DB_USER,
		DB_PASS,
		[
			PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
			PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
		]
	);

	// Get hand information
	$handStmt = $pdo->prepare("
        SELECT 
            hero_position, 
            hero_stack, 
            hero_cards, 
            board, 
            is_completed
        FROM hands 
        WHERE hand_id = ?
    ");
	$handStmt->execute([$input['hand_id']]);
	$handData = $handStmt->fetch();
	if (!$handData) throw new Exception("Hand not found");

	// Get hero player ID
	$heroStmt = $pdo->prepare("
        SELECT a.player_id, p.nickname 
        FROM actions a
        JOIN players p ON a.player_id = p.player_id
        WHERE a.hand_id = ? AND a.position = ?
        ORDER BY a.sequence_num LIMIT 1
    ");
	$heroStmt->execute([$input['hand_id'], $input['hero_position']]);
	$heroData = $heroStmt->fetch();
	$heroPlayerId = $heroData['player_id'] ?? null;
	$heroNickname = $heroData['nickname'] ?? 'Hero';

	// Get all players in the hand with comprehensive stats
	$playersStmt = $pdo->prepare("
        SELECT 
            p.player_id, 
            p.nickname,
            a.position,
            p.vpip,
            p.pfr,
            p.af,
            p.afq,
            p.three_bet,
            p.wtsd,
            p.hands_played,
            p.cbet,
            p.fold_to_cbet,
            p.steal_attempt,
            p.steal_success,
            
            -- Current hand status
            (SELECT a2.action_type FROM actions a2 
             WHERE a2.hand_id = ? AND a2.player_id = p.player_id 
             ORDER BY a2.sequence_num DESC LIMIT 1) as last_action,
            
            EXISTS (SELECT 1 FROM actions a2 WHERE a2.hand_id = ? AND a2.player_id = p.player_id AND a2.action_type = 'fold') as has_folded,
            EXISTS (SELECT 1 FROM actions a2 WHERE a2.hand_id = ? AND a2.player_id = p.player_id AND a2.street = ?) as has_acted_on_street
        FROM players p
        JOIN actions a ON p.player_id = a.player_id AND a.hand_id = ?
        WHERE EXISTS (
            SELECT 1 FROM actions a2 
            WHERE a2.hand_id = ? AND a2.player_id = p.player_id
        )
        ORDER BY FIELD(a.position, 'SB', 'BB', 'UTG', 'UTG+1', 'MP', 'HJ', 'CO', 'BTN')
    ");
	$playersStmt->execute([
		$input['hand_id'],
		$input['hand_id'],
		$input['hand_id'],
		$input['current_street'],
		$input['hand_id'],
		$input['hand_id']
	]);
	$players = $playersStmt->fetchAll();

	// Get positions order for this hand
	$positionsStmt = $pdo->prepare("
        SELECT position, MIN(sequence_num) as min_seq
        FROM actions 
        WHERE hand_id = ?
        GROUP BY position
        ORDER BY min_seq
    ");
	$positionsStmt->execute([$input['hand_id']]);
	$orderedPositions = $positionsStmt->fetchAll();
	$allPositionsInHand = array_column($orderedPositions, 'position');

	// Calculate current stack for each player
	foreach ($players as &$player) {
		$stackStmt = $pdo->prepare("
            SELECT 
                (SELECT amount FROM actions WHERE hand_id = ? AND player_id = ? ORDER BY sequence_num LIMIT 1) as initial_stack,
                (SELECT SUM(amount) FROM actions WHERE hand_id = ? AND player_id = ?) as total_bet
        ");
		$stackStmt->execute([$input['hand_id'], $player['player_id'], $input['hand_id'], $player['player_id']]);
		$stackData = $stackStmt->fetch();

		$player['current_stack'] = ($stackData['initial_stack'] ?? 0) - ($stackData['total_bet'] ?? 0);
		$player['is_active'] = !$player['has_folded'];
	}
	unset($player);

	// Get hero total bet
	$heroTotalBet = 0;
	if ($heroPlayerId) {
		$betStmt = $pdo->prepare("SELECT SUM(amount) FROM actions WHERE hand_id = ? AND player_id = ?");
		$betStmt->execute([$input['hand_id'], $heroPlayerId]);
		$heroTotalBet = $betStmt->fetchColumn() ?? 0;
	}
	$heroCurrentStack = $handData['hero_stack'] - $heroTotalBet;

	// Split players into before and after hero
	$heroIndex = array_search($input['hero_position'], $allPositionsInHand);
	$playersBeforeHero = [];
	$playersAfterHero = [];

	foreach ($players as $player) {
		if ($player['position'] === $input['hero_position']) continue;

		$playerIndex = array_search($player['position'], $allPositionsInHand);
		if ($playerIndex === false) continue;

		$playerData = [
			'player_id' => $player['player_id'],
			'nickname' => $player['nickname'],
			'position' => $player['position'],
			'has_acted' => $player['has_acted_on_street'],
			'has_folded' => $player['has_folded'],
			'is_active' => $player['is_active'],
			'current_stack' => $player['current_stack'],
			'stats' => [
				'vpip' => $player['vpip'],
				'pfr' => $player['pfr'],
				'af' => $player['af'],
				'afq' => $player['afq'],
				'three_bet' => $player['three_bet']
			]
		];

		if ($playerIndex < $heroIndex) {
			$playersBeforeHero[] = $playerData;
		} else {
			$playersAfterHero[] = $playerData;
		}
	}

	// Get remaining players to act (only active players after hero)
	$remainingPlayers = array_filter($playersAfterHero, function($p) {
		return $p['is_active'] && !$p['has_acted'];
	});

	usort($remainingPlayers, function($a, $b) use ($allPositionsInHand) {
		return array_search($a['position'], $allPositionsInHand) - array_search($b['position'], $allPositionsInHand);
	});

	// Get action history for the hand
	$actionsStmt = $pdo->prepare("
        SELECT 
            a.street, 
            a.action_type,
            a.amount,
            a.player_id,
            p.nickname,
            a.sequence_num,
            a.position,
            a.is_aggressive
        FROM actions a
        JOIN players p ON a.player_id = p.player_id
        WHERE a.hand_id = ?
        ORDER BY a.sequence_num
    ");
	$actionsStmt->execute([$input['hand_id']]);
	$actions = $actionsStmt->fetchAll();

	// Calculate pot sizes and action history
	$streetPots = ['preflop' => 0, 'flop' => 0, 'turn' => 0, 'river' => 0];
	$currentPot = 0;
	$actionHistory = [];
	$streetActions = [];

	foreach ($actions as $action) {
		if ($action['amount'] > 0) $currentPot += $action['amount'];
		$streetPots[$action['street']] = $currentPot;

		$actionHistory[] = [
			'street' => substr($action['street'], 0, 1),
			'player_id' => (int)$action['player_id'],
			'action' => substr($action['action_type'], 0, 1),
			'amount' => $action['amount'] ? round($action['amount'], 1) : null,
			'pot_ratio' => $action['amount'] ? round($action['amount'] / max($currentPot, 1), 2) : null,
			'position' => $action['position'],
			'is_aggressive' => (bool)$action['is_aggressive']
		];

		if (!isset($streetActions[$action['street']])) {
			$streetActions[$action['street']] = [];
		}
		$streetActions[$action['street']][] = $actionHistory[count($actionHistory)-1];
	}

	// Get recent hand IDs (last 50 completed hands)
	$recentHandsStmt = $pdo->prepare("
        SELECT hand_id FROM hands 
        WHERE is_completed = 1 
        ORDER BY hand_id DESC 
        LIMIT 50
    ");
	$recentHandsStmt->execute();
	$recentHandIds = $recentHandsStmt->fetchAll(PDO::FETCH_COLUMN, 0);

	// Get hero reactions data if we have recent hands
	$reactionAnalysis = [];
	if (!empty($recentHandIds) && $heroPlayerId) {
		$recentHandsPlaceholders = implode(',', array_fill(0, count($recentHandIds), '?'));

		$heroReactionsStmt = $pdo->prepare("
            SELECT 
                a.player_id,
                p.nickname,
                a.action_type,
                a.street,
                COUNT(*) as action_count,
                SUM(a.is_aggressive) as aggressive_count
            FROM actions a
            JOIN players p ON a.player_id = p.player_id
            WHERE a.hand_id IN ($recentHandsPlaceholders)
            AND EXISTS (
                SELECT 1 FROM actions a2 
                WHERE a2.hand_id = a.hand_id 
                AND a2.player_id = ?
                AND a2.sequence_num < a.sequence_num
            )
            GROUP BY a.player_id, a.action_type, a.street
        ");
		$heroReactionsStmt->execute(array_merge($recentHandIds, [$heroPlayerId]));
		$heroReactions = $heroReactionsStmt->fetchAll();

		foreach ($heroReactions as $reaction) {
			if (!isset($reactionAnalysis[$reaction['player_id']])) {
				$reactionAnalysis[$reaction['player_id']] = [
					'nickname' => $reaction['nickname'],
					'total_actions' => 0,
					'aggressive_actions' => 0,
					'action_breakdown' => [],
					'street_breakdown' => []
				];
			}

			$reactionAnalysis[$reaction['player_id']]['total_actions'] += $reaction['action_count'];
			$reactionAnalysis[$reaction['player_id']]['aggressive_actions'] += $reaction['aggressive_count'];

			if (!isset($reactionAnalysis[$reaction['player_id']]['action_breakdown'][$reaction['action_type']])) {
				$reactionAnalysis[$reaction['player_id']]['action_breakdown'][$reaction['action_type']] = 0;
			}
			$reactionAnalysis[$reaction['player_id']]['action_breakdown'][$reaction['action_type']] += $reaction['action_count'];

			if (!isset($reactionAnalysis[$reaction['player_id']]['street_breakdown'][$reaction['street']])) {
				$reactionAnalysis[$reaction['player_id']]['street_breakdown'][$reaction['street']] = [
					'total' => 0,
					'aggressive' => 0
				];
			}
			$reactionAnalysis[$reaction['player_id']]['street_breakdown'][$reaction['street']]['total'] += $reaction['action_count'];
			$reactionAnalysis[$reaction['player_id']]['street_breakdown'][$reaction['street']]['aggressive'] += $reaction['aggressive_count'];
		}
	}

	// Prepare complete analysis data
	$analysisData = [
		'hand_id' => (int)$input['hand_id'],
		'street' => $input['current_street'],
		'hero' => [
			'player_id' => (int)$heroPlayerId,
			'position' => $handData['hero_position'],
			'stack' => round($heroCurrentStack, 1),
			'total_bet' => round($heroTotalBet, 1),
			'cards' => $handData['hero_cards']
		],
		'hero_position' => $input['hero_position'],
		'current_street' => $input['current_street'],
		'players_before_hero' => $playersBeforeHero,
		'players_after_hero' => $playersAfterHero,
		'board' => $handData['board'],
		'pots' => [
			'preflop' => $streetPots['preflop'],
			'flop' => $streetPots['flop'],
			'turn' => $streetPots['turn'],
			'river' => $streetPots['river'],
			'current' => $currentPot
		],
		'players' => array_map(function($p) use ($reactionAnalysis) {
			$reactionData = $reactionAnalysis[$p['player_id']] ?? null;
			$aggPct = $reactionData && $reactionData['total_actions'] > 0 ?
				round($reactionData['aggressive_actions'] / $reactionData['total_actions'] * 100, 1) : 0;

			return [
				'id' => (int)$p['player_id'],
				'name' => $p['nickname'],
				'position' => $p['position'],
				'stack' => round($p['current_stack'], 1),
				'stats' => [
					'vpip' => $p['vpip'],
					'pfr' => $p['pfr'],
					'af' => $p['af'],
					'afq' => $p['afq'],
					'three_bet' => $p['three_bet'],
					'wtsd' => $p['wtsd'],
					'cbet' => $p['cbet'],
					'fold_to_cbet' => $p['fold_to_cbet'],
					'steal_attempt' => $p['steal_attempt'],
					'steal_success' => $p['steal_success']
				],
				'last_action' => $p['last_action'] ? substr($p['last_action'], 0, 1) : null,
				'status' => [
					'folded' => $p['has_folded'],
					'acted' => $p['has_acted_on_street'],
					'active' => $p['is_active'],
					'to_act' => $p['is_active'] && !$p['has_acted_on_street']
				],
				'reactions' => $reactionData ? [
					'aggression' => $aggPct,
					'actions' => $reactionData['action_breakdown'],
					'streets' => array_map(function($street) {
						return [
							'total' => $street['total'],
							'aggressive' => $street['aggressive'],
							'agg_pct' => $street['total'] > 0 ? round($street['aggressive'] / $street['total'] * 100, 1) : 0
						];
					}, $reactionData['street_breakdown'])
				] : null
			];
		}, $players),
		'actions' => $actionHistory,
		'street_actions' => $streetActions,
		'remaining_players' => array_map(function($p) {
			return [
				'id' => (int)$p['player_id'],
				'name' => $p['nickname'],
				'position' => $p['position'],
				'stack' => round($p['current_stack'], 1),
				'stats' => [
					'vpip' => $p['vpip'],
					'pfr' => $p['pfr'],
					'af' => $p['af'],
					'afq' => $p['afq'],
					'three_bet' => $p['three_bet']
				]
			];
		}, $remainingPlayers)
	];

	// Call AI for analysis
	$content = '
        Ты — профессиональный покерный ИИ. Анализируй раздачу:
        1. Карты: {hero_cards} | Доска: {board}
        2. Позиция: {hero_position} (после {players_before_count} игроков)
        3. Действия до тебя: {actions_before}
        4. Игроки после: {players_after}
        5. Статистика:
           - Перед тобой: {players_before_stats}
           - После тебя: {players_after_stats}
        Отвечай максимально коротко: (чек, колл, рейз X BB) | Краткое описание (3-4 слова)
    ';

	$content = str_replace(
		['{hero_cards}', '{board}', '{street}', '{pot}'],
		[$analysisData['hero']['cards'], $analysisData['board'], $input['current_street'], $streetPots[$input['current_street']]],
		$content
	);

	$content .= "\n\n" . json_encode($analysisData, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

	$api_key = 'sk-JBDhoWZZwZSn8q2xmqmi9zETz12StFzC';
	$url = 'https://api.proxyapi.ru/openai/v1/chat/completions';
	$headers = ['Content-Type: application/json', 'Authorization: Bearer ' . $api_key];

	$ch = curl_init($url);
	curl_setopt_array($ch, [
		CURLOPT_RETURNTRANSFER => true,
		CURLOPT_HTTPHEADER => $headers,
		CURLOPT_POST => true,
		CURLOPT_POSTFIELDS => json_encode([
			'model' => 'gpt-4o',
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
		'analysis' => $analysisData,
		'data' => trim($apiResponse->choices[0]->message->content)
	];

} catch (Exception $e) {
	$response['error'] = $e->getMessage();
} finally {
	if (isset($ch)) curl_close($ch);
	echo json_encode($response, JSON_UNESCAPED_UNICODE);
}
?>
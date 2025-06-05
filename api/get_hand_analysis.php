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
        SELECT hero_position, hero_stack, hero_cards, board, is_completed 
        FROM hands WHERE hand_id = ?
    ");
	$handStmt->execute([$input['hand_id']]);
	$handData = $handStmt->fetch();
	if (!$handData) throw new Exception("Hand not found");

	// Get all players in the hand and their fold status
	$playersStmt = $pdo->prepare("
        SELECT DISTINCT p.player_id, p.nickname, a.position
        FROM players p
        JOIN actions a ON p.player_id = a.player_id
        WHERE a.hand_id = ?
    ");
	$playersStmt->execute([$input['hand_id']]);
	$allPlayersInHand = $playersStmt->fetchAll();

	// Get fold status for each player
	$foldStatus = [];
	foreach ($allPlayersInHand as $player) {
		$foldStmt = $pdo->prepare("
            SELECT 1 FROM actions 
            WHERE hand_id = ? AND player_id = ? AND action_type = 'fold'
            LIMIT 1
        ");
		$foldStmt->execute([$input['hand_id'], $player['player_id']]);
		$foldStatus[$player['player_id']] = $foldStmt->fetch() ? true : false;
	}

	// Get total bet by hero
	$stackStmt = $pdo->prepare("
        SELECT player_id FROM actions 
        WHERE hand_id = ? AND position = ?
        ORDER BY sequence_num LIMIT 1
    ");
	$stackStmt->execute([$input['hand_id'], $input['hero_position']]);
	$heroPlayerId = $stackStmt->fetchColumn();

	$totalBet = 0;
	if ($heroPlayerId) {
		$betStmt = $pdo->prepare("SELECT SUM(amount) FROM actions WHERE hand_id = ? AND player_id = ?");
		$betStmt->execute([$input['hand_id'], $heroPlayerId]);
		$totalBet = $betStmt->fetchColumn() ?? 0;
	}
	$currentStack = $handData['hero_stack'] - $totalBet;

	// Get recent hand IDs (last 30 completed hands)
	$recentHandsStmt = $pdo->prepare("
        SELECT hand_id FROM hands 
        WHERE is_completed = 1 
        ORDER BY hand_id DESC 
        LIMIT 30
    ");
	$recentHandsStmt->execute();
	$recentHandIds = $recentHandsStmt->fetchAll(PDO::FETCH_COLUMN, 0);

	// Get all players in the current hand with improved stats
	$playersStmt = $pdo->prepare("
        SELECT 
            p.player_id, 
            p.nickname,
            COALESCE(a.position, 'UTG') as position,
            ROUND(p.vpip,1) as vpip, 
            ROUND(p.pfr,1) as pfr,
            ROUND(p.af,1) as af, 
            ROUND(p.afq,1) as afq,
            ROUND(p.three_bet,1) as three_bet,
            ROUND(p.wtsd,1) as wtsd, 
            ROUND(p.wsd,1) as wsd,
            p.hands_played,
            (
                SELECT a2.action_type 
                FROM actions a2 
                WHERE a2.hand_id = ? AND a2.player_id = p.player_id
                ORDER BY a2.sequence_num DESC 
                LIMIT 1
            ) as last_action,
            (
                SELECT COUNT(DISTINCT a2.hand_id)
                FROM actions a2
                WHERE a2.player_id = p.player_id
                AND a2.street = 'flop'
                AND a2.action_type = 'bet'
                AND EXISTS (
                    SELECT 1 FROM actions a3 
                    WHERE a3.hand_id = a2.hand_id 
                    AND a3.player_id = a2.player_id 
                    AND a3.street = 'preflop' 
                    AND a3.is_aggressive = 1
                )
            ) as cbet_count,
            (
                SELECT COUNT(DISTINCT a2.hand_id)
                FROM actions a2
                JOIN actions a3 ON a2.hand_id = a3.hand_id
                WHERE a2.player_id = p.player_id
                AND a2.street = 'flop'
                AND a3.street = 'flop'
                AND a3.action_type = 'bet'
                AND a3.sequence_num < a2.sequence_num
                AND a3.player_id != p.player_id
            ) as faced_cbet_count,
            (
                SELECT COUNT(DISTINCT a2.hand_id)
                FROM actions a2
                JOIN actions a3 ON a2.hand_id = a3.hand_id
                WHERE a2.player_id = p.player_id
                AND a2.street = 'flop'
                AND a2.action_type = 'fold'
                AND a3.street = 'flop'
                AND a3.action_type = 'bet'
                AND a3.sequence_num < a2.sequence_num
                AND a3.player_id != p.player_id
            ) as fold_to_cbet_count,
            (
                SELECT COUNT(DISTINCT a2.hand_id)
                FROM actions a2
                WHERE a2.player_id = p.player_id
                AND a2.position IN ('BTN', 'CO', 'HJ')
                AND a2.street = 'preflop'
                AND a2.is_aggressive = 1
                AND NOT EXISTS (
                    SELECT 1 FROM actions a3 
                    WHERE a3.hand_id = a2.hand_id 
                    AND a3.street = 'preflop'
                    AND a3.is_aggressive = 1
                    AND a3.sequence_num < a2.sequence_num
                )
            ) as steal_attempt_count,
            EXISTS (
                SELECT 1 FROM actions a2 
                WHERE a2.hand_id = ? AND a2.player_id = p.player_id
            ) as has_acted_in_hand,
            EXISTS (
                SELECT 1 FROM actions a2 
                WHERE a2.hand_id = ? AND a2.player_id = p.player_id AND action_type = 'fold'
            ) as has_folded,
            EXISTS (
                SELECT 1 FROM actions a2 
                WHERE a2.hand_id = ? AND a2.player_id = p.player_id AND street = ?
            ) as has_acted_on_street,
            EXISTS (
                SELECT 1 FROM actions a2
                WHERE a2.player_id = p.player_id
                AND a2.hand_id IN (".implode(',', array_fill(0, count($recentHandIds), '?')).")
            ) as is_recent_player
        FROM players p
        LEFT JOIN actions a ON p.player_id = a.player_id AND a.hand_id = ?
        WHERE EXISTS (
            SELECT 1 FROM actions a2 
            WHERE a2.hand_id = ? AND a2.player_id = p.player_id
        ) OR (
            ? IN ('SB', 'BB') AND 
            NOT EXISTS (
                SELECT 1 FROM actions a3 
                WHERE a3.hand_id = ? AND a3.player_id = p.player_id
            ) AND
            EXISTS (
                SELECT 1 FROM actions a4
                WHERE a4.player_id = p.player_id
                AND a4.hand_id IN (".implode(',', array_fill(0, count($recentHandIds), '?')).")
            )
        )
        ORDER BY FIELD(COALESCE(a.position, 'UTG'), 'SB', 'BB', 'UTG', 'MP', 'CO', 'BTN')
    ");

	$params = array_merge(
		[$input['hand_id'], $input['hand_id'], $input['hand_id'], $input['hand_id'], $input['current_street']],
		$recentHandIds,
		[$input['hand_id'], $input['hand_id']],
		[$input['hero_position'], $input['hand_id']],
		$recentHandIds
	);

	$playersStmt->execute($params);
	$players = $playersStmt->fetchAll();

	// Enhance player data with active status
	foreach ($players as &$player) {
		$player['is_active'] = !$foldStatus[$player['player_id']];

		$player['cbet_pct'] = ($player['faced_cbet_count'] > 0) ?
			round(($player['cbet_count'] / $player['faced_cbet_count']) * 100, 1) : 0;

		$player['fold_to_cbet_pct'] = ($player['faced_cbet_count'] > 0) ?
			round(($player['fold_to_cbet_count'] / $player['faced_cbet_count']) * 100, 1) : 0;

		$player['steal_attempt_pct'] = ($player['hands_played'] > 0) ?
			round(($player['steal_attempt_count'] / $player['hands_played']) * 100, 1) : 0;
	}
	unset($player);

	// Get remaining players to act (only active players)
	$positionsOrder = ['SB', 'BB', 'UTG', 'UTG+1', 'MP', 'HJ', 'CO', 'BTN'];
	$remainingPlayers = array_filter($players, function($p) use ($input) {
		return $p['is_active'] && !$p['has_acted_on_street'] && $p['position'] != $input['hero_position'];
	});

	usort($remainingPlayers, function($a, $b) use ($positionsOrder) {
		return array_search($a['position'], $positionsOrder) - array_search($b['position'], $positionsOrder);
	});

	// Get action history for the hand
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

	// Calculate pot sizes and action history
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
			'r' => $action['amount'] ? round($action['amount'] / max($currentPot, 1), 2) : null,
			'pos' => $action['position']
		];
	}

	// Get hero reactions data (simplified)
	$heroReactionsStmt = $pdo->prepare("
        SELECT 
            a.player_id,
            p.nickname,
            a.action_type,
            COUNT(*) as action_count,
            SUM(a.is_aggressive) as aggressive_count
        FROM actions a
        JOIN players p ON a.player_id = p.player_id
        WHERE a.hand_id IN (".implode(',', array_fill(0, count($recentHandIds), '?')).")
        AND EXISTS (
            SELECT 1 FROM actions a2 
            WHERE a2.hand_id = a.hand_id 
            AND a2.player_id = ?
            AND a2.sequence_num < a.sequence_num
        )
        GROUP BY a.player_id, a.action_type
    ");
	$heroReactionsStmt->execute(array_merge($recentHandIds, [$heroPlayerId]));
	$heroReactions = $heroReactionsStmt->fetchAll();

	$reactionAnalysis = [];
	foreach ($heroReactions as $reaction) {
		if (!isset($reactionAnalysis[$reaction['player_id']])) {
			$reactionAnalysis[$reaction['player_id']] = [
				'nickname' => $reaction['nickname'],
				'total_actions' => 0,
				'aggressive_actions' => 0,
				'action_breakdown' => []
			];
		}

		$reactionAnalysis[$reaction['player_id']]['total_actions'] += $reaction['action_count'];
		$reactionAnalysis[$reaction['player_id']]['aggressive_actions'] += $reaction['aggressive_count'];
		$reactionAnalysis[$reaction['player_id']]['action_breakdown'][$reaction['action_type']] = $reaction['action_count'];
	}

	// Prepare complete analysis data
	$analysisData = [
		'hand_id' => (int)$input['hand_id'],
		'street' => substr($input['current_street'], 0, 1),
		'hero' => [
			'position' => $handData['hero_position'],
			'stack' => round($currentStack, 1),
			'cards' => $handData['hero_cards']
		],
		'board' => $handData['board'],
		'pots' => [
			'preflop' => $streetPots['preflop'],
			'flop' => $streetPots['flop'],
			'turn' => $streetPots['turn'],
			'river' => $streetPots['river']
		],
		'players' => array_map(function($p) use ($reactionAnalysis, $input) {
			$reactionData = $reactionAnalysis[$p['player_id']] ?? null;
			$aggPct = $reactionData && $reactionData['total_actions'] > 0 ?
				round($reactionData['aggressive_actions'] / $reactionData['total_actions'] * 100, 1) : 0;

			return [
				'id' => (int)$p['player_id'],
				'name' => $p['nickname'],
				'position' => $p['position'],
				'stats' => [
					'vpip' => $p['vpip'],
					'pfr' => $p['pfr'],
					'af' => $p['af'],
					'afq' => $p['afq'],
					'three_bet' => $p['three_bet'],
					'wtsd' => $p['wtsd'],
					'wsd' => $p['wsd'],
					'cbet' => $p['cbet_pct'],
					'fold_cbet' => $p['fold_to_cbet_pct'],
					'steal' => $p['steal_attempt_pct']
				],
				'last_action' => $p['last_action'] ? substr($p['last_action'], 0, 1) : null,
				'status' => [
					'folded' => $p['has_folded'],
					'acted' => $p['has_acted_on_street'],
					'active' => $p['is_active'],
					'to_act' => $p['is_active'] && !$p['has_acted_on_street'] && $p['position'] != $input['hero_position']
				],
				'reactions' => $reactionData ? [
					'aggression' => $aggPct,
					'actions' => $reactionData['action_breakdown']
				] : null
			];
		}, $players),
		'actions' => $actionHistory,
		'remaining_players' => array_map(function($p) {
			return [
				'id' => (int)$p['player_id'],
				'name' => $p['nickname'],
				'position' => $p['position'],
				'stats' => [
					'vpip' => $p['vpip'],
					'pfr' => $p['pfr'],
					'af' => $p['af'],
					'afq' => $p['afq']
				]
			];
		}, $remainingPlayers)
	];

	// Call AI for analysis
	$content = '
        Ты — профессиональный покерный ИИ. Анализируй раздачу по следующим правилам:
        1. Карты:
        - Герой: {hero_cards} | Доска: {board}
        - На префлопе анализируй только карты героя
        - На постфлопе учитывай текущую комбинацию
        2. Анализ:
        - Улица: {street} | Банк: {pot}
        - Статистика игроков: VPIP/PFR/3Bet/AF
        - Реакции оппонентов на действия героя
        - Оставшиеся игроки и их стиль игры
        Отвечай максимально коротко: (чек, колл, рейз X BB) | Краткое описание (3-4 слова)
    ';

	$content = str_replace(
		['{hero_cards}', '{board}', '{street}', '{pot}'],
		[$analysisData['hero']['cards'], $analysisData['board'], $input['current_street'], $streetPots[$input['current_street']]],
		$content
	);

	$content .= "\n\n" . json_encode($analysisData, JSON_UNESCAPED_UNICODE);

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
		'data' => $apiResponse->choices[0]->message->content
	];

} catch (Exception $e) {
	$response['error'] = $e->getMessage();
} finally {
	if (isset($ch)) curl_close($ch);
	echo json_encode($response, JSON_UNESCAPED_UNICODE);
}
?>
<?php
header('Content-Type: application/json');
require_once __DIR__.'/../db/config.php';

$response = ['success' => false, 'error' => null];

try {
	// Validate input
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
        FROM hands 
        WHERE hand_id = ?
    ");
	$handStmt->execute([$input['hand_id']]);
	$handData = $handStmt->fetch();
	if (!$handData) throw new Exception("Hand not found");

	// Get ALL players who have participated in this hand (including those who haven't acted yet)
	$playersStmt = $pdo->prepare("
        SELECT DISTINCT
            p.player_id, 
            p.nickname,
            a.position,
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
                SELECT COUNT(*) 
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
                SELECT COUNT(*) 
                FROM actions a2 
                WHERE a2.player_id = p.player_id 
                AND a2.street = 'flop' 
                AND a2.action_type IN ('fold', 'call', 'raise')
                AND EXISTS (
                    SELECT 1 FROM actions a3 
                    WHERE a3.hand_id = a2.hand_id 
                    AND a3.player_id != a2.player_id 
                    AND a3.street = 'flop' 
                    AND a3.action_type = 'bet'
                    AND a3.sequence_num < a2.sequence_num
                )
            ) as faced_cbet_count,
            (
                SELECT COUNT(*) 
                FROM actions a2 
                WHERE a2.player_id = p.player_id 
                AND a2.street = 'flop' 
                AND a2.action_type = 'fold'
                AND EXISTS (
                    SELECT 1 FROM actions a3 
                    WHERE a3.hand_id = a2.hand_id 
                    AND a3.player_id != a2.player_id 
                    AND a3.street = 'flop' 
                    AND a3.action_type = 'bet'
                    AND a3.sequence_num < a2.sequence_num
                )
            ) as fold_to_cbet_count,
            (
                SELECT COUNT(*) 
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
            ) as steal_attempt_count
        FROM players p
        LEFT JOIN actions a ON p.player_id = a.player_id AND a.hand_id = ?
        WHERE EXISTS (
            SELECT 1 FROM actions a2 
            WHERE a2.hand_id = ? AND a2.player_id = p.player_id
        )
        OR EXISTS (
            SELECT 1 FROM hands h 
            WHERE h.hand_id = ? 
            AND (
                h.hero_position = p.player_id OR 
                h.hero_position LIKE CONCAT('%', p.player_id, '%')
            )
        )
        ORDER BY FIELD(a.position, 'SB', 'BB', 'UTG', 'MP', 'CO', 'BTN')
    ");
	$playersStmt->execute([
		$input['hand_id'],
		$input['hand_id'],
		$input['hand_id'],
		$input['hand_id']
	]);
	$players = $playersStmt->fetchAll();

	// Determine action order for current street
	$positionsOrder = ['SB', 'BB', 'UTG', 'UTG+1', 'MP', 'HJ', 'CO', 'BTN'];
	$currentStreet = $input['current_street'];

	// Enhance player data with status and calculated metrics
	foreach ($players as &$player) {
		// Check if player has folded
		$foldStmt = $pdo->prepare("
            SELECT 1 FROM actions 
            WHERE hand_id = ? AND player_id = ? AND action_type = 'fold'
            LIMIT 1
        ");
		$foldStmt->execute([$input['hand_id'], $player['player_id']]);
		$player['has_folded'] = $foldStmt->fetch() ? true : false;

		// Check if player has acted on current street
		$actedStmt = $pdo->prepare("
            SELECT 1 FROM actions 
            WHERE hand_id = ? AND player_id = ? AND street = ?
            LIMIT 1
        ");
		$actedStmt->execute([$input['hand_id'], $player['player_id'], $currentStreet]);
		$player['has_acted'] = $actedStmt->fetch() ? true : false;

		// Calculate derived stats
		$player['cbet_pct'] = ($player['faced_cbet_count'] > 0) ?
			round(($player['cbet_count'] / $player['faced_cbet_count']) * 100, 1) : 0;

		$player['fold_to_cbet_pct'] = ($player['faced_cbet_count'] > 0) ?
			round(($player['fold_to_cbet_count'] / $player['faced_cbet_count']) * 100, 1) : 0;

		$player['steal_attempt_pct'] = ($player['hands_played'] > 0) ?
			round(($player['steal_attempt_count'] / $player['hands_played']) * 100, 1) : 0;
	}
	unset($player);

	// Get remaining players to act (not folded, not acted, not hero)
	$remainingPlayers = array_filter($players, function($p) use ($input) {
		return !$p['has_folded'] && !$p['has_acted'] && $p['position'] != $input['hero_position'];
	});

	// Sort remaining players by position order
	usort($remainingPlayers, function($a, $b) use ($positionsOrder) {
		return array_search($a['position'], $positionsOrder) - array_search($b['position'], $positionsOrder);
	});

	// Get hero reactions data
	$heroReactionsStmt = $pdo->prepare("
        SELECT 
            a.player_id,
            p.nickname,
            a.action_type,
            COUNT(*) as action_count,
            SUM(CASE WHEN a.is_aggressive THEN 1 ELSE 0 END) as aggressive_count
        FROM actions a
        JOIN players p ON a.player_id = p.player_id
        JOIN hands h ON a.hand_id = h.hand_id
        WHERE h.is_completed = 1
        AND a.hand_id != ?
        AND EXISTS (
            SELECT 1 FROM actions a2 
            WHERE a2.hand_id = a.hand_id 
            AND a2.player_id = ?
            AND a2.sequence_num < a.sequence_num
        )
        GROUP BY a.player_id, a.action_type
    ");
	$heroReactionsStmt->execute([$input['hand_id'], $input['hero_position']]);
	$heroReactions = $heroReactionsStmt->fetchAll();

	// Analyze reactions to hero's actions
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

	// Prepare complete analysis data
	$analysisData = [
		'i' => (int)$input['hand_id'],
		's' => substr($currentStreet, 0, 1),
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
		'pl' => array_map(function($p) use ($reactionAnalysis, $input) {
			$reactionData = $reactionAnalysis[$p['player_id']] ?? null;
			$aggPct = $reactionData && $reactionData['total_actions'] > 0 ?
				round($reactionData['aggressive_actions'] / $reactionData['total_actions'] * 100, 1) : 0;

			return [
				'i' => (int)$p['player_id'],
				'n' => $p['nickname'],
				'pos' => $p['position'],
				'st' => [
					'v' => $p['vpip'],
					'p' => $p['pfr'],
					'a' => $p['af'],
					'aq' => $p['afq'],
					't' => $p['three_bet'],
					'wtsd' => $p['wtsd'],
					'wsd' => $p['wsd'],
					'cbet' => $p['cbet_pct'],
					'fold_cbet' => $p['fold_to_cbet_pct'],
					'steal' => $p['steal_attempt_pct']
				],
				'l' => $p['last_action'] ? substr($p['last_action'], 0, 1) : null,
				'status' => [
					'folded' => $p['has_folded'],
					'acted' => $p['has_acted'],
					'active' => !$p['has_folded'],
					'to_act' => !$p['has_folded'] && !$p['has_acted'] && $p['position'] != $input['hero_position']
				],
				'react' => $reactionData ? [
					'agg' => $aggPct,
					'actions' => $reactionData['action_breakdown']
				] : null
			];
		}, $players),
		'a' => $actionHistory,
		'remaining_players' => array_map(function($p) {
			return [
				'i' => (int)$p['player_id'],
				'n' => $p['nickname'],
				'pos' => $p['position'],
				'st' => [
					'v' => $p['vpip'],
					'p' => $p['pfr'],
					'a' => $p['af'],
					'aq' => $p['afq']
				]
			];
		}, $remainingPlayers)
	];

	// Call AI for analysis
	$content = "Ты — профессиональный покерный ИИ. Проанализируй раздачу с учётом:\n";
	$content .= "- Статистики игроков (VPIP, PFR, агрессия и др.)\n";
	$content .= "- CBET статистики (частота контбетов и фолдов к ним)\n";
	$content .= "- Попыток стилов с поздних позиций\n";
	$content .= "- Реакций оппонентов на предыдущие действия героя\n";
	$content .= "- Текущей улицы и истории действий\n";
	$content .= "- Статуса игроков (активен/выбыл/уже действовал)\n";
	$content .= "- Игроков, которым еще предстоит действовать (remaining_players)\n\n";
	$content .= "Отвечай кратко: действие (если рейз, укажи размер) | обоснование (2-3 слова)\n";
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
		'data' => $apiResponse->choices[0]->message->content,
		'analysis' => $analysisData
	];

} catch (Exception $e) {
	$response['error'] = $e->getMessage();
} finally {
	if (isset($ch)) curl_close($ch);
	echo json_encode($response, JSON_UNESCAPED_UNICODE);
}
?>
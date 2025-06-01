<?php
header('Content-Type: application/json');
require_once __DIR__.'/../db/config.php';

$response = ['success' => false, 'error' => null];

try {
	$input = json_decode(file_get_contents('php://input'), true);
	if (!$input) {
		throw new Exception('Неверный JSON-ввод');
	}

	$required = ['hand_id', 'current_street', 'hero_position'];
	foreach ($required as $field) {
		if (!isset($input[$field])) {
			throw new Exception("Отсутствует обязательное поле: $field");
		}
	}

	$validStreets = ['preflop', 'flop', 'turn', 'river'];
	if (!in_array($input['current_street'], $validStreets)) {
		throw new Exception("Недопустимая улица: " . $input['current_street']);
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

	// Получаем информацию о раздаче
	$handStmt = $pdo->prepare("
        SELECT hero_position, hero_stack, hero_cards, board, is_completed 
        FROM hands 
        WHERE hand_id = ?
    ");
	$handStmt->execute([$input['hand_id']]);
	$handData = $handStmt->fetch();
	if (!$handData) throw new Exception("Раздача не найдена");

	// Получаем активных игроков с расширенной статистикой
	$playersStmt = $pdo->prepare("
        SELECT 
            p.player_id, p.nickname, 
            ROUND(p.vpip,1) as vpip, ROUND(p.pfr,1) as pfr,
            ROUND(p.af,1) as af, ROUND(p.afq,1) as afq,
            ROUND(p.three_bet,1) as three_bet,
            ROUND(p.wtsd,1) as wtsd, ROUND(p.wsd,1) as wsd,
            a.action_type as last_action, a.position,
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

	// Добавляем расчетные метрики для каждого игрока
	foreach ($players as &$player) {
		// CBET статистика
		$player['cbet_pct'] = $player['cbet_count'] > 0 ?
			round(($player['cbet_count'] / $player['faced_cbet_count']) * 100, 1) : 0;

		// Fold to CBET
		$player['fold_to_cbet_pct'] = $player['faced_cbet_count'] > 0 ?
			round(($player['fold_to_cbet_count'] / $player['faced_cbet_count']) * 100, 1) : 0;

		// Steal attempt percentage
		$player['steal_attempt_pct'] = $player['steal_attempt_count'] > 0 ?
			round(($player['steal_attempt_count'] / $player['hands_played']) * 100, 1) : 0;
	}
	unset($player);

	// Получаем историю действий для анализа реакции на героя
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

	// Анализируем реакцию на действия героя
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

	// Получаем историю действий
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

	// Рассчитываем банки и историю действий
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

	// Подготавливаем данные для анализа с новой информацией
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
		'pl' => array_map(function($p) {
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
				'l' => substr($p['last_action'], 0, 1),
				'react' => isset($reactionAnalysis[$p['player_id']]) ? [
					'agg' => round($reactionAnalysis[$p['player_id']]['aggressive_actions'] /
						$reactionAnalysis[$p['player_id']]['total_actions'] * 100, 1),
					'actions' => $reactionAnalysis[$p['player_id']]['action_breakdown']
				] : null
			];
		}, $players),
		'a' => $actionHistory
	];

	// Запрос к AI с дополнительным контекстом
	$content = "Ты — профессиональный покерный AI. Анализируй раздачу с учетом:\n";
	$content .= "- CBET статистики игроков (частота контбетов и фолдов к ним)\n";
	$content .= "- Steal-попыток с поздних позиций\n";
	$content .= "- Реакции оппонентов на предыдущие действия героя\n";
	$content .= "Отвечай коротко: действие (если рейз, то сколько) | обоснование (несколько слов)\n";
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
	if (curl_errno($ch)) throw new Exception('Ошибка CURL: ' . curl_error($ch));

	$apiResponse = json_decode($apiResponse);
	if (!$apiResponse || !isset($apiResponse->choices[0]->message->content)) {
		throw new Exception('Неверный ответ API');
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
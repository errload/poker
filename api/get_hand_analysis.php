<?php
header('Content-Type: application/json');
require_once __DIR__.'/../db/config.php';

$response = ['success' => false, 'error' => null];

try {
	$input = json_decode(file_get_contents('php://input'), true);
	if (!$input) {
		throw new Exception('Invalid JSON input');
	}

	// Проверяем обязательные поля
	$required = ['hand_id', 'current_street', 'hero_position'];
	foreach ($required as $field) {
		if (!isset($input[$field])) {
			throw new Exception("Missing required field: $field");
		}
	}

	// Создаем подключение к БД только после проверки входных данных
	$pdo = new PDO(
		"mysql:host=".DB_HOST.";dbname=".DB_NAME.";charset=utf8mb4",
		DB_USER,
		DB_PASS,
		[
			PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
			PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
		]
	);

	// 1. Получаем информацию о текущей раздаче
	$handStmt = $pdo->prepare("
        SELECT hero_position, hero_stack, hero_cards, board, is_completed, stacks 
        FROM hands 
        WHERE hand_id = ?
    ");
	if (!$handStmt->execute([$input['hand_id']])) {
		throw new Exception("Failed to execute hand query");
	}
	$handData = $handStmt->fetch();
	if (!$handData) {
		throw new Exception("Hand not found");
	}

	// 2. Получаем активных игроков с их последним стеком
	$playersStmt = $pdo->prepare("
        SELECT 
            p.player_id, p.nickname, p.vpip, p.pfr, p.af, p.afq, p.three_bet, 
            p.wtsd, p.wsd, p.hands_played,
            a.current_stack,
            a.action_type as last_action
        FROM players p
        JOIN (
            SELECT 
                player_id, 
                MAX(sequence_num) as last_seq
            FROM actions
            WHERE hand_id = ?
            AND action_type != 'fold'
            GROUP BY player_id
        ) last ON p.player_id = last.player_id
        JOIN actions a ON a.hand_id = ? AND a.player_id = p.player_id AND a.sequence_num = last.last_seq
        ORDER BY a.sequence_num ASC
    ");
	if (!$playersStmt->execute([$input['hand_id'], $input['hand_id']])) {
		throw new Exception("Failed to execute players query");
	}
	$players = $playersStmt->fetchAll();

	// 3. Для каждого игрока получаем историю последних 20 сыгранных рук
	foreach ($players as &$player) {
		$historyStmt = $pdo->prepare("
            SELECT 
                h.hand_id,
                h.hero_position as position,
                h.hero_cards,
                h.board,
                h.is_completed,
                GROUP_CONCAT(
                    CONCAT(a.street, ':', a.action_type, ':', IFNULL(a.amount, 0))
                    ORDER BY a.sequence_num SEPARATOR '|'
                ) AS actions
            FROM hands h
            JOIN actions a ON h.hand_id = a.hand_id AND a.player_id = ?
            WHERE h.is_completed = 1
            AND h.hand_id != ?
            GROUP BY h.hand_id
            ORDER BY h.hand_id DESC
            LIMIT 20
        ");
		$historyStmt->execute([$player['player_id'], $input['hand_id']]);
		$playerHistory = $historyStmt->fetchAll();

		$player['history'] = array_map(function($hand) {
			return [
				'hand_id' => $hand['hand_id'],
				'position' => $hand['position'],
				'cards' => $hand['hero_cards'],
				'board' => $hand['board'],
				'actions' => array_map(function($action) {
					$parts = explode(':', $action);
					return [
						'street' => $parts[0],
						'type' => $parts[1],
						'amount' => $parts[2] ? (float)$parts[2] : null
					];
				}, explode('|', $hand['actions']))
			];
		}, $playerHistory);
	}
	unset($player);

	// 4. Получаем историю действий текущей раздачи
	$actionsStmt = $pdo->prepare("
        SELECT a.street, a.action_type, a.amount, a.sequence_num,
               p.player_id, p.nickname
        FROM actions a
        JOIN players p ON a.player_id = p.player_id
        WHERE a.hand_id = ?
        ORDER BY a.sequence_num
    ");
	if (!$actionsStmt->execute([$input['hand_id']])) {
		throw new Exception("Failed to execute actions query");
	}
	$actionHistory = $actionsStmt->fetchAll();

	// 5. Получаем информацию о showdown (если есть)
	$showdownStmt = $pdo->prepare("
        SELECT s.player_id, s.cards, p.nickname
        FROM showdowns s
        JOIN players p ON s.player_id = p.player_id
        WHERE s.hand_id = ?
    ");
	if (!$showdownStmt->execute([$input['hand_id']])) {
		throw new Exception("Failed to execute showdown query");
	}
	$showdownData = $showdownStmt->fetchAll();

	// Формируем данные для ИИ
	$analysisData = [
		'hand_id' => (int)$input['hand_id'],
		'current_street' => $input['current_street'],
		'hero' => [
			'position' => $handData['hero_position'],
			'stack' => round($handData['hero_stack'], 1),
			'cards' => $handData['hero_cards']
		],
		'board' => $handData['board'],
		'players' => array_map(function($player) {
			$playerData = [
				'id' => (int)$player['player_id'],
				'nickname' => $player['nickname'],
				'stack' => round($player['current_stack'], 1),
				'stats' => [
					'vpip' => round($player['vpip'], 1),
					'pfr' => round($player['pfr'], 1),
					'af' => round($player['af'], 1),
					'afq' => round($player['afq'], 1),
					'three_bet' => round($player['three_bet'], 1)
				],
				'history' => array_map(function($history) {
					return [
						'hand_id' => (int)$history['hand_id'],
						'position' => $history['position'],
						'cards' => $history['cards'],
						'board' => $history['board'],
						'actions' => array_map(function($action) {
							return [
								'street' => substr($action['street'], 0, 1),
								'type' => substr($action['type'], 0, 1),
								'amount' => $action['amount'] ? round($action['amount'], 1) : null
							];
						}, $history['actions'])
					];
				}, $player['history'] ?? [])
			];
			return $playerData;
		}, $players),
		'actions' => array_map(function($action) {
			return [
				'street' => substr($action['street'], 0, 1),
				'player_id' => (int)$action['player_id'],
				'type' => substr($action['action_type'], 0, 1),
				'amount' => $action['amount'] ? round($action['amount'], 1) : null
			];
		}, $actionHistory),
		'showdown' => $showdownData ? array_map(function($sd) {
			return [
				'player_id' => (int)$sd['player_id'],
				'cards' => $sd['cards']
			];
		}, $showdownData) : null
	];

	$content = "Ты — профессиональный покерный AI. Это холдем онлайн турнир Bounty 8 max.";
	$content = "Стадия турнира " . $input['stady'] . ".";
	$content .= "Отвечай максимально коротко: действие (если рейз, то сколько) | короткое описание.";
	$content .= json_encode($analysisData);

	$api_key = 'sk-JBDhoWZZwZSn8q2xmqmi9zETz12StFzC';
	$url = 'https://api.proxyapi.ru/openai/v1/chat/completions';
	$headers = ['Content-Type: application/json', 'Authorization: Bearer ' . $api_key];

	$ch = curl_init($url);
	curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
	curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
	curl_setopt($ch, CURLOPT_POST, true);
	curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode([
		'model' => 'gpt-4.1',
		'messages' => [[ 'role' => 'user', 'content' => $content ]],
		'temperature' => 0.3
	]));
	$apiResponse = curl_exec($ch);

	if (curl_errno($ch)) {
		throw new Exception('Ошибка cURL: ' . curl_error($ch));
	}

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
	echo json_encode($response);
}
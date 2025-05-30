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

	// Проверяем последовательность улиц
	$validStreets = ['preflop', 'flop', 'turn', 'river'];
	if (!in_array($input['current_street'], $validStreets)) {
		throw new Exception("Invalid street: " . $input['current_street']);
	}

	// Создаем подключение к БД
	$pdo = new PDO(
		"mysql:host=".DB_HOST.";dbname=".DB_NAME.";charset=utf8mb4",
		DB_USER,
		DB_PASS,
		[
			PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
			PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
		]
	);

	// 1. Получаем основную информацию о раздаче
	$handStmt = $pdo->prepare("
        SELECT hero_position, hero_stack, hero_cards, board, is_completed, stacks 
        FROM hands 
        WHERE hand_id = ?
    ");
	$handStmt->execute([$input['hand_id']]);
	$handData = $handStmt->fetch();
	if (!$handData) throw new Exception("Hand not found");

	// 2. Получаем активных игроков и их последние действия
	$playersStmt = $pdo->prepare("
        SELECT 
            p.player_id, p.nickname, 
            ROUND(p.vpip,1) as vpip, ROUND(p.pfr,1) as pfr, 
            ROUND(p.af,1) as af, ROUND(p.three_bet,1) as three_bet,
            a.current_stack, a.action_type as last_action
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

	// 3. Получаем историю действий текущей раздачи с расчетом банка
	$actionsStmt = $pdo->prepare("
        SELECT 
            a.street, 
            SUBSTRING(a.action_type, 1, 1) as act,
            a.amount,
            p.player_id,
            p.nickname,
            a.sequence_num
        FROM actions a
        JOIN players p ON a.player_id = p.player_id
        WHERE a.hand_id = ?
        ORDER BY a.sequence_num
    ");
	$actionsStmt->execute([$input['hand_id']]);
	$actions = $actionsStmt->fetchAll();

	// Рассчитываем банк для каждой улицы
	$streetPots = [
		'preflop' => 0,
		'flop' => 0,
		'turn' => 0,
		'river' => 0
	];
	$currentPot = 0;
	$actionHistory = [];

	foreach ($actions as $action) {
		if ($action['amount'] > 0) {
			$currentPot += $action['amount'];
		}
		$streetPots[$action['street']] = $currentPot;

		$actionHistory[] = [
			's' => substr($action['street'], 0, 1), // street
			'p' => (int)$action['player_id'], // player_id
			'a' => $action['act'], // action type
			'v' => $action['amount'] ? round($action['amount'], 1) : null, // value
			'r' => $action['amount'] ? round($action['amount'] / $currentPot, 2) : null // ratio to pot
		];
	}

	// 4. Получаем сокращенную историю последних 5 рук для каждого игрока
	foreach ($players as &$player) {
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
                            IFNULL(a.amount, 0)
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
            LIMIT 5
        ");
		$historyStmt->execute([$player['player_id'], $input['hand_id'], $player['player_id']]);
		$player['hist'] = $historyStmt->fetchAll();
	}
	unset($player);

	// 5. Формируем компактные данные для ИИ
	$analysisData = [
		'id' => (int)$input['hand_id'],
		'street' => substr($input['current_street'], 0, 1),
		'hero' => [
			'pos' => $handData['hero_position'],
			'stack' => round($handData['hero_stack'], 1),
			'cards' => $handData['hero_cards']
		],
		'board' => $handData['board'],
		'pot' => $streetPots,
		'players' => array_map(function($p) {
			return [
				'id' => (int)$p['player_id'],
				'name' => $p['nickname'],
				'stack' => round($p['current_stack'], 1),
				'stats' => [
					'vpip' => $p['vpip'],
					'pfr' => $p['pfr'],
					'af' => $p['af'],
					'3b' => $p['three_bet']
				],
				'last' => $p['last_action'][0] ?? 'f', // last action first letter
				'hist' => array_map(function($h) {
					$actions = [];
					if (!empty($h['acts'])) {
						foreach (explode('|', $h['acts']) as $act) {
							$parts = explode(':', $act);
							$actions[] = [
								's' => $parts[0], // street
								'a' => $parts[1], // action
								'v' => $parts[2] > 0 ? (float)$parts[2] : null // value
							];
						}
					}
					return [
						'id' => (int)$h['hand_id'],
						'pos' => $h['pos'],
						'cards' => $h['cards'],
						'acts' => $actions
					];
				}, $p['hist'] ?? [])
			];
		}, $players),
		'acts' => $actionHistory
	];

	// Формируем запрос для ИИ
	$content = "Ты — профессиональный покерный AI в турнире Bounty 8 max. Стадия: " . ($input['stage'] ?? 'unknown') . ".\n";
	$content .= "Отвечай максимально коротко: действие (если рейз, то сколько) | короткое описание (буквально несколько слов).\n";
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
		'analysis' => $analysisData // optional, можно убрать в продакшене
	];

} catch (Exception $e) {
	$response['error'] = $e->getMessage();
} finally {
	if (isset($ch)) curl_close($ch);
	echo json_encode($response, JSON_UNESCAPED_UNICODE);
}
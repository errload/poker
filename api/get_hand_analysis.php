<?php
header('Content-Type: application/json');
require_once __DIR__.'/../db/config.php';

$response = ['success' => false, 'error' => null];

try {
	// Validate input
//	$input = json_decode(file_get_contents('php://input'), true);
//	if (!$input) throw new Exception('Invalid JSON input');

	$input = [
		'hand_id' => 4,
		'current_street' => 'preflop',
		'hero_position' => 'MP',
		'hero_id' => '999999',
		'hero_nickname' => 'Player999999'
	];

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

	// 1. Получение основной информации о раздаче
	$handStmt = $pdo->prepare("
        SELECT hero_position, hero_stack, hero_cards, board, is_completed 
        FROM hands 
        WHERE hand_id = :hand_id
    ");
	$handStmt->execute([':hand_id' => $input['hand_id']]);
	$handInfo = $handStmt->fetch();

	if (!$handInfo) {
		throw new Exception("Раздача не найдена");
	}

	// 2. Получение информации о шоудауне
	$showdownStmt = $pdo->prepare("
		SELECT s.player_id, p.nickname, s.hand_id, s.cards 
		FROM showdown s
		JOIN players p ON s.player_id = p.player_id
		ORDER BY s.created_at DESC
		LIMIT 1000 -- можно ограничить количество записей для производительности
	");
	$showdownStmt->execute();
	$showdownInfo = $showdownStmt->fetchAll();

	// 3. Получение игроков в текущей раздаче
	$currentHandPlayersStmt = $pdo->prepare("
        SELECT DISTINCT a.player_id, p.nickname, a.position 
        FROM actions a
        JOIN players p ON a.player_id = p.player_id
        WHERE a.hand_id = :hand_id
        ORDER BY 
            CASE a.position
                WHEN 'BTN' THEN 1
                WHEN 'SB' THEN 2
                WHEN 'BB' THEN 3
                WHEN 'UTG' THEN 4
                WHEN 'UTG+1' THEN 5
                WHEN 'MP' THEN 6
                WHEN 'HJ' THEN 7
                WHEN 'CO' THEN 8
                ELSE 9
            END
    ");
	$currentHandPlayersStmt->execute([':hand_id' => $input['hand_id']]);
	$currentHandPlayers = $currentHandPlayersStmt->fetchAll();

	// 4. Получение действий в раздаче
	$handActionsStmt = $pdo->prepare("
        SELECT 
            player_id, action_type, amount, street, sequence_num, 
            is_aggressive, is_voluntary, is_cbet, is_steal
        FROM actions
        WHERE hand_id = :hand_id
        ORDER BY sequence_num
    ");
	$handActionsStmt->execute([':hand_id' => $input['hand_id']]);
	$handActions = $handActionsStmt->fetchAll();

	// 5. Получение всех игроков (исключая героя)
	$allPlayersStmt = $pdo->prepare("
        SELECT player_id, nickname, 
               vpip, pfr, af, afq, three_bet, wtsd, hands_played, showdowns,
               preflop_raises, postflop_raises, check_raises, cbet, fold_to_cbet,
               aggressive_actions, passive_actions, steal_attempt, steal_success,
               postflop_raise_pct, check_raise_pct,
               preflop_aggression, flop_aggression, turn_aggression, river_aggression,
               last_seen, created_at
        FROM players
        WHERE player_id NOT IN (
            SELECT player_id FROM actions 
            WHERE hand_id = :hand_id 
            AND position = :hero_position
        )
        ORDER BY last_seen DESC
    ");
	$allPlayersStmt->execute([
		':hand_id' => $input['hand_id'],
		':hero_position' => $input['hero_position']
	]);
	$allPlayers = $allPlayersStmt->fetchAll();

	// Подготовка ответа
	$response = [
		'hand_id' => $input['hand_id'],
		'current_street' => $input['current_street'],
		'board' => $handInfo['board'] ?? null,
		'hero' => [
			'id' => $input['hero_id'] ?? null, // Используем переданный ID героя
			'nickname' => $input['hero_nickname'] ?? null, // Используем переданный никнейм
			'cards' => $handInfo['hero_cards'],
			'position' => $input['hero_position'],
			'stack' => $handInfo['hero_stack']
		],
		'players' => [],
		'pots' => [
			'preflop' => 0,
			'flop' => 0,
			'turn' => 0,
			'river' => 0
		],
		'street_actions' => [
			'preflop' => [],
			'flop' => [],
			'turn' => [],
			'river' => []
		],
		'showdown' => []
	];

	// Получаем ID и никнейм героя из текущей раздачи (если не были переданы)
	if (empty($response['hero']['id'])) {
		foreach ($currentHandPlayers as $player) {
			if ($player['position'] === $input['hero_position']) {
				$response['hero']['id'] = $player['player_id'];
				$response['hero']['nickname'] = $player['nickname'];
				break;
			}
		}
	}

	// Запрос для получения всех игроков (исключая героя по ID)
	$allPlayersStmt = $pdo->prepare("
		SELECT player_id, nickname, 
			   vpip, pfr, af, afq, three_bet, wtsd, hands_played, showdowns,
			   preflop_raises, postflop_raises, check_raises, cbet, fold_to_cbet,
			   aggressive_actions, passive_actions, steal_attempt, steal_success,
			   postflop_raise_pct, check_raise_pct,
			   preflop_aggression, flop_aggression, turn_aggression, river_aggression,
			   last_seen, created_at
		FROM players
		WHERE player_id != :hero_id
		ORDER BY last_seen DESC
	");
	$allPlayersStmt->execute([
		':hero_id' => $response['hero']['id'] // Фильтруем по ID героя
	]);
	$allPlayers = $allPlayersStmt->fetchAll();

	// Получаем список игроков, которые уже совершили действия в текущей раздаче
	$actedPlayersStmt = $pdo->prepare("
		SELECT DISTINCT player_id 
		FROM actions 
		WHERE hand_id = :hand_id
	");
	$actedPlayersStmt->execute([':hand_id' => $input['hand_id']]);
	$actedPlayers = $actedPlayersStmt->fetchAll(PDO::FETCH_COLUMN);

	// Добавляем игроков в ответ (уже без героя)
	foreach ($allPlayers as $player) {
		$playerData = [
			'id' => $player['player_id'],
			'nickname' => $player['nickname'],
			'stats' => [
				'vpip' => $player['vpip'],
				'pfr' => $player['pfr'],
				'af' => $player['af'],
				'afq' => $player['afq'],
				'three_bet' => $player['three_bet'],
				'wtsd' => $player['wtsd'],
				'hands_played' => $player['hands_played'],
				'showdowns' => $player['showdowns'],
				'preflop_raises' => $player['preflop_raises'],
				'postflop_raises' => $player['postflop_raises'],
				'check_raises' => $player['check_raises'],
				'cbet' => $player['cbet'],
				'fold_to_cbet' => $player['fold_to_cbet'],
				'steal_attempt' => $player['steal_attempt'],
				'steal_success' => $player['steal_success'],
				'postflop_raise_pct' => $player['postflop_raise_pct'],
				'check_raise_pct' => $player['check_raise_pct'],
				'preflop_aggression' => $player['preflop_aggression'],
				'flop_aggression' => $player['flop_aggression'],
				'turn_aggression' => $player['turn_aggression'],
				'river_aggression' => $player['river_aggression']
			],
			'in_current_hand' => false,
			'has_acted' => false, // По умолчанию игрок еще не действовал
			'position' => null
		];

		// Проверяем участие в текущей раздаче
		foreach ($currentHandPlayers as $handPlayer) {
			if ($handPlayer['player_id'] === $player['player_id']) {
				$playerData['in_current_hand'] = true;
				$playerData['position'] = $handPlayer['position'];
				$playerData['has_acted'] = in_array($player['player_id'], $actedPlayers);
				break;
			}
		}

		$response['players'][] = $playerData;
	}

	// Добавляем информацию о шоудауне с картами
	foreach ($showdownInfo as $player) {
		$response['showdown'][] = [
			'player_id' => $player['player_id'],
			'nickname' => $player['nickname'],
			'hand_id' => $player['hand_id'],
			'cards' => $player['cards']
		];
	}

	// Добавляем действия по улицам
	foreach ($handActions as $action) {
		$street = $action['street'];
		$playerNickname = '';

		foreach ($allPlayers as $player) {
			if ($player['player_id'] === $action['player_id']) {
				$playerNickname = $player['nickname'];
				break;
			}
		}

		$response['street_actions'][$street][] = [
			'player' => $playerNickname,
			'action' => $action['action_type'],
			'amount' => $action['amount'],
			'is_aggressive' => $action['is_aggressive'],
			'is_voluntary' => $action['is_voluntary'],
			'is_cbet' => $action['is_cbet'],
			'is_steal' => $action['is_steal']
		];

		if (in_array($action['action_type'], ['bet', 'raise', 'all-in']) && $action['amount'] > 0) {
			$response['pots'][$street] += $action['amount'];
		}
	}

	die(print_r($response));

















	$analysisData = [];

	$content = '
        Ты — профессиональный покерный ИИ. Анализируй раздачу:
        Отвечай максимально коротко: (чек, колл, рейз X BB) | Краткое описание (3-4 слова)
    ';

	$api_key = 'sk-JBDhoWZZwZSn8q2xmqmi9zETz12StFzC';
	$url = 'https://api.proxyapi.ru/openai/v1/chat/completions';
	$headers = ['Content-Type: application/json', 'Authorization: Bearer ' . $api_key];

	$ch = curl_init($url);
	curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
	curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
	curl_setopt($ch, CURLOPT_POST, true);
	curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode([
		'model' => 'gpt-4o',
		'messages' => [[ 'role' => 'user', 'content' => $content ]],
		'temperature' => 0.3
	]));
	$response = curl_exec($ch);

	if (curl_errno($ch)) echo json_encode('Ошибка cURL: ' . curl_error($ch));
	else {
		$response = $response->choices[0]->message->content;
		echo json_encode($response = [
			'success' => true,
			'analysis' => $analysisData,
			'data' => trim($response->choices[0]->message->content)
		]);
	}

} catch (Exception $e) {
	$response['error'] = $e->getMessage();
} finally {
	if (isset($ch)) curl_close($ch);
	echo json_encode($response, JSON_UNESCAPED_UNICODE);
}

?>
<?php
header('Content-Type: application/json');
require_once __DIR__.'/../db/config.php';

$response = ['success' => false, 'error' => null];

try {
	// Validate input
	$input = json_decode(file_get_contents('php://input'), true);
	if (!$input) throw new Exception('Invalid JSON input');

//	$input = [
//		'hand_id' => 1,
//		'current_street' => 'preflop',
//		'hero_position' => 'MP',
//		'hero_id' => '999999',
//		'hero_nickname' => 'Player999999',
//		'stady' => 'ранняя'
//	];

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
        LIMIT 1000
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

	// Подготовка ответа
	$response = [
		'hand_id' => $input['hand_id'],
		'current_street' => $input['current_street'],
		'board' => $handInfo['board'] ?? null,
		'hero' => [
			'id' => $input['hero_id'] ?? null,
			'name' => $input['hero_nickname'] ?? null,
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
		'actions' => [
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
				$response['hero']['name'] = $player['nickname'];
				break;
			}
		}
	}

	// Запрос для получения всех игроков (исключая героя по ID)
	$allPlayersStmt = $pdo->prepare("
		SELECT 
			player_id, 
			nickname, 
			vpip, 
			pfr, 
			af, 
			afq, 
			three_bet, 
			wtsd, 
			hands_played, 
			showdowns,
			preflop_raises, 
			postflop_raises, 
			check_raises, 
			cbet, 
			fold_to_cbet,
			aggressive_actions, 
			passive_actions, 
			steal_attempt, 
			steal_success,
			postflop_raise_pct, 
			check_raise_pct,
			preflop_aggression, 
			flop_aggression, 
			turn_aggression, 
			river_aggression,
			last_seen, 
			created_at,
			CASE WHEN player_id = :hero_id THEN 1 ELSE 0 END as is_hero
		FROM players
		ORDER BY 
			CASE WHEN player_id = :hero_id THEN 0 ELSE 1 END,
			last_seen DESC
	");
	$allPlayersStmt->execute([':hero_id' => $response['hero']['id']]);
	$allPlayers = $allPlayersStmt->fetchAll();

	// Добавляем игроков в ответ
	foreach ($allPlayers as $player) {
		if ($player['hands_played'] < 10) continue;
		if ((int)$player['id'] === (int)$input['hero_id']) continue;

		$playerData = [
			'id' => $player['player_id'],
			'name' => $player['nickname'],
			'stats' => [
				'vpip' => $player['vpip'] ?? 0,
				'pfr' => $player['pfr'] ?? 0,
				'aggression_factor' => $player['af'] ?? 0,
				'aggression_frequency' => $player['afq'] ?? 0,
				'three_bet' => $player['three_bet'] ?? 0,
				'went_to_showdown' => $player['wtsd'] ?? 0,
				'hands_played' => $player['hands_played'] ?? 0,
				'showdowns' => $player['showdowns'] ?? 0,
				'preflop_raises' => $player['preflop_raises'] ?? 0,
				'postflop_raises' => $player['postflop_raises'] ?? 0,
				'check_raises' => $player['check_raises'] ?? 0,
				'cbet' => $player['cbet'] ?? 0,
				'fold_to_cbet' => $player['fold_to_cbet'] ?? 0,
				'steal_attempt' => $player['steal_attempt'] ?? 0,
				'steal_success' => $player['steal_success'] ?? 0,
				'postflop_raise_percent' => $player['postflop_raise_pct'] ?? 0,
				'check_raise_percent' => $player['check_raise_pct'] ?? 0,
				'preflop_aggression' => $player['preflop_aggression'] ?? 0,
				'flop_aggression' => $player['flop_aggression'] ?? 0,
				'turn_aggression' => $player['turn_aggression'] ?? 0,
				'river_aggression' => $player['river_aggression'] ?? 0
			],
			'position' => null,
			'stats_reliable' => ($player['hands_played'] >= 50)
		];

		// Проверяем участие в текущей раздаче
		foreach ($currentHandPlayers as $handPlayer) {
			if ($handPlayer['player_id'] === $player['player_id']) {
				$playerData['position'] = $handPlayer['position'];
				break;
			}
		}

		$response['players'][] = $playerData;
	}

	// Добавляем информацию о шоудауне
	foreach ($showdownInfo as $player) {
		$response['showdown'][] = [
			'player_id' => $player['player_id'],
			'name' => $player['nickname'],
			'hand_id' => $player['hand_id'],
			'cards' => $player['cards']
		];
	}

	// Добавляем действия по улицам и рассчитываем банки
	foreach ($handActions as $action) {
		$street = strtolower($action['street']);

		// Находим никнейм игрока
		$playerNickname = '';
		foreach ($allPlayers as $player) {
			if ($player['player_id'] === $action['player_id']) {
				$playerNickname = $player['nickname'];
				break;
			}
		}

		// Добавляем действие
		$response['actions'][$street][] = [
			'player' => $playerNickname,
			'action' => $action['action_type'],
			'amount' => $action['amount'],
			'is_aggressive' => $action['is_aggressive'],
			'is_voluntary' => $action['is_voluntary'],
			'is_cbet' => $action['is_cbet'],
			'is_steal' => $action['is_steal']
		];

		// Увеличиваем банк для текущей улицы
		if (in_array($action['action_type'], ['bet', 'raise', 'all-in']) && $action['amount'] > 0) {
			$response['pots'][$street] += $action['amount'];
		}
	}

	// Формируем запрос к AI
	$analysisData = json_encode($response, JSON_UNESCAPED_UNICODE);
	$stady = $input['stady'];
	$content = "
		Стадия турнира - $stady.
        Ты — профессиональный покерный ИИ-ассистент для турниров Texas Hold'em Bounty 8 max.\n
        Твоя задача — давать максимально точные и агрессивные рекомендации, основываясь на:\n
        1. Анализе силы руки:\n
		- Четко определяй реальный потенциал руки героя (не путай дро оппонента с дро героя)\n
		- Учитывай опасные борды (монтоны, стрит-дро, парные)\n
		- Различай сильные дро (nut flush draw, OESD) и слабые\n
		2. Агрессивной стратегии:\n
		- Рекомендуй фолд против агрессивных игроков с узким диапазоном\n
		- Давление на слабых оппонентов (steal, c-bet, squeeze)\n
		- Избегай пассивных линий (чек/колл без веской причины)\n
		- На префлопе: чек ТОЛЬКО для BB без ставок, иначе фолд/колл/рейз\n
		3. Чтении оппонентов:\n
		- Учитывай статистику (VPIP/PFR/AF) каждого игрока\n
		- Выявляй тайтов (fold to 3bet > 60%) и лузовых (VPIP > 35%)\n
		- Адаптируйся к стадии турнира (ранняя/средняя/поздняя)\n
		4. Анализ showdown\n
		- Изучи карты игроков в шоудауне (поле 'showdown' в данных)
		- Определи типичные диапазоны рук для каждого оппонента (какие руки показывают, с какими доходят до ривера)
		5. Размерах ставок:\n
		- Рекомендуй рейзы против лузовых\n
		- 3bet против steal-попыток\n
		- Размер c-bet 50-75% банка\n
		Формат ответа: (действие: фолд/чек/колл/рейз X BB) | (Обоснование, буквально в 3-5 словах)\n
		Примеры правильных рекомендаций:\n
		Рейз 2.5BB | Давление на лузового UTG\n
		Фолд | Слабый KJo против 3bet тайта\n
		Колл | Nut flush draw с odds\n
        $analysisData
    ";

	$api_key = 'sk-JBDhoWZZwZSn8q2xmqmi9zETz12StFzC';
	$url = 'https://api.proxyapi.ru/openai/v1/chat/completions';
	$headers = ['Content-Type: application/json', 'Authorization: Bearer ' . $api_key];

	$ch = curl_init($url);
	curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
	curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
	curl_setopt($ch, CURLOPT_POST, true);
	curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode([
		'model' => 'gpt-4.1-mini',
		'messages' => [[ 'role' => 'user', 'content' => $content ]],
		'temperature' => 0.3
	]));
	$apiResponse = curl_exec($ch);

	if (curl_errno($ch)) {
		$response['error'] = 'Ошибка cURL: ' . curl_error($ch);
	} else {
		$apiData = json_decode($apiResponse, true);
		if (isset($apiData['choices'][0]['message']['content'])) {
			$response = [
				'success' => true,
				'analysis' => $response,
				'data' => trim($apiData['choices'][0]['message']['content'])
			];
		} else {
			$response['error'] = 'Неверный формат ответа от API';
		}
	}

} catch (Exception $e) {
	$response['error'] = $e->getMessage();
} finally {
	if (isset($ch)) curl_close($ch);
	echo json_encode($response, JSON_UNESCAPED_UNICODE);
}

?>
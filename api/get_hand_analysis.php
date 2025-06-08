<?php
header('Content-Type: application/json');
require_once __DIR__.'/../db/config.php';

$response = ['success' => false, 'error' => null];

try {
	// Validate input
	$input = json_decode(file_get_contents('php://input'), true);
	if (!$input) throw new Exception('Invalid JSON input');

//	$input = [
//		'hand_id' => 3,
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

	// Вспомогательные функции
	function getLastAggressiveAction($actions) {
		$streets = ['preflop', 'flop', 'turn', 'river'];
		$lastAction = null;

		foreach ($streets as $street) {
			if (!empty($actions[$street])) {
				foreach (array_reverse($actions[$street]) as $action) {
					if (in_array($action['action'], ['raise', 'all-in', 'bet'])) {
						return "{$action['player']} {$action['action']} {$action['amount']} бб";
					}
				}
			}
		}

		return 'Нет агрессивных действий';
	}

	function getEffectiveStack($response) {
		$heroStack = $response['hero']['stack'];
		$minStack = $heroStack;

		foreach ($response['players'] as $player) {
			if ($player['stack'] < $minStack) {
				$minStack = $player['stack'];
			}
		}

		return min($heroStack, $minStack);
	}

	function calculatePotOdds($response) {
		$totalPot = 0;
		$callAmount = 0;

		foreach ($response['actions'] as $street => $actions) {
			foreach ($actions as $action) {
				$totalPot += $action['amount'];
				if ($action['action'] == 'raise') {
					$callAmount = $action['amount'];
				}
			}
		}

		return $callAmount > 0 ? round($callAmount / ($totalPot + $callAmount) * 100) . '%' : '0%';
	}

	// Формируем запрос к AI
	$analysisData = json_encode($response, JSON_UNESCAPED_UNICODE);
	$board = $response['board'] ?? 'нет карт';
	$content = "
		### УНИВЕРСАЛЬНЫЙ АЛГОРИТМ АНАЛИЗА ДЛЯ ВСЕХ УЛИЦ
		**ПРАВИЛА АНАЛИЗА КАРТ**:
		- Формат карт: [ранг][масть] (например: QsQh, AdKs, Th9c)
		- Сила руки определяется по таблице силы префлоп-рук (см. ниже)
		**КЛЮЧЕВЫЕ ФАКТОРЫ ДЛЯ ПРИНЯТИЯ РЕШЕНИЙ**:
		- Сила руки (см. классификацию ниже)
		- Позиция (BTN > CO > MP > UTG)
		- Действия оппонентов (колл/рейз/3-бет)
		- Размер ставок (в бб)
		- Эффективный стек
		- Стадия турнира (ранняя/средняя/поздняя)
		- Статистика оппонентов (VPIP, PFR, AF)
		**ОЦЕНКА РУКИ ГЕРОЯ**:
		- Всегда проверяй, не формируют ли карты героя какую-то комбинацию или совпадение с бордом
		Пример:  
		- Борд: 9hThJd Td Kc + рука QsQh → стрит (9-T-J-Q-K).  
		- Борд: Ah5h9h + рука 2h7h → флеш (5 карт одной масти).  
		- Если рука героя завершает какую-то комбинацию — это СИЛЬНАЯ рука. 
		**КЛАССИФИКАЦИЯ СИЛЫ РУК**:
		Префлоп:
		- TOP 5%: AA, KK, QQ, AKs
		- TOP 10%: JJ, TT, AQs, AKo
		- TOP 20%: 99, 88, AJs, KQs
		- SPEC: 22-77, suited connectors (T9s+), Axs
		Флоп/Терн/Ривер:
		- MONSTER: Флеш/Стрит/Сет/Две пары+
		- STRONG: Топ-пара+ (с хорошим кикером)
		- MEDIUM: Средняя пара, дро (8+ аутсов)
		- WEAK: Слабые пары, дро (<8 аутсов)
		**СТРАТЕГИЯ ПО УЛИЦАМ**:
		Префлоп:
		- TOP 5%: 3-бет/рейз (кроме UTG)
		- TOP 10%: Рейз/колл против рейза
		- TOP 20%: Колл/фолд в зависимости от позиции
		- SPEC: Колл только в поздних позициях
		Флоп:
		- MONSTER: Агрессия (рейз/донк)
		- STRONG: Контролируемая агрессия
		- MEDIUM: Чек/колл с дро
		- WEAK: Фолд против агрессии
		Терн:
		- Анализ новых аутсов
		- Переоценка силы руки
		- Учет текстуры борда
		Ривер:
		- Финальная оценка силы
		- Анализ линии оппонента
		- Блеф только с хорошей историей
		**РЕКОМЕНДАЦИИ ПРОТИВ АГРЕССИИ**:
		- Против рейза: 3-бет с TOP 10%+, колл с MEDIUM+
		- Против 3-бета: Колл только с MONSTER/TOP 5%
		- Против 4-бета: Пуш только с AA/KK
		- Если на текущей улице все игроки прочекали, рекомендуй чек или бет (если есть шанс выиграть банк)  
		- Рекомендация фолд допустима ТОЛЬКО при агрессивных действиях оппонентов (рейз, бет, олл-ин)
		- Рекомендация чек на префлопе допустима ТОЛЬКО в позиции BB, если не было агрессивных действиях оппонентов (рейз, бет, олл-ин)
		**ФОРМАТ ОТВЕТА**:
		Отвечай максимально коротко: [Действие] [Размер] | [Обоснование в несколько слов]
		Примеры:
		- 3-бет 22бб | QQ против рейза CO
		- Колл | TdTs + флеш-дро
		- Фолд | Слабый дро против 2 баррелей
		**ТЕКУЩАЯ СИТУАЦИЯ**:
		- Улица: {$input['current_street']}
		- Герой: {$response['hero']['cards']} ({$input['hero_position']})
		- Борд: {$board}
		- Последняя агрессия: " . getLastAggressiveAction($response['actions']) . "
		- Эффективный стек: " . getEffectiveStack($response) . " бб
		- Pot odds: " . calculatePotOdds($response) . "
		- Стадия: {$input['stady']}
	";

	// Добавляем историю действий если нужно
	foreach ($response['actions'] as $street => $actions) {
		if (!empty($actions)) {
			$content .= "\n{$street}:\n";
			foreach ($actions as $action) {
				$content .= "- {$action['player']} {$action['action']} {$action['amount']}";
				$content .= ($action['action'] !== 'check' && $action['action'] !== 'fold') ? " бб\n" : "\n";
			}
		}
	}

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
		'temperature' => 0.5
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
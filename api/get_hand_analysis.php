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
			'nickname' => $player['nickname'],
			'hand_id' => $player['hand_id'],
			'cards' => $player['cards']
		];
	}

	// Инициализация переменных для подсчета банка
	$streetBets = []; // Текущие ставки игроков на улице
	$streetPot = 0;   // Банк на текущей улице
	$lastStreet = null;

	// Добавляем действия по улицам и рассчитываем банки
	foreach ($handActions as $action) {
		$street = strtolower($action['street']);

		// Если перешли на новую улицу, сбрасываем ставки
		if ($street !== $lastStreet) {
			$streetBets = [];
			$streetPot = 0;
			$lastStreet = $street;
		}

		$playerId = $action['player_id'];
		$amount = (float)$action['amount'];
		$actionType = $action['action_type'];

		// Инициализируем ставку игрока, если еще не было
		if (!isset($streetBets[$playerId])) {
			$streetBets[$playerId] = 0;
		}

		// Находим никнейм игрока
		$playerNickname = '';
		foreach ($allPlayers as $player) {
			if ($player['player_id'] === $playerId) {
				$playerNickname = $player['nickname'];
				break;
			}
		}

		// Получаем текущую максимальную ставку на улице
		$maxBet = !empty($streetBets) ? max($streetBets) : 0;

		// Добавляем действие
		$response['actions'][$street][] = [
			'player' => $playerNickname,
			'action' => $actionType,
			'amount' => $amount,
			'is_aggressive' => $action['is_aggressive'],
			'is_voluntary' => $action['is_voluntary'],
			'is_cbet' => $action['is_cbet'],
			'is_steal' => $action['is_steal']
		];

		// Обрабатываем действие для подсчета банка
		switch ($actionType) {
			case 'bet':
				$streetPot += ($amount - $streetBets[$playerId]);
				$streetBets[$playerId] = $amount;
				break;

			case 'raise':
				$streetPot += ($amount - $streetBets[$playerId]);
				$streetBets[$playerId] = $amount;
				break;

			case 'call':
				$streetPot += ($maxBet - $streetBets[$playerId]);
				$streetBets[$playerId] = $maxBet;
				break;

			case 'all-in':
				if ($amount > $maxBet) {
					$streetPot += ($amount - $streetBets[$playerId]);
				} else {
					$streetPot += ($maxBet - $streetBets[$playerId]);
				}
				$streetBets[$playerId] = $amount;
				break;

			case 'check':
			case 'fold':
				// Не влияют на банк
				break;
		}

		// Обновляем банк на текущей улице
		$response['pots'][$street] = $streetPot;
	}

	// Вспомогательные функции
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

	function printActionHistory($actions) {
		$output = "";
		foreach ($actions as $street => $streetActions) {
			if (!empty($streetActions)) {
				$output .= "{$street}:\n";
				foreach ($streetActions as $action) {
					$betSize = ($action['action'] !== 'check' && $action['action'] !== 'fold')
						? " {$action['amount']} бб" : "";
					$output .= "- {$action['player']} {$action['action']}{$betSize}";
					if ($action['is_aggressive']) $output .= " (АГРЕССИЯ)";
					$output .= "\n";
				}
			}
		}
		return $output;
	}

	function getLastAggressionDetails($actions) {
		$aggressors = [];
		$streets = ['preflop', 'flop', 'turn', 'river'];

		foreach ($streets as $street) {
			if (!empty($actions[$street])) {
				foreach ($actions[$street] as $action) {
					if ($action['is_aggressive']) {
						$aggressors[$street] = [
							'player' => $action['player'],
							'action' => $action['action'],
							'amount' => $action['amount']
						];
					}
				}
			}
		}

		if (empty($aggressors)) return "Агрессивных действий нет";

		$output = "";
		foreach ($aggressors as $street => $details) {
			$output .= "{$street}: {$details['player']} {$details['action']} {$details['amount']}бб\n";
		}
		return $output;
	}

	function analyzeOpponentsRanges($response) {
		if (empty($response['showdown'])) return "Данные шоудауна отсутствуют";

		$ranges = [];
		foreach ($response['showdown'] as $showdown) {
			$playerId = $showdown['player_id'];
			if (!isset($ranges[$playerId])) {
				$ranges[$playerId] = [
					'nickname' => $showdown['nickname'],
					'cards' => [],
					'vpip' => 0,
					'pfr' => 0
				];

				// Находим статистику игрока
				foreach ($response['players'] as $player) {
					if ($player['id'] == $playerId) {
						$ranges[$playerId]['vpip'] = $player['stats']['vpip'];
						$ranges[$playerId]['pfr'] = $player['stats']['pfr'];
						break;
					}
				}
			}
			$ranges[$playerId]['cards'][] = $showdown['cards'];
		}

		$output = "";
		foreach ($ranges as $playerId => $data) {
			$output .= "{$data['nickname']} (VPIP: {$data['vpip']}%, PFR: {$data['pfr']}%):\n";
			$output .= "- Показанные руки: " . implode(", ", $data['cards']) . "\n";
			$output .= "- Предполагаемый диапазон: " . estimateRange($data) . "\n";
		}
		return $output;
	}

	function estimateRange($playerData) {
		$vpip = $playerData['vpip'];
		$shownHands = $playerData['cards'];

		if ($vpip < 15) return "Узкий (TT+, AQ+)";
		if ($vpip < 25) return "Умеренный (77+, AT+, KQ)";
		if ($vpip < 35) return "Широкий (22+, Ax+, Kx+)";
		return "Очень широкий (любые карты)";
	}

	// Формируем запрос к AI
	$analysisData = json_encode($response, JSON_UNESCAPED_UNICODE);
	$board = $response['board'] ?? 'нет карт';
	$content = "
		Ты — профессиональный покерный советник. Анализируй раздачу строго по шагам:\n
		Определи комбинацию героя:\n
		- Карты героя: (hero->cards)\n
		- Борд: (board)\n
		- Используй правила Техасского Холдема. Если комбинация неочевидна (например, стрит или флеш), перечисли карты, которые её формируют\n
		Проанализируй историю действий:\n
		- Префлоп: (actions->preflop)\n
		- Флоп: (actions->flop)\n
		- Терн: (actions->turn)\n
		- Ривер: (actions->river)\n
		- Определи агрессора, пассивных игроков и их паттерны (например, C-bet, чек-рейз)\n
		Оцени диапазоны оппонентов:\n
		- Учитывай их статистику (VPIP, PFR, AF) из (players)\n
		- Если есть данные шоудауна (showdown), используй их для сужения диапазона\n
		Дай рекомендацию:\n
		- Отвечай максимально коротко: [Действие (если рейз, то сколько)] | [Комбинация] + [Короткое описание] (буквально 3-5 слов)\n
		- В ответе не нужно расписывать всю последовательность действий, от тебя требуется только рекомендация к действию\n
		- Примеры:
		- Рейз 15бб | Сет (QQ) + слабая защита блайндов (PFR 10%)\n
		- Фолд | Оверпара (KK) + 2 барреля от нита (AF 4.1)\n
		- Чек-колл | Флеш-дро (9 аутов) + pot odds 25%\n
		Важно:\n
		- Если у героя стрит/флеш/сет или сильнее — НЕ рекомендуй фолд\n
		- Всегда учитывай эффективный стек и pot odds\n
		$analysisData
	";

//	die(var_dump($content));

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

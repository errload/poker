<?php
header('Content-Type: application/json');
require_once __DIR__.'/../db/config.php';

$response = ['success' => false, 'error' => null];

try {
	// Validate input
	$input = json_decode(file_get_contents('php://input'), true);
	if (!$input) throw new Exception('Invalid JSON input');

//	$input = [
//		'hand_id' => 7,
//		'current_street' => 'turn',
//		'hero_position' => 'CO',
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

	// Формируем запрос к AI
	$analysisData = json_encode($response, JSON_UNESCAPED_UNICODE);
	$content = "
Ты — профессиональный AI-анализатор покера.
Твоя задача — проанализировать раздачу и дать точную рекомендацию для героя.
Стадия турнира - {$input['stady']}.
**Борд {board}:**
- это то, что открылось на доске
- если борд есть, это постфлоп, иначе префлоп
- борд в формате [AhQs2d Kh 4s] (тут Ah - туз черва, Qs - дама пики, 2d - двойка бубна, Kh - король черва, 4s - четверка пики)
- на опасных бордах учитывай риск сильных комбинаций оппонентов
На префлопе оцени силу карманных карт исходя из позиции и ставок игроков по шкале:
- премиум-пары (AA-KK)
- Сильные руки (QQ-99), AK/AQ
- Средние пары (88-22), suited коннекторы (T9s+), AXs
- слабые руки (остальные)
- только оценка карманных карт (без борда)
На постфлопе точно определи комбинацию с учетом борда:
- четко различай пары/две пары/сеты/стриты/флеши/фулхаусы/каре/стрит флэш
- учитывай текстуру борда (монотонный, парный, стрит-дро/флэш-дро возможности)
- Не путай топ-пару со второй парой, сет с фулхаусом и т.д.
- для дро точно указывай количество аутов (флеш-дро: 9 аутов, стрит-дро: 8/4 аутов)
- всегда составляй комбинации исходя из карт героя + борда
- указывай точное название (например, не пара, а топ-пара с кикером Х, или вторая пара и т.д.)
- не путай даже похожие (сет ≠ фулхаус)
- всегда определяй опасности для героя (стрит-дро, флэш-дро, сет и т.д., которых у героя нет, или не попал, например, в масть)
- не путай флэши (5 карт одной масти), стриты (5 карт подряд разных мастей)
- для дро: сравни аутсы с pot odds (размер банка / стоимость колла)
- точно указывай количество аутов (не предлагай дро если нужно 2 карты)
- флеш-дро: 4 карты одной масти (9 аутов)
- стрит-дро: одинарный (4 аута), двойной (8 аутов), гатшот (4 аута с пропуском)
- комбинированные дро: учитывай все возможные аутсы
- на парном борде: осторожность с сетами/фуллами, на монотонном: проверять флеш-дро у оппонентов
- чек не возможен, если была ставка до героя на текущей улице
**Примеры собранных комбинаций:**
Комбинации на флопе (3 общие карты):
- сет: карты героя [7c7h], борд [7dJs2c]
- две пары: карты героя [AsKd], борд [AhKc4s]
- флэш-дро (4 карты одной масти, не хватает пятой): карты героя [Qh9h], борд [Ah5h2c]
- стрит-дро (открытое, 4 карты подряд, не хватает одной): карты героя [6s7d], борд [5h8cKd]
- гатшот (нужна 1 карта для стрита, но не открытое дро): карты героя [9cTd], борд [Jh7s2d] (нужна Q или 8)
- бэкдор-флэш (нужно две масти подряд для флеша): карты героя [Ac3c], борд [Kd7c2h]
Комбинации на терне (4 общие карты):
- флэш завершился: карты героя [KhQh], борд [Ah5h2d Jh]
- стрит завершился: карты героя [8s9d], борд [7cThJd 6s]
Комбинации на ривере (5 общих карт):
- две пары улучшились до фулл-хауса (пара + тройка): карты героя [AdQc], борд [As5hQd 3c Qs]
- cтрит (5 карт подряд): карты героя [6s7d], борд [5h8c9d 4s Tc]
- Старшая пара (One Pair): карты героя [AsJd], борд [Qh3c8d 2s Ah]
Разбор пар и двух пар:
- первая пара (старшая): карты героя [KdQh], борд [Kh7s2d] (пара королей)
- вторая пара (младшая): карты героя [8h9c], борд [8d4sJs] (пара восьмерок)
Блеф и полублеф:
- полублеф (есть дро, но пока нет сильной руки): карты героя [6h7h], борд [5d8sKh] (может изображать пару королей)
- чистый блеф (нет ничего, но агрессивная ставка): карты героя [Ad2c], борд [7s9h3d] (ставка с высоким аутом — асс)
**Текущая улица {current_street}:**
- это то, на какой улице мы сейчас находимся: префлоп, постфлоп (флоп, терн, ривер)
- на каждой улице так же переоценивай силу руки с учетом карт героя + открывшихся карт текущей улицы
- учитывай реальные возможности улучшения
- на каждой удице читай последовательные действия игроков, и перед этим так же читай их прошлые действия на предыдущих улицах
- на префлопе check возможен только на BB, если до этого не было повышения ставок
- на постфлопе колл возможен только если кто-то повысил ставку
**Герой {hero}:**
Тут будет информация о герое, к этому тоже относись очень внимательно:
- карты героя {cards} в формате [AhQs] (тут Ah - туз черва, Qs - дама пики)
- позиция героя {position}, оценивай в какой позиции герой, и исходя из его позиции и действий других игроков принимай решение
- стек героя {stack} - это его начальный стек на префлопе до его первого действия, не путай с оставшимся
- всегда рассчитывай текущий стек героя как: начальный стек минус все его ставки в раздаче
- не предлагай неадекватных рейзов исходя из остаточного стека героя
- размер ставки не должен превышать текущий стек героя минус всего его ставки по всем улицам
**Игроки {players}:**
- тут будет храниться информация о статистике игроков
- если игроков нет, то статистики по ним так же пока нет, играй с ними более аккуратно
- если какие то игроки есть, а других нет, то против этих других так же играй аккуратно
- у существующих игроков проверь VPIP/PFR/AF каждого игрока, кто остался в раздаче
- пассивные (AF < 2): вероятно слабые руки, агрессоры (AF > 4): могут чекать с сильными руками
- VPIP/PFR для префлоп-диапазона
- AF/postflop_raise для агрессивности
- учитывай позицию игроков: поздние позиции чаще блефуют
- сравни размер ставки с диапазоном игрока
- проверь fold_to_cbet для решения о контбете или для оценки вероятности фолда
- проверяй так же другие показатели игроков, кто как себя ведет, анализируй как против них играть
**Размер банка {pots}:**
- тут по каждой улице собирается информация по размеру вложенных блайндов в раздачу
- суммируй их, получишь общий банк, он поможет принимать решение
**Действия игроков {actions}:**
- тут последовательные действия игроков в текущей раздаче
- определи позиции игроков (OOP/IP) относительно героя, их действия (fold/call/bet/raise/check/all-in)
- кто проявляет агрессию, кто пассивен, кто сфолдил
- обращай внимание на размеры ставок, на последнюю агрессию
**Шоудаун {showdown}:**
- это карты игроков, которые дошли до ривера в предыдущих раздачах и вскрыли карты (не в текущей раздаче)
- если тут есть карты, читай по ID игрока его карты и строй предполагаемый диапазон его открытия
**Запрещенные ошибки:**
- неправильное название комбинаций (каре ≠ фулл-хаус)
- нереальные дро (только 1-карточные улучшения)
- игнорирование статистики оппонентов
- рекомендация невозможных действий
- неточный расчет стека/банка
**Формат рекомендации:**
- отвечай максимально коротко: [Действие] | [Краткое обоснование]
**Примеры корректных ответов:**
- Raise 35 BB | Топ пара + nut flush дро (15 аутов)
- Fold | Слабый кикер на опасном борде (A5 на QQ5)
- Call 12 BB | Средняя пара + pot odds 25% (остаток стека 120 BB)
- All-in 85 BB | Премиум пара (AA) против 3-бета
- Check | Нет эквити на монотонном борде
Вот текущая раздача для анализа: $analysisData
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
		'model' => 'gpt-4o-mini',
		'messages' => [[ 'role' => 'user', 'content' => $content ]],
		'temperature' => 0,
		'top_p' => 1
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

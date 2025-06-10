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
//		'stady' => 'early'
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
		if ($player['hands_played'] < 1) continue;
		if ((int)$player['player_id'] === (int)$input['hero_id']) continue;

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


// Вспомогательные функции для анализа карт и ситуаций
	function parseCards($cardString) {
		if (empty($cardString)) return [];

		$cards = [];
		$matches = [];
		preg_match_all('/\[([^\]]+)\]/', $cardString, $matches);

		if (!empty($matches[1])) {
			foreach (explode(' ', $matches[1][0]) as $card) {
				$cards[] = [
					'rank' => substr($card, 0, 1),
					'suit' => substr($card, 1, 1),
					'full' => $card
				];
			}
		}

		return $cards;
	}

	function evaluatePreflopHand($cardString) {
		$cards = parseCards($cardString);
		if (count($cards) != 2) return ['strength' => 'unknown', 'description' => 'Invalid hand'];

		$rank1 = $cards[0]['rank'];
		$rank2 = $cards[1]['rank'];
		$suit1 = $cards[0]['suit'];
		$suit2 = $cards[1]['suit'];

		$isPair = $rank1 === $rank2;
		$isSuited = $suit1 === $suit2;
		$isConnector = abs(rankToValue($rank1) - rankToValue($rank2)) <= 1;

		$highCards = ['A', 'K', 'Q', 'J', 'T'];
		$isHighCard = in_array($rank1, $highCards) || in_array($rank2, $highCards);

		if ($isPair) {
			if (in_array($rank1, ['A', 'K', 'Q'])) {
				return ['strength' => 'premium', 'description' => "Premium pair {$rank1}{$rank1}"];
			} elseif (in_array($rank1, ['J', 'T', '9'])) {
				return ['strength' => 'strong', 'description' => "Strong pair {$rank1}{$rank1}"];
			} else {
				return ['strength' => 'medium', 'description' => "Medium pair {$rank1}{$rank1}"];
			}
		}

		if ($isHighCard) {
			if (($rank1 === 'A' && $rank2 === 'K') || ($rank1 === 'K' && $rank2 === 'A')) {
				return ['strength' => 'premium', 'description' => "Premium hand AK" . ($isSuited ? 's' : 'o')];
			}

			if (in_array($rank1, ['A', 'K']) && in_array($rank2, ['Q', 'J', 'T'])) {
				return ['strength' => 'strong', 'description' => "Strong hand {$rank1}{$rank2}" . ($isSuited ? 's' : 'o')];
			}
		}

		if ($isSuited && $isConnector && $isHighCard) {
			return ['strength' => 'strong', 'description' => "Suited connector {$rank1}{$rank2}s"];
		}

		if ($isSuited) {
			return ['strength' => 'speculative', 'description' => "Suited cards {$rank1}{$rank2}s"];
		}

		if ($isConnector) {
			return ['strength' => 'speculative', 'description' => "Connector {$rank1}{$rank2}o"];
		}

		return ['strength' => 'weak', 'description' => "Weak hand {$rank1}{$rank2}"];
	}

	function evaluateHandStrength($holeCards, $boardCards, $street) {
		$hole = parseCards($holeCards);
		$board = parseCards($boardCards);
		$allCards = array_merge($hole, $board);

		if (count($hole) != 2 || empty($board)) {
			return ['strength' => 'unknown', 'description' => 'Invalid hand/board'];
		}

		// Анализ комбинаций
		$result = [
			'strength' => 'high_card',
			'description' => 'High card',
			'exact' => null,
			'outs' => 0,
			'outs_description' => null,
			'made_hand' => false,
			'draw' => false,
			'nut' => false
		];

		// Проверка комбинаций от сильных к слабым
		if ($flush = isFlush($allCards)) {
			if (isStraightFlush($allCards)) {
				$result = [
					'strength' => 'straight_flush',
					'description' => 'Straight Flush',
					'exact' => $flush,
					'nut' => isNutStraightFlush($allCards)
				];
			} else {
				$result = [
					'strength' => 'flush',
					'description' => 'Flush',
					'exact' => $flush,
					'nut' => isNutFlush($allCards)
				];
			}
		} elseif ($quads = isFourOfAKind($allCards)) {
			$result = [
				'strength' => 'four_of_a_kind',
				'description' => "Four of a kind {$quads[0]['rank']}",
				'exact' => $quads
			];
		} elseif ($fullHouse = isFullHouse($allCards)) {
			$result = [
				'strength' => 'full_house',
				'description' => "Full house {$fullHouse['trips']} over {$fullHouse['pair']}",
				'exact' => $fullHouse
			];
		} elseif ($straight = isStraight($allCards)) {
			$result = [
				'strength' => 'straight',
				'description' => "Straight to {$straight['high']}",
				'exact' => $straight,
				'nut' => isNutStraight($allCards)
			];
		} elseif ($trips = isThreeOfAKind($allCards)) {
			$result = [
				'strength' => 'three_of_a_kind',
				'description' => "Three of a kind {$trips[0]['rank']}",
				'exact' => $trips
			];
		} elseif ($twoPair = isTwoPair($allCards)) {
			$result = [
				'strength' => 'two_pair',
				'description' => "Two pair {$twoPair['high']} and {$twoPair['low']}",
				'exact' => $twoPair
			];
		} elseif ($pair = isPair($allCards)) {
			$result = [
				'strength' => 'pair',
				'description' => "Pair of {$pair[0]['rank']}",
				'exact' => $pair,
				'kicker' => getKickers($allCards, $pair)
			];
		}

		// Проверка дро
		$draws = checkDraws($hole, $board, $street);
		if (!empty($draws)) {
			$result['draw'] = true;
			$result['outs'] = array_sum(array_column($draws, 'outs'));
			$result['outs_description'] = implode(', ', array_map(function($d) {
				return $d['description'] . " (" . $d['outs'] . " outs)";
			}, $draws));
		}

		return $result;
	}

	function analyzeBoardTexture($boardCards) {
		$board = parseCards($boardCards);
		if (count($board) < 3) return 'preflop';

		$textures = [];

		// Монотонность
		$suits = array_count_values(array_column($board, 'suit'));
		if (max($suits) >= 3) {
			$textures[] = 'monotone';
		}

		// Парность
		$ranks = array_count_values(array_column($board, 'rank'));
		$pairs = array_filter($ranks, function($v) { return $v >= 2; });
		if (!empty($pairs)) {
			$textures[] = 'paired';
			if (count($pairs) >= 2) {
				$textures[] = 'multi_paired';
			}
		}

		// Стрит-возможности
		$straightPotential = hasStraightPotential($board);
		if ($straightPotential) {
			$textures[] = 'straight_draw_possible';
		}

		// Высокие карты
		$highCards = array_filter($board, function($c) {
			return in_array($c['rank'], ['A', 'K', 'Q']);
		});
		if (count($highCards) >= 2) {
			$textures[] = 'high_cards';
		}

		return !empty($textures) ? implode(', ', $textures) : 'neutral';
	}

	function calculateBoardDanger($boardCards) {
		$texture = analyzeBoardTexture($boardCards);
		$danger = 0;

		if (strpos($texture, 'monotone') !== false) $danger += 30;
		if (strpos($texture, 'paired') !== false) $danger += 20;
		if (strpos($texture, 'straight_draw_possible') !== false) $danger += 25;
		if (strpos($texture, 'high_cards') !== false) $danger += 15;

		return min($danger, 100);
	}

	function calculateEffectiveStack($heroStack, $players) {
		$minStack = $heroStack;
		foreach ($players as $player) {
			if (isset($player['stack']) && $player['stack'] < $minStack) {
				$minStack = $player['stack'];
			}
		}
		return min($heroStack, $minStack);
	}

	function calculateHeroPotCommitment($handId, $heroId) {
		if (empty($heroId)) return 0;

		global $pdo;
		$stmt = $pdo->prepare("
        SELECT SUM(amount) as total 
        FROM actions 
        WHERE hand_id = :hand_id AND player_id = :player_id
    ");
		$stmt->execute([':hand_id' => $handId, ':player_id' => $heroId]);
		$result = $stmt->fetch();
		return $result['total'] ?? 0;
	}

	function calculatePotOdds($handId, $street) {
		global $pdo;

		// Общий банк
		$stmt = $pdo->prepare("
        SELECT SUM(amount) as total 
        FROM actions 
        WHERE hand_id = :hand_id AND street = :street
    ");
		$stmt->execute([':hand_id' => $handId, ':street' => $street]);
		$pot = $stmt->fetch()['total'] ?? 0;

		// Текущая ставка для колла
		$stmt = $pdo->prepare("
        SELECT MAX(amount) as max_bet 
        FROM actions 
        WHERE hand_id = :hand_id AND street = :street
    ");
		$stmt->execute([':hand_id' => $handId, ':street' => $street]);
		$toCall = $stmt->fetch()['max_bet'] ?? 0;

		return $toCall > 0 ? round(($toCall / ($pot + $toCall)) * 100) . '%' : '0%';
	}

	function analyzePlayerTendencies($playerStats) {
		$tendencies = [];

		if ($playerStats['vpip'] < 15) $tendencies[] = 'tight';
		elseif ($playerStats['vpip'] > 30) $tendencies[] = 'loose';

		if ($playerStats['pfr'] < 10) $tendencies[] = 'passive_preflop';
		elseif ($playerStats['pfr'] > 20) $tendencies[] = 'aggressive_preflop';

		if ($playerStats['af'] < 2) $tendencies[] = 'passive_postflop';
		elseif ($playerStats['af'] > 4) $tendencies[] = 'aggressive_postflop';

		if ($playerStats['three_bet'] > 8) $tendencies[] = 'three_bet_heavy';

		if ($playerStats['fold_to_cbet'] > 70) $tendencies[] = 'folds_to_cbet';
		elseif ($playerStats['fold_to_cbet'] < 40) $tendencies[] = 'calls_cbet';

		return !empty($tendencies) ? implode(', ', $tendencies) : 'balanced';
	}

	function calculateRelativePosition($playerPos, $heroPos) {
		$positions = ['BTN', 'SB', 'BB', 'UTG', 'UTG+1', 'MP', 'HJ', 'CO'];
		$heroIndex = array_search($heroPos, $positions);
		$playerIndex = array_search($playerPos, $positions);

		if ($heroIndex === false || $playerIndex === false) return 'unknown';

		if ($playerIndex < $heroIndex) return 'in_position';
		if ($playerIndex > $heroIndex) return 'out_of_position';
		return 'same_position';
	}

	function getStreetSequence($currentStreet) {
		$streets = ['preflop', 'flop', 'turn', 'river'];
		$currentIndex = array_search($currentStreet, $streets);
		return array_slice($streets, 0, $currentIndex + 1);
	}

	function getNextToAct($handId, $currentStreet) {
		global $pdo;

		$stmt = $pdo->prepare("
        SELECT a.player_id, p.nickname, a.position 
        FROM actions a
        JOIN players p ON a.player_id = p.player_id
        WHERE a.hand_id = :hand_id AND a.street = :street
        ORDER BY a.sequence_num DESC
        LIMIT 1
    ");
		$stmt->execute([':hand_id' => $handId, ':street' => $currentStreet]);
		$lastAction = $stmt->fetch();

		return $lastAction ? [
			'player_id' => $lastAction['player_id'],
			'player_name' => $lastAction['nickname'],
			'position' => $lastAction['position']
		] : null;
	}

	function isActionRequired($handId, $street, $heroPos) {
		global $pdo;

		// Проверяем, есть ли ставки на текущей улице
		$stmt = $pdo->prepare("
        SELECT COUNT(*) as bets 
        FROM actions 
        WHERE hand_id = :hand_id AND street = :street AND action_type IN ('bet', 'raise', 'all-in')
    ");
		$stmt->execute([':hand_id' => $handId, ':street' => $street]);
		$hasBets = $stmt->fetch()['bets'] > 0;

		// Проверяем, сделал ли герой уже действие на этой улице
		$stmt = $pdo->prepare("
        SELECT COUNT(*) as actions 
        FROM actions 
        WHERE hand_id = :hand_id AND street = :street AND position = :position
    ");
		$stmt->execute([':hand_id' => $handId, ':street' => $street, ':position' => $heroPos]);
		$hasActed = $stmt->fetch()['actions'] > 0;

		return $hasBets && !$hasActed;
	}

// Дополнительные функции для анализа комбинаций
	function isFlush($cards) {
		$suits = array_count_values(array_column($cards, 'suit'));
		$flushSuit = array_search(5, $suits);
		if ($flushSuit === false) $flushSuit = array_search(4, $suits);

		if ($flushSuit !== false) {
			return array_filter($cards, function($c) use ($flushSuit) {
				return $c['suit'] === $flushSuit;
			});
		}
		return false;
	}

	function isStraightFlush($cards) {
		$flush = isFlush($cards);
		if (!$flush) return false;

		return isStraight($flush);
	}

	function isFourOfAKind($cards) {
		$ranks = array_count_values(array_column($cards, 'rank'));
		$quadRank = array_search(4, $ranks);

		if ($quadRank !== false) {
			return array_filter($cards, function($c) use ($quadRank) {
				return $c['rank'] === $quadRank;
			});
		}
		return false;
	}

	function isFullHouse($cards) {
		$ranks = array_count_values(array_column($cards, 'rank'));
		$tripsRank = array_search(3, $ranks);
		$pairRank = array_search(2, $ranks);

		if ($tripsRank !== false && $pairRank !== false) {
			return [
				'trips' => $tripsRank,
				'pair' => $pairRank
			];
		}
		return false;
	}

	function isStraight($cards) {
		$values = array_map('rankToValue', array_column($cards, 'rank'));
		$uniqueValues = array_unique($values);
		rsort($uniqueValues);

		// Проверяем обычный стрит
		$straight = findStraight($uniqueValues);
		if ($straight) return $straight;

		// Проверяем стрит с тузом как 1 (A-2-3-4-5)
		if (in_array(14, $uniqueValues)) {
			$lowValues = array_map(function($v) { return $v == 14 ? 1 : $v; }, $uniqueValues);
			rsort($lowValues);
			$straight = findStraight($lowValues);
			if ($straight) return $straight;
		}

		return false;
	}

	function findStraight($values) {
		if (count($values) < 5) return false;

		for ($i = 0; $i <= count($values) - 5; $i++) {
			if ($values[$i] - $values[$i+4] == 4) {
				return [
					'high' => valueToRank($values[$i]),
					'cards' => array_slice($values, $i, 5)
				];
			}
		}
		return false;
	}

	function isThreeOfAKind($cards) {
		$ranks = array_count_values(array_column($cards, 'rank'));
		$tripsRank = array_search(3, $ranks);

		if ($tripsRank !== false) {
			return array_filter($cards, function($c) use ($tripsRank) {
				return $c['rank'] === $tripsRank;
			});
		}
		return false;
	}

	function isTwoPair($cards) {
		$ranks = array_count_values(array_column($cards, 'rank'));
		$pairs = array_filter($ranks, function($v) { return $v >= 2; });

		if (count($pairs) >= 2) {
			$pairRanks = array_keys($pairs);
			rsort($pairRanks);
			return [
				'high' => $pairRanks[0],
				'low' => $pairRanks[1]
			];
		}
		return false;
	}

	function isPair($cards) {
		$ranks = array_count_values(array_column($cards, 'rank'));
		$pairRank = array_search(2, $ranks);

		if ($pairRank !== false) {
			return array_filter($cards, function($c) use ($pairRank) {
				return $c['rank'] === $pairRank;
			});
		}
		return false;
	}

	function checkDraws($holeCards, $boardCards, $street) {
		$draws = [];
		$allCards = array_merge($holeCards, $boardCards);

		// Флеш-дро
		$flushDraw = checkFlushDraw($holeCards, $boardCards);
		if ($flushDraw) $draws[] = $flushDraw;

		// Стрит-дро
		$straightDraws = checkStraightDraws($holeCards, $boardCards);
		$draws = array_merge($draws, $straightDraws);

		return $draws;
	}

	function checkFlushDraw($holeCards, $boardCards) {
		$allCards = array_merge($holeCards, $boardCards);
		$suits = array_count_values(array_column($allCards, 'suit'));
		$flushSuit = array_search(4, $suits);

		if ($flushSuit !== false) {
			$holeSuited = array_filter($holeCards, function($c) use ($flushSuit) {
				return $c['suit'] === $flushSuit;
			});

			if (count($holeSuited) >= 2) {
				return [
					'type' => 'flush_draw',
					'outs' => 9,
					'description' => 'Flush draw'
				];
			}
		}
		return false;
	}

	function checkStraightDraws($holeCards, $boardCards) {
		$draws = [];
		$allCards = array_merge($holeCards, $boardCards);
		$values = array_map('rankToValue', array_column($allCards, 'rank'));
		$uniqueValues = array_unique($values);
		rsort($uniqueValues);

		// Открытое стрит-дро (8 аутов)
		$openEnded = findOpenEndedStraightDraw($uniqueValues);
		if ($openEnded) {
			$draws[] = [
				'type' => 'open_ended_straight_draw',
				'outs' => 8,
				'description' => 'Open-ended straight draw'
			];
		}

		// Гастшот (4 аута)
		$gutshot = findGutshotStraightDraw($uniqueValues);
		if ($gutshot) {
			$draws[] = [
				'type' => 'gutshot_straight_draw',
				'outs' => 4,
				'description' => 'Gutshot straight draw'
			];
		}

		return $draws;
	}

	function rankToValue($rank) {
		$values = [
			'2' => 2, '3' => 3, '4' => 4, '5' => 5,
			'6' => 6, '7' => 7, '8' => 8, '9' => 9,
			'T' => 10, 'J' => 11, 'Q' => 12, 'K' => 13, 'A' => 14
		];
		return $values[$rank] ?? 0;
	}

	function valueToRank($value) {
		$ranks = [
			2 => '2', 3 => '3', 4 => '4', 5 => '5',
			6 => '6', 7 => '7', 8 => '8', 9 => '9',
			10 => 'T', 11 => 'J', 12 => 'Q', 13 => 'K', 14 => 'A'
		];
		return $ranks[$value] ?? '';
	}

	function calculatePotAfterAction($hand_id, $sequence_num) {
		global $pdo;
		$stmt = $pdo->prepare("
        SELECT SUM(amount) as pot 
        FROM actions 
        WHERE hand_id = :hand_id AND sequence_num <= :sequence_num
    ");
		$stmt->execute([':hand_id' => $hand_id, ':sequence_num' => $sequence_num]);
		$result = $stmt->fetch();
		return $result['pot'] ?? 0;
	}

	function getLastAggressor($streetActions) {
		$lastAggressor = null;
		foreach ($streetActions as $action) {
			if ($action['is_aggressive']) {
				$lastAggressor = [
					'player_id' => $action['player_id'],
					'player_name' => $action['player_name'],
					'position' => $action['position'],
					'action_type' => $action['type'],
					'amount' => $action['amount']
				];
			}
		}
		return $lastAggressor;
	}

	function isNutStraightFlush($cards) {
		$flush = isFlush($cards);
		if (!$flush) return false;

		$values = array_map('rankToValue', array_column($flush, 'rank'));
		rsort($values);
		return $values[0] === 14 && $values[1] === 13 && $values[2] === 12 && $values[3] === 11 && $values[4] === 10;
	}

	function isNutFlush($cards) {
		$flush = isFlush($cards);
		if (!$flush) return false;

		$values = array_map('rankToValue', array_column($flush, 'rank'));
		rsort($values);
		return $values[0] === 14; // Натсовый флеш содержит туза
	}

	function isNutStraight($cards) {
		$straight = isStraight($cards);
		if (!$straight) return false;

		return $straight['high'] === 'A'; // Натсовый стрит - от туза до 10
	}

	function getKickers($allCards, $pair) {
		$pairRank = $pair[0]['rank'];
		$kickers = array_filter($allCards, function($c) use ($pairRank) {
			return $c['rank'] !== $pairRank;
		});

		usort($kickers, function($a, $b) {
			return rankToValue($b['rank']) - rankToValue($a['rank']);
		});

		return array_slice($kickers, 0, 3); // Возвращаем топ-3 кикера
	}

	function hasStraightPotential($board) {
		if (count($board) < 3) return false;

		$values = array_map('rankToValue', array_column($board, 'rank'));
		$uniqueValues = array_unique($values);
		rsort($uniqueValues);

		// Проверяем промежутки между картами
		$gaps = 0;
		for ($i = 0; $i < count($uniqueValues) - 1; $i++) {
			$diff = $uniqueValues[$i] - $uniqueValues[$i+1];
			if ($diff > 1) $gaps += $diff - 1;
		}

		return $gaps <= 2; // Если промежутки небольшие, есть потенциал для стрита
	}

	function findOpenEndedStraightDraw($uniqueValues) {
		if (count($uniqueValues) < 4) return false;

		for ($i = 0; $i <= count($uniqueValues) - 4; $i++) {
			if ($uniqueValues[$i] - $uniqueValues[$i+3] == 3) {
				// Проверяем, можно ли завершить стрит с обеих сторон
				$neededLow = $uniqueValues[$i] + 1;
				$neededHigh = $uniqueValues[$i+3] - 1;

				if (($neededLow <= 14 && !in_array($neededLow, $uniqueValues)) ||
					($neededHigh >= 2 && !in_array($neededHigh, $uniqueValues))) {
					return true;
				}
			}
		}
		return false;
	}

	function findGutshotStraightDraw($uniqueValues) {
		if (count($uniqueValues) < 4) return false;

		for ($i = 0; $i <= count($uniqueValues) - 4; $i++) {
			if ($uniqueValues[$i] - $uniqueValues[$i+3] == 4) {
				// Есть промежуток в 1 карту между 4 картами
				return true;
			}
		}
		return false;
	}



	$response = [
		'hand_id' => $input['hand_id'],
		'stage' => $input['stady'] ?? 'unknown',
		'current_street' => $input['current_street'],
		'board' => [
			'cards' => $handInfo['board'] ? parseCards($handInfo['board']) : null,
			'texture' => $handInfo['board'] ? analyzeBoardTexture($handInfo['board']) : null,
			'danger_level' => $handInfo['board'] ? calculateBoardDanger($handInfo['board']) : 0
		],
		'hero' => [
			'id' => $input['hero_id'] ?? null,
			'name' => $input['hero_nickname'] ?? null,
			'cards' => parseCards($handInfo['hero_cards']),
			'hand_strength' => $handInfo['board'] ?
				evaluateHandStrength($handInfo['hero_cards'], $handInfo['board'], $input['current_street']) :
				evaluatePreflopHand($handInfo['hero_cards']),
			'position' => $input['hero_position'],
			'stack' => $handInfo['hero_stack'],
			'effective_stack' => calculateEffectiveStack($handInfo['hero_stack'], $currentHandPlayers),
			'pot_commitment' => calculateHeroPotCommitment($input['hand_id'], $input['hero_id'] ?? null)
		],
		'players' => array_map(function($player) use ($currentHandPlayers, $input) {
			$inCurrentHand = false;
			$currentPosition = null;

			foreach ($currentHandPlayers as $handPlayer) {
				if ($handPlayer['player_id'] === $player['player_id']) {
					$inCurrentHand = true;
					$currentPosition = $handPlayer['position'];
					break;
				}
			}

			return [
				'id' => $player['player_id'],
				'name' => $player['nickname'],
				'in_current_hand' => $inCurrentHand,
				'position' => $currentPosition,
				'relative_position' => $inCurrentHand ?
					calculateRelativePosition($currentPosition, $input['hero_position']) : null,
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
				'stats_reliable' => ($player['hands_played'] ?? 0) >= 50,
				'tendencies' => analyzePlayerTendencies($player),
				'last_seen' => $player['last_seen'] ?? null
			];
		}, $allPlayers),
		'pot' => [
			'total' => array_sum($response['pots']),
			'by_street' => $response['pots'],
			'current_street_contribution' => $response['pots'][$input['current_street']] ?? 0,
			'odds' => calculatePotOdds($input['hand_id'], $input['current_street'])
		],
		'actions' => array_map(function($streetActions, $street) use ($input, $currentHandPlayers) {
			return [
				'street' => $street,
				'actions' => array_map(function($action) use ($currentHandPlayers) {
					$playerPosition = null;
					foreach ($currentHandPlayers as $player) {
						if ($player['player_id'] === $action['player_id']) {
							$playerPosition = $player['position'];
							break;
						}
					}

					return [
						'player_id' => $action['player_id'],
						'player_name' => $action['player_nickname'] ?? null,
						'position' => $playerPosition,
						'type' => $action['action_type'],
						'amount' => $action['amount'],
						'street' => $action['street'],
						'sequence' => $action['sequence_num'],
						'is_aggressive' => $action['is_aggressive'],
						'is_voluntary' => $action['is_voluntary'],
						'is_cbet' => $action['is_cbet'],
						'is_steal' => $action['is_steal'],
						'pot_after' => calculatePotAfterAction($action['hand_id'], $action['sequence_num'])
					];
				}, $streetActions),
				'aggression_count' => count(array_filter($streetActions, function($a) { return $a['is_aggressive']; })),
				'last_aggressor' => getLastAggressor($streetActions)
			];
		}, array_filter($response['actions'], function($a) { return !empty($a); }), array_keys(array_filter($response['actions'], function($a) { return !empty($a); }))),
		'showdown' => array_map(function($showdown) {
			return [
				'player_id' => $showdown['player_id'],
				'player_name' => $showdown['nickname'],
				'hand_id' => $showdown['hand_id'],
				'cards' => parseCards($showdown['cards']),
				'hand_strength' => evaluateHandStrength($showdown['cards'], '', 'river')
			];
		}, $showdownInfo),
		'hand_progress' => [
			'street_sequence' => getStreetSequence($input['current_street']),
			'next_to_act' => getNextToAct($input['hand_id'], $input['current_street']),
			'action_required' => isActionRequired($input['hand_id'], $input['current_street'], $input['hero_position'])
		],
		'meta' => [
			'timestamp' => date('Y-m-d H:i:s'),
			'hand_date' => $handInfo['created_at'] ?? null
		]
	];


	die(print_r($response));

	$api_key = 'sk-JBDhoWZZwZSn8q2xmqmi9zETz12StFzC';
	$url = 'https://api.proxyapi.ru/openai/v1/chat/completions';
	$headers = ['Content-Type: application/json', 'Authorization: Bearer ' . $api_key];

	$ch = curl_init($url);
	curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
	curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
	curl_setopt($ch, CURLOPT_POST, true);
	curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode([
		'model' => 'gpt-4o-mini',
		'messages' => [[ 'role' => 'user', 'content' => $response ]],
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

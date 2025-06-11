<?php
header('Content-Type: application/json');
require_once __DIR__.'/../db/config.php';

$response = ['success' => false, 'error' => null];

try {
	// Validate input
//	$input = json_decode(file_get_contents('php://input'), true);
//	if (!$input) throw new Exception('Invalid JSON input');

	// Debug input (uncomment for testing)
	$input = [
		'hand_id' => 1,
		'current_street' => 'preflop',
		'hero_position' => 'MP',
		'hero_id' => '999999',
		'hero_nickname' => 'Player999999',
		'stady' => 'early'
	];

	$required = ['hand_id', 'current_street', 'hero_position'];
	foreach ($required as $field) {
		if (!isset($input[$field])) throw new Exception("Missing required field: $field", 400);
	}

	$validStreets = ['preflop', 'flop', 'turn', 'river'];
	if (!in_array($input['current_street'], $validStreets)) {
		throw new Exception("Invalid street: " . $input['current_street'], 400);
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

	// 1. Get basic hand information
	$handStmt = $pdo->prepare("
        SELECT hero_position, hero_stack, hero_cards, board, is_completed, created_at 
        FROM hands 
        WHERE hand_id = :hand_id
    ");
	$handStmt->execute([':hand_id' => $input['hand_id']]);
	$handInfo = $handStmt->fetch();

	if (!$handInfo) {
		throw new Exception("Hand not found", 404);
	}

	// 2. Get showdown information for this hand only
	$showdownStmt = $pdo->prepare("
        SELECT s.player_id, p.nickname, s.hand_id, s.cards 
        FROM showdown s
        JOIN players p ON s.player_id = p.player_id
        WHERE s.hand_id = :hand_id
        ORDER BY s.created_at DESC
    ");
	$showdownStmt->execute([':hand_id' => $input['hand_id']]);
	$showdownInfo = $showdownStmt->fetchAll();

	// 3. Get players in current hand with their positions
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

	// 4. Get hand actions with proper player nicknames
	$handActionsStmt = $pdo->prepare("
        SELECT 
            a.player_id, 
            p.nickname as player_nickname,
            a.action_type, 
            a.amount, 
            a.street, 
            a.sequence_num, 
            a.position,
            a.is_aggressive, 
            a.is_voluntary, 
            a.is_cbet, 
            a.is_steal
        FROM actions a
        JOIN players p ON a.player_id = p.player_id
        WHERE a.hand_id = :hand_id
        ORDER BY a.sequence_num
    ");
	$handActionsStmt->execute([':hand_id' => $input['hand_id']]);
	$handActions = $handActionsStmt->fetchAll();

	// Organize actions by street
	$actionsByStreet = [];
	foreach ($handActions as $action) {
		$street = strtolower($action['street']);
		$actionsByStreet[$street][] = $action;
	}

	// Get hero info from input or find in current hand players
	$heroInfo = [
		'id' => $input['hero_id'] ?? null,
		'name' => $input['hero_nickname'] ?? null,
		'position' => $input['hero_position']
	];

	if (empty($heroInfo['id'])) {
		foreach ($currentHandPlayers as $player) {
			if ($player['position'] === $input['hero_position']) {
				$heroInfo['id'] = $player['player_id'];
				$heroInfo['name'] = $player['nickname'];
				break;
			}
		}
	}

	// Get all players (excluding hero if ID is provided)
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
            created_at
        FROM players
        ORDER BY last_seen DESC
        LIMIT 100
    ");
	$allPlayersStmt->execute();
	$allPlayers = $allPlayersStmt->fetchAll();

	// Helper functions for card analysis
	function parseCards($cardString) {
		if (empty($cardString)) return [];

		$cards = [];
		$parts = preg_split('/\s+/', trim($cardString));

		foreach ($parts as $card) {
			if (preg_match('/^[2-9TJQKA][cdhs]$/i', $card)) {
				$cards[] = [
					'rank' => strtoupper(substr($card, 0, 1)),
					'suit' => strtolower(substr($card, 1, 1)),
					'full' => strtoupper($card)
				];
			}
		}

		return $cards;
	}

	function rankToValue($rank) {
		$values = [
			'2' => 2, '3' => 3, '4' => 4, '5' => 5,
			'6' => 6, '7' => 7, '8' => 8, '9' => 9,
			'T' => 10, 'J' => 11, 'Q' => 12, 'K' => 13, 'A' => 14
		];
		return $values[strtoupper($rank)] ?? 0;
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

		if (count($hole) != 2 || empty($board)) {
			return ['strength' => 'unknown', 'description' => 'Invalid hand/board'];
		}

		$allCards = array_merge($hole, $board);
		$ranks = array_column($allCards, 'rank');
		$suits = array_column($allCards, 'suit');

		// Basic evaluation - in a real app you'd want a proper hand evaluator
		$rankCounts = array_count_values($ranks);
		$suitCounts = array_count_values($suits);

		// Check for flush
		$flush = false;
		foreach ($suitCounts as $suit => $count) {
			if ($count >= 5) {
				$flush = true;
				break;
			}
		}

		// Check for straight
		$values = array_map('rankToValue', $ranks);
		$uniqueValues = array_unique($values);
		rsort($uniqueValues);

		$straight = false;
		if (count($uniqueValues) >= 5) {
			for ($i = 0; $i <= count($uniqueValues) - 5; $i++) {
				if ($uniqueValues[$i] - $uniqueValues[$i+4] == 4) {
					$straight = true;
					break;
				}
			}
		}

		// Determine hand strength
		if ($flush && $straight) {
			return ['strength' => 'straight_flush', 'description' => 'Straight Flush'];
		} elseif (max($rankCounts) >= 4) {
			return ['strength' => 'four_of_a_kind', 'description' => 'Four of a Kind'];
		} elseif (count(array_filter($rankCounts, function($v) { return $v >= 3; })) >= 1 &&
			count(array_filter($rankCounts, function($v) { return $v >= 2; })) >= 2) {
			return ['strength' => 'full_house', 'description' => 'Full House'];
		} elseif ($flush) {
			return ['strength' => 'flush', 'description' => 'Flush'];
		} elseif ($straight) {
			return ['strength' => 'straight', 'description' => 'Straight'];
		} elseif (max($rankCounts) >= 3) {
			return ['strength' => 'three_of_a_kind', 'description' => 'Three of a Kind'];
		} elseif (count(array_filter($rankCounts, function($v) { return $v >= 2; })) >= 2) {
			return ['strength' => 'two_pair', 'description' => 'Two Pair'];
		} elseif (max($rankCounts) >= 2) {
			return ['strength' => 'pair', 'description' => 'Pair'];
		} else {
			return ['strength' => 'high_card', 'description' => 'High Card'];
		}
	}

	function analyzeBoardTexture($boardCards) {
		$board = parseCards($boardCards);
		if (count($board) < 3) return 'preflop';

		$textures = [];
		$ranks = array_column($board, 'rank');
		$suits = array_column($board, 'suit');

		// Check for flush potential
		$suitCounts = array_count_values($suits);
		if (max($suitCounts) >= 3) {
			$textures[] = 'monotone';
		}

		// Check for pairs
		$rankCounts = array_count_values($ranks);
		$pairs = array_filter($rankCounts, function($v) { return $v >= 2; });
		if (!empty($pairs)) {
			$textures[] = 'paired';
			if (count($pairs) >= 2) {
				$textures[] = 'multi_paired';
			}
		}

		// Check for connectedness
		$values = array_map('rankToValue', $ranks);
		rsort($values);
		$connected = false;
		for ($i = 0; $i < count($values) - 1; $i++) {
			if ($values[$i] - $values[$i+1] <= 2) {
				$connected = true;
				break;
			}
		}

		if ($connected) {
			$textures[] = 'connected';
		}

		// Check for high cards
		$highCards = array_filter($values, function($v) { return $v >= 10; });
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
		if (strpos($texture, 'connected') !== false) $danger += 25;
		if (strpos($texture, 'high_cards') !== false) $danger += 15;

		return min($danger, 100);
	}

	function calculateEffectiveStack($heroStack, $players) {
		$minStack = $heroStack;
		foreach ($players as $player) {
			if (isset($player['hero_stack']) && $player['hero_stack'] < $minStack) {
				$minStack = $player['hero_stack'];
			}
		}
		return min($heroStack, $minStack);
	}

	function calculateHeroPotCommitment($pdo, $handId, $heroId) {
		if (empty($heroId)) return 0;

		$stmt = $pdo->prepare("
            SELECT SUM(amount) as total 
            FROM actions 
            WHERE hand_id = :hand_id AND player_id = :player_id
        ");
		$stmt->execute([':hand_id' => $handId, ':player_id' => $heroId]);
		$result = $stmt->fetch();
		return $result['total'] ?? 0;
	}

	function calculatePotOdds($pdo, $handId, $street) {
		// Total pot
		$stmt = $pdo->prepare("
            SELECT SUM(amount) as total 
            FROM actions 
            WHERE hand_id = :hand_id AND street = :street
        ");
		$stmt->execute([':hand_id' => $handId, ':street' => $street]);
		$pot = $stmt->fetch()['total'] ?? 0;

		// Current bet to call
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

	function getNextToAct($pdo, $handId, $currentStreet) {
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

	function isActionRequired($pdo, $handId, $street, $heroPos) {
		// Check if there are bets on current street
		$stmt = $pdo->prepare("
            SELECT COUNT(*) as bets 
            FROM actions 
            WHERE hand_id = :hand_id AND street = :street AND action_type IN ('bet', 'raise', 'all-in')
        ");
		$stmt->execute([':hand_id' => $handId, ':street' => $street]);
		$hasBets = $stmt->fetch()['bets'] > 0;

		// Check if hero has acted on this street
		$stmt = $pdo->prepare("
            SELECT COUNT(*) as actions 
            FROM actions 
            WHERE hand_id = :hand_id AND street = :street AND position = :position
        ");
		$stmt->execute([':hand_id' => $handId, ':street' => $street, ':position' => $heroPos]);
		$hasActed = $stmt->fetch()['actions'] > 0;

		return $hasBets && !$hasActed;
	}

	function getLastAggressor($streetActions) {
		$lastAggressor = null;
		foreach ($streetActions as $action) {
			if ($action['is_aggressive']) {
				$lastAggressor = [
					'player_id' => $action['player_id'],
					'player_name' => $action['player_nickname'],
					'position' => $action['position'],
					'action_type' => $action['action_type'],
					'amount' => $action['amount']
				];
			}
		}
		return $lastAggressor;
	}

	// Calculate pots by street
	$potsByStreet = [];
	$streetBets = [];
	$currentStreet = null;

	foreach ($handActions as $action) {
		$street = strtolower($action['street']);

		if ($street !== $currentStreet) {
			$streetBets = [];
			$currentStreet = $street;
		}

		$playerId = $action['player_id'];
		$amount = (float)$action['amount'];
		$actionType = $action['action_type'];

		if (!isset($streetBets[$playerId])) {
			$streetBets[$playerId] = 0;
		}

		$maxBet = !empty($streetBets) ? max($streetBets) : 0;

		switch ($actionType) {
			case 'bet':
				$potsByStreet[$street] = ($potsByStreet[$street] ?? 0) + ($amount - $streetBets[$playerId]);
				$streetBets[$playerId] = $amount;
				break;

			case 'raise':
				$potsByStreet[$street] = ($potsByStreet[$street] ?? 0) + ($amount - $streetBets[$playerId]);
				$streetBets[$playerId] = $amount;
				break;

			case 'call':
				$potsByStreet[$street] = ($potsByStreet[$street] ?? 0) + ($maxBet - $streetBets[$playerId]);
				$streetBets[$playerId] = $maxBet;
				break;

			case 'all-in':
				if ($amount > $maxBet) {
					$potsByStreet[$street] = ($potsByStreet[$street] ?? 0) + ($amount - $streetBets[$playerId]);
				} else {
					$potsByStreet[$street] = ($potsByStreet[$street] ?? 0) + ($maxBet - $streetBets[$playerId]);
				}
				$streetBets[$playerId] = $amount;
				break;

			case 'check':
			case 'fold':
				break;
		}
	}

	// Prepare response
	$response = [
		'success' => true,
		'hand_id' => $input['hand_id'],
		'stage' => $input['stady'] ?? 'unknown',
		'current_street' => $input['current_street'],
		'board' => [
			'cards' => parseCards($handInfo['board']),
			'texture' => analyzeBoardTexture($handInfo['board']),
			'danger_level' => calculateBoardDanger($handInfo['board'])
		],
		'hero' => [
			'id' => $heroInfo['id'],
			'name' => $heroInfo['name'],
			'cards' => parseCards($handInfo['hero_cards']),
			'hand_strength' => $handInfo['board'] ?
				evaluateHandStrength($handInfo['hero_cards'], $handInfo['board'], $input['current_street']) :
				evaluatePreflopHand($handInfo['hero_cards']),
			'position' => $heroInfo['position'],
			'stack' => $handInfo['hero_stack'],
			'effective_stack' => calculateEffectiveStack($handInfo['hero_stack'], $currentHandPlayers),
			'pot_commitment' => calculateHeroPotCommitment($pdo, $input['hand_id'], $heroInfo['id'])
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
			'total' => array_sum($potsByStreet),
			'by_street' => $potsByStreet,
			'current_street_contribution' => $potsByStreet[$input['current_street']] ?? 0,
			'odds' => calculatePotOdds($pdo, $input['hand_id'], $input['current_street'])
		],
		'actions' => array_map(function($actions, $street) use ($currentHandPlayers, $input) {
			return [
				'street' => $street,
				'actions' => array_map(function($action) {
					return [
						'player_id' => $action['player_id'],
						'player_name' => $action['player_nickname'],
						'position' => $action['position'],
						'type' => $action['action_type'],
						'amount' => $action['amount'],
						'street' => $action['street'],
						'sequence' => $action['sequence_num'],
						'is_aggressive' => $action['is_aggressive'],
						'is_voluntary' => $action['is_voluntary'],
						'is_cbet' => $action['is_cbet'],
						'is_steal' => $action['is_steal']
					];
				}, $actions),
				'aggression_count' => count(array_filter($actions, function($a) { return $a['is_aggressive']; })),
				'last_aggressor' => getLastAggressor($actions)
			];
		}, $actionsByStreet, array_keys($actionsByStreet)),
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
			'next_to_act' => getNextToAct($pdo, $input['hand_id'], $input['current_street']),
			'action_required' => isActionRequired($pdo, $input['hand_id'], $input['current_street'], $input['hero_position'])
		],
		'meta' => [
			'timestamp' => date('Y-m-d H:i:s'),
			'hand_date' => $handInfo['created_at'] ?? null
		]
	];

	die(print_r($response));

	$response = json_encode($response, JSON_UNESCAPED_UNICODE);
	$content = "
		Отвечай максимально коротко: действие (если рейз, то сколько) | короткое описание
		Примеры корректных ответов:
		Raise 35 BB | Топ пара + nut flush дро (15 аутов)
		Fold | Слабый кикер на опасном борде (A5 на QQ5)
		Call 12 BB | Средняя пара + pot odds 25% (остаток стека 120 BB)
		All-in 85 BB | Премиум пара (AA) против 3-бета
		$response
	";

	// Send to AI for analysis
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
		$response['error'] = 'CURL error: ' . curl_error($ch);
	} else {
		$apiData = json_decode($apiResponse, true);
		if (isset($apiData['choices'][0]['message']['content'])) {
			$response = trim($apiData['choices'][0]['message']['content']);
		} else {
			$response['error'] = 'Invalid API response format';
			$response['api_response'] = $apiData;
		}
	}
	curl_close($ch);

} catch (Exception $e) {
	$response['success'] = false;
	$response['error'] = $e->getMessage();
	http_response_code($e->getCode() ?: 500);
}

echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
?>
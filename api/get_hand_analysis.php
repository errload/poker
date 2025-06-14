<?php

use FourBet\HandEvaluator;

header('Content-Type: application/json');
require_once __DIR__.'/../db/config.php';
require_once __DIR__ . '/../src/HandEvaluator.php';

$response = ['success' => false, 'error' => null];

try {
	// Validate input
//	$input = json_decode(file_get_contents('php://input'), true);
//	if (!$input) throw new Exception('Invalid JSON input');

	// Debug input (uncomment for testing)
	$input = [
		'hand_id' => 7,
		'current_street' => 'flop',
		'hero_position' => 'BTN',
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

//1. Основная статистика (обязательная)
//Эти данные помогают оценить стиль игры оппонента:
//VPIP (Voluntarily Put $ In Pot) – % раз, когда игрок добровольно вкладывает деньги в банк (широта диапазона).
//PFR (Preflop Raise) – % раз, когда игрок делает рейз префлоп.
//AF (Aggression Factor) – соотношение агрессивных действий (рейз/бет) к пассивным (колл/чек).
//Fold to CBet – % фолдов на ставку оппонента на флопе.
//WTSD (Went to Showdown) – % раз, когда игрок доходит до вскрытия.
//WSD (Won at Showdown) – % побед на вскрытии.
//
//2. Дополнительная статистика (для глубокого анализа)
//Позволяет детализировать стратегию:
//3Bet% – частота 3-бета префлоп.
//Fold to 3Bet – % фолдов на 3-бет.
//Steal% – частота попыток украсть блайнды (с BTN/SB/CO).
//Squeeze% – частота сквизов (рейз после колла и рейза).
//Check-Raise% – частота чек-рейзов.
//Turn CBet% – продолжение ставки на терне.
//River Aggression – агрессия на ривере.
//
//3. Позиционная статистика
//Игроки могут менять стиль в зависимости от позиции:
//VPIP/PFR по позициям (UTG, CO, BTN, SB/BB).
//Fold to Steal – % фолдов при защите блайндов.
//
//4. Динамические данные (для адаптации)
//Stack-to-Pot Ratio (SPR) – соотношение стека к банку.
//Tendencies – склонности (например, игрок часто блефует на ривере).
//История действий в текущей сессии (например, игрок делает 3-бет 4 раза подряд).
//
//"player_stats": {
//	"Villian1": {
//		"VPIP": 22,
//		"PFR": 15,
//		"AF": 1.8,
//		"Fold_to_CBet": 65,
//		"3Bet": 5,
//		"Steal": 25,
//		"WTSD": 28,
//		"positional_stats": {
//			"BTN": { "VPIP": 30, "PFR": 20 },
//			"BB": { "VPIP": 18, "PFR": 12 }
//		}
//	}
//}

//	nut, strong, medium, weak

//1. Дро-комбинации (Draws)
//Комбинация	Пример (рука + борд)	Примечание
//"flush_draw"	A♠ K♠ на 2♠ 5♠ 9♥	4 карты одной масти
//"straight_draw"	7♦ 8♦ на 5♣ 6♥ 9♠	Открытый (8 аутов)
//"gutshot_draw"	J♣ T♦ на 8♠ 9♥ A♦	Гатошот (4 аута)
//"backdoor_flush"	Q♣ 2♣ на K♠ T♣ 3♦	Бэкдор-флеш (2 карты масти)
//"backdoor_straight"	4♥ 5♠ на 2♦ 7♣ 9♠	Бэкдор-стрит
//
//2. Сделанные комбинации (Made Hands)
//Комбинация	Пример (рука + борд)	Примечание
//"high_card"	A♣ J♦ на 2♥ 5♦ 8♠	Старшая карта
//"pair"	K♠ Q♥ на K♦ 3♠ 7♥	Одна пара
//"two_pair"	J♣ J♦ на 5♥ 5♦ T♠	Две пары
//"set"	4♠ 4♥ на 4♦ 9♣ 2♠	Сет (пара в руке + одна на борде)
//"trips"	A♠ 2♦ на A♥ A♣ 5♠	Трипс (пара на борде + одна в руке)
//"straight"	6♠ 7♥ на 8♦ 9♣ T♠	Стрит
//"flush"	K♣ T♣ на 2♣ 5♣ 9♣	Флеш
//"full_house"	Q♦ Q♥ на Q♠ 9♦ 9♣	Фул-хаус
//"quads"	5♠ 5♦ на 5♥ 5♣ J♠	Каре
//"straight_flush"	8♠ 9♠ на T♠ J♠ Q♠	Стрит-флеш
//"royal_flush"	A♠ K♠ на Q♠ J♠ T♠	Роял-флеш
//
//3. Специальные случаи
//Комбинация	Пример (рука + борд)	Примечание
//"overpair"	A♠ A♦ на K♥ 7♦ 2♣	Пара выше борда
//"top_pair"	K♠ Q♦ на K♥ 5♠ 2♦	Пара со старшей картой борда
//"middle_pair"	J♠ T♦ на Q♥ J♦ 3♠	Пара со средней картой борда
//"weak_pair"	6♠ 4♥ на A♦ 6♣ 2♠	Пара с низкой картой
//"pair+draw"	8♠ 9♠ на 7♥ T♠ J♦	Пара + стрит/флеш-дро
//
//4. Угрозы (Threats)
//Для описания возможных рук оппонента:
//"flush_threat" (флеш на борде)
//"straight_threat" (4 в ряд на борде)
//"paired_board" (пара на борде → риск сета)
//"overcards" (карты выше вашей пары, например, A♥ на Q♦ Q♠)

	$response = [
		'tournament_stage' => $input['stady'],
		'pot' => [
			'current_bb' => 40, // ?
			'hero_equity' => 35 // ?
		],
		'hero' => [
			'position' => $input['hero_position'],
			'cards' => ["As", "Kd"], // ?
			'stack_bb' => 67, // ?
			'starting_stack_bb' => 125 // ?
		],
		'board' => ['8h', '9s', '2d', 'Qc', '3h'], // ?
		'hand_analysis' => [ // ?
			'hero_combination' => [
				'type' => 'two_pair',
				'strength' => 'strong',
				'components' => [
					'main' => ['Ah', 'Ad', 'Kc', 'Kd'],
					'kickers' => ['Qs']
				],
//				'type' => 'flush',
//				'strength' => 'medium',
//				'components' => [
//					'main' => ['As', 'Ks', 'Qs', 'Js', '9s'],
//					'kickers' => []
//				],
			],
			'draws' => [
				[
					'type' => 'flush_draw',
					'strength' => 'nut',
					'backdoor' => false,
					'outs' => 9
				],
				[
					'type' => 'straight_draw',
					'strength' => 'medium',
					'backdoor' => true,
					'outs' => 4
				]
			],
			'threats' => [
				[
					'type' => 'flush',
					'strength' => 'strong',
					'description' => 'Opponent may have Qx flush'
				],
				[
					'type' => 'straight',
					'strength' => "nut",
					'description' => 'QJ makes nut straight'
				]
			]
		],
		'action_flow' => [ // ?
			'current_street' => 'river',
			'history' => [
				'preflop' => [
					[ 'player' => 'Villian1', 'position' => 'UTG', 'action' => 'raise', 'amount_bb' => 2.5 ],
					[ 'player' => 'Villian2', 'position' => 'CO', 'action' => 'call' ],
					[ 'player' => 'Hero', 'position' => 'BTN', 'action' => 'raise', 'amount_bb' => 3 ],
					[ 'player' => 'SB', 'action' => 'fold' ],
					[ 'player' => 'BB', 'action' => 'fold' ],
					[ 'player' => 'Villian1', 'action' => 'call' ],
					[ 'player' => 'Villian2', 'action' => 'call' ]
				],
				'flop' => [
					[ 'player' => 'Villian1', 'action' => 'check' ],
					[ 'player' => 'Villian2', 'action' => 'check' ],
					[ 'player' => 'Hero', 'action' => 'bet', 'size' => '2/3_pot' ],
					[ 'player' => 'Villian1', 'action' => 'fold' ],
					[ 'player' => 'Villian2', 'action' => 'call' ]
				],
				'turn' => [
					[ 'player' => 'Villian2', 'action' => 'check' ],
					[ 'player' => 'Hero', 'action' => 'bet', 'size' => '1/2_pot' ],
					[ 'player' => 'Villian2', 'action' => 'call' ]
				],
				'river' => [
					[ 'player' => 'Villian2', 'action' => 'check' ],
					[ 'player' => 'Hero', 'action' => '?' ]
				]
			],
			'street_aggressors' => [
				'preflop' => 'Villian1',
				'flop' => 'Villian2',
				'turn' => null,
				'river' => 'Hero'
			],
			'last_aggressor' => 'Hero'
		],
		'opponent_profiles' => [ // ?
			'Villian1' => [
				'stats' => [
					'VPIP' => 28,
					'PFR' => 18,
					'aggression_factor' => 2.5,
					'fold_to_cbet' => 65
				],
				'previous_showdowns' => [
					['Ac', '2d'],
					['Kh', 'Jd'],
					['Ts', '9s']
				]
			],
			'Villian2' => [
				'stats' => [
					'VPIP' => 35,
					'PFR' => 22,
					'aggression_factor' => 1.8,
					'fold_to_cbet' => 45,
					'WTSD' => 30
				]
			]
		],
		'decision_context' => [ // ?
			'recommended_actions' => ['bet', 'check'],
			'illegal_actions' => ['fold'],
			'pot_odds' => 4.2,
			'stack_to_pot_ratio' => 0.76 // SPR
		]
	];

	print_r($response);
	die();

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
    ORDER BY s.created_at DESC
    LIMIT 100  -- Ограничение для безопасности (можно убрать или изменить)
");
	$showdownStmt->execute();
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
        WHERE hands_played >= 10
        ORDER BY last_seen DESC
        LIMIT 100
    ");
	$allPlayersStmt->execute();
	$allPlayers = $allPlayersStmt->fetchAll();

	// Улучшенная функция для парсинга карт
	function parseCards($cardString) {
		if (empty($cardString)) return [];

		$cards = [];
		$parts = preg_split('/\s+/', trim($cardString));

		foreach ($parts as $card) {
			if (preg_match('/^([2-9TJQKA])([cdhs])$/i', $card, $matches)) {
				$rank = strtoupper($matches[1]);
				$suit = strtolower($matches[2]);
				$cards[] = [
					'rank' => $rank,
					'suit' => $suit,
					'full' => $rank . $suit,
					'value' => rankToValue($rank)
				];
			}
		}

		// Сортируем карты по значению (от старших к младшим)
		usort($cards, function($a, $b) {
			return $b['value'] - $a['value'];
		});

		return $cards;
	}

	// Функция для преобразования ранга в числовое значение
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

	// Полная функция оценки силы руки с учетом всех нюансов
	function evaluateHandStrength($holeCards, $boardCards, $street) {
		$hole = parseCards($holeCards);
		$board = parseCards($boardCards);

		if (count($hole) != 2) {
			return ['strength' => 'invalid', 'description' => 'Invalid hole cards'];
		}

		$allCards = array_merge($hole, $board);
		if (count($allCards) < 5 && $street == 'river') {
			return ['strength' => 'invalid', 'description' => 'Not enough cards'];
		}

		// Основные переменные для анализа
		$ranks = array_column($allCards, 'rank');
		$suits = array_column($allCards, 'suit');
		$values = array_column($allCards, 'value');
		$holeValues = array_column($hole, 'value');

		$rankCounts = array_count_values($ranks);
		$suitCounts = array_count_values($suits);
		arsort($rankCounts);
		arsort($suitCounts);

		// Проверка комбинаций от старшей к младшей
		$result = [
			'strength' => 'high_card',
			'description' => 'High Card',
			'combination' => [],
			'kickers' => [],
			'draws' => [],
			'outs' => 0,
			'nut_status' => 'none'
		];

		// Проверка на флеш (5+ карт одной масти)
		$flush = false;
		$flushSuit = null;
		$flushCards = [];
		foreach ($suitCounts as $suit => $count) {
			if ($count >= 5) {
				$flush = true;
				$flushSuit = $suit;
				$flushCards = array_filter($allCards, function($card) use ($suit) {
					return $card['suit'] === $suit;
				});
				usort($flushCards, function($a, $b) { return $b['value'] - $a['value']; });
				break;
			}
		}

		// Проверка на стрит (5 последовательных карт)
		$straight = false;
		$straightCards = [];
		$uniqueValues = array_unique($values);
		rsort($uniqueValues);

		// Проверка для обычного стрита (A-5-4-3-2)
		if (in_array(14, $uniqueValues)) {
			$uniqueValues[] = 1; // Добавляем младший туз для проверки стрита A-2-3-4-5
		}

		$uniqueValues = array_unique($uniqueValues);
		sort($uniqueValues);

		$straightLength = 1;
		for ($i = 1; $i < count($uniqueValues); $i++) {
			if ($uniqueValues[$i] == $uniqueValues[$i-1] + 1) {
				$straightLength++;
				if ($straightLength >= 5) {
					$straight = true;
					$straightCards = array_slice($uniqueValues, $i-4, 5);
					rsort($straightCards);
				}
			} else {
				$straightLength = 1;
			}
		}

		// Проверка на стрит-флеш и роял-флеш
		if ($flush && $straight) {
			$flushStraightCards = [];
			$flushValues = array_column($flushCards, 'value');
			$flushUniqueValues = array_unique($flushValues);
			rsort($flushUniqueValues);

			if (in_array(14, $flushUniqueValues)) {
				$flushUniqueValues[] = 1;
			}

			$flushUniqueValues = array_unique($flushUniqueValues);
			sort($flushUniqueValues);

			$straightLength = 1;
			for ($i = 1; $i < count($flushUniqueValues); $i++) {
				if ($flushUniqueValues[$i] == $flushUniqueValues[$i-1] + 1) {
					$straightLength++;
					if ($straightLength >= 5) {
						$flushStraightCards = array_slice($flushUniqueValues, $i-4, 5);
						rsort($flushStraightCards);

						// Проверка на роял-флеш
						if ($flushStraightCards[0] == 14 && $flushStraightCards[4] == 10) {
							$result = [
								'strength' => 'royal_flush',
								'description' => 'Royal Flush',
								'combination' => array_slice($flushCards, 0, 5),
								'kickers' => [],
								'draws' => [],
								'outs' => 0,
								'nut_status' => 'absolute_nuts'
							];
							return $result;
						}

						// Обычный стрит-флеш
						$result = [
							'strength' => 'straight_flush',
							'description' => 'Straight Flush ('.implode('-', $flushStraightCards).')',
							'combination' => array_slice($flushCards, 0, 5),
							'kickers' => [],
							'draws' => [],
							'outs' => 0,
							'nut_status' => $flushStraightCards[0] == 14 ? 'absolute_nuts' : 'strong'
						];
						return $result;
					}
				} else {
					$straightLength = 1;
				}
			}
		}

		// Проверка на каре (4 карты одного ранга)
		if (max($rankCounts) >= 4) {
			$quadRank = array_search(4, $rankCounts);
			$quadCards = array_filter($allCards, function($card) use ($quadRank) {
				return $card['rank'] === $quadRank;
			});
			$kickers = array_filter($allCards, function($card) use ($quadRank) {
				return $card['rank'] !== $quadRank;
			});
			usort($kickers, function($a, $b) { return $b['value'] - $a['value']; });

			$result = [
				'strength' => 'four_of_a_kind',
				'description' => 'Four of a Kind ('.$quadRank.')',
				'combination' => $quadCards,
				'kickers' => array_slice($kickers, 0, 1),
				'draws' => [],
				'outs' => 0,
				'nut_status' => $quadRank == 'A' ? 'absolute_nuts' : 'strong'
			];
			return $result;
		}

		// Проверка на фулл-хаус (3+2)
		$trips = [];
		$pairs = [];
		foreach ($rankCounts as $rank => $count) {
			if ($count >= 3) $trips[] = $rank;
			if ($count >= 2) $pairs[] = $rank;
		}
		$trips = array_unique($trips);
		$pairs = array_unique($pairs);
		rsort($trips);
		rsort($pairs);

		if (count($trips) >= 1 && (count($pairs) >= 2 || count($trips) >= 2)) {
			$fullHouseTrips = $trips[0];
			$fullHousePair = null;

			// Ищем самую старшую пару, отличную от тройки
			foreach ($pairs as $pair) {
				if ($pair != $fullHouseTrips) {
					$fullHousePair = $pair;
					break;
				}
			}

			// Если не нашли пару, но есть вторая тройка - используем ее для пары
			if (!$fullHousePair && count($trips) >= 2) {
				$fullHousePair = $trips[1];
			}

			if ($fullHousePair) {
				$tripCards = array_filter($allCards, function($card) use ($fullHouseTrips) {
					return $card['rank'] === $fullHouseTrips;
				});
				$pairCards = array_filter($allCards, function($card) use ($fullHousePair) {
					return $card['rank'] === $fullHousePair;
				});

				$result = [
					'strength' => 'full_house',
					'description' => 'Full House ('.$fullHouseTrips.' over '.$fullHousePair.')',
					'combination' => array_merge(array_slice($tripCards, 0, 3), array_slice($pairCards, 0, 2)),
					'kickers' => [],
					'draws' => [],
					'outs' => 0,
					'nut_status' => $fullHouseTrips == 'A' ? 'absolute_nuts' : 'strong'
				];
				return $result;
			}
		}

		// Проверка на флеш
		if ($flush) {
			$flushCards = array_slice($flushCards, 0, 5);
			$nutFlush = $flushCards[0]['value'] == 14 && in_array(13, array_column($flushCards, 'value'));
			$hasHigherCards = (max(array_column($board, 'value')) > $flushCards[0]['value']);

			$result = [
				'strength' => 'flush',
				'description' => 'Flush ('.$flushSuit.')',
				'combination' => $flushCards,
				'kickers' => [],
				'draws' => [],
				'outs' => 0,
				'nut_status' => $nutFlush ? 'nut_flush' :
					($hasHigherCards ? 'weak' : 'strong')
			];
			return $result;
		}

		// Проверка на стрит
		if ($straight) {
			$nutStraight = $straightCards[0] == 14;
			$isVulnerable = ($straightCards[0] < 12 && count(array_unique($straightCards)) == 5);

			$result = [
				'strength' => 'straight',
				'description' => 'Straight ('.implode('-', $straightCards).')',
				'combination' => [],
				'kickers' => [],
				'draws' => [],
				'outs' => 0,
				'nut_status' => $nutStraight ? 'nut_straight' :
					($isVulnerable ? 'medium' : 'strong')
			];
			return $result;
		}

		// Проверка на тройку
		if (max($rankCounts) >= 3) {
			$tripRank = array_search(3, $rankCounts);
			$tripCards = array_filter($allCards, function($card) use ($tripRank) {
				return $card['rank'] === $tripRank;
			});
			$kickers = array_filter($allCards, function($card) use ($tripRank) {
				return $card['rank'] !== $tripRank;
			});
			usort($kickers, function($a, $b) { return $b['value'] - $a['value']; });

			$result = [
				'strength' => 'three_of_a_kind',
				'description' => 'Three of a Kind ('.$tripRank.')',
				'combination' => $tripCards,
				'kickers' => array_slice($kickers, 0, 2),
				'draws' => [],
				'outs' => 0,
				'nut_status' => ($tripRank == 'A') ? 'strong' :
					(max(array_column($board, 'value')) > $tripRank ? 'medium' : 'strong')
			];
			return $result;
		}

		// Проверка на две пары
		if (count(array_filter($rankCounts, function($v) { return $v >= 2; })) >= 2) {
			$pairRanks = array_keys(array_filter($rankCounts, function($v) { return $v >= 2; }));
			rsort($pairRanks);
			$topPair = $pairRanks[0];
			$secondPair = $pairRanks[1];

			$topPairCards = array_filter($allCards, function($card) use ($topPair) {
				return $card['rank'] === $topPair;
			});
			$secondPairCards = array_filter($allCards, function($card) use ($secondPair) {
				return $card['rank'] === $secondPair;
			});
			$kickers = array_filter($allCards, function($card) use ($topPair, $secondPair) {
				return $card['rank'] !== $topPair && $card['rank'] !== $secondPair;
			});
			usort($kickers, function($a, $b) { return $b['value'] - $a['value']; });

			$isStrong = ($topPair == 'A' || $topPair == 'K') && ($secondPair == 'Q' || $secondPair == 'J');
			$result = [
				'strength' => 'two_pair',
				'description' => 'Two Pair ('.$topPair.' and '.$secondPair.')',
				'combination' => array_merge(array_slice($topPairCards, 0, 2), array_slice($secondPairCards, 0, 2)),
				'kickers' => array_slice($kickers, 0, 1),
				'draws' => [],
				'outs' => 0,
				'nut_status' => $isStrong ? 'strong' :
					(max(array_column($board, 'value')) > $topPair ? 'medium' : 'strong')
			];
			return $result;
		}

		// Проверка на пару
		if (max($rankCounts) >= 2) {
			$pairRank = array_search(2, $rankCounts);
			$pairCards = array_filter($allCards, function($card) use ($pairRank) {
				return $card['rank'] === $pairRank;
			});
			$kickers = array_filter($allCards, function($card) use ($pairRank) {
				return $card['rank'] !== $pairRank;
			});
			usort($kickers, function($a, $b) { return $b['value'] - $a['value']; });

			// Определяем тип пары
			$heroHoleRanks = array_column($hole, 'rank');
			$boardRanks = array_column($board, 'rank');
			$boardValues = array_column($board, 'value');
			rsort($boardValues); // Сортируем борд по убыванию

			$isPocketPair = count($hole) == 2 && $hole[0]['rank'] == $hole[1]['rank'] && $hole[0]['rank'] == $pairRank;
			$isBoardPair = in_array($pairRank, $boardRanks);
			$isTopPair = false;
			$isSecondPair = false;
			$isBottomPair = false;

			if ($isPocketPair) {
				// Для карманной пары определяем ее уровень относительно борда
				$pocketPairValue = $hole[0]['value'];
				$higherPairsOnBoard = 0;

				foreach ($boardValues as $boardValue) {
					if ($boardValue > $pocketPairValue) {
						$higherPairsOnBoard++;
					}
				}

				if ($higherPairsOnBoard >= 2) {
					$pairLevel = 'third_pair';
				} elseif ($higherPairsOnBoard == 1) {
					$pairLevel = 'second_pair';
				} else {
					$pairLevel = 'top_pair';
				}
			} elseif ($isBoardPair) {
				// Если пара на борде
				if ($isPocketPair) {
					// Для карманной пары сравниваем ее с картами на борде
					if ($hole[0]['value'] > $board[0]['value']) {
						$pairLevel = 'overpair';
					} elseif ($hole[0]['value'] > $board[1]['value']) {
						$pairLevel = 'top_pair';
					} elseif ($hole[0]['value'] > $board[2]['value']) {
						$pairLevel = 'second_pair';
					} else {
						$pairLevel = 'bottom_pair';
					}
					$description = 'Pocket pair of '.$pairRank.' ('.$pairLevel.')';
					$nutStatus = 'medium';
					if ($pairRank == 'A' || $pairRank == 'K') {
						$nutStatus = 'strong';
					} elseif ($pairRank >= 'J') {
						$nutStatus = 'medium_strong';
					}

					$result['nut_status'] = $nutStatus;
					$result['description'] = $description;
					$result['is_pocket_pair'] = true; // Добавляем флаг карманной пары
				} elseif ($pairRank == $board[0]['rank']) {
					$description = 'Top pair of '.$pairRank;
				} elseif (isset($board[1]) && $pairRank == $board[1]['rank']) {
					$description = 'Second pair of '.$pairRank;
				} else {
					$description = 'Weak pair of '.$pairRank;
				}
			} elseif (in_array($pairRank, $heroHoleRanks)) {
				// Если пара состоит из одной карты руки и одной с борда
				if ($pairRank == $board[0]['rank']) {
					$isTopPair = true;
				} else {
					$isSecondPair = true;
				}
			}

			$description = 'Pair of '.$pairRank;
			if ($isPocketPair) {
				$description .= ' (pocket pair - '.$pairLevel.')';
			} elseif ($isTopPair) {
				$description .= ' (top pair)';
			} elseif ($isSecondPair) {
				$description .= ' (second pair)';
			} elseif ($isBottomPair) {
				$description .= ' (bottom pair)';
			} else {
				$description .= ' (weak pair)';
			}

			$result = [
				'strength' => 'pair',
				'description' => $description,
				'combination' => $pairCards,
				'kickers' => array_slice($kickers, 0, 3),
				'draws' => [],
				'outs' => 0,
				'nut_status' => $isTopPair ?
					($kickers[0]['value'] >= 10 ? 'strong' : 'weak') :
					($isPocketPair ?
						($pairLevel == 'top_pair' ? 'strong' :
							($pairLevel == 'second_pair' ? 'medium' : 'weak')) :
						'weak')
			];

			if ($street == 'turn') {
				// Уменьшаем силу дро, так как осталась только одна карта
				foreach ($result['draws'] as &$draw) {
					if (strpos($draw, 'draw') !== false) {
						$result['outs'] = max(1, round($result['outs'] * 0.5));
					}
				}
			}

			return $result;
		}

		// Если ничего не найдено - старшая карта
		usort($allCards, function($a, $b) { return $b['value'] - $a['value']; });
		$heroCards = array_column($hole, 'rank');
		$boardCards = array_column($board, 'rank');

		// Проверяем, есть ли у героя карта, совпадающая со старшей на борде
		$hasTopCard = in_array($allCards[0]['rank'], $heroCards);

		$result = [
			'strength' => 'high_card',
			'description' => $hasTopCard ?
				'High Card ('.$allCards[0]['rank'].')' :
				'No Pair (best board card: '.$allCards[0]['rank'].')',
			'combination' => [],
			'kickers' => array_slice($allCards, 1, 4),
			'draws' => [],
			'outs' => 0,
			'nut_status' => 'weak',
			'has_pair_with_board' => false // Явно указываем, что нет пары с бордом
		];

		// Анализ дро и потенциалов
		if ($street != 'river' && count($board) >= 3) {
			$draws = [];
			$outs = 0;

			// Проверка на флеш-дро
			$holeSuits = array_column($hole, 'suit');
			$holeSuitCounts = array_count_values($holeSuits);
			$flushDrawSuit = null;

			foreach ($suitCounts as $suit => $count) {
				if ($count == 4 && isset($holeSuitCounts[$suit])) {
					$flushDrawSuit = $suit;
					break;
				}
			}

			if ($flushDrawSuit) {
				$missingCards = array_filter($hole, function($card) use ($flushDrawSuit) {
					return $card['suit'] === $flushDrawSuit;
				});

				$draws[] = 'flush_draw';
				$highCardInSuit = max(array_column($hole, 'value')) >= 10;
				$outs += $highCardInSuit ? 9 : 7; // Меньше аутов, если нет старших карт

				// Проверка на нут-флеш дро
				$nutFlushDraw = false;
				if (in_array(14, array_column($missingCards, 'value'))) {
					$nutFlushDraw = true;
					$draws[] = 'nut_flush_draw';
				}
			}

			// Проверка на стрит-дро
			$allValues = array_unique(array_merge($values, $holeValues));
			sort($allValues);

			// Проверка на открытое стрит-дро (8 аутов)
			for ($i = 0; $i < count($allValues) - 3; $i++) {
				if ($allValues[$i+3] - $allValues[$i] == 4) {
					$draws[] = 'open_ended_straight_draw';
					$outs += 8;
					break;
				}
			}

			// Проверка на гатшот (4 аутов)
			for ($i = 0; $i < count($allValues) - 2; $i++) {
				if ($allValues[$i+2] - $allValues[$i] == 3) {
					$draws[] = 'gutshot_straight_draw';
					$outs += 4;
					break;
				}
			}

			// Проверка на бэкдор-флеш (очень слабое дро)
			if (!$flushDrawSuit) {
				foreach ($suitCounts as $suit => $count) {
					if ($count == 3 && isset($holeSuitCounts[$suit])) {
						$draws[] = 'backdoor_flush_draw';
						$outs += 0.5; // Очень мало аутов
						break;
					}
				}
			}

			// Обновляем результат с информацией о дро
			if (!empty($draws)) {
				$result['draws'] = $draws;
				$result['outs'] = $outs;

				// Обновляем описание в зависимости от наличия пары
				if ($result['has_pair_with_board']) {
					$result['description'] .= ' + '.implode(', ', $draws).' ('.$outs.' outs)';
				} else {
					$result['description'] = 'No Pair + '.implode(', ', $draws).' ('.$outs.' outs)';
				}
			}
		}

		return $result;
	}

	// Улучшенная функция для анализа текстуры борда
	function analyzeBoardTexture($boardCards) {
		$board = parseCards($boardCards);
		if (count($board) < 3) return ['texture' => 'preflop', 'danger' => 0];

		$ranks = array_column($board, 'rank');
		$suits = array_column($board, 'suit');
		$values = array_column($board, 'value');
		rsort($values); // Сортируем по убыванию

		$textures = [];
		$baseDanger = 0;
		$multipliers = 1.0;

		// Анализ пар и сетов
		$rankCounts = array_count_values($ranks);
		$paired = count(array_filter($rankCounts, function($v) { return $v >= 2; })) > 0;

		if ($paired) {
			$pairCount = count(array_filter($rankCounts, function($v) { return $v >= 2; }));
			$maxPair = max($rankCounts);

			if ($maxPair == 3) {
				$textures[] = 'trips_on_board';
				$baseDanger += 70; // Сет на борде - очень опасно
			} elseif ($maxPair == 2) {
				if ($pairCount >= 2) {
					$textures[] = 'multi_paired';
					$baseDanger += 60; // Две пары на борде
				} else {
					$textures[] = 'paired';
					$baseDanger += 40; // Одна пара на борде
				}
			}
		}

		// Анализ силы карт на борде относительно возможных рук героя
		$topBoardCard = $values[0];
		$secondBoardCard = $values[1] ?? 0;

		// Опасность высоких карт зависит от их ранга
		$highCardsDanger = 0;
		foreach ($values as $value) {
			if ($value >= 14) $highCardsDanger += 25; // Увеличено с 20
			elseif ($value >= 12) $highCardsDanger += 20; // Увеличено с 15 (K,Q)
			elseif ($value >= 10) $highCardsDanger += 15; // Увеличено с 10 (J,T)
		}

		if ($highCardsDanger > 0) {
			$textures[] = 'high_cards';
			$baseDanger += $highCardsDanger;

			// Дополнительная опасность, если есть две сильные карты
			if ($topBoardCard >= 12 && $secondBoardCard >= 10) {
				$baseDanger += 15;
				$multipliers *= 1.3;
			}
		}

		if ($paired && $topBoardCard >= 12) {
			$baseDanger += 15; // Дополнительная опасность если пара с высокими картами
		}

		// Анализ флеш-потенциала
		$suitCounts = array_count_values($suits);
		$flushPotential = max($suitCounts) >= 3;

		if ($flushPotential) {
			$textures[] = 'flush_possible';
			$baseDanger += 25;

			if (max($suitCounts) == 4) {
				$textures[] = 'flush_draw';
				$baseDanger += 20;
				$multipliers *= 1.5; // Увеличиваем общую опасность при флеш-дро
			}
		}

		// Анализ стрит-потенциала
		$uniqueValues = array_unique($values);
		sort($uniqueValues);
		$straightPotential = false;

		// Проверка на возможные стриты
		if (count($uniqueValues) >= 3) {
			$gaps = [];
			for ($i = 1; $i < count($uniqueValues); $i++) {
				$gap = $uniqueValues[$i] - $uniqueValues[$i-1];
				if ($gap > 1) $gaps[] = $gap;
			}

			$totalGap = array_sum($gaps);
			$neededCards = count($gaps) + 1;

			if ($totalGap <= 4 && $neededCards <= 2) {
				$straightPotential = true;
				$textures[] = 'straight_possible';
				$baseDanger += 30;

				if ($totalGap <= 2 && $neededCards == 1) {
					$textures[] = 'straight_draw';
					$baseDanger += 25;
					$multipliers *= 1.3;
				}
			}
		}

		// Анализ высоких карт
		$highCards = array_filter($values, function($v) { return $v >= 11; });
		$highCardCount = count($highCards);
		if ($highCardCount >= 2) {
			$baseDanger += ($highCardCount * 10); // 10% за каждую высокую карту

			// Добавляем тег только если его еще нет
			if (!in_array('high_cards', $textures)) {
				$textures[] = 'high_cards';
			}

			// Дополнительная опасность для двух сильных карт
			if ($topBoardCard >= 12 && $secondBoardCard >= 10) {
				$baseDanger += 15;
				$multipliers *= 1.3;
			}
		}

		// Дополнительные факторы опасности
		$isWet = $flushPotential || $straightPotential || $paired;
		$isDry = !$isWet;

		if ($isDry && $topBoardCard >= 12) {
			$baseDanger += 20;
			if (!in_array('high_card_dry', $textures)) {
				$textures[] = 'high_card_dry';
			}
		}

		if ($straightPotential) {
			// Только для флопа и терна учитываем полные дро
			if (count($board) <= 4) { // флоп или терн
				if ($totalGap <= 4 && $neededCards <= 2) {
					$straightPotential = true;
					$textures[] = 'straight_possible';
					$baseDanger += 30;

					if ($totalGap <= 2 && $neededCards == 1) {
						$textures[] = 'straight_draw';
						$baseDanger += 25;
						$multipliers *= 1.3;
					}
				}
			} else {
				// Для ривера стрит-дро уже неактуальны
				$baseDanger += 10; // минимальная опасность
			}
		}

		// Итоговый расчет опасности с учетом множителей
		$danger = min(round($baseDanger * $multipliers), 85);
		$textureDescription = !empty($textures) ? implode(', ', $textures) : 'neutral';

		return [
			'texture' => $textureDescription,
			'danger' => $danger,
			'is_wet' => $isWet,
			'is_dry' => $isDry,
			'top_board_card' => $topBoardCard,
			'second_board_card' => $secondBoardCard
		];
	}

	function calculateBoardDanger($boardCards) {
		$texture = analyzeBoardTexture($boardCards);
		return $texture['danger'] ?? 0; // Возвращаем уровень опасности из анализа текстуры
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

	function calculateHeroPotCommitment($pdo, $handId, $heroId, $heroStack) {
		if (empty($heroId)) return 0;

		$stmt = $pdo->prepare("
			SELECT SUM(amount) as total 
			FROM actions 
			WHERE hand_id = :hand_id AND player_id = :player_id
		");
		$stmt->execute([':hand_id' => $handId, ':player_id' => $heroId]);
		$result = $stmt->fetch();
		$totalCommitted = $result['total'] ?? 0;

		// Рассчитываем текущий стек как начальный стек минус все ставки
		$currentStack = $heroStack - $totalCommitted;

		return [
			'total_committed' => $totalCommitted,
			'current_stack' => $currentStack
		];
	}

	function calculatePotOdds($pdo, $handId, $street) {
		// Total pot on current street
		$stmt = $pdo->prepare("
			SELECT SUM(amount) as total 
			FROM actions 
			WHERE hand_id = :hand_id AND street = :street
		");
		$stmt->execute([':hand_id' => $handId, ':street' => $street]);
		$pot = $stmt->fetch()['total'] ?? 0;

		// Current bet to call on current street
		$stmt = $pdo->prepare("
			SELECT MAX(amount) as max_bet 
			FROM actions 
			WHERE hand_id = :hand_id AND street = :street 
			AND action_type IN ('bet', 'raise', 'all-in')
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

	function getNextToAct($pdo, $handId, $currentStreet, $heroPosition) {
		$positionsOrder = ['BTN', 'SB', 'BB', 'UTG', 'UTG+1', 'MP', 'HJ', 'CO'];
		$startPosition = ($currentStreet == 'preflop') ? 'UTG' : 'SB';

		// 1. Получаем всех игроков в раздаче
		$playersStmt = $pdo->prepare("
			SELECT DISTINCT a.player_id, p.nickname, a.position 
			FROM actions a
			JOIN players p ON a.player_id = p.player_id
			WHERE a.hand_id = :hand_id
		");
		$playersStmt->execute([':hand_id' => $handId]);
		$allPlayers = $playersStmt->fetchAll();

		if (empty($allPlayers)) return null;

		// 2. Получаем ID всех сфолдивших игроков
		$foldedStmt = $pdo->prepare("
			SELECT DISTINCT player_id 
			FROM actions 
			WHERE hand_id = :hand_id AND action_type = 'fold'
		");
		$foldedStmt->execute([':hand_id' => $handId]);
		$foldedPlayers = array_column($foldedStmt->fetchAll(), 'player_id');

		// 3. Получаем последнюю позицию, которая действовала на текущей улице
		$lastActionStmt = $pdo->prepare("
			SELECT position 
			FROM actions 
			WHERE hand_id = :hand_id AND street = :street
			ORDER BY sequence_num DESC 
			LIMIT 1
		");
		$lastActionStmt->execute([':hand_id' => $handId, ':street' => $currentStreet]);
		$lastPosition = $lastActionStmt->fetchColumn();

		// 4. Если действий на улице еще не было — начинаем с начальной позиции
		if (!$lastPosition) {
			$nextPosition = $startPosition;
		} else {
			// Ищем следующую позицию после последней
			$currentIndex = array_search($lastPosition, $positionsOrder);
			$nextIndex = ($currentIndex + 1) % count($positionsOrder);
			$nextPosition = $positionsOrder[$nextIndex];
		}

		// 5. Проверяем всех возможных следующих игроков (максимум 8 итераций)
		$checkedPositions = [];
		while (!in_array($nextPosition, $checkedPositions)) {
			$checkedPositions[] = $nextPosition;

			// Проверяем, есть ли игрок на этой позиции
			$playerFound = false;
			$playerData = null;
			foreach ($allPlayers as $player) {
				if ($player['position'] === $nextPosition) {
					$playerFound = true;
					$playerData = $player;
					break;
				}
			}

			// Если игрок не найден → null (его еще не было в раздаче)
			if (!$playerFound) {
				return null;
			}

			// Если игрок фолдил → переходим к следующему
			if (in_array($playerData['player_id'], $foldedPlayers)) {
				$currentIndex = array_search($nextPosition, $positionsOrder);
				$nextIndex = ($currentIndex + 1) % count($positionsOrder);
				$nextPosition = $positionsOrder[$nextIndex];
				continue;
			}

			// Если это герой → null
			if ($playerData['position'] === $heroPosition) {
				return null;
			}

			// Игрок найден, не фолдил, и это не герой → возвращаем его
			return $playerData;
		}

		// Если прошли все позиции и не нашли подходящего игрока
		return null;
	}

	function isActionRequired($pdo, $handId, $street, $heroPos) {
		// Check if this is a new street (no actions at all)
		$stmt = $pdo->prepare("
			SELECT COUNT(*) as actions 
			FROM actions 
			WHERE hand_id = :hand_id AND street = :street
		");
		$stmt->execute([':hand_id' => $handId, ':street' => $street]);
		$isNewStreet = $stmt->fetch()['actions'] == 0;

		// If new street - action is required only if hero is first to act
		if ($isNewStreet) {
			$firstToAct = ($street == 'preflop') ? 'UTG' : 'SB';
			return $heroPos === $firstToAct;
		}

		// For existing streets - check if hero hasn't acted yet and there are bets
		$stmt = $pdo->prepare("
			SELECT COUNT(*) as actions 
			FROM actions 
			WHERE hand_id = :hand_id AND street = :street AND position = :position
		");
		$stmt->execute([':hand_id' => $handId, ':street' => $street, ':position' => $heroPos]);
		$hasActed = $stmt->fetch()['actions'] > 0;

		if (!$hasActed) {
			$stmt = $pdo->prepare("
				SELECT COUNT(*) as bets 
				FROM actions 
				WHERE hand_id = :hand_id AND street = :street AND action_type IN ('bet', 'raise', 'all-in')
			");
			$stmt->execute([':hand_id' => $handId, ':street' => $street]);
			$hasBets = $stmt->fetch()['bets'] > 0;

			return $hasBets;
		}

		return false;
	}

	function getLastAggressor($streetActions, $currentStreet) {
		$lastAggressor = null;
		$maxBet = 0;

		foreach ($streetActions as $action) {
			if ($action['is_aggressive'] && $action['street'] === $currentStreet) {
				$betSize = (float)$action['amount'];
				if ($betSize >= $maxBet) {
					$maxBet = $betSize;
					$lastAggressor = [
						'player_id' => $action['player_id'],
						'player_name' => $action['player_nickname'],
						'position' => $action['position'],
						'action_type' => $action['action_type'],
						'amount' => $action['amount'],
						'bet_size_relative' => $maxBet > 0 ? round($betSize / $maxBet, 2) : 0
					];
				}
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

	function evaluateShowdownHand($showdownCards, $handId, $pdo) {
		// Получаем карты на столе для этой раздачи
		$stmt = $pdo->prepare("SELECT board FROM hands WHERE hand_id = :hand_id");
		$stmt->execute([':hand_id' => $handId]);
		$boardCards = $stmt->fetchColumn();

		// Если нет карт на столе (например, все сфолдили префлоп), оцениваем только карты игрока
		if (empty($boardCards)) {
			return evaluateHandStrength($showdownCards, '', 'river');
		}

		// Оцениваем комбинацию с учетом карт на столе
		return evaluateHandStrength($showdownCards, $boardCards, 'river');
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
			'pot_commitment' => calculateHeroPotCommitment($pdo, $input['hand_id'], $heroInfo['id'], $handInfo['hero_stack']),
			'is_pocket_pair' => true,
			'relative_strength' => 'medium_strong'
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
				'tendencies' => analyzePlayerTendencies($player)
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
				'last_aggressor' => getLastAggressor($actions, $street)
			];
		}, $actionsByStreet, array_keys($actionsByStreet)),
		'showdown_history' => array_map(function($showdown) use ($pdo) {
			return [
				'player_name' => $showdown['nickname'],
				'historical_hand_id' => $showdown['hand_id'],
				'cards' => parseCards($showdown['cards']),
				'hand_strength' => evaluateShowdownHand($showdown['cards'], $showdown['hand_id'], $pdo),
				'note' => 'Исторические данные для оценки диапазона'
			];
		}, $showdownInfo),
		'hand_progress' => [
			'street_sequence' => getStreetSequence($input['current_street']),
			'next_to_act' => getNextToAct($pdo, $input['hand_id'], $input['current_street'], $input['hero_position']),
			'action_required' => isActionRequired($pdo, $input['hand_id'], $input['current_street'], $input['hero_position']),
			'is_new_street' => !isset($actionsByStreet[$input['current_street']]) || empty($actionsByStreet[$input['current_street']]),
			'last_aggressor' => isset($actionsByStreet[$input['current_street']]) && !empty($actionsByStreet[$input['current_street']]) ?
				getLastAggressor($actionsByStreet[$input['current_street']], $input['current_street'])
				: null,
			'available_actions' => [] // Определим ниже
		]
	];

	// Определяем доступные действия
	if ($response['hand_progress']['is_new_street']) {
		// Если новая улица, можно Check или Bet
		$response['hand_progress']['available_actions'] = ['Check', 'Bet', 'All-in'];
	} else {
		// Если уже были ставки, можно Fold/Call/Raise
		$response['hand_progress']['available_actions'] = ['Fold', 'Call', 'Raise', 'All-in'];
	}

//	die(print_r($response));

	$response = json_encode($response, JSON_UNESCAPED_UNICODE);
	$content = "
		Отвечай максимально коротко: действие (если рейз, то сколько) | короткое описание
		Примеры корректных ответов:
		Raise 35 BB | Топ пара + nut flush дро (15 аутов)
		Fold | Слабый кикер на опасном борде (A5 на QQ5)
		Call 12 BB | Средняя пара + pot odds 25% (остаток стека 120 BB)
		All-in 85 BB | Премиум пара (AA) против 3-бета
		Важные правила:
		- 'showdown_history' содержит только ИСТОРИЧЕСКИЕ данные (прошлые вскрытия игроков), а не их текущие карты в этой раздаче
		- Используй эти данные только для оценки диапазонов оппонентов, но не как факт их текущих карт
		- В текущей раздаче карты оппонентов неизвестны, кроме карт героя
		- На ривере и терне не учитывай дро (только готовые руки)
		- Если статистики игрока < 10 рук - не делай предположений о его стиле
		- Оллин после пассивной игры = очень сильный сигнал
		- На бордах с высокими картами (K,Q,J) средние пары слабее
		- Если danger_level > 50 - будь осторожен с medium strength hands
		- Всегда учитывай bet_size_relative (большие ставки = сильные руки)
		- Учитывай позицию: ранняя позиция = сильнее диапазон
		- Всегда указывай конкретный размер ставки (если не Fold/Check)
		$response
	";

//	die(print_r($content));

	// Send to AI for analysis
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
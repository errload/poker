<?php

namespace FourBet;

class HandEvaluator
{
	private const RANK_VALUES = [
		'2' => 2, '3' => 3, '4' => 4, '5' => 5,
		'6' => 6, '7' => 7, '8' => 8, '9' => 9,
		'T' => 10, 'J' => 11, 'Q' => 12, 'K' => 13, 'A' => 14
	];

	public static function evaluateHand(\PDO $pdo, int $handId): array
	{
		$handStmt = $pdo->prepare("
			SELECT hero_cards, board, is_completed 
			FROM hands 
			WHERE hand_id = :hand_id
		");
		$handStmt->execute([':hand_id' => $handId]);
		$handData = $handStmt->fetch(\PDO::FETCH_ASSOC);

		if (!$handData) {
			return ['strength' => 'invalid', 'description' => 'Distribution not found'];
		}

		$heroCardsString = $handData['hero_cards'] ?? '';
		$boardString = $handData['board'] ?? '';
		$isCompleted = (bool) $handData['is_completed'];

		$parsedHeroCards = self::parseCards($heroCardsString);
		$parsedBoardCards = self::parseCards($boardString);

		// Проверяем валидность карт героя
		if (count($parsedHeroCards) !== 2) {
			return ['strength' => 'invalid', 'description' => 'Invalid cards'];
		}

		if (empty($parsedBoardCards)) {
			return self::evaluatePreflopHand($parsedHeroCards);
		}

		$allCards = array_merge($parsedHeroCards, $parsedBoardCards);
		$result = self::evaluateCombination($allCards, $parsedHeroCards, $parsedBoardCards);

		if (!$isCompleted && count($parsedBoardCards) < 5) {
			$draws = self::evaluateDraws($parsedHeroCards, $parsedBoardCards);
			if (!empty($draws['draws'])) {
				$result['draws'] = $draws['draws'];
				$result['outs'] = $draws['outs'];
				$result['description'] .= ' + ' . implode(', ', $draws['draws']) . ' (' . $draws['outs'] . ' аутов)';
			}
		}

		return $result;
	}

	/**
	 * Парсит строку с картами в структурированный массив
	 * @param string $cardString Строка с картами (например "As Kd Qh" или "Td 9c")
	 * @return array Массив карт
	 */
	private static function parseCards(string $cardString): array
	{
		// Если входная строка пустая - сразу возвращаем пустой массив
		if (empty($cardString)) return [];

		$cards = [];
		$uniqueCards = [];

		// Разбиваем строку по пробелам (поддерживаем несколько форматов: "AsKd" или "As Kd")
		$parts = preg_split('/\s+/', trim($cardString));

		foreach ($parts as $card) {
			// Проверяем соответствие формата карты (например "As" или "Kd")
			if (preg_match('/^([2-9TJQKA])([cdhs])$/i', $card, $matches)) {
				$rank = strtoupper($matches[1]);
				$suit = strtolower($matches[2]);
				$fullCard = $rank . $suit;

				// Проверка на дубликаты карт
				if (isset($uniqueCards[$fullCard])) {
					return [];
				}

				// Запоминаем карту как уникальную
				$uniqueCards[$fullCard] = true;

				// Добавляем карту в результат
				$cards[] = [
					'rank' => $rank,
					'suit' => $suit,
					'full' => $fullCard,
					'value' => self::RANK_VALUES[$rank] ?? 0
				];
			}
		}

		// Сортируем карты по убыванию значения (старшие карты сначала)
		usort($cards, function($a, $b) {
			return $b['value'] - $a['value'];
		});

		return $cards;
	}

	/**
	 * Оценивает силу стартовой руки (двух карт) на префлопе
	 * @param array $holeCards Массив с картами руки
	 * @return array Массив с оценкой силы руки, содержащий ключи 'strength' и 'description'
	 */
	private static function evaluatePreflopHand(array $holeCards): array
	{
		// Извлекаем базовые параметры карт
		$rank1 = $holeCards[0]['rank'];
		$rank2 = $holeCards[1]['rank'];
		$suit1 = $holeCards[0]['suit'];
		$suit2 = $holeCards[1]['suit'];
		$value1 = $holeCards[0]['value'];
		$value2 = $holeCards[1]['value'];

		// Базовые характеристики руки
		$isPair = $rank1 === $rank2;
		$isSuited = $suit1 === $suit2;
		$isConnector = abs($value1 - $value2) <= 1;
		$highCards = ['A', 'K', 'Q', 'J', 'T'];
		$suitedSuffix = $isSuited ? 's' : 'o';

		// Классификация пар по силе
		$isPremiumPair = $isPair && in_array($rank1, ['A', 'K', 'Q']);
		$isStrongPair = $isPair && in_array($rank1, ['J', 'T']);
		$isMediumPair = $isPair && in_array($rank1, ['9', '8', '7']);
		$isWeakPair = $isPair && !$isPremiumPair && !$isStrongPair && !$isMediumPair;

		// Непарные руки
		$isAceWithGoodKicker = ($rank1 === 'A' && in_array($rank2, ['K', 'Q', 'J', 'T'])) ||
			($rank2 === 'A' && in_array($rank1, ['K', 'Q', 'J', 'T']));
		$isBothHighCards = in_array($rank1, $highCards) && in_array($rank2, $highCards);

		// Спекулятивные руки
		$isSpeculativeSuited = $isSuited && (
			($rank1 === 'A' && $rank2 < 'T') ||
			($rank2 === 'A' && $rank1 < 'T') ||
			($isConnector && $value1 >= 7 && $value2 >= 7)
		);

		// Оценка силы руки по приоритетам (от сильных к слабым)
		// Премиум пары и AK (самые сильные стартеры)
		if ($isPremiumPair) {
			return ['strength' => 'premium', 'description' => "Premium pair {$rank1}{$rank1}"];
		}

		// AK (особый случай - премиум даже без пары)
		if (($rank1 === 'A' && $rank2 === 'K') || ($rank1 === 'K' && $rank2 === 'A')) {
			return ['strength' => 'premium', 'description' => "Premium hand {$rank1}{$rank2}{$suitedSuffix}"];
		}

		// Сильные пары (JJ, TT)
		if ($isStrongPair) {
			return ['strength' => 'strong', 'description' => "Strong pair {$rank1}{$rank1}"];
		}

		// Руки с тузом и хорошим кикером (AQ, AJs, ATs)
		if ($isAceWithGoodKicker) {
			$handName = $rank1 === 'A' ? "{$rank1}{$rank2}" : "{$rank2}{$rank1}";
			$strength = ($isSuited && $rank2 !== 'T') ? 'strong' : 'medium';
			return ['strength' => $strength, 'description' => "Ace with kicker {$handName}{$suitedSuffix}"];
		}

		// Две высокие карты (KQ, KJ, QJ)
		if ($isBothHighCards) {
			$handName = $value1 > $value2 ? "{$rank1}{$rank2}" : "{$rank2}{$rank1}";
			$handType = '';

			if (($rank1 === 'K' && $rank2 === 'Q') || ($rank1 === 'Q' && $rank2 === 'K')) {
				$strength = $isSuited ? 'strong' : 'medium';
				$handType = $isSuited ? 'Strong suited' : 'Medium offsuit';
			}
			elseif (
				($rank1 === 'K' && $rank2 === 'J') || ($rank1 === 'J' && $rank2 === 'K') ||
				($rank1 === 'Q' && $rank2 === 'J') || ($rank1 === 'J' && $rank2 === 'Q')
			) {
				$strength = $isSuited ? 'medium' : 'marginal';
				$handType = $isSuited ? 'Medium suited' : 'Marginal offsuit';
			}
			else {
				$strength = 'marginal';
				$handType = 'Marginal high';
			}

			return [
				'strength' => $strength,
				'description' => "{$handType} {$handName}{$suitedSuffix}"
			];
		}

		// Средние пары (99-77)
		if ($isMediumPair) {
			return ['strength' => 'medium', 'description' => "Medium pair {$rank1}{$rank1}"];
		}

		// Спекулятивные одномастные руки (Axs, коннекторы)
		if ($isSpeculativeSuited) {
			$handName = $value1 > $value2 ? "{$rank1}{$rank2}" : "{$rank2}{$rank1}";
			$type = ($rank1 === 'A' || $rank2 === 'A') ? 'Axs' : 'Suited connector';
			return ['strength' => 'speculative', 'description' => "{$type} {$handName}s"];
		}

		// Слабые пары (66-22)
		if ($isWeakPair) {
			return ['strength' => 'weak', 'description' => "Weak pair {$rank1}{$rank1}"];
		}

		// Остальные одномастные руки
		if ($isSuited) {
			$handName = $value1 > $value2 ? "{$rank1}{$rank2}" : "{$rank2}{$rank1}";
			return ['strength' => 'marginal', 'description' => "Marginal suited {$handName}s"];
		}

		// Коннекторы (не одномастные)
		if ($isConnector) {
			$handName = $value1 > $value2 ? "{$rank1}{$rank2}" : "{$rank2}{$rank1}";
			if ($value1 >= 10 || $value2 >= 10) {
				$strength = 'marginal';
			} elseif ($value1 >= 8 || $value2 >= 8) {
				$strength = 'weak';
			} else {
				$strength = 'fold';
			}
			return ['strength' => $strength, 'description' => "Offsuit connector {$handName}o"];
		}

		// Все остальные руки (слабые)
		return ['strength' => 'weak', 'description' => "Weak hand {$rank1}{$rank2}"];
	}

	public static function evaluateCombination(array $allCards, array $holeCards, array $boardCards): array
	{
		$ranks = array_column($allCards, 'rank');
		$suits = array_column($allCards, 'suit');
		$values = array_column($allCards, 'value');
		$rankCounts = array_count_values($ranks);
		$suitCounts = array_count_values($suits);
		arsort($rankCounts);
		arsort($suitCounts);

		// Проверка комбинаций от старшей к младшей
		if ($flushResult = self::checkFlush($allCards, $suits, $suitCounts, $holeCards, $boardCards)) {
			return $flushResult;
		}

		if ($straightResult = self::checkStraight($allCards, $values)) {
			return $straightResult;
		}

		if ($quadsResult = self::checkQuads($allCards, $rankCounts)) {
			return $quadsResult;
		}

		if ($fullHouseResult = self::checkFullHouse($allCards, $rankCounts)) {
			return $fullHouseResult;
		}

		if ($tripsResult = self::checkTrips($allCards, $rankCounts, $holeCards, $boardCards)) {
			return $tripsResult;
		}

		if ($twoPairResult = self::checkTwoPair($allCards, $rankCounts, $holeCards, $boardCards)) {
			return $twoPairResult;
		}

		if ($pairResult = self::checkPair($allCards, $rankCounts, $holeCards, $boardCards)) {
			return $pairResult;
		}

		return self::checkHighCard($allCards, $holeCards, $boardCards);
	}

	public static function checkFlush(array $allCards, array $suits, array $suitCounts, array $holeCards, array $boardCards): ?array
	{
		$flushSuit = null;
		$flushCards = [];

		foreach ($suitCounts as $suit => $count) {
			if ($count >= 5) {
				$flushSuit = $suit;
				$flushCards = array_filter($allCards, function($card) use ($suit) {
					return $card['suit'] === $suit;
				});
				usort($flushCards, function($a, $b) { return $b['value'] - $a['value']; });
				break;
			}
		}

		if (!$flushSuit) return null;

		$nutFlush = $flushCards[0]['value'] == 14 && in_array(13, array_column($flushCards, 'value'));
		$hasHigherCards = (max(array_column($boardCards, 'value')) > $flushCards[0]['value']);

		return [
			'strength' => 'flush',
			'description' => 'Flush ('.$flushSuit.')',
			'combination' => array_slice($flushCards, 0, 5),
			'kickers' => [],
			'nut_status' => $nutFlush ? 'nut_flush' : ($hasHigherCards ? 'weak' : 'strong')
		];
	}

	public static function checkStraight(array $allCards, array $values): ?array
	{
		$uniqueValues = array_unique($values);
		rsort($uniqueValues);

		// Проверка для обычного стрита (A-5-4-3-2)
		if (in_array(14, $uniqueValues)) {
			$uniqueValues[] = 1; // Добавляем младший туз для проверки стрита A-2-3-4-5
		}

		$uniqueValues = array_unique($uniqueValues);
		sort($uniqueValues);

		$straightLength = 1;
		$straightCards = [];
		for ($i = 1; $i < count($uniqueValues); $i++) {
			if ($uniqueValues[$i] == $uniqueValues[$i-1] + 1) {
				$straightLength++;
				if ($straightLength >= 5) {
					$straightCards = array_slice($uniqueValues, $i-4, 5);
					rsort($straightCards);
				}
			} else {
				$straightLength = 1;
			}
		}

		if (empty($straightCards)) return null;

		$nutStraight = $straightCards[0] == 14;
		$isVulnerable = ($straightCards[0] < 12 && count(array_unique($straightCards)) == 5);

		return [
			'strength' => 'straight',
			'description' => 'Straight ('.implode('-', $straightCards).')',
			'combination' => [],
			'kickers' => [],
			'nut_status' => $nutStraight ? 'nut_straight' : ($isVulnerable ? 'medium' : 'strong')
		];
	}

	public static function checkQuads(array $allCards, array $rankCounts): ?array
	{
		if (max($rankCounts) < 4) return null;

		$quadRank = array_search(4, $rankCounts);
		$quadCards = array_filter($allCards, function($card) use ($quadRank) {
			return $card['rank'] === $quadRank;
		});
		$kickers = array_filter($allCards, function($card) use ($quadRank) {
			return $card['rank'] !== $quadRank;
		});
		usort($kickers, function($a, $b) { return $b['value'] - $a['value']; });

		return [
			'strength' => 'four_of_a_kind',
			'description' => 'Four of a Kind ('.$quadRank.')',
			'combination' => $quadCards,
			'kickers' => array_slice($kickers, 0, 1),
			'nut_status' => $quadRank == 'A' ? 'absolute_nuts' : 'strong'
		];
	}

	public static function checkFullHouse(array $allCards, array $rankCounts): ?array
	{
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

		if (count($trips) < 1 || (count($pairs) < 2 && count($trips) < 2)) {
			return null;
		}

		$fullHouseTrips = $trips[0];
		$fullHousePair = null;

		foreach ($pairs as $pair) {
			if ($pair != $fullHouseTrips) {
				$fullHousePair = $pair;
				break;
			}
		}

		if (!$fullHousePair && count($trips) >= 2) {
			$fullHousePair = $trips[1];
		}

		if (!$fullHousePair) return null;

		$tripCards = array_filter($allCards, function($card) use ($fullHouseTrips) {
			return $card['rank'] === $fullHouseTrips;
		});
		$pairCards = array_filter($allCards, function($card) use ($fullHousePair) {
			return $card['rank'] === $fullHousePair;
		});

		return [
			'strength' => 'full_house',
			'description' => 'Full House ('.$fullHouseTrips.' over '.$fullHousePair.')',
			'combination' => array_merge(array_slice($tripCards, 0, 3), array_slice($pairCards, 0, 2)),
			'kickers' => [],
			'nut_status' => $fullHouseTrips == 'A' ? 'absolute_nuts' : 'strong'
		];
	}

	public static function checkTrips(array $allCards, array $rankCounts, array $holeCards, array $boardCards): ?array
	{
		if (max($rankCounts) < 3) return null;

		$tripRank = array_search(3, $rankCounts);
		$tripCards = array_filter($allCards, function($card) use ($tripRank) {
			return $card['rank'] === $tripRank;
		});
		$kickers = array_filter($allCards, function($card) use ($tripRank) {
			return $card['rank'] !== $tripRank;
		});
		usort($kickers, function($a, $b) { return $b['value'] - $a['value']; });

		return [
			'strength' => 'three_of_a_kind',
			'description' => 'Three of a Kind ('.$tripRank.')',
			'combination' => $tripCards,
			'kickers' => array_slice($kickers, 0, 2),
			'nut_status' => ($tripRank == 'A') ? 'strong' :
				(max(array_column($boardCards, 'value')) > $tripRank ? 'medium' : 'strong')
		];
	}

	public static function checkTwoPair(array $allCards, array $rankCounts, array $holeCards, array $boardCards): ?array
	{
		if (count(array_filter($rankCounts, function($v) { return $v >= 2; })) < 2) {
			return null;
		}

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

		return [
			'strength' => 'two_pair',
			'description' => 'Two Pair ('.$topPair.' and '.$secondPair.')',
			'combination' => array_merge(array_slice($topPairCards, 0, 2), array_slice($secondPairCards, 0, 2)),
			'kickers' => array_slice($kickers, 0, 1),
			'nut_status' => $isStrong ? 'strong' :
				(max(array_column($boardCards, 'value')) > $topPair ? 'medium' : 'strong')
		];
	}

	public static function checkPair(array $allCards, array $rankCounts, array $holeCards, array $boardCards): ?array
	{
		if (max($rankCounts) < 2) return null;

		$pairRank = array_search(2, $rankCounts);
		$pairCards = array_filter($allCards, function($card) use ($pairRank) {
			return $card['rank'] === $pairRank;
		});
		$kickers = array_filter($allCards, function($card) use ($pairRank) {
			return $card['rank'] !== $pairRank;
		});
		usort($kickers, function($a, $b) { return $b['value'] - $a['value']; });

		// Определяем тип пары
		$heroHoleRanks = array_column($holeCards, 'rank');
		$boardRanks = array_column($boardCards, 'rank');
		$boardValues = array_column($boardCards, 'value');
		rsort($boardValues);

		$isPocketPair = count($holeCards) == 2 && $holeCards[0]['rank'] == $holeCards[1]['rank'] && $holeCards[0]['rank'] == $pairRank;
		$isBoardPair = in_array($pairRank, $boardRanks);
		$isTopPair = false;
		$isSecondPair = false;

		if ($isPocketPair) {
			$pocketPairValue = $holeCards[0]['value'];
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

			$description = 'Pocket pair of '.$pairRank.' ('.$pairLevel.')';
			$nutStatus = 'medium';
			if ($pairRank == 'A' || $pairRank == 'K') {
				$nutStatus = 'strong';
			} elseif ($pairRank >= 'J') {
				$nutStatus = 'medium_strong';
			}
		} elseif ($isBoardPair) {
			if ($pairRank == $boardCards[0]['rank']) {
				$description = 'Top pair of '.$pairRank;
				$nutStatus = $kickers[0]['value'] >= 10 ? 'strong' : 'weak';
			} elseif (isset($boardCards[1]) && $pairRank == $boardCards[1]['rank']) {
				$description = 'Second pair of '.$pairRank;
				$nutStatus = 'medium';
			} else {
				$description = 'Weak pair of '.$pairRank;
				$nutStatus = 'weak';
			}
		} elseif (in_array($pairRank, $heroHoleRanks)) {
			if ($pairRank == $boardCards[0]['rank']) {
				$isTopPair = true;
				$description = 'Top pair of '.$pairRank;
				$nutStatus = $kickers[0]['value'] >= 10 ? 'strong' : 'weak';
			} else {
				$isSecondPair = true;
				$description = 'Second pair of '.$pairRank;
				$nutStatus = 'medium';
			}
		} else {
			$description = 'Pair of '.$pairRank;
			$nutStatus = 'weak';
		}

		return [
			'strength' => 'pair',
			'description' => $description,
			'combination' => $pairCards,
			'kickers' => array_slice($kickers, 0, 3),
			'nut_status' => $nutStatus,
			'is_pocket_pair' => $isPocketPair
		];
	}

	public static function checkHighCard(array $allCards, array $holeCards, array $boardCards): array
	{
		usort($allCards, function($a, $b) { return $b['value'] - $a['value']; });
		$heroCards = array_column($holeCards, 'rank');
		$hasTopCard = in_array($allCards[0]['rank'], $heroCards);

		return [
			'strength' => 'high_card',
			'description' => $hasTopCard ?
				'High Card ('.$allCards[0]['rank'].')' :
				'No Pair (best board card: '.$allCards[0]['rank'].')',
			'combination' => [],
			'kickers' => array_slice($allCards, 1, 4),
			'nut_status' => 'weak',
			'has_pair_with_board' => false
		];
	}

	public static function evaluateDraws(array $holeCards, array $boardCards): array
	{
		$allCards = array_merge($holeCards, $boardCards);
		$ranks = array_column($allCards, 'rank');
		$suits = array_column($allCards, 'suit');
		$values = array_column($allCards, 'value');
		$holeValues = array_column($holeCards, 'value');
		$rankCounts = array_count_values($ranks);
		$suitCounts = array_count_values($suits);

		$draws = [];
		$outs = 0;

		// Проверка на флеш-дро
		$holeSuits = array_column($holeCards, 'suit');
		$holeSuitCounts = array_count_values($holeSuits);
		$flushDrawSuit = null;

		foreach ($suitCounts as $suit => $count) {
			if ($count == 4 && isset($holeSuitCounts[$suit])) {
				$flushDrawSuit = $suit;
				break;
			}
		}

		if ($flushDrawSuit) {
			$missingCards = array_filter($holeCards, function($card) use ($flushDrawSuit) {
				return $card['suit'] === $flushDrawSuit;
			});

			$draws[] = 'flush_draw';
			$highCardInSuit = max(array_column($holeCards, 'value')) >= 10;
			$outs += $highCardInSuit ? 9 : 7;

			// Проверка на нут-флеш дро
			if (in_array(14, array_column($missingCards, 'value'))) {
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
					$outs += 0.5;
					break;
				}
			}
		}

		return [
			'draws' => $draws,
			'outs' => $outs
		];
	}
}

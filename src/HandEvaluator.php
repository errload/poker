<?php

namespace FourBet;

class HandEvaluator
{
	private const RANK_VALUES = [
		'2' => 2, '3' => 3, '4' => 4, '5' => 5,
		'6' => 6, '7' => 7, '8' => 8, '9' => 9,
		'T' => 10, 'J' => 11, 'Q' => 12, 'K' => 13, 'A' => 14
	];

	/**
	 * Оценивает силу руки (комбинации карт) в покере
	 * @param \PDO $pdo Объект подключения к базе данных
	 * @param int $handId ID раздачи в базе данных
	 * @return array Массив с результатами оценки
	 */
	public static function evaluateHand(\PDO $pdo, int $handId): array
	{
		// Запрос данных раздачи из базы данных
		$handStmt = $pdo->prepare("
			SELECT hero_cards, board, is_completed 
			FROM hands 
			WHERE hand_id = :hand_id
		");
		$handStmt->execute([':hand_id' => $handId]);
		$handData = $handStmt->fetch(\PDO::FETCH_ASSOC);

		// Проверка существования раздачи
		if (!$handData) {
			return ['strength' => 'invalid', 'description' => 'Distribution not found'];
		}

		// Извлечение данных о картах
		$heroCardsString = $handData['hero_cards'] ?? '';
		$boardString = $handData['board'] ?? '';
		$isCompleted = (bool) $handData['is_completed'];

		// Парсинг строк карт в массив
		$parsedHeroCards = self::parseCards($heroCardsString);
		$parsedBoardCards = self::parseCards($boardString);

		// Проверяем валидность карт героя (должно быть ровно 2 карты)
		if (count($parsedHeroCards) !== 2) {
			return ['strength' => 'invalid', 'description' => 'Invalid cards'];
		}

		// Если на столе нет карт (префлоп), оцениваем силу стартовой руки
		if (empty($parsedBoardCards)) {
			return self::evaluatePreflopHand($parsedHeroCards);
		}

		// Объединяем карты героя и карты на столе для оценки комбинации
		$allCards = array_merge($parsedHeroCards, $parsedBoardCards);
		$result = self::evaluateCombination($allCards, $parsedHeroCards, $parsedBoardCards);

		// Для незавершенных раздач (флоп/терн) оцениваем возможные дро
		if (!$isCompleted && count($parsedBoardCards) < 5) {
			$draws = self::evaluateDraws($parsedHeroCards, $parsedBoardCards);

			if (!empty($draws['draws'])) {
				$result['draws'] = $draws['draws'];
				$result['outs'] = $draws['outs'];
				$result['description'] .= ' + ' . implode(', ', $draws['draws']) . ' (' . $draws['outs'] . ' outs)';
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
				$strength = $isSuited ? 'speculative' : 'marginal';
				$handType = $isSuited ? 'Speculative suited' : 'Marginal offsuit';
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
			if ($rank1 === 'A' || $rank2 === 'A') {
				return ['strength' => 'speculative', 'description' => "Speculative suited {$handName}s"];
			}
		}

		// Остальные одномастные руки
		$highRanks = ['K', 'Q', 'J'];
		if ($isSuited) {
			$handName = $value1 > $value2 ? "{$rank1}{$rank2}" : "{$rank2}{$rank1}";

			// Проверка на коннекторы
			$isConnector = abs($value1 - $value2) <= 1;
			$isBroadway = ($value1 >= 10 && $value2 >= 10);
			$isMarginal =
				(in_array($rank1, $highRanks) && $value2 >= 7 && $value2 < 10) ||
				(in_array($rank2, $highRanks) && $value1 >= 7 && $value1 < 10);

			if ($isConnector) {
				if ($isBroadway) {
					return ['strength' => 'speculative', 'description' => "Speculative suited {$handName}s"];
				} else {
					return ['strength' => 'marginal', 'description' => "Marginal suited {$handName}s"];
				}
			}

			// Проверка на маргинальные одномастные руки (K9s-K7s, Q9s-Q7s, J9s-J7s)
			if ($isMarginal) {
				return ['strength' => 'marginal', 'description' => "Marginal suited {$handName}s"];
			}

			// Все остальные одномастные руки (T2s, 93s)
			return ['strength' => 'weak', 'description' => "Weak suited {$handName}s"];
		}

		// Слабые пары (66-22)
		if ($isWeakPair) {
			return ['strength' => 'weak', 'description' => "Weak pair {$rank1}{$rank1}"];
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

		// Условие для Axo (A5o-A9o)
		if (($rank1 === 'A' && $value2 < 10) || ($rank2 === 'A' && $value1 < 10)) {
			$handName = $rank1 === 'A' ? "{$rank1}{$rank2}" : "{$rank2}{$rank1}";
			return ['strength' => 'marginal', 'description' => "Marginal high {$handName}o"];
		}

		// Условие для обычных маргинальных рук (K9o, Q8o, J7o)
		$isMarginal =
			(in_array($rank1, $highRanks) && $value2 >= 7 && $value2 < 10) ||
			(in_array($rank2, $highRanks) && $value1 >= 7 && $value1 < 10);

		if ($isMarginal) {
			$handName = $value1 > $value2 ? "{$rank1}{$rank2}" : "{$rank2}{$rank1}";
			return ['strength' => 'marginal', 'description' => "Marginal offsuit {$handName}o"];
		}

		// Все остальные руки (слабые)
		return ['strength' => 'weak', 'description' => "Weak hand {$rank1}{$rank2}o"];
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
		if ($royalFlushResult = self::checkRoyalFlush($allCards, $suitCounts)) {
			return $royalFlushResult;
		}

		if ($straightFlushResult = self::checkStraightFlush($allCards, $suitCounts)) {
			return $straightFlushResult;
		}

		if ($quadsResult = self::checkQuads($allCards, $rankCounts)) {
			return $quadsResult;
		}

		if ($fullHouseResult = self::checkFullHouse($allCards, $rankCounts)) {
			return $fullHouseResult;
		}

		if ($flushResult = self::checkFlush($holeCards, $boardCards)) {
			return $flushResult;
		}

		if ($straightResult = self::checkStraight($allCards, $values)) {
			return $straightResult;
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

	/**
	 * Проверяет, есть ли у игрока роял-флэш
	 * @param array $allCards Все карты (карты игрока + общие карты на столе)
	 * @param array $suitCounts Количество карт каждой масти
	 * @return array|null Возвращает информацию о роял-флэше или null
	 */
	private static function checkRoyalFlush(array $allCards, array $suitCounts): ?array {
		// Проверяем, есть ли масть с 5+ картами (возможный флеш)
		foreach ($suitCounts as $suit => $count) {
			if ($count >= 5) {
				// Фильтруем карты, оставляя только текущую масть
				$flushCards = array_filter($allCards, function($card) use ($suit) {
					return $card['suit'] === $suit;
				});

				// Сортируем карты по убыванию (от туза до младших)
				usort($flushCards, function($a, $b) {
					return $b['value'] - $a['value'];
				});

				// Проверяем наличие всех карт роял-флэша (A, K, Q, J, 10)
				$royalFlushRanks = ['A', 'K', 'Q', 'J', 'T'];
				$flushRanks = array_column($flushCards, 'rank');

				$hasRoyalFlush = true;
				foreach ($royalFlushRanks as $rank) {
					if (!in_array($rank, $flushRanks)) {
						$hasRoyalFlush = false;
						break;
					}
				}

				// Формируем массив именно из 5 карт роял-флэша
				if ($hasRoyalFlush) {
					$royalFlushCards = [];
					foreach ($royalFlushRanks as $rank) {
						foreach ($flushCards as $card) {
							if ($card['rank'] === $rank) {
								$royalFlushCards[] = $card;
								break;
							}
						}
					}

					return [
						'strength' => 'royal_flush',
						'description' => 'Royal Flush (' . $suit . ')',
						'combination' => $royalFlushCards,
						'kickers' => [],
						'nut_status' => 'nut'
					];
				}
			}
		}

		return null;
	}

	/**
	 * Проверяет, есть ли у игрока стрит-флэш
	 * @param array $allCards Все карты (карты игрока + общие карты на столе)
	 * @param array $suitCounts Количество карт каждой масти
	 * @return array|null Возвращает информацию о стрит-флэше или null
	 */
	private static function checkStraightFlush(array $allCards, array $suitCounts): ?array {
		foreach ($suitCounts as $suit => $count) {
			if ($count >= 5) {
				// Получаем карты нужной масти и сортируем по убыванию
				$flushCards = array_values(array_filter($allCards, fn($card) => $card['suit'] === $suit));
				usort($flushCards, fn($a, $b) => $b['value'] - $a['value']);

				// Собираем значения карт
				$values = array_column($flushCards, 'value');
				$hasAce = in_array(14, $values);

				// Проверяем специальный случай "колеса" (A-2-3-4-5)
				if ($hasAce) {
					$wheelCheck = [14, 2, 3, 4, 5];
					$wheelFound = true;
					foreach ($wheelCheck as $val) {
						if (!in_array($val, $values)) {
							$wheelFound = false;
							break;
						}
					}

					// Собираем карты для "колеса" в правильном порядке
					if ($wheelFound) {
						$wheelCards = [];
						foreach ($wheelCheck as $val) {
							foreach ($flushCards as $card) {
								if ($card['value'] == $val) {
									$wheelCards[] = $card;
									break;
								}
							}
						}

						return [
							'strength' => 'straight_flush',
							'description' => 'Straight Flush ('.$suit.' A-2-3-4-5)',
							'combination' => $wheelCards,
							'kickers' => [],
							'nut_status' => 'strong'
						];
					}
				}

				// Проверяем обычные стрит-флэши
				$straightValues = [];
				for ($i = 0; $i <= count($values) - 5; $i++) {
					if ($values[$i] - $values[$i+4] === 4 && count(array_unique(array_slice($values, $i, 5))) === 5) {
						$straightValues = array_slice($values, $i, 5);
						break;
					}
				}

				if (!empty($straightValues)) {
					$combination = array_slice($flushCards, array_search($straightValues[0], $values), 5);

					// Проверка на роял-флэш
					if ($combination[0]['value'] == 14 && $combination[1]['value'] == 13 &&
						$combination[2]['value'] == 12 && $combination[3]['value'] == 11 &&
						$combination[4]['value'] == 10) {
						return null;
					}

					return [
						'strength' => 'straight_flush',
						'description' => 'Straight Flush ('.$suit.' '.implode('-', $straightValues).')',
						'combination' => $combination,
						'kickers' => [],
						'nut_status' => $combination[0]['value'] == 14 ? 'nut' : 'strong'
					];
				}
			}
		}

		return null;
	}

	/**
	 * Проверяет наличие комбинации "Каре"
	 * @param array $allCards Массив всех карт
	 * @param array $rankCounts Ассоциативный массив с количеством карт каждого достоинства
	 * @return array|null Возвращает массив с информацией о комбинации или null
	 */
	private static function checkQuads(array $allCards, array $rankCounts): ?array
	{
		// Проверяем, есть ли хотя бы один ранг с 4+ картами
		$quadRank = null;
		foreach ($rankCounts as $rank => $count) {
			if ($count >= 4) {
				$quadRank = $rank;
				break;
			}
		}

		if ($quadRank === null) {
			return null;
		}

		// Выбираем 4 карты, которые входят в каре
		$quadRank = (string) $quadRank;
		$quadCards = array_filter($allCards, function($card) use ($quadRank) {
			return (string) $card['rank'] === $quadRank;
		});

		// Остальные карты будут кикерами
		$kickers = array_filter($allCards, function($card) use ($quadRank) {
			return $card['rank'] !== $quadRank;
		});

		// Сортируем кикеры по убыванию ценности
		usort($kickers, function($a, $b) {
			return $b['value'] - $a['value'];
		});

		return [
			'strength' => 'four_of_a_kind',
			'description' => 'Four of a Kind (' . $quadRank . ')',
			'combination' => array_slice($quadCards, 0, 4),
			'kickers' => array_slice($kickers, 0, 1),
			'nut_status' => $quadRank == 'A' ? 'absolute_nuts' : 'strong'
		];
	}

	/**
	 * Проверяет, есть ли среди карт комбинация "Фулл Хаус" (тройка + пара)
	 * @param array $allCards Все карты игрока и общие карты стола
	 * @param array $rankCounts Ассоциативный массив с количеством карт каждого ранга
	 * @return array|null Возвращает массив с информацией о комбинации или null
	 */
	private static function checkFullHouse(array $allCards, array $rankCounts): ?array
	{
		// Ищем все тройки и пары
		$trips = [];
		$pairs = [];

		foreach ($rankCounts as $rank => $count) {
			if ($count >= 3) {
				$trips[] = $rank;
			}
			if ($count >= 2) {
				$pairs[] = $rank;
			}
		}

		// Сортируем по убыванию
		rsort($trips);
		rsort($pairs);

		// Удаляем дубликаты (на случай если тройка также считается парой)
		$pairs = array_unique($pairs);

		// Для фулл-хауса нужно: Хотя бы одна тройка И хотя бы одна другая пара или вторая тройка
		if (empty($trips) || (count($pairs) < 1 && count($trips) < 2)) {
			return null;
		}

		// Выбираем старшую тройку
		$tripRank = $trips[0];

		// Выбираем старшую пару, отличную от тройки
		$pairRank = null;
		foreach ($pairs as $rank) {
			if ($rank != $tripRank) {
				$pairRank = $rank;
				break;
			}
		}

		// Если не нашли отдельную пару, но есть вторая тройка - используем её как пару
		if (!$pairRank && count($trips) > 1) {
			$pairRank = $trips[1];
		}

		if (!$pairRank) {
			return null;
		}

		// Собираем карты для комбинации
		$tripCards = array_values(array_filter($allCards, fn($card) => $card['rank'] == $tripRank));
		$pairCards = array_values(array_filter($allCards, fn($card) => $card['rank'] == $pairRank));

		// Проверяем, что карт достаточно
		if (count($tripCards) < 3 || count($pairCards) < 2) {
			return null;
		}

		// Формируем комбинацию (3 старшие карты из тройки и 2 старшие из пары)
		$combination = array_merge(
			array_slice($tripCards, 0, 3),
			array_slice($pairCards, 0, 2)
		);

		return [
			'strength' => 'full_house',
			'description' => 'Full House (' . $tripRank . ' over ' . $pairRank . ')',
			'combination' => $combination,
			'kickers' => [],
			'nut_status' => $tripRank == 'A' ? 'absolute_nuts' : 'strong'
		];
	}

	/**
	 * Определяет наличие флеша и его характеристики
	 * @param array $heroCards Карты игрока
	 * @param array $boardCards Карты на борде
	 * @return array|null Данные флеша или null
	 */
	private static function checkFlush(array $heroCards, array $boardCards): ?array
	{
		// Находим масть флеша
		$allCards = array_merge($heroCards, $boardCards);
		$suits = [];
		foreach ($allCards as $card) {
			$suits[$card['suit']][] = $card;
		}

		$flushSuit = null;
		foreach ($suits as $suit => $cards) {
			if (count($cards) >= 5) {
				$flushSuit = $suit;
				break;
			}
		}

		if (!$flushSuit) {
			return [
				'strength' => 'no_flush',
				'danger' => 'strong'
			];
		}

		// Получаем все карты флеша и сортируем
		$flushCards = array_filter($allCards, fn($c) => $c['suit'] === $flushSuit);
		usort($flushCards, fn($a, $b) => $b['value'] - $a['value']);
		$topFlushValue = $flushCards[0]['value'];

		// Находим максимальную карту героя во флеше
		$heroFlushCards = array_filter($heroCards, fn($c) => $c['suit'] === $flushSuit);
		$heroMaxValue = !empty($heroFlushCards) ? max(array_column($heroFlushCards, 'value')) : 0;

		// Проверяем наличие всех возможных старших карт
		$allPossibleValues = [14, 13, 12, 11, 10, 9, 8, 7, 6, 5, 4, 3, 2];
		$playedValues = array_column($flushCards, 'value');

		// 5. Определяем недостающие старшие карты
		$missingHigher = [];
		foreach ($allPossibleValues as $value) {
			if ($value > $heroMaxValue && !in_array($value, $playedValues)) {
				$missingHigher[] = $value;
			}
		}

		// Определяем danger и hero_has_top
		$danger = 'high';
		$heroHasTopCard = false;

		if ($heroMaxValue == 14) {
			// Случай с тузом у героя
			$danger = 'nut';
			$heroHasTopCard = true;
		} elseif (empty($missingHigher)) {
			// Все старшие карты в игре
			$danger = 'nut';
			$heroHasTopCard = true;
		} else {
			// Определяем danger по силе карты героя
			$danger = match(true) {
				$heroMaxValue >= 12 => 'low',
				$heroMaxValue >= 10 => 'medium',
				default => 'high'
			};
			$heroHasTopCard = false;
		}

		return [
			'strength' => 'flush',
			'suit' => $flushSuit,
			'top_card' => $topFlushValue,
			'hero_has_top' => $heroHasTopCard,
			'danger' => $danger,
			'is_low' => $heroMaxValue <= 7
		];
	}

	/**
	 * Определяет наличие стрита и его характеристики
	 * @param array $heroCards Карты игрока
	 * @param array $boardCards Карты на борде
	 * @return array|null Данные стрита или null
	 */
	private static function checkStraight(array $heroCards, array $boardCards): ?array
	{
		// Объединяем все карты
		$allCards = array_merge($heroCards, $boardCards);
		$values = array_column($allCards, 'value');
		$uniqueValues = array_unique($values);
		rsort($uniqueValues);

		// Проверяем наличие стрита
		$straightFound = false;
		$straightHighCard = 0;
		$straightCards = [];

		// Проверка обычных стритов
		for ($i = 0; $i <= count($uniqueValues) - 5; $i++) {
			if ($uniqueValues[$i] - $uniqueValues[$i + 4] === 4) {
				$straightFound = true;
				$straightHighCard = $uniqueValues[$i];
				$straightCards = array_slice($uniqueValues, $i, 5);
				break;
			}
		}

		// Проверка колеса (A-2-3-4-5)
		if (!$straightFound && in_array(14, $uniqueValues)) {
			$wheelCheck = [5, 4, 3, 2, 14];
			$hasWheel = true;
			foreach ($wheelCheck as $card) {
				if (!in_array($card, $uniqueValues)) {
					$hasWheel = false;
					break;
				}
			}
			if ($hasWheel) {
				$straightFound = true;
				$straightHighCard = 5;
				$straightCards = $wheelCheck;
			}
		}

		// Проверка потенциала стрита
		if (!$straightFound) {
			$potential = false;
			for ($i = 0; $i <= count($uniqueValues) - 4; $i++) {
				if ($uniqueValues[$i] - $uniqueValues[$i + 3] === 3) {
					$potential = true;
					break;
				}
			}

			return [
				'strength' => 'no_straight',
				'danger' => $potential ? 'medium' : 'low',
				'hero_cards_count' => 0
			];
		}

		// Определяем участие карт героя
		$heroValues = array_column($heroCards, 'value');
		$heroInStraight = count(array_intersect($heroValues, $straightCards));

		// Базовый уровень опасности
		$danger = 'none';

		// Натс-стрит (A-K-Q-J-T)
		if ($straightHighCard === 14 && $straightCards === [14, 13, 12, 11, 10]) {
			$danger = 'none';
		}
		// Колесо (A-2-3-4-5)
		elseif ($straightHighCard === 5 && in_array(14, $straightCards)) {
			$danger = 'medium';
		}
		// Высокие стриты (K-Q-J-T-9 и выше)
		elseif ($straightHighCard >= 10) {
			$danger = $heroInStraight > 0 ? 'none' : 'medium';
		}
		// Средние стриты (6-9)
		elseif ($straightHighCard >= 6) {
			$danger = 'medium';
		}
		// Низкие стриты (2-5)
		else {
			$danger = 'high';
		}

		// Логика для стритов с одной картой героя
		if ($heroInStraight === 1) {
			$heroStraightCard = array_intersect($heroValues, $straightCards)[0];
			$boardStraightCards = array_intersect(array_column($boardCards, 'value'), $straightCards);
			$minBoardStraight = min($boardStraightCards);

			// Если карта героя выше всех карт борда в стрите - medium
			if ($heroStraightCard > max($boardStraightCards)) {
				$danger = 'medium';
			}
			// Если карта героя ниже всех карт борда в стрите - high
			elseif ($heroStraightCard < $minBoardStraight) {
				$danger = 'high';
			}
			// Если карта героя между картами борда - medium
			else {
				$danger = 'medium';
			}
		}

		return [
			'strength' => 'straight',
			'danger' => $danger,
			'hero_cards_count' => $heroInStraight
		];
	}

	/**
	 * Проверяет наличие тройки (сета) и определяет уровень ее опасности
	 * @param array $heroCards Карты героя
	 * @param array $boardCards Карты на борде
	 * @return array Массив с результатами или null
	 */
	private static function checkTrips(array $heroCards, array $boardCards): ?array
	{
		// Объединяем все карты
		$allCards = array_merge($heroCards, $boardCards);
		$values = array_column($allCards, 'value');
		$valueCounts = array_count_values($values);

		// Проверяем наличие тройки
		$tripsValue = null;
		foreach ($valueCounts as $value => $count) {
			if ($count >= 3) {
				$tripsValue = $value;
				break;
			}
		}

		// Проверяем наличие пары
		if (!$tripsValue) {
			$hasPair = max($valueCounts) >= 2;
			return [
				'strength' => 'no_trips',
				'danger' => $hasPair ? 'high' : 'low',
				'hero_cards_count' => 0
			];
		}

		// Определяем участие карт героя в тройке
		$heroValues = array_column($heroCards, 'value');
		$heroInTrips = min(2, count(array_keys($heroValues, $tripsValue)));

		// Базовый уровень опасности
		$danger = 'high';

		// Тройка с двумя картами героя - low опасность
		if ($heroInTrips === 2) {
			$danger = 'low';
		}
		// Тройка с одной картой героя - medium опасность
		elseif ($heroInTrips === 1) {
			$danger = 'medium';
		}
		// Низкие тройки (2-7) - medium опасность
		elseif ($tripsValue >= 2 && $tripsValue <= 7) {
			$danger = 'medium';
		}

		// Если тройка полностью на борде - всегда high опасность
		$boardValues = array_column($boardCards, 'value');
		if (count(array_keys($boardValues, $tripsValue)) >= 3) {
			$danger = 'high';
		}

		return [
			'strength' => 'trips',
			'danger' => $danger,
			'hero_cards_count' => $heroInTrips
		];
	}

	/**
	 * Проверяет наличие двух пар у героя и на борде
	 * @param array $heroCards Карты героя
	 * @param array $boardCards Карты на борде
	 * @return array Результат с информацией о силе, опасности и количестве карт героя в парах
	 */
	private static function checkTwoPair(array $heroCards, array $boardCards): array
	{
		// Объединяем все карты
		$allCards = array_merge($heroCards, $boardCards);
		$values = array_column($allCards, 'value');
		$valueCounts = array_count_values($values);

		// Находим все пары (значения, встречающиеся 2+ раза)
		$pairs = [];
		foreach ($valueCounts as $value => $count) {
			if ($count >= 2) {
				$pairs[] = (int)$value;
			}
		}

		// Если меньше двух пар
		if (count($pairs) < 2) {
			$hasPair = !empty($pairs);
			$heroCardsCount = $hasPair ? count(array_intersect(array_column($heroCards, 'value'), [$pairs[0]])) : 0;
			return [
				'strength' => 'no_two_pairs',
				'danger' => $hasPair ? 'medium' : 'low',
				'hero_cards_count' => min($heroCardsCount, 2)
			];
		}

		// Сортируем пары по убыванию
		rsort($pairs);
		$topPair = $pairs[0];
		$secondPair = $pairs[1];

		// Считаем участие героя в парах
		$heroValues = array_column($heroCards, 'value');
		$heroInTopPair = min(2, count(array_keys($heroValues, $topPair)));
		$heroInSecondPair = min(2, count(array_keys($heroValues, $secondPair)));
		$heroCardsCount = $heroInTopPair + $heroInSecondPair;

		// Проверяем полностью ли пары на борде
		$boardValues = array_column($boardCards, 'value');
		$boardTopPairCount = count(array_keys($boardValues, $topPair));
		$boardSecondPairCount = count(array_keys($boardValues, $secondPair));

		// Определяем уровень опасности
		$danger = 'high'; // по умолчанию

		if ($boardTopPairCount >= 2 && $boardSecondPairCount >= 2) {
			$danger = 'high';
		} elseif ($heroInTopPair == 2 && $heroInSecondPair == 0 && $boardSecondPairCount >= 2) {
			$danger = 'medium';
			$heroCardsCount = 1;
		} elseif ($heroCardsCount == 2) {
			$danger = 'low';
		} elseif ($heroCardsCount == 1) {
			$danger = 'medium';
		} elseif ($topPair <= 7 && $secondPair <= 7) {
			$danger = 'low';
		}

		return [
			'strength' => 'two_pairs',
			'danger' => $danger,
			'hero_cards_count' => $heroCardsCount
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

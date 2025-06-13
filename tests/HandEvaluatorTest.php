<?php

namespace FourBet\Tests;
use FourBet\HandEvaluator;
use PHPUnit\Framework\TestCase;

class HandEvaluatorTest extends TestCase
{
	private function callParseCards(string $cardString): array
	{
		$reflector = new \ReflectionClass(HandEvaluator::class);
		$method = $reflector->getMethod('parseCards');
		$method->setAccessible(true);
		return $method->invokeArgs(null, [$cardString]);
	}

	public function testParseCards()
	{
		// Тест разбора валидных карт
		$cards = 'Ah Ks Qd Jc Th';
		$parsed = $this->callParseCards($cards);

		// Проверяем что разобрано 5 карт
		$this->assertCount(5, $parsed);

		// Проверяем первую карту (Ah)
		$this->assertEquals('A', $parsed[0]['rank']);
		$this->assertEquals('h', $parsed[0]['suit']);
		$this->assertEquals(14, $parsed[0]['value']);

		// Тест с пустой строкой - должен вернуть пустой массив
		$empty = $this->callParseCards('');
		$this->assertEmpty($empty);

		// Тест с невалидными картами - должен вернуть пустой массив
		$invalid = $this->callParseCards('Xy 11 ZZ');
		$this->assertEmpty($invalid);

		// Тест с дубликатами карт - должен вернуть пустой массив
		$duplicates = $this->callParseCards("Ah Ah Ks Qd Jc");
		$this->assertEmpty($duplicates);
	}

	/**
	 * Тестирует метод evaluatePreflopHand()
	 * Проверяет оценку силы руки на префлопе
	 */
	public function testEvaluatePreflopHand()
	{
		// Премиум пара
		$premiumPair = [['rank' => 'A', 'suit' => 'h', 'value' => 14], ['rank' => 'A', 'suit' => 's', 'value' => 14]];
		$result = HandEvaluator::evaluatePreflopHand($premiumPair);
		$this->assertEquals('premium', $result['strength']);
		$this->assertStringContainsString('Premium pair AA', $result['description']);

		// Сьютные карты
		$suited = [['rank' => 'A', 'suit' => 'h', 'value' => 14], ['rank' => 'K', 'suit' => 'h', 'value' => 13]];
		$result = HandEvaluator::evaluatePreflopHand($suited);
		$this->assertEquals('premium', $result['strength']);
		$this->assertStringContainsString('AKs', $result['description']);

		// Слабые карты
		$weak = [['rank' => '7', 'suit' => 'h', 'value' => 7], ['rank' => '2', 'suit' => 'd', 'value' => 2]];
		$result = HandEvaluator::evaluatePreflopHand($weak);
		$this->assertEquals('weak', $result['strength']);
	}

	/**
	 * Тестирует метод evaluateCombination()
	 * Проверяет определение комбинаций на постфлопе
	 */
	public function testEvaluateCombination()
	{
		// Тест на флеш
		$flushCards = [
			['rank' => 'A', 'suit' => 'h', 'value' => 14, 'full' => 'Ah'],
			['rank' => 'K', 'suit' => 'h', 'value' => 13, 'full' => 'Kh'],
			['rank' => 'Q', 'suit' => 'h', 'value' => 12, 'full' => 'Qh'],
			['rank' => 'J', 'suit' => 'h', 'value' => 11, 'full' => 'Jh'],
			['rank' => '9', 'suit' => 'h', 'value' => 9, 'full' => '9h'],
			['rank' => '2', 'suit' => 'd', 'value' => 2, 'full' => '2d'],
			['rank' => '3', 'suit' => 'c', 'value' => 3, 'full' => '3c']
		];
		$result = HandEvaluator::evaluateCombination($flushCards, array_slice($flushCards, 0, 2), array_slice($flushCards, 2, 5));
		$this->assertEquals('flush', $result['strength']);
		$this->assertCount(5, $result['combination']);

		// Тест на стрит
		$straightCards = [
			['rank' => 'T', 'suit' => 'h', 'value' => 10, 'full' => 'Th'],
			['rank' => '9', 'suit' => 'd', 'value' => 9, 'full' => '9d'],
			['rank' => '8', 'suit' => 'c', 'value' => 8, 'full' => '8c'],
			['rank' => '7', 'suit' => 's', 'value' => 7, 'full' => '7s'],
			['rank' => '6', 'suit' => 'h', 'value' => 6, 'full' => '6h'],
			['rank' => '2', 'suit' => 'd', 'value' => 2, 'full' => '2d'],
			['rank' => '3', 'suit' => 'c', 'value' => 3, 'full' => '3c']
		];
		$result = HandEvaluator::evaluateCombination($straightCards, array_slice($straightCards, 0, 2), array_slice($straightCards, 2, 5));
		$this->assertEquals('straight', $result['strength']);

		// Тест на сет
		$setCards = [
			['rank' => 'A', 'suit' => 'h', 'value' => 14, 'full' => 'Ah'],
			['rank' => 'A', 'suit' => 'd', 'value' => 14, 'full' => 'Ad'],
			['rank' => 'A', 'suit' => 'c', 'value' => 14, 'full' => 'Ac'],
			['rank' => 'K', 'suit' => 'h', 'value' => 13, 'full' => 'Kh'],
			['rank' => 'Q', 'suit' => 'd', 'value' => 12, 'full' => 'Qd']
		];
		$result = HandEvaluator::evaluateCombination($setCards, array_slice($setCards, 0, 2), array_slice($setCards, 2, 3));
		$this->assertEquals('three_of_a_kind', $result['strength']);
	}

	/**
	 * Тестирует метод checkFlush()
	 * Проверяет определение флеша
	 */
	public function testCheckFlush()
	{
		$flushCards = [
			['rank' => 'A', 'suit' => 'h', 'value' => 14, 'full' => 'Ah'],
			['rank' => 'K', 'suit' => 'h', 'value' => 13, 'full' => 'Kh'],
			['rank' => 'Q', 'suit' => 'h', 'value' => 12, 'full' => 'Qh'],
			['rank' => 'J', 'suit' => 'h', 'value' => 11, 'full' => 'Jh'],
			['rank' => '9', 'suit' => 'h', 'value' => 9, 'full' => '9h'],
			['rank' => '2', 'suit' => 'd', 'value' => 2, 'full' => '2d'],
			['rank' => '3', 'suit' => 'c', 'value' => 3, 'full' => '3c']
		];

		$suits = array_column($flushCards, 'suit');
		$suitCounts = array_count_values($suits);

		$result = HandEvaluator::checkFlush($flushCards, $suits, $suitCounts, array_slice($flushCards, 0, 2), array_slice($flushCards, 2, 5));
		$this->assertNotNull($result);
		$this->assertEquals('flush', $result['strength']);
		$this->assertEquals('h', $result['combination'][0]['suit']);

		// Тест без флеша
		$noFlushCards = [
			['rank' => 'A', 'suit' => 'h', 'value' => 14, 'full' => 'Ah'],
			['rank' => 'K', 'suit' => 'd', 'value' => 13, 'full' => 'Kd'],
			['rank' => 'Q', 'suit' => 'c', 'value' => 12, 'full' => 'Qc'],
			['rank' => 'J', 'suit' => 's', 'value' => 11, 'full' => 'Js'],
			['rank' => '9', 'suit' => 'h', 'value' => 9, 'full' => '9h']
		];

		$suits = array_column($noFlushCards, 'suit');
		$suitCounts = array_count_values($suits);

		$result = HandEvaluator::checkFlush($noFlushCards, $suits, $suitCounts, array_slice($noFlushCards, 0, 2), array_slice($noFlushCards, 2, 3));
		$this->assertNull($result);
	}

	/**
	 * Тестирует метод checkStraight()
	 * Проверяет определение стрита
	 */
	public function testCheckStraight()
	{
		// Обычный стрит
		$straightCards = [
			['rank' => 'T', 'suit' => 'h', 'value' => 10, 'full' => 'Th'],
			['rank' => '9', 'suit' => 'd', 'value' => 9, 'full' => '9d'],
			['rank' => '8', 'suit' => 'c', 'value' => 8, 'full' => '8c'],
			['rank' => '7', 'suit' => 's', 'value' => 7, 'full' => '7s'],
			['rank' => '6', 'suit' => 'h', 'value' => 6, 'full' => '6h'],
			['rank' => '2', 'suit' => 'd', 'value' => 2, 'full' => '2d'],
			['rank' => '3', 'suit' => 'c', 'value' => 3, 'full' => '3c']
		];
		$values = array_column($straightCards, 'value');
		$result = HandEvaluator::checkStraight($straightCards, $values);
		$this->assertNotNull($result);
		$this->assertEquals('straight', $result['strength']);

		// Стрит от туза (A-2-3-4-5)
		$wheelCards = [
			['rank' => 'A', 'suit' => 'h', 'value' => 14, 'full' => 'Ah'],
			['rank' => '2', 'suit' => 'd', 'value' => 2, 'full' => '2d'],
			['rank' => '3', 'suit' => 'c', 'value' => 3, 'full' => '3c'],
			['rank' => '4', 'suit' => 's', 'value' => 4, 'full' => '4s'],
			['rank' => '5', 'suit' => 'h', 'value' => 5, 'full' => '5h']
		];
		$values = array_column($wheelCards, 'value');
		$result = HandEvaluator::checkStraight($wheelCards, $values);
		$this->assertNotNull($result);

		// Нет стрита
		$noStraightCards = [
			['rank' => 'A', 'suit' => 'h', 'value' => 14, 'full' => 'Ah'],
			['rank' => 'K', 'suit' => 'd', 'value' => 13, 'full' => 'Kd'],
			['rank' => 'Q', 'suit' => 'c', 'value' => 12, 'full' => 'Qc'],
			['rank' => 'J', 'suit' => 's', 'value' => 11, 'full' => 'Js'],
			['rank' => '9', 'suit' => 'h', 'value' => 9, 'full' => '9h']
		];
		$values = array_column($noStraightCards, 'value');
		$result = HandEvaluator::checkStraight($noStraightCards, $values);
		$this->assertNull($result);
	}

	/**
	 * Тестирует метод checkQuads()
	 * Проверяет определение каре
	 */
	public function testCheckQuads()
	{
		$quadsCards = [
			['rank' => 'A', 'suit' => 'h', 'value' => 14, 'full' => 'Ah'],
			['rank' => 'A', 'suit' => 'd', 'value' => 14, 'full' => 'Ad'],
			['rank' => 'A', 'suit' => 'c', 'value' => 14, 'full' => 'Ac'],
			['rank' => 'A', 'suit' => 's', 'value' => 14, 'full' => 'As'],
			['rank' => 'K', 'suit' => 'h', 'value' => 13, 'full' => 'Kh']
		];
		$rankCounts = array_count_values(array_column($quadsCards, 'rank'));
		$result = HandEvaluator::checkQuads($quadsCards, $rankCounts);
		$this->assertNotNull($result);
		$this->assertEquals('four_of_a_kind', $result['strength']);
		$this->assertEquals('A', $result['combination'][0]['rank']);

		// Нет каре
		$noQuadsCards = [
			['rank' => 'A', 'suit' => 'h', 'value' => 14, 'full' => 'Ah'],
			['rank' => 'A', 'suit' => 'd', 'value' => 14, 'full' => 'Ad'],
			['rank' => 'A', 'suit' => 'c', 'value' => 14, 'full' => 'Ac'],
			['rank' => 'K', 'suit' => 's', 'value' => 13, 'full' => 'Ks'],
			['rank' => 'K', 'suit' => 'h', 'value' => 13, 'full' => 'Kh']
		];
		$rankCounts = array_count_values(array_column($noQuadsCards, 'rank'));
		$result = HandEvaluator::checkQuads($noQuadsCards, $rankCounts);
		$this->assertNull($result);
	}

	/**
	 * Тестирует метод checkFullHouse()
	 * Проверяет определение фулл-хауса
	 */
	public function testCheckFullHouse()
	{
		$fullHouseCards = [
			['rank' => 'A', 'suit' => 'h', 'value' => 14, 'full' => 'Ah'],
			['rank' => 'A', 'suit' => 'd', 'value' => 14, 'full' => 'Ad'],
			['rank' => 'A', 'suit' => 'c', 'value' => 14, 'full' => 'Ac'],
			['rank' => 'K', 'suit' => 's', 'value' => 13, 'full' => 'Ks'],
			['rank' => 'K', 'suit' => 'h', 'value' => 13, 'full' => 'Kh']
		];
		$rankCounts = array_count_values(array_column($fullHouseCards, 'rank'));
		$result = HandEvaluator::checkFullHouse($fullHouseCards, $rankCounts);
		$this->assertNotNull($result);
		$this->assertEquals('full_house', $result['strength']);
		$this->assertEquals('A', $result['combination'][0]['rank']);
		$this->assertEquals('K', $result['combination'][3]['rank']);

		// Нет фулл-хауса
		$noFullHouseCards = [
			['rank' => 'A', 'suit' => 'h', 'value' => 14, 'full' => 'Ah'],
			['rank' => 'A', 'suit' => 'd', 'value' => 14, 'full' => 'Ad'],
			['rank' => 'K', 'suit' => 'c', 'value' => 13, 'full' => 'Kc'],
			['rank' => 'K', 'suit' => 's', 'value' => 13, 'full' => 'Ks'],
			['rank' => 'Q', 'suit' => 'h', 'value' => 12, 'full' => 'Qh']
		];
		$rankCounts = array_count_values(array_column($noFullHouseCards, 'rank'));
		$result = HandEvaluator::checkFullHouse($noFullHouseCards, $rankCounts);
		$this->assertNull($result);
	}

	/**
	 * Тестирует метод checkTrips()
	 * Проверяет определение сета
	 */
	public function testCheckTrips()
	{
		$tripsCards = [
			['rank' => 'A', 'suit' => 'h', 'value' => 14, 'full' => 'Ah'],
			['rank' => 'A', 'suit' => 'd', 'value' => 14, 'full' => 'Ad'],
			['rank' => 'A', 'suit' => 'c', 'value' => 14, 'full' => 'Ac'],
			['rank' => 'K', 'suit' => 's', 'value' => 13, 'full' => 'Ks'],
			['rank' => 'Q', 'suit' => 'h', 'value' => 12, 'full' => 'Qh']
		];
		$rankCounts = array_count_values(array_column($tripsCards, 'rank'));
		$holeCards = array_slice($tripsCards, 0, 2);
		$boardCards = array_slice($tripsCards, 2, 3);
		$result = HandEvaluator::checkTrips($tripsCards, $rankCounts, $holeCards, $boardCards);
		$this->assertNotNull($result);
		$this->assertEquals('three_of_a_kind', $result['strength']);
		$this->assertEquals('A', $result['combination'][0]['rank']);

		// Нет сета
		$noTripsCards = [
			['rank' => 'A', 'suit' => 'h', 'value' => 14, 'full' => 'Ah'],
			['rank' => 'A', 'suit' => 'd', 'value' => 14, 'full' => 'Ad'],
			['rank' => 'K', 'suit' => 'c', 'value' => 13, 'full' => 'Kc'],
			['rank' => 'K', 'suit' => 's', 'value' => 13, 'full' => 'Ks'],
			['rank' => 'Q', 'suit' => 'h', 'value' => 12, 'full' => 'Qh']
		];
		$rankCounts = array_count_values(array_column($noTripsCards, 'rank'));
		$result = HandEvaluator::checkTrips($noTripsCards, $rankCounts, array_slice($noTripsCards, 0, 2), array_slice($noTripsCards, 2, 3));
		$this->assertNull($result);
	}

	/**
	 * Тестирует метод checkTwoPair()
	 * Проверяет определение двух пар
	 */
	public function testCheckTwoPair()
	{
		$twoPairCards = [
			['rank' => 'A', 'suit' => 'h', 'value' => 14, 'full' => 'Ah'],
			['rank' => 'A', 'suit' => 'd', 'value' => 14, 'full' => 'Ad'],
			['rank' => 'K', 'suit' => 'c', 'value' => 13, 'full' => 'Kc'],
			['rank' => 'K', 'suit' => 's', 'value' => 13, 'full' => 'Ks'],
			['rank' => 'Q', 'suit' => 'h', 'value' => 12, 'full' => 'Qh']
		];
		$rankCounts = array_count_values(array_column($twoPairCards, 'rank'));
		$holeCards = array_slice($twoPairCards, 0, 2);
		$boardCards = array_slice($twoPairCards, 2, 3);
		$result = HandEvaluator::checkTwoPair($twoPairCards, $rankCounts, $holeCards, $boardCards);
		$this->assertNotNull($result);
		$this->assertEquals('two_pair', $result['strength']);
		$this->assertEquals('A', $result['combination'][0]['rank']);
		$this->assertEquals('K', $result['combination'][2]['rank']);

		// Нет двух пар
		$noTwoPairCards = [
			['rank' => 'A', 'suit' => 'h', 'value' => 14, 'full' => 'Ah'],
			['rank' => 'A', 'suit' => 'd', 'value' => 14, 'full' => 'Ad'],
			['rank' => 'K', 'suit' => 'c', 'value' => 13, 'full' => 'Kc'],
			['rank' => 'Q', 'suit' => 's', 'value' => 12, 'full' => 'Qs'],
			['rank' => 'J', 'suit' => 'h', 'value' => 11, 'full' => 'Jh']
		];
		$rankCounts = array_count_values(array_column($noTwoPairCards, 'rank'));
		$result = HandEvaluator::checkTwoPair($noTwoPairCards, $rankCounts, array_slice($noTwoPairCards, 0, 2), array_slice($noTwoPairCards, 2, 3));
		$this->assertNull($result);
	}

	/**
	 * Тестирует метод checkPair()
	 * Проверяет определение пары
	 */
	public function testCheckPair()
	{
		$pairCards = [
			['rank' => 'A', 'suit' => 'h', 'value' => 14, 'full' => 'Ah'],
			['rank' => 'A', 'suit' => 'd', 'value' => 14, 'full' => 'Ad'],
			['rank' => 'K', 'suit' => 'c', 'value' => 13, 'full' => 'Kc'],
			['rank' => 'Q', 'suit' => 's', 'value' => 12, 'full' => 'Qs'],
			['rank' => 'J', 'suit' => 'h', 'value' => 11, 'full' => 'Jh']
		];
		$rankCounts = array_count_values(array_column($pairCards, 'rank'));
		$holeCards = array_slice($pairCards, 0, 2);
		$boardCards = array_slice($pairCards, 2, 3);
		$result = HandEvaluator::checkPair($pairCards, $rankCounts, $holeCards, $boardCards);
		$this->assertNotNull($result);
		$this->assertEquals('pair', $result['strength']);
		$this->assertEquals('A', $result['combination'][0]['rank']);

		// Нет пары
		$noPairCards = [
			['rank' => 'A', 'suit' => 'h', 'value' => 14, 'full' => 'Ah'],
			['rank' => 'K', 'suit' => 'd', 'value' => 13, 'full' => 'Kd'],
			['rank' => 'Q', 'suit' => 'c', 'value' => 12, 'full' => 'Qc'],
			['rank' => 'J', 'suit' => 's', 'value' => 11, 'full' => 'Js'],
			['rank' => 'T', 'suit' => 'h', 'value' => 10, 'full' => 'Th']
		];
		$rankCounts = array_count_values(array_column($noPairCards, 'rank'));
		$result = HandEvaluator::checkPair($noPairCards, $rankCounts, array_slice($noPairCards, 0, 2), array_slice($noPairCards, 2, 3));
		$this->assertNull($result);
	}

	/**
	 * Тестирует метод checkHighCard()
	 * Проверяет определение старшей карты
	 */
	public function testCheckHighCard()
	{
		$highCardCards = [
			['rank' => 'A', 'suit' => 'h', 'value' => 14, 'full' => 'Ah'],
			['rank' => 'K', 'suit' => 'd', 'value' => 13, 'full' => 'Kd'],
			['rank' => 'Q', 'suit' => 'c', 'value' => 12, 'full' => 'Qc'],
			['rank' => 'J', 'suit' => 's', 'value' => 11, 'full' => 'Js'],
			['rank' => '9', 'suit' => 'h', 'value' => 9, 'full' => '9h']
		];
		$holeCards = array_slice($highCardCards, 0, 2);
		$boardCards = array_slice($highCardCards, 2, 3);
		$result = HandEvaluator::checkHighCard($highCardCards, $holeCards, $boardCards);
		$this->assertEquals('high_card', $result['strength']);
		$this->assertEquals('A', $result['kickers'][0]['rank']);
	}

	/**
	 * Тестирует метод evaluateDraws()
	 * Проверяет определение дро (незаконченных комбинаций)
	 */
	public function testEvaluateDraws()
	{
		// Флеш-дро
		$flushDrawCards = [
			['rank' => 'A', 'suit' => 'h', 'value' => 14, 'full' => 'Ah'],
			['rank' => 'K', 'suit' => 'h', 'value' => 13, 'full' => 'Kh'],
			['rank' => 'Q', 'suit' => 'h', 'value' => 12, 'full' => 'Qh'],
			['rank' => 'J', 'suit' => 'h', 'value' => 11, 'full' => 'Jh'],
			['rank' => '9', 'suit' => 'd', 'value' => 9, 'full' => '9d']
		];
		$holeCards = array_slice($flushDrawCards, 0, 2);
		$boardCards = array_slice($flushDrawCards, 2, 3);
		$result = HandEvaluator::evaluateDraws($holeCards, $boardCards);
		$this->assertContains('flush_draw', $result['draws']);
		$this->assertEquals(9, $result['outs']);

		// Стрит-дро
		$straightDrawCards = [
			['rank' => 'T', 'suit' => 'h', 'value' => 10, 'full' => 'Th'],
			['rank' => '9', 'suit' => 'd', 'value' => 9, 'full' => '9d'],
			['rank' => '8', 'suit' => 'c', 'value' => 8, 'full' => '8c'],
			['rank' => '7', 'suit' => 's', 'value' => 7, 'full' => '7s'],
			['rank' => '2', 'suit' => 'h', 'value' => 2, 'full' => '2h']
		];
		$holeCards = array_slice($straightDrawCards, 0, 2);
		$boardCards = array_slice($straightDrawCards, 2, 3);
		$result = HandEvaluator::evaluateDraws($holeCards, $boardCards);
		$this->assertContains('open_ended_straight_draw', $result['draws']);
		$this->assertEquals(8, $result['outs']);
	}

	/**
	 * Тестирует метод evaluateHand()
	 * Проверяет полную оценку руки с разными сценариями
	 */
	public function testEvaluateHand()
	{
		// Создаем мок PDO для тестирования
		$pdo = $this->createMock(\PDO::class);
		$stmt = $this->createMock(\PDOStatement::class);

		// Настраиваем мок для префлопа
		$pdo->method('prepare')->willReturn($stmt);

		// Тест для префлопа
		$stmt->method('fetch')->willReturn([
			'hero_cards' => 'Ah Kh',
			'board' => '',
			'is_completed' => false
		]);
		$preflopResult = HandEvaluator::evaluateHand($pdo, 1);
		$this->assertEquals('premium', $preflopResult['strength']);

		// Тест для флопа с флешем
		$stmt->method('fetch')->willReturn([
			'hero_cards' => 'Ah Kh',
			'board' => 'Qh Jh 9h',
			'is_completed' => false
		]);
		$flopResult = HandEvaluator::evaluateHand($pdo, 2);
		$this->assertEquals('flush', $flopResult['strength']);

		// Тест для терна с дро
		$stmt->method('fetch')->willReturn([
			'hero_cards' => 'Th 9h',
			'board' => 'Qh Jh 2d 3c',
			'is_completed' => false
		]);
		$turnResult = HandEvaluator::evaluateHand($pdo, 3);
		$this->assertArrayHasKey('draws', $turnResult);
		$this->assertContains('flush_draw', $turnResult['draws']);

		// Тест для невалидных карт
		$stmt->method('fetch')->willReturn([
			'hero_cards' => 'Ah',
			'board' => '',
			'is_completed' => false
		]);
		$invalidResult = HandEvaluator::evaluateHand($pdo, 4);
		$this->assertEquals('invalid', $invalidResult['strength']);

		// Тест для несуществующей раздачи
		$stmt->method('fetch')->willReturn(false);
		$notFoundResult = HandEvaluator::evaluateHand($pdo, 999);
		$this->assertEquals('invalid', $notFoundResult['strength']);
		$this->assertEquals('Раздача не найдена', $notFoundResult['description']);
	}
}

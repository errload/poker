<?php

namespace FourBet\Tests;
use FourBet\HandEvaluator;
use PHPUnit\Framework\TestCase;

class HandEvaluatorTest extends TestCase
{
	/**
	 * Тестирует метод evaluateHand()
	 * Оценивает силы руки в покере
	 */
	public function testEvaluateHand()
	{
		// Создаем и настраиваем мок-объекты PDO и PDOStatement для изоляции теста от реальной БД
		$pdo = $this->createMock(\PDO::class);
		$stmt = $this->createMock(\PDOStatement::class);
		$pdo->method('prepare')->willReturn($stmt);

		// Тест для префлопа (карты героя без карт на столе)
		$stmt->method('fetch')->willReturn([
			'hero_cards' => 'Ah Kh',
			'board' => '',
			'is_completed' => false
		]);
		$preflopResult = HandEvaluator::evaluateHand($pdo, 1);
		$this->assertEquals('premium', $preflopResult['strength'],
			'AK одномастные должны определяться как премиум-рука на префлопе');

		// Тест для флопа с флеш-комбинацией
		$stmt->method('fetch')->willReturn([
			'hero_cards' => 'Ah Kh',   // 2 червы в руке
			'board' => 'Qh Jh 9h',    // 3 червы на столе - флеш
			'is_completed' => false    // Раздача не завершена (возможны дро)
		]);
		$flopResult = HandEvaluator::evaluateHand($pdo, 2);
		$this->assertEquals('flush', $flopResult['strength'],
			'5 карт одной масти должны определяться как флеш');

		// Тест для терна с дро на флеш
		$stmt->method('fetch')->willReturn([
			'hero_cards' => 'Th 9h',   // 2 червы в руке
			'board' => 'Qh Jh 2d 3c', // 2 червы на столе (4 всего) - дро на флеш
			'is_completed' => false    // Раздача не завершена
		]);
		$turnResult = HandEvaluator::evaluateHand($pdo, 3);
		$this->assertArrayHasKey('draws', $turnResult,
			'Для незавершенных комбинаций должен возвращаться массив draws');
		$this->assertContains('flush_draw', $turnResult['draws'],
			'4 карты одной масти должны определяться как дро на флеш');

		// Тест для невалидных карт (только 1 карта в руке)
		$stmt->method('fetch')->willReturn([
			'hero_cards' => 'Ah',     // Только 1 карта - невалидная рука
			'board' => '',             // Пустой стол
			'is_completed' => false    // Раздача не завершена
		]);
		$invalidResult = HandEvaluator::evaluateHand($pdo, 4);
		$this->assertEquals('invalid', $invalidResult['strength'],
			'Рука с менее чем 2 картами должна быть невалидной');

		// Тест для несуществующей раздачи
		$stmt->method('fetch')->willReturn(false); // Имитация отсутствия данных
		$notFoundResult = HandEvaluator::evaluateHand($pdo, 999);
		$this->assertEquals('invalid', $notFoundResult['strength'],
			'Несуществующая раздача должна возвращать invalid');
		$this->assertEquals('Distribution not found', $notFoundResult['description'],
			'Для несуществующей раздачи должно быть соответствующее описание');
	}

	/**
	 * Тестирует метод parseCards()
	 * Проверяет парсинг карт
	 */
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
	private function callEvaluatePreflopHand(array $holeCards): array
	{
		$reflector = new \ReflectionClass(HandEvaluator::class);
		$method = $reflector->getMethod('evaluatePreflopHand');
		$method->setAccessible(true);
		return $method->invokeArgs(null, [$holeCards]);
	}

	public function testEvaluatePreflopHand()
	{
		// Премиум пары (AA, KK, QQ), одномастные (AKs, AQs), разномастные (AKo)
		$premiumPairs = [
			[['rank' => 'A', 'suit' => 'h', 'value' => 14], ['rank' => 'A', 'suit' => 's', 'value' => 14]],
			[['rank' => 'K', 'suit' => 'd', 'value' => 13], ['rank' => 'K', 'suit' => 'c', 'value' => 13]],
			[['rank' => 'Q', 'suit' => 'h', 'value' => 12], ['rank' => 'Q', 'suit' => 'd', 'value' => 12]],
			[['rank' => 'A', 'suit' => 'h', 'value' => 14], ['rank' => 'K', 'suit' => 'h', 'value' => 13]],
			[['rank' => 'A', 'suit' => 'h', 'value' => 14], ['rank' => 'K', 'suit' => 'd', 'value' => 13]]
		];

		foreach ($premiumPairs as $cards) {
			$result = $this->callEvaluatePreflopHand($cards);
			$this->assertEquals('premium', $result['strength']);
		}

		// Сильные пары (TT, JJ), одномастные (ATs+, KQs, QJs), разномастные (AQo, KQo)
		$strongSuited = [
			[['rank' => 'J', 'suit' => 'h', 'value' => 11], ['rank' => 'J', 'suit' => 'd', 'value' => 11]],
			[['rank' => 'T', 'suit' => 's', 'value' => 10], ['rank' => 'T', 'suit' => 'c', 'value' => 10]],
			[['rank' => 'A', 'suit' => 'h', 'value' => 14], ['rank' => 'T', 'suit' => 'h', 'value' => 10]],
			[['rank' => 'A', 'suit' => 's', 'value' => 14], ['rank' => 'Q', 'suit' => 's', 'value' => 12]],
			[['rank' => 'A', 'suit' => 'h', 'value' => 14], ['rank' => 'J', 'suit' => 'h', 'value' => 11]],
			[['rank' => 'K', 'suit' => 'd', 'value' => 13], ['rank' => 'Q', 'suit' => 'd', 'value' => 12]],
			[['rank' => 'Q', 'suit' => 's', 'value' => 12], ['rank' => 'J', 'suit' => 's', 'value' => 11]],
			[['rank' => 'A', 'suit' => 'h', 'value' => 14], ['rank' => 'Q', 'suit' => 'd', 'value' => 12]],
			[['rank' => 'K', 'suit' => 's', 'value' => 13], ['rank' => 'Q', 'suit' => 'c', 'value' => 12]]
		];

		foreach ($strongSuited as $cards) {
			$result = $this->callEvaluatePreflopHand($cards);
			$this->assertEquals('strong', $result['strength']);
		}

		// Средние пары (77-99), одномастные (A9s-A2s, QTs+, J9s), разномастные (AJo+, KJo+)
		$mediumPairs = [
			[['rank' => '9', 'suit' => 'h', 'value' => 9], ['rank' => '9', 'suit' => 'd', 'value' => 9]],
			[['rank' => '8', 'suit' => 's', 'value' => 8], ['rank' => '8', 'suit' => 'c', 'value' => 8]],
			[['rank' => '7', 'suit' => 'h', 'value' => 7], ['rank' => '7', 'suit' => 's', 'value' => 7]],
			[['rank' => 'A', 'suit' => 'h', 'value' => 14], ['rank' => '8', 'suit' => 'h', 'value' => 8]],
			[['rank' => 'Q', 'suit' => 'd', 'value' => 12], ['rank' => 'T', 'suit' => 'd', 'value' => 10]],
			[['rank' => 'J', 'suit' => 's', 'value' => 11], ['rank' => 'T', 'suit' => 's', 'value' => 10]],
			[['rank' => 'A', 'suit' => 'h', 'value' => 14], ['rank' => 'J', 'suit' => 'd', 'value' => 11]],
			[['rank' => 'K', 'suit' => 's', 'value' => 13], ['rank' => 'J', 'suit' => 'c', 'value' => 11]],
			[['rank' => 'K', 'suit' => 'h', 'value' => 13], ['rank' => 'T', 'suit' => 'h', 'value' => 10]]
		];

		foreach ($mediumPairs as $cards) {
			$result = $this->callEvaluatePreflopHand($cards);
			$this->assertEquals('medium', $result['strength']);
		}

		// Слабые пары (22-66), одномастные (K9s-K2s, T7s+), разномастные (K9o+)
		$weakPairs = [
			[['rank' => 'J', 'suit' => 's', 'value' => 11], ['rank' => '9', 'suit' => 's', 'value' => 9]],
			[['rank' => '6', 'suit' => 'h', 'value' => 6], ['rank' => '6', 'suit' => 'd', 'value' => 6]],
			[['rank' => '5', 'suit' => 's', 'value' => 5], ['rank' => '5', 'suit' => 'c', 'value' => 5]],
			[['rank' => '2', 'suit' => 'h', 'value' => 2], ['rank' => '2', 'suit' => 's', 'value' => 2]],
			[['rank' => 'K', 'suit' => 'h', 'value' => 13], ['rank' => '4', 'suit' => 'h', 'value' => 4]],
			[['rank' => 'T', 'suit' => 'd', 'value' => 10], ['rank' => '7', 'suit' => 'd', 'value' => 7]],
			[['rank' => 'T', 'suit' => 'd', 'value' => 10], ['rank' => '8', 'suit' => 'd', 'value' => 8]],
			[['rank' => 'K', 'suit' => 's', 'value' => 13], ['rank' => 'T', 'suit' => 'c', 'value' => 10]],
			[['rank' => 'K', 'suit' => 's', 'value' => 13], ['rank' => '9', 'suit' => 'c', 'value' => 9]]
		];

		foreach ($weakPairs as $cards) {
			$result = $this->callEvaluatePreflopHand($cards);
			$this->assertEquals('weak', $result['strength']);
		}

		// Trash руки (все остальные)
		$trashHands = [
			[['rank' => 'J', 'suit' => 'h', 'value' => 11], ['rank' => '7', 'suit' => 'd', 'value' => 7]],
			[['rank' => '9', 'suit' => 's', 'value' => 9], ['rank' => '4', 'suit' => 'c', 'value' => 4]],
			[['rank' => 'T', 'suit' => 'h', 'value' => 10], ['rank' => '2', 'suit' => 's', 'value' => 2]],
			[['rank' => 'Q', 'suit' => 'h', 'value' => 12], ['rank' => '8', 'suit' => 's', 'value' => 8]]
		];

		foreach ($trashHands as $cards) {
			$result = $this->callEvaluatePreflopHand($cards);
			$this->assertEquals('trash', $result['strength']);
		}
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
	 * Тестирует метод checkRoyalFlush()
	 * Проверяет определение роял-флэша
	 */
	private function callCheckRoyalFlush(array $allCards, array $suitCounts): ?array
	{
		$reflector = new \ReflectionClass(HandEvaluator::class);
		$method = $reflector->getMethod('checkRoyalFlush');
		$method->setAccessible(true);
		return $method->invokeArgs(null, [$allCards, $suitCounts] ?? null);
	}

	public function testCheckRoyalFlush()
	{
		// Роял-флэш червы
		$royalFlushCards = [
			['rank' => 'A', 'suit' => 'h', 'value' => 14, 'full' => 'Ah'],
			['rank' => 'K', 'suit' => 'h', 'value' => 13, 'full' => 'Kh'],
			['rank' => 'Q', 'suit' => 'h', 'value' => 12, 'full' => 'Qh'],
			['rank' => 'J', 'suit' => 'h', 'value' => 11, 'full' => 'Jh'],
			['rank' => 'T', 'suit' => 'h', 'value' => 10, 'full' => 'Th'],
			['rank' => '2', 'suit' => 'd', 'value' => 2, 'full' => '2d'],
			['rank' => '3', 'suit' => 'c', 'value' => 3, 'full' => '3c']
		];

		$suits = array_column($royalFlushCards, 'suit');
		$suitCounts = array_count_values($suits);
		$result = $this->callCheckRoyalFlush($royalFlushCards, $suitCounts);
		$this->assertNotNull($result);
		$this->assertEquals('royal_flush', $result['strength']);
		$this->assertCount(5, $result['combination']);
		$this->assertEquals('h', $result['combination'][0]['suit']);
		$this->assertEquals('nut', $result['nut_status']);

		// Нет роял-флэша (не хватает 10)
		$noRoyalFlushCards = [
			['rank' => 'A', 'suit' => 'h', 'value' => 14, 'full' => 'Ah'],
			['rank' => 'K', 'suit' => 'h', 'value' => 13, 'full' => 'Kh'],
			['rank' => 'Q', 'suit' => 'h', 'value' => 12, 'full' => 'Qh'],
			['rank' => 'J', 'suit' => 'h', 'value' => 11, 'full' => 'Jh'],
			['rank' => '9', 'suit' => 'h', 'value' => 9, 'full' => '9h'],
			['rank' => '2', 'suit' => 'd', 'value' => 2, 'full' => '2d'],
			['rank' => '3', 'suit' => 'c', 'value' => 3, 'full' => '3c']
		];

		$suits = array_column($noRoyalFlushCards, 'suit');
		$result = $this->callCheckRoyalFlush($noRoyalFlushCards, $suits);
		$this->assertNull($result);
	}

	/**
	 * Тестирует метод checkStraightFlush()
	 * Проверяет определение стрит-флэша
	 */
	private function callCheckStraightFlush(array $allCards, array $suitCounts): ?array
	{
		$reflector = new \ReflectionClass(HandEvaluator::class);
		$method = $reflector->getMethod('checkStraightFlush');
		$method->setAccessible(true);
		return $method->invokeArgs(null, [$allCards, $suitCounts]);
	}

	public function testCheckStraightFlush()
	{
		// Стрит-флэш червы (K-Q-J-10-9)
		$straightFlushCards = [
			['rank' => 'K', 'suit' => 'h', 'value' => 13, 'full' => 'Kh'],
			['rank' => 'Q', 'suit' => 'h', 'value' => 12, 'full' => 'Qh'],
			['rank' => 'J', 'suit' => 'h', 'value' => 11, 'full' => 'Jh'],
			['rank' => 'T', 'suit' => 'h', 'value' => 10, 'full' => 'Th'],
			['rank' => '9', 'suit' => 'h', 'value' => 9, 'full' => '9h'],
			['rank' => '2', 'suit' => 'd', 'value' => 2, 'full' => '2d'],
			['rank' => '3', 'suit' => 'c', 'value' => 3, 'full' => '3c']
		];

		$suits = array_column($straightFlushCards, 'suit');
		$suitCounts = array_count_values($suits);
		$result = $this->callCheckStraightFlush($straightFlushCards, $suitCounts);
		$this->assertNotNull($result);
		$this->assertEquals('straight_flush', $result['strength']);
		$this->assertCount(5, $result['combination']);
		$this->assertEquals('h', $result['combination'][0]['suit']);
		$this->assertEquals('strong', $result['nut_status']);

		// Стрит-флэш червы (T-9-8-7-6)
		$straightFlushCards = [
			['rank' => 'T', 'suit' => 'h', 'value' => 10, 'full' => 'Th'],
			['rank' => '9', 'suit' => 'h', 'value' => 9, 'full' => '9h'],
			['rank' => '8', 'suit' => 'h', 'value' => 8, 'full' => '8h'],
			['rank' => '7', 'suit' => 'h', 'value' => 7, 'full' => '7h'],
			['rank' => '6', 'suit' => 'h', 'value' => 6, 'full' => '6h']
		];

		$suits = array_column($straightFlushCards, 'suit');
		$suitCounts = array_count_values($suits);
		$result = $this->callCheckStraightFlush($straightFlushCards, $suitCounts);
		$this->assertNotNull($result);
		$this->assertEquals('straight_flush', $result['strength']);
		$this->assertCount(5, $result['combination']);
		$this->assertEquals('h', $result['combination'][0]['suit']);
		$this->assertEquals('strong', $result['nut_status']);

		// Стрит-флэш с младшим тузом (A-2-3-4-5)
		$wheelFlushCards = [
			['rank' => 'A', 'suit' => 'h', 'value' => 14, 'full' => 'Ah'],
			['rank' => '2', 'suit' => 'h', 'value' => 2, 'full' => '2h'],
			['rank' => '3', 'suit' => 'h', 'value' => 3, 'full' => '3h'],
			['rank' => '4', 'suit' => 'h', 'value' => 4, 'full' => '4h'],
			['rank' => '5', 'suit' => 'h', 'value' => 5, 'full' => '5h'],
			['rank' => 'K', 'suit' => 'd', 'value' => 13, 'full' => 'Kd'],
			['rank' => 'Q', 'suit' => 'c', 'value' => 12, 'full' => 'Qc']
		];

		$suits = array_column($wheelFlushCards, 'suit');
		$suitCounts = array_count_values($suits);
		$result = $this->callCheckStraightFlush($wheelFlushCards, $suitCounts);
		$this->assertNotNull($result);
		$this->assertEquals('straight_flush', $result['strength']);
		// Проверяем, что комбинация содержит карту 5h (младшую в стрит-флэше)
		$this->assertEquals('5h', $result['combination'][4]['full']);

		// Роял-флэш (должен вернуть null, так как проверяется в другом методе)
		$royalFlushCards = [
			['rank' => 'A', 'suit' => 'h', 'value' => 14, 'full' => 'Ah'],
			['rank' => 'K', 'suit' => 'h', 'value' => 13, 'full' => 'Kh'],
			['rank' => 'Q', 'suit' => 'h', 'value' => 12, 'full' => 'Qh'],
			['rank' => 'J', 'suit' => 'h', 'value' => 11, 'full' => 'Jh'],
			['rank' => 'T', 'suit' => 'h', 'value' => 10, 'full' => 'Th'],
			['rank' => '2', 'suit' => 'd', 'value' => 2, 'full' => '2d'],
			['rank' => '3', 'suit' => 'c', 'value' => 3, 'full' => '3c']
		];

		$suits = array_column($royalFlushCards, 'suit');
		$suitCounts = array_count_values($suits);
		$result = $this->callCheckStraightFlush($royalFlushCards, $suitCounts);
		$this->assertNull($result, 'Роял-флэш должен обрабатываться другим методом');

		// Нет стрит-флэша (просто флеш)
		$noStraightFlushCards = [
			['rank' => 'A', 'suit' => 'h', 'value' => 14, 'full' => 'Ah'],
			['rank' => 'K', 'suit' => 'h', 'value' => 13, 'full' => 'Kh'],
			['rank' => 'Q', 'suit' => 'h', 'value' => 12, 'full' => 'Qh'],
			['rank' => 'J', 'suit' => 'h', 'value' => 11, 'full' => 'Jh'],
			['rank' => '9', 'suit' => 'h', 'value' => 9, 'full' => '9h'],
			['rank' => '2', 'suit' => 'd', 'value' => 2, 'full' => '2d'],
			['rank' => '3', 'suit' => 'c', 'value' => 3, 'full' => '3c']
		];

		$suits = array_column($noStraightFlushCards, 'suit');
		$suitCounts = array_count_values($suits);
		$result = $this->callCheckStraightFlush($noStraightFlushCards, $suitCounts);
		$this->assertNull($result);
	}

	/**
	 * Тестирует метод checkQuads()
	 * Проверяет определение каре
	 */
	private function callCheckQuads(array $allCards, array $rankCounts): ?array
	{
		$reflector = new \ReflectionClass(HandEvaluator::class);
		$method = $reflector->getMethod('checkQuads');
		$method->setAccessible(true);
		return $method->invokeArgs(null, [$allCards, $rankCounts]);
	}

	public function testCheckQuads()
	{
		// Проверка случая с каре тузов
		$quadsCards = [
			['rank' => 'A', 'suit' => 'h', 'value' => 14, 'full' => 'Ah'],
			['rank' => 'A', 'suit' => 'd', 'value' => 14, 'full' => 'Ad'],
			['rank' => 'A', 'suit' => 'c', 'value' => 14, 'full' => 'Ac'],
			['rank' => 'A', 'suit' => 's', 'value' => 14, 'full' => 'As'],
			['rank' => 'K', 'suit' => 'h', 'value' => 13, 'full' => 'Kh']
		];

		$rankCounts = array_count_values(array_column($quadsCards, 'rank'));
		$result = $this->callCheckQuads($quadsCards, $rankCounts);
		$this->assertNotNull($result);
		$this->assertEquals('four_of_a_kind', $result['strength']);
		$this->assertEquals('A', $result['combination'][0]['rank']);

		// Проверка случая с каре троек
		$quadsCards = [
			['rank' => 'A', 'suit' => 's', 'value' => 14, 'full' => 'As'],
			['rank' => 'A', 'suit' => 'h', 'value' => 14, 'full' => 'Ah'],
			['rank' => '3', 'suit' => 'h', 'value' => 3, 'full' => '3h'],
			['rank' => '3', 'suit' => 'd', 'value' => 3, 'full' => '3d'],
			['rank' => '3', 'suit' => 'c', 'value' => 3, 'full' => '3c'],
			['rank' => '3', 'suit' => 's', 'value' => 3, 'full' => '3s'],
			['rank' => '2', 'suit' => 'h', 'value' => 2, 'full' => '2h'],
		];

		$rankCounts = array_count_values(array_column($quadsCards, 'rank'));
		$result = $this->callCheckQuads($quadsCards, $rankCounts);
		$this->assertNotNull($result);
		$this->assertEquals('four_of_a_kind', $result['strength']);
		$this->assertEquals('3', $result['combination'][0]['rank']);

		// Проверка случая без каре (только 3 карты одного ранга)
		$noQuadsCards = [
			['rank' => 'A', 'suit' => 'h', 'value' => 14, 'full' => 'Ah'],
			['rank' => 'A', 'suit' => 'd', 'value' => 14, 'full' => 'Ad'],
			['rank' => 'A', 'suit' => 'c', 'value' => 14, 'full' => 'Ac'],
			['rank' => 'K', 'suit' => 's', 'value' => 13, 'full' => 'Ks'],
			['rank' => 'K', 'suit' => 'h', 'value' => 13, 'full' => 'Kh']
		];

		$rankCounts = array_count_values(array_column($noQuadsCards, 'rank'));
		$result = $this->callCheckQuads($noQuadsCards, $rankCounts);
		$this->assertNull($result);
	}

	/**
	 * Тестирует метод checkFullHouse()
	 * Проверяет определение фулл-хауса
	 */
	private function callCheckFullHouse(array $allCards, array $rankCounts): ?array
	{
		$reflector = new \ReflectionClass(HandEvaluator::class);
		$method = $reflector->getMethod('checkFullHouse');
		$method->setAccessible(true);
		return $method->invokeArgs(null, [$allCards, $rankCounts]);
	}

	public function testCheckFullHouse()
	{
		// Проверяем корректное определение фулл хауса (тройка A + пара K)
		$fullHouseCards = [
			['rank' => 'A', 'suit' => 'h', 'value' => 14, 'full' => 'Ah'],
			['rank' => 'A', 'suit' => 'd', 'value' => 14, 'full' => 'Ad'],
			['rank' => 'A', 'suit' => 'c', 'value' => 14, 'full' => 'Ac'],
			['rank' => 'K', 'suit' => 's', 'value' => 13, 'full' => 'Ks'],
			['rank' => 'K', 'suit' => 'h', 'value' => 13, 'full' => 'Kh']
		];
		$rankCounts = array_count_values(array_column($fullHouseCards, 'rank'));
		$result = $this->callCheckFullHouse($fullHouseCards, $rankCounts);
		$this->assertNotNull($result);
		$this->assertEquals('full_house', $result['strength']);
		$this->assertEquals('A', $result['combination'][0]['rank']);
		$this->assertEquals('K', $result['combination'][3]['rank']);

		// Корректный фулл-хаус (тройка 4 + пара J)
		$fullHouseCards = [
			['rank' => 'A', 'suit' => 'h', 'value' => 14, 'full' => 'Ah'],
			['rank' => 'K', 'suit' => 's', 'value' => 13, 'full' => 'Ks'],
			['rank' => 'J', 'suit' => 'h', 'value' => 11, 'full' => 'Jh'],
			['rank' => 'J', 'suit' => 's', 'value' => 11, 'full' => 'Js'],
			['rank' => '4', 'suit' => 'h', 'value' => 4, 'full' => '4h'],
			['rank' => '4', 'suit' => 'd', 'value' => 4, 'full' => '4d'],
			['rank' => '4', 'suit' => 's', 'value' => 4, 'full' => '4s'],
		];

		$rankCounts = array_count_values(array_column($fullHouseCards, 'rank'));
		$result = $this->callCheckFullHouse($fullHouseCards, $rankCounts);

		$this->assertNotNull($result);
		$this->assertEquals('full_house', $result['strength']);
		$this->assertEquals('4', $result['combination'][0]['rank']);
		$this->assertEquals('J', $result['combination'][3]['rank']);

		// Нет фулл-хауса
		$noFullHouseCards = [
			['rank' => 'A', 'suit' => 'h', 'value' => 14, 'full' => 'Ah'],
			['rank' => 'A', 'suit' => 'd', 'value' => 14, 'full' => 'Ad'],
			['rank' => 'K', 'suit' => 'c', 'value' => 13, 'full' => 'Kc'],
			['rank' => 'K', 'suit' => 's', 'value' => 13, 'full' => 'Ks'],
			['rank' => 'Q', 'suit' => 'h', 'value' => 12, 'full' => 'Qh']
		];
		$rankCounts = array_count_values(array_column($noFullHouseCards, 'rank'));
		$result = $this->callCheckFullHouse($noFullHouseCards, $rankCounts);
		$this->assertNull($result);
	}

	/**
	 * Тестирует метод checkFlush()
	 * Проверяет определение флеша
	 */
	private function callCheckFlush(array $heroCards, array $boardCards): ?array
	{
		$reflector = new \ReflectionClass(HandEvaluator::class);
		$method = $reflector->getMethod('checkFlush');
		$method->setAccessible(true);
		return $method->invokeArgs(null, [$heroCards, $boardCards]);
	}

	public function testCheckFlush()
	{
		// Натс-флеш (A-high)
		$heroCards = [
			['rank' => 'A', 'suit' => 'h', 'value' => '14', 'full' => 'Ah'],
			['rank' => '7', 'suit' => 'h', 'value' => '7', 'full' => '7h']
		];
		$boardCards = [
			['rank' => 'K', 'suit' => 'h', 'value' => 13, 'full' => 'Kh'],
			['rank' => '2', 'suit' => 'h', 'value' => 2, 'full' => '2h'],
			['rank' => 'Q', 'suit' => 's', 'value' => 12, 'full' => 'Qs'],
			['rank' => 'J', 'suit' => 'h', 'value' => 11, 'full' => 'Jh'],
			['rank' => '4', 'suit' => 'h', 'value' => 4, 'full' => '4h']
		];

		$result = $this->callCheckFlush($heroCards, $boardCards);
		$this->assertEquals('nut', $result['danger']);
		$this->assertTrue($result['hero_has_top']);

		// Натс-флеш (Q-high, туз и король на борде)
		$heroCards = [
			['rank' => 'Q', 'suit' => 'h', 'value' => '12', 'full' => 'Qh'],
			['rank' => '7', 'suit' => 'h', 'value' => '7', 'full' => '7h']
		];
		$boardCards = [
			['rank' => 'A', 'suit' => 'h', 'value' => '14', 'full' => 'Ah'],
			['rank' => '2', 'suit' => 'h', 'value' => '2', 'full' => '2h'],
			['rank' => 'Q', 'suit' => 's', 'value' => '12', 'full' => 'Qs'],
			['rank' => 'J', 'suit' => 'h', 'value' => '11', 'full' => 'Jh'],
			['rank' => 'K', 'suit' => 'h', 'value' => '13', 'full' => 'Kh']
		];

		$result = $this->callCheckFlush($heroCards, $boardCards);
		$this->assertEquals('nut', $result['danger']);
		$this->assertTrue($result['hero_has_top']);

		// K-high флеш - danger = 'low' (почти неуязвим)
		$heroCards = [
			['rank' => 'K', 'suit' => 'h', 'value' => '13', 'full' => 'Kh'],
			['rank' => '7', 'suit' => 'h', 'value' => '7', 'full' => '7h']
		];
		$boardCards = [
			['rank' => '6', 'suit' => 'h', 'value' => '6', 'full' => '6h'],
			['rank' => '2', 'suit' => 'h', 'value' => '2', 'full' => '2h'],
			['rank' => 'Q', 'suit' => 's', 'value' => '12', 'full' => 'Qs'],
			['rank' => '4', 'suit' => 'h', 'value' => '4', 'full' => '4h']
		];
		$result = $this->callCheckFlush($heroCards, $boardCards);
		$this->assertEquals('low', $result['danger']);

		// Q-high флеш - danger = 'low' (но ниже K)
		$heroCards = [
			['rank' => '4', 'suit' => 'd', 'value' => '4', 'full' => '4d'],
			['rank' => 'Q', 'suit' => 'd', 'value' => '12', 'full' => 'Qd']
		];
		$boardCards = [
			['rank' => '6', 'suit' => 'd', 'value' => '6', 'full' => '6d'],
			['rank' => '2', 'suit' => 'h', 'value' => '2', 'full' => '2h'],
			['rank' => 'T', 'suit' => 'd', 'value' => '10', 'full' => 'Td'],
			['rank' => '4', 'suit' => 'd', 'value' => '4', 'full' => '4d']
		];
		$result = $this->callCheckFlush($heroCards, $boardCards);
		$this->assertEquals('low', $result['danger']);

		// J-high флеш - danger = 'medium' (но ниже K)
		$heroCards = [
			['rank' => '4', 'suit' => 's', 'value' => '4', 'full' => '4s'],
			['rank' => 'J', 'suit' => 'd', 'value' => '11', 'full' => 'Jd']
		];
		$boardCards = [
			['rank' => '6', 'suit' => 'd', 'value' => '6', 'full' => '6d'],
			['rank' => '2', 'suit' => 'h', 'value' => '2', 'full' => '2h'],
			['rank' => 'T', 'suit' => 'd', 'value' => '10', 'full' => 'Td'],
			['rank' => 'K', 'suit' => 'd', 'value' => '13', 'full' => 'Kd'],
			['rank' => 'J', 'suit' => 'h', 'value' => '11', 'full' => 'Jh'],
			['rank' => '4', 'suit' => 'd', 'value' => '4', 'full' => '4d']
		];
		$result = $this->callCheckFlush($heroCards, $boardCards);
		$this->assertEquals('medium', $result['danger']);

		// T-high флеш - danger = 'medium' (умеренный риск)
		$heroCards = [
			['rank' => '4', 'suit' => 'd', 'value' => '4', 'full' => '4d'],
			['rank' => 'T', 'suit' => 'd', 'value' => '10', 'full' => 'Td']
		];
		$boardCards = [
			['rank' => '6', 'suit' => 'd', 'value' => '6', 'full' => '6d'],
			['rank' => '2', 'suit' => 'h', 'value' => '2', 'full' => '2h'],
			['rank' => 'K', 'suit' => 'd', 'value' => '13', 'full' => 'Kd'],
			['rank' => '4', 'suit' => 'd', 'value' => '4', 'full' => '4d']
		];
		$result = $this->callCheckFlush($heroCards, $boardCards);
		$this->assertEquals('medium', $result['danger']);

		// 7-high флеш - danger = 'high' (высокий риск!)
		$heroCards = [
			['rank' => '4', 'suit' => 'd', 'value' => '4', 'full' => '4d'],
			['rank' => '7', 'suit' => 'd', 'value' => '7', 'full' => '7d']
		];
		$boardCards = [
			['rank' => '6', 'suit' => 'd', 'value' => '6', 'full' => '6d'],
			['rank' => '2', 'suit' => 'h', 'value' => '2', 'full' => '2h'],
			['rank' => 'K', 'suit' => 'd', 'value' => '13', 'full' => 'Kd'],
			['rank' => '4', 'suit' => 'd', 'value' => '4', 'full' => '4d']
		];
		$result = $this->callCheckFlush($heroCards, $boardCards);
		$this->assertEquals('high', $result['danger']);

		// 3-high флеш - danger = 'high' (высокий риск!)
		$heroCards = [
			['rank' => '2', 'suit' => 'd', 'value' => '2', 'full' => '2d'],
			['rank' => '3', 'suit' => 'd', 'value' => '3', 'full' => '3d']
		];
		$boardCards = [
			['rank' => '6', 'suit' => 'd', 'value' => '6', 'full' => '6d'],
			['rank' => '2', 'suit' => 'h', 'value' => '2', 'full' => '2h'],
			['rank' => 'K', 'suit' => 'd', 'value' => '13', 'full' => 'Kd'],
			['rank' => '4', 'suit' => 'd', 'value' => '4', 'full' => '4d']
		];
		$result = $this->callCheckFlush($heroCards, $boardCards);
		$this->assertEquals('high', $result['danger']);

		// Нет флеша - danger = 'strong' (очень высокий риск!)
		$heroCards = [
			['rank' => 'K', 'suit' => 'h', 'value' => '13', 'full' => 'Kh'],
			['rank' => 'Q', 'suit' => 's', 'value' => '12', 'full' => 'Qs']
		];
		$boardCards = [
			['rank' => '6', 'suit' => 'd', 'value' => '6', 'full' => '6d'],
			['rank' => '2', 'suit' => 'h', 'value' => '2', 'full' => '2h'],
			['rank' => 'K', 'suit' => 'd', 'value' => '13', 'full' => 'Kd'],
			['rank' => '4', 'suit' => 'd', 'value' => '4', 'full' => '4d']
		];
		$result = $this->callCheckFlush($heroCards, $boardCards);
		$this->assertEquals('strong', $result['danger']);
	}

	/**
	 * Тестирует метод checkStraight()
	 * Проверяет определение стрита
	 */
	private function callCheckStraight(array $heroCards, array $boardCards): ?array
	{
		$reflector = new \ReflectionClass(HandEvaluator::class);
		$method = $reflector->getMethod('checkStraight');
		$method->setAccessible(true);
		return $method->invokeArgs(null, [$heroCards, $boardCards]);
	}

	public function testCheckStraight()
	{
		// Натс-стрит (A-K-Q-J-T) - абсолютная сила
		$heroCards = [['rank' => 'A', 'value' => '14'], ['rank' => 'K', 'value' => '13']];
		$boardCards = [
			['rank' => 'Q', 'value' => '12'],
			['rank' => 'J', 'value' => '11'],
			['rank' => 'T', 'value' => '10'],
			['rank' => '2', 'value' => '2']
		];
		$result = $this->callCheckStraight($heroCards, $boardCards);
		$this->assertEquals('straight', $result['strength']);
		$this->assertEquals('none', $result['danger'], "Натс-стрит не может быть перебит");

		// Общий стрит до туза (A-K-Q-J-T), но у героя карты ниже - опасности нет
		$heroCards = [['rank' => '3', 'value' => '3'], ['rank' => '2', 'value' => '2']];
		$boardCards = [
			['rank' => 'A', 'value' => '14'],
			['rank' => 'K', 'value' => '13'],
			['rank' => 'Q', 'value' => '12'],
			['rank' => 'J', 'value' => '11'],
			['rank' => 'T', 'value' => '10']
		];
		$result = $this->callCheckStraight($heroCards, $boardCards);
		$this->assertEquals('straight', $result['strength']);
		$this->assertEquals('none', $result['danger'], "Общий стрит до туза - опасности нет, даже с низкими картами героя");

		// Общий стрит не до туза (K-Q-J-T-9), у героя карты ниже - средняя опасность
		$heroCards = [['rank' => '3', 'value' => '3'], ['rank' => '2', 'value' => '2']];
		$boardCards = [
			['rank' => 'K', 'value' => '13'],
			['rank' => 'Q', 'value' => '12'],
			['rank' => 'J', 'value' => '11'],
			['rank' => 'T', 'value' => '10'],
			['rank' => '9', 'value' => '9']
		];
		$result = $this->callCheckStraight($heroCards, $boardCards);
		$this->assertEquals('straight', $result['strength']);
		$this->assertEquals('medium', $result['danger'], "Общий стрит может дать кому то выше - средняя опасность");

		// Колесо (A-2-3-4-5) - средняя уязвимость (может быть перебит стритом от 6)
		$heroCards = [['rank' => 'A', 'value' => '14'], ['rank' => '5', 'value' => '5']];
		$boardCards = [
			['rank' => '2', 'value' => '2'],
			['rank' => '3', 'value' => '3'],
			['rank' => '4', 'value' => '4'],
			['rank' => 'K', 'value' => '13']
		];
		$result = $this->callCheckStraight($heroCards, $boardCards);
		$this->assertEquals('straight', $result['strength']);
		$this->assertEquals('medium', $result['danger'], "Колесо уязвимо для стритов от 6");

		// Стрит от короля (K-Q-J-T-9) - минимальная опасность
		$heroCards = [['rank' => 'K', 'value' => '13'], ['rank' => 'Q', 'value' => '12']];
		$boardCards = [
			['rank' => 'J', 'value' => '11'],
			['rank' => 'T', 'value' => '10'],
			['rank' => '9', 'value' => '9'],
			['rank' => '2', 'value' => '2']
		];
		$result = $this->callCheckStraight($heroCards, $boardCards);
		$this->assertEquals('straight', $result['strength']);
		$this->assertEquals('none', $result['danger'], "Высокий стрит неуязвим");

		// Низкий стрит (7-6-5-4-3) - средняя уязвимость
		$heroCards = [['rank' => '7', 'value' => '7'], ['rank' => '3', 'value' => '3']];
		$boardCards = [
			['rank' => '6', 'value' => '6'],
			['rank' => '5', 'value' => '5'],
			['rank' => '4', 'value' => '4'],
			['rank' => 'K', 'value' => '13']
		];
		$result = $this->callCheckStraight($heroCards, $boardCards);
		$this->assertEquals('straight', $result['strength']);
		$this->assertEquals('medium', $result['danger'], "Средний стрит может быть перебит");

		// Стрит с одной картой героя - средняя опасность
		$heroCards = [['rank' => '8', 'value' => '8'], ['rank' => '2', 'value' => '2']];
		$boardCards = [
			['rank' => '7', 'value' => '7'],
			['rank' => '6', 'value' => '6'],
			['rank' => '5', 'value' => '5'],
			['rank' => '4', 'value' => '4']
		];
		$result = $this->callCheckStraight($heroCards, $boardCards);
		$this->assertEquals('straight', $result['strength']);
		$this->assertEquals('medium', $result['danger'], "Стрит с одной высокой картой героя уязвим");
		$this->assertEquals(1, $result['hero_cards_count']);

		// Стрит с одной картой героя - высокая опасность
		$heroCards = [['rank' => '3', 'value' => '3'], ['rank' => '2', 'value' => '2']];
		$boardCards = [
			['rank' => '7', 'value' => '7'],
			['rank' => '6', 'value' => '6'],
			['rank' => '5', 'value' => '5'],
			['rank' => '4', 'value' => '4']
		];
		$result = $this->callCheckStraight($heroCards, $boardCards);
		$this->assertEquals('straight', $result['strength']);
		$this->assertEquals('high', $result['danger'], "Стрит с одной низкой картой героя уязвим");
		$this->assertEquals(1, $result['hero_cards_count']);

		// Уязвимый стрит с возможным перебитием (Q-J-T-9-8 при наличии K на борде)
		$heroCards = [['rank' => 'Q', 'value' => '12'], ['rank' => '8', 'value' => '8']];
		$boardCards = [
			['rank' => 'J', 'value' => '11'],
			['rank' => 'T', 'value' => '10'],
			['rank' => '9', 'value' => '9'],
			['rank' => 'K', 'value' => '13']
		];
		$result = $this->callCheckStraight($heroCards, $boardCards);
		$this->assertEquals('straight', $result['strength']);
		$this->assertEquals('medium', $result['danger'], "Стрит может быть перебит более высоким");

		// Нет стрита вообще - опасность зависит от потенциала
		$heroCards = [['rank' => 'A', 'value' => '14'], ['rank' => 'K', 'value' => '13']];
		$boardCards = [
			['rank' => 'Q', 'value' => '12'],
			['rank' => '2', 'value' => '2'],
			['rank' => '3', 'value' => '3']
		];
		$result = $this->callCheckStraight($heroCards, $boardCards);
		$this->assertEquals('no_straight', $result['strength']);
		$this->assertEquals('low', $result['danger'], "Нет стрита и слабый потенциал - низкая опасность");
	}

	/**
	 * Тестирует метод checkTrips()
	 * Проверяет определение сета
	 */
	private function callCheckTrips(array $heroCards, array $boardCards): ?array
	{
		$reflector = new \ReflectionClass(HandEvaluator::class);
		$method = $reflector->getMethod('checkTrips');
		$method->setAccessible(true);
		return $method->invokeArgs(null, [$heroCards, $boardCards]);
	}

	public function testCheckTrips()
	{
		// Тройка с двумя картами героя - абсолютная сила (но все равно уязвима для каре)
		$heroCards = [['rank' => 'A', 'value' => '14'], ['rank' => 'A', 'value' => '14']];
		$boardCards = [
			['rank' => 'A', 'value' => '14'],
			['rank' => 'K', 'value' => '13'],
			['rank' => 'Q', 'value' => '12']
		];
		$result = $this->callCheckTrips($heroCards, $boardCards);
		$this->assertEquals('trips', $result['strength']);
		$this->assertEquals('low', $result['danger'], "Даже тройка с двумя картами героя уязвима для каре");

		// Общая тройка на борде - всегда высокая опасность (возможны каре/фулхаус у оппонентов)
		$heroCards = [['rank' => 'K', 'value' => '13'], ['rank' => 'Q', 'value' => '12']];
		$boardCards = [
			['rank' => 'A', 'value' => '14'],
			['rank' => 'A', 'value' => '14'],
			['rank' => 'A', 'value' => '14'],
			['rank' => 'J', 'value' => '11']
		];
		$result = $this->callCheckTrips($heroCards, $boardCards);
		$this->assertEquals('trips', $result['strength']);
		$this->assertEquals('high', $result['danger'], "Общая тройка всегда высокая опасность");

		// Тройка с одной картой героя - средняя опасность
		$heroCards = [['rank' => 'Q', 'value' => '12'], ['rank' => '2', 'value' => '2']];
		$boardCards = [
			['rank' => 'Q', 'value' => '12'],
			['rank' => 'Q', 'value' => '12'],
			['rank' => 'J', 'value' => '11'],
			['rank' => 'T', 'value' => '10']
		];
		$result = $this->callCheckTrips($heroCards, $boardCards);
		$this->assertEquals('trips', $result['strength']);
		$this->assertEquals('medium', $result['danger'], "Тройка с одной картой героя - средняя опасность");
		$this->assertEquals(1, $result['hero_cards_count']);

		// Низкая тройка (555) - низкая опасность (неочевидная комбинация)
		$heroCards = [['rank' => '5', 'value' => '5'], ['rank' => '5', 'value' => '5']];
		$boardCards = [
			['rank' => '5', 'value' => '5'],
			['rank' => 'J', 'value' => '11'],
			['rank' => 'T', 'value' => '10']
		];
		$result = $this->callCheckTrips($heroCards, $boardCards);
		$this->assertEquals('trips', $result['strength']);
		$this->assertEquals('low', $result['danger'], "Тройка - низкая опасность");

		// Нет тройки - высокая опасность (возможен сет у оппонентов)
		$heroCards = [['rank' => 'A', 'value' => '14'], ['rank' => 'K', 'value' => '13']];
		$boardCards = [
			['rank' => 'Q', 'value' => '12'],
			['rank' => 'Q', 'value' => '12'],
			['rank' => 'J', 'value' => '11']
		];
		$result = $this->callCheckTrips($heroCards, $boardCards);
		$this->assertEquals('no_trips', $result['strength']);
		$this->assertEquals('high', $result['danger'], "Нет тройки - высокая опасность");
	}

	/**
	 * Тестирует метод checkTwoPair()
	 * Проверяет определение двух пар
	 */
	private function callCheckTwoPair(array $heroCards, array $boardCards): ?array
	{
		$reflector = new \ReflectionClass(HandEvaluator::class);
		$method = $reflector->getMethod('checkTwoPair');
		$method->setAccessible(true);
		return $method->invokeArgs(null, [$heroCards, $boardCards]);
	}

	public function testCheckTwoPairs()
	{
		// 1. Две сильные пары с двумя картами героя (AA+KK) - низкая опасность
		$heroCards = [['rank' => 'A', 'value' => '14'], ['rank' => 'K', 'value' => '13']];
		$boardCards = [
			['rank' => 'A', 'value' => '14'],
			['rank' => 'K', 'value' => '13'],
			['rank' => 'Q', 'value' => '12']
		];
		$result = $this->callCheckTwoPair($heroCards, $boardCards);
		$this->assertEquals('two_pairs', $result['strength']);
		$this->assertEquals('low', $result['danger']);
		$this->assertEquals(2, $result['hero_cards_count']);

		// 2. Общие две высокие пары на борде (AA+KK) - высокая опасность
		$heroCards = [['rank' => 'Q', 'value' => '12'], ['rank' => 'J', 'value' => '11']];
		$boardCards = [
			['rank' => 'A', 'value' => '14'],
			['rank' => 'A', 'value' => '14'],
			['rank' => 'K', 'value' => '13'],
			['rank' => 'K', 'value' => '13'],
			['rank' => '2', 'value' => '2']
		];
		$result = $this->callCheckTwoPair($heroCards, $boardCards);
		$this->assertEquals('two_pairs', $result['strength']);
		$this->assertEquals('high', $result['danger']);
		$this->assertEquals(0, $result['hero_cards_count']);

		// 3. Две пары с одной картой героя (QQ+JJ) - высокая опасность
		$heroCards = [['rank' => 'Q', 'value' => '12'], ['rank' => '2', 'value' => '2']];
		$boardCards = [
			['rank' => 'Q', 'value' => '12'],
			['rank' => 'J', 'value' => '11'],
			['rank' => 'J', 'value' => '11'],
			['rank' => 'T', 'value' => '10']
		];
		$result = $this->callCheckTwoPair($heroCards, $boardCards);
		$this->assertEquals('two_pairs', $result['strength']);
		$this->assertEquals('medium', $result['danger']);
		$this->assertEquals(1, $result['hero_cards_count']);

		// 4. Низкие две пары (55+33) - низкая опасность
		$heroCards = [['rank' => '5', 'value' => '5'], ['rank' => '3', 'value' => '3']];
		$boardCards = [
			['rank' => '5', 'value' => '5'],
			['rank' => '3', 'value' => '3'],
			['rank' => 'J', 'value' => '11']
		];
		$result = $this->callCheckTwoPair($heroCards, $boardCards);
		$this->assertEquals('two_pairs', $result['strength']);
		$this->assertEquals('low', $result['danger']);
		$this->assertEquals(2, $result['hero_cards_count']);

		// 5. Средние две пары (TT+99) - низкая опасность (исправлено по замечанию)
		$heroCards = [['rank' => 'T', 'value' => '10'], ['rank' => '9', 'value' => '9']];
		$boardCards = [
			['rank' => 'T', 'value' => '10'],
			['rank' => '9', 'value' => '9'],
			['rank' => '2', 'value' => '2'],
			['rank' => '3', 'value' => '3']
		];
		$result = $this->callCheckTwoPair($heroCards, $boardCards);
		$this->assertEquals('two_pairs', $result['strength']);
		$this->assertEquals('low', $result['danger']);
		$this->assertEquals(2, $result['hero_cards_count']);

		// 6. Пара героя выше борда + пара на борде (AA + QQ на QJ2) - теперь корректные две пары
		$heroCards = [['rank' => 'A', 'value' => '14'], ['rank' => 'Q', 'value' => '12']];
		$boardCards = [
			['rank' => 'Q', 'value' => '12'],
			['rank' => 'J', 'value' => '11'],
			['rank' => 'J', 'value' => '11'],
			['rank' => '2', 'value' => '2']
		];
		$result = $this->callCheckTwoPair($heroCards, $boardCards);
		$this->assertEquals('two_pairs', $result['strength']);
		$this->assertEquals('medium', $result['danger']);
		$this->assertEquals(1, $result['hero_cards_count']);

		// 7. Пара героя выше всех карт на борде + пара на борде - средняя опасность
		$heroCards = [['rank' => 'K', 'value' => '13'], ['rank' => 'K', 'value' => '13']];
		$boardCards = [
			['rank' => '8', 'value' => '8'],
			['rank' => 'Q', 'value' => '12'],
			['rank' => '8', 'value' => '8'],
			['rank' => '2', 'value' => '2'],
			['rank' => '3', 'value' => '3']
		];
		$result = $this->callCheckTwoPair($heroCards, $boardCards);
		$this->assertEquals('two_pairs', $result['strength']);
		$this->assertEquals('medium', $result['danger']);
		$this->assertEquals(1, $result['hero_cards_count']);

		// 8. Только одна пара (не две пары) - тест на отрицательный сценарий
		$heroCards = [['rank' => 'A', 'value' => '14'], ['rank' => 'K', 'value' => '13']];
		$boardCards = [
			['rank' => 'A', 'value' => '14'],
			['rank' => 'J', 'value' => '11'],
			['rank' => '2', 'value' => '2']
		];
		$result = $this->callCheckTwoPair($heroCards, $boardCards);
		$this->assertEquals('no_two_pairs', $result['strength']);
		$this->assertEquals('medium', $result['danger']);
		$this->assertEquals(1, $result['hero_cards_count']);
	}

	/**
	 * Тестирует метод checkPair()
	 * Проверяет определение пары
	 */
	private function callCheckPair(array $heroCards, array $boardCards): ?array
	{
		$reflector = new \ReflectionClass(HandEvaluator::class);
		$method = $reflector->getMethod('checkTwoPair');
		$method->setAccessible(true);
		return $method->invokeArgs(null, [$heroCards, $boardCards]);
	}

	public function testcheckTwoPair()
	{
		// 1. Топ-пара у героя (AA) - низкая опасность
		$heroCards = [['rank' => 'A', 'value' => 14], ['rank' => 'K', 'value' => 13]];
		$boardCards = [
			['rank' => 'A', 'value' => 14],
			['rank' => 'Q', 'value' => 12],
			['rank' => 'J', 'value' => 11]
		];
		$result = $this->callCheckOnePair($heroCards, $boardCards);
		$this->assertEquals('top_pair', $result['strength']);
		$this->assertEquals('low', $result['danger']);
		$this->assertEquals(1, $result['hero_cards_count']);

		// 2. Топ-пара на борде (AA) - высокая опасность
		$heroCards = [['rank' => 'K', 'value' => 13], ['rank' => 'Q', 'value' => 12]];
		$boardCards = [
			['rank' => 'A', 'value' => 14],
			['rank' => 'A', 'value' => 14],
			['rank' => 'J', 'value' => 11]
		];
		$result = $this->callCheckOnePair($heroCards, $boardCards);
		$this->assertEquals('top_pair', $result['strength']);
		$this->assertEquals('high', $result['danger']);
		$this->assertEquals(0, $result['hero_cards_count']);

		// 3. Оверпара у героя (KK на QJ доске) - низкая опасность
		$heroCards = [['rank' => 'K', 'value' => 13], ['rank' => 'K', 'value' => 13]];
		$boardCards = [
			['rank' => 'Q', 'value' => 12],
			['rank' => 'J', 'value' => 11],
			['rank' => 'T', 'value' => 10]
		];
		$result = $this->callCheckOnePair($heroCards, $boardCards);
		$this->assertEquals('overpair', $result['strength']);
		$this->assertEquals('low', $result['danger']);
		$this->assertEquals(2, $result['hero_cards_count']);

		// 4. Вторая пара у героя (QQ на AKJ доске) - средняя опасность
		$heroCards = [['rank' => 'Q', 'value' => 12], ['rank' => 'Q', 'value' => 12]];
		$boardCards = [
			['rank' => 'A', 'value' => 14],
			['rank' => 'K', 'value' => 13],
			['rank' => 'J', 'value' => 11]
		];
		$result = $this->callCheckOnePair($heroCards, $boardCards);
		$this->assertEquals('second_pair', $result['strength']);
		$this->assertEquals('medium', $result['danger']);
		$this->assertEquals(2, $result['hero_cards_count']);

		// 5. Третья пара у героя (JJ на AKQ доске) - высокая опасность
		$heroCards = [['rank' => 'J', 'value' => 11], ['rank' => 'J', 'value' => 11]];
		$boardCards = [
			['rank' => 'A', 'value' => 14],
			['rank' => 'K', 'value' => 13],
			['rank' => 'Q', 'value' => 12]
		];
		$result = $this->callCheckOnePair($heroCards, $boardCards);
		$this->assertEquals('third_pair', $result['strength']);
		$this->assertEquals('high', $result['danger']);
		$this->assertEquals(2, $result['hero_cards_count']);

		// 6. Вторая пара с одной картой героя (Q с Q на борде при AK) - средняя опасность
		$heroCards = [['rank' => 'Q', 'value' => 12], ['rank' => 'T', 'value' => 10]];
		$boardCards = [
			['rank' => 'A', 'value' => 14],
			['rank' => 'K', 'value' => 13],
			['rank' => 'Q', 'value' => 12]
		];
		$result = $this->callCheckOnePair($heroCards, $boardCards);
		$this->assertEquals('second_pair', $result['strength']);
		$this->assertEquals('medium', $result['danger']);
		$this->assertEquals(1, $result['hero_cards_count']);

		// 7. Третья пара с одной картой героя (J с J на борде при AKQ) - высокая опасность
		$heroCards = [['rank' => 'J', 'value' => 11], ['rank' => 'T', 'value' => 10]];
		$boardCards = [
			['rank' => 'A', 'value' => 14],
			['rank' => 'K', 'value' => 13],
			['rank' => 'Q', 'value' => 12],
			['rank' => 'J', 'value' => 11]
		];
		$result = $this->callCheckOnePair($heroCards, $boardCards);
		$this->assertEquals('third_pair', $result['strength']);
		$this->assertEquals('high', $result['danger']);
		$this->assertEquals(1, $result['hero_cards_count']);

		// 8. Нет пар - высокая опасность
		$heroCards = [['rank' => 'A', 'value' => 14], ['rank' => 'K', 'value' => 13]];
		$boardCards = [
			['rank' => 'Q', 'value' => 12],
			['rank' => 'J', 'value' => 11],
			['rank' => 'T', 'value' => 10]
		];
		$result = $this->callCheckOnePair($heroCards, $boardCards);
		$this->assertEquals('no_pair', $result['strength']);
		$this->assertEquals('high', $result['danger']);
		$this->assertEquals(0, $result['hero_cards_count']);

		// 9. Слабая пара (ниже третьей) - высокая опасность
		$heroCards = [['rank' => '8', 'value' => 8], ['rank' => '8', 'value' => 8]];
		$boardCards = [
			['rank' => 'A', 'value' => 14],
			['rank' => 'K', 'value' => 13],
			['rank' => 'Q', 'value' => 12],
			['rank' => 'J', 'value' => 11]
		];
		$result = $this->callCheckOnePair($heroCards, $boardCards);
		$this->assertEquals('weak_pair', $result['strength']);
		$this->assertEquals('high', $result['danger']);
		$this->assertEquals(2, $result['hero_cards_count']);
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
}

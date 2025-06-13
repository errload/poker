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
		// Премиум пара (AA)
		$premiumPair = [
			['rank' => 'A', 'suit' => 'h', 'value' => 14],
			['rank' => 'A', 'suit' => 's', 'value' => 14]
		];
		$result = $this->callEvaluatePreflopHand($premiumPair);
		$this->assertEquals('premium', $result['strength']);
		$this->assertStringContainsString('Premium pair AA', $result['description']);

		// Премиум одномастная рука (AKs)
		$premiumSuited = [
			['rank' => 'A', 'suit' => 'h', 'value' => 14],
			['rank' => 'K', 'suit' => 'h', 'value' => 13]
		];
		$result = $this->callEvaluatePreflopHand($premiumSuited);
		$this->assertEquals('premium', $result['strength']);
		$this->assertStringContainsString('Premium hand AKs', $result['description']);

		// Премиум разномастная рука (AKo)
		$premiumOffsuit = [
			['rank' => 'A', 'suit' => 'h', 'value' => 14],
			['rank' => 'K', 'suit' => 'd', 'value' => 13]
		];
		$result = $this->callEvaluatePreflopHand($premiumOffsuit);
		$this->assertEquals('premium', $result['strength']);
		$this->assertStringContainsString('Premium hand AKo', $result['description']);

		// Сильная пара (JJ)
		$strongPair = [
			['rank' => 'J', 'suit' => 'h', 'value' => 11],
			['rank' => 'J', 'suit' => 'd', 'value' => 11]
		];
		$result = $this->callEvaluatePreflopHand($strongPair);
		$this->assertEquals('strong', $result['strength']);
		$this->assertStringContainsString('Strong pair JJ', $result['description']);

		// Рука с тузом и хорошим кикером (AJs)
		$aceWithKicker = [
			['rank' => 'A', 'suit' => 'h', 'value' => 14],
			['rank' => 'J', 'suit' => 'h', 'value' => 11]
		];
		$result = $this->callEvaluatePreflopHand($aceWithKicker);
		$this->assertEquals('strong', $result['strength']);
		$this->assertStringContainsString('Ace with kicker AJs', $result['description']);

		// Рука с тузом и хорошим кикером (AQo)
		$aceWithKicker = [
			['rank' => 'A', 'suit' => 'h', 'value' => 14],
			['rank' => 'Q', 'suit' => 'd', 'value' => 12]
		];
		$result = $this->callEvaluatePreflopHand($aceWithKicker);
		$this->assertEquals('medium', $result['strength']);
		$this->assertStringContainsString('Ace with kicker AQo', $result['description']);

		// Две высокие карты (KQs)
		$highSuitedCards = [
			['rank' => 'K', 'suit' => 'h', 'value' => 13],
			['rank' => 'Q', 'suit' => 'h', 'value' => 12]
		];
		$result = $this->callEvaluatePreflopHand($highSuitedCards);
		$this->assertEquals('strong', $result['strength']);
		$this->assertStringContainsString('Strong suited KQs', $result['description']);

		// Две высокие карты (KQo)
		$highCards = [
			['rank' => 'K', 'suit' => 'h', 'value' => 13],
			['rank' => 'Q', 'suit' => 'd', 'value' => 12]
		];
		$result = $this->callEvaluatePreflopHand($highCards);
		$this->assertEquals('medium', $result['strength']);
		$this->assertStringContainsString('Medium offsuit KQo', $result['description']);

		// Две средние карты (QJs)
		$highCards = [
			['rank' => 'Q', 'suit' => 's', 'value' => 12],
			['rank' => 'J', 'suit' => 's', 'value' => 11]
		];
		$result = $this->callEvaluatePreflopHand($highCards);
		$this->assertEquals('medium', $result['strength']);
		$this->assertStringContainsString('Medium suited QJs', $result['description']);

		// Две маржинальные карты (QJo)
		$highCards = [
			['rank' => 'Q', 'suit' => 'h', 'value' => 12],
			['rank' => 'J', 'suit' => 'd', 'value' => 11]
		];
		$result = $this->callEvaluatePreflopHand($highCards);
		$this->assertEquals('marginal', $result['strength']);
		$this->assertStringContainsString('Marginal offsuit QJo', $result['description']);

		// Средняя пара (88)
		$mediumPair = [
			['rank' => '8', 'suit' => 'h', 'value' => 8],
			['rank' => '8', 'suit' => 'd', 'value' => 8]
		];
		$result = $this->callEvaluatePreflopHand($mediumPair);
		$this->assertEquals('medium', $result['strength']);
		$this->assertStringContainsString('Medium pair 88', $result['description']);

		// Спекулятивная одномастная рука (Axs A5s)
		$speculativeSuited = [
			['rank' => 'A', 'suit' => 'h', 'value' => 14],
			['rank' => '5', 'suit' => 'h', 'value' => 5]
		];
		$result = $this->callEvaluatePreflopHand($speculativeSuited);
		$this->assertEquals('speculative', $result['strength']);
		$this->assertStringContainsString('Speculative suited A5s', $result['description']);

		// Коннектор с мастью (JTs)
		$suitedConnector = [
			['rank' => 'J', 'suit' => 'h', 'value' => 11],
			['rank' => 'T', 'suit' => 'h', 'value' => 10]
		];
		$result = $this->callEvaluatePreflopHand($suitedConnector);
		$this->assertEquals('speculative', $result['strength']);
		$this->assertStringContainsString('Speculative suited JTs', $result['description']);

		// Коннектор с мастью (89s)
		$suitedConnector = [
			['rank' => '9', 'suit' => 'h', 'value' => 9],
			['rank' => '8', 'suit' => 'h', 'value' => 8]
		];
		$result = $this->callEvaluatePreflopHand($suitedConnector);
		$this->assertEquals('marginal', $result['strength']);
		$this->assertStringContainsString('Marginal suited 98s', $result['description']);

		// Слабая рука (72s)
		$weakSuited = [
			['rank' => '7', 'suit' => 'h', 'value' => 7],
			['rank' => '2', 'suit' => 'h', 'value' => 2]
		];
		$result = $this->callEvaluatePreflopHand($weakSuited);
		$this->assertEquals('weak', $result['strength']);
		$this->assertStringContainsString('Weak suited 72', $result['description']);

		// Маржинальная одномастная рука (K9s)
		$marginalSuited = [
			['rank' => 'K', 'suit' => 'h', 'value' => 13],
			['rank' => '9', 'suit' => 'h', 'value' => 9]
		];
		$result = $this->callEvaluatePreflopHand($marginalSuited);
		$this->assertEquals('marginal', $result['strength']);
		$this->assertStringContainsString('Marginal suited K9s', $result['description']);

		// Спекулятивная разномастная рука (Axo)
		$speculativeOffsuit = [
			['rank' => 'A', 'suit' => 'h', 'value' => 14],
			['rank' => '5', 'suit' => 'd', 'value' => 5]
		];
		$result = $this->callEvaluatePreflopHand($speculativeOffsuit);
		$this->assertEquals('marginal', $result['strength']);
		$this->assertStringContainsString('Marginal high', $result['description']);

		// Слабая пара (44)
		$weakPair = [
			['rank' => '4', 'suit' => 'h', 'value' => 4],
			['rank' => '4', 'suit' => 'd', 'value' => 4]
		];
		$result = $this->callEvaluatePreflopHand($weakPair);
		$this->assertEquals('weak', $result['strength']);
		$this->assertStringContainsString('Weak pair 44', $result['description']);

		// Маржинальная разномастная рука (K9o)
		$marginalOffsuit = [
			['rank' => 'K', 'suit' => 'h', 'value' => 13],
			['rank' => '9', 'suit' => 'd', 'value' => 9]
		];
		$result = $this->callEvaluatePreflopHand($marginalOffsuit);
		$this->assertEquals('marginal', $result['strength']);
		$this->assertStringContainsString('Marginal offsuit K9', $result['description']);

		// Коннектор без масти (JTo)
		$connectorOffsuit = [
			['rank' => 'J', 'suit' => 'h', 'value' => 11],
			['rank' => 'T', 'suit' => 'd', 'value' => 10]
		];
		$result = $this->callEvaluatePreflopHand($connectorOffsuit);
		$this->assertEquals('marginal', $result['strength']);
		$this->assertStringContainsString('Marginal offsuit JTo', $result['description']);

		// Слабая рука (72o)
		$weakHand = [
			['rank' => '7', 'suit' => 'h', 'value' => 7],
			['rank' => '2', 'suit' => 'd', 'value' => 2]
		];
		$result = $this->callEvaluatePreflopHand($weakHand);
		$this->assertEquals('weak', $result['strength']);
		$this->assertStringContainsString('Weak hand 72o', $result['description']);
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
		$holeCards = array_slice($royalFlushCards, 0, 2);
		$boardCards = array_slice($royalFlushCards, 2, 5);

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
		$suitCounts = array_count_values($suits);
		$result = $this->callCheckRoyalFlush($noRoyalFlushCards, $suits);
		$this->assertNull($result);
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
}

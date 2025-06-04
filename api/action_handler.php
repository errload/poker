<?php
header('Content-Type: application/json');
require_once __DIR__.'/../db/config.php';

// Конфигурация повторных попыток
const MAX_RETRIES = 3;
const INITIAL_RETRY_DELAY_MS = 100;

$response = [
	'success' => false,
	'action_id' => null,
	'message' => '',
	'processed_action_type' => null,
	'current_max_bet' => null
];

try {
	// Валидация входных данных
	$input = validateInput();

	// Подключение к базе данных
	$pdo = createPDOConnection();

	// Предварительные проверки вне транзакции
	validateHandExists($pdo, $input['hand_id']);
	ensurePlayerExists($pdo, $input['player_id']);

	// Обработка действия с повторными попытками
	$response = processActionWithRetries($pdo, $input);

} catch (Exception $e) {
	$response['message'] = $e->getMessage();
}

echo json_encode($response);

/**
 * Валидация входных данных
 */
function validateInput(): array {
	$input = json_decode(file_get_contents('php://input'), true);
	if (!$input) {
		throw new Exception('Неверный JSON-ввод');
	}

	// Проверка обязательных полей
	$required = ['hand_id', 'player_id', 'street', 'action_type', 'position'];
	foreach ($required as $field) {
		if (!isset($input[$field])) {
			throw new Exception("Отсутствует обязательное поле: $field");
		}
	}

	// Проверка допустимых значений
	$validations = [
		'action_type' => ['fold', 'check', 'call', 'bet', 'raise', 'all-in'],
		'position' => ['BTN', 'SB', 'BB', 'UTG', 'UTG+1', 'MP', 'HJ', 'CO'],
		'street' => ['preflop', 'flop', 'turn', 'river']
	];

	foreach ($validations as $field => $allowedValues) {
		if (!in_array($input[$field], $allowedValues)) {
			throw new Exception("Недопустимое значение для $field: {$input[$field]}");
		}
	}

	return $input;
}

/**
 * Создание подключения к базе данных
 */
function createPDOConnection(): PDO {
	return new PDO(
		"mysql:host=".DB_HOST.";dbname=".DB_NAME.";charset=utf8mb4",
		DB_USER,
		DB_PASS,
		[
			PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
			PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
		]
	);
}

/**
 * Проверка существования раздачи
 */
function validateHandExists(PDO $pdo, string $handId): void {
	$stmt = $pdo->prepare("SELECT 1 FROM hands WHERE hand_id = ?");
	$stmt->execute([$handId]);
	if (!$stmt->fetch()) {
		throw new Exception("Раздача не найдена");
	}
}

/**
 * Проверка и создание игрока при необходимости
 */
function ensurePlayerExists(PDO $pdo, string $playerId): void {
	$stmt = $pdo->prepare("SELECT 1 FROM players WHERE player_id = ?");
	$stmt->execute([$playerId]);
	if (!$stmt->fetch()) {
		$nickname = "Player" . substr($playerId, 0, 6);
		$stmt = $pdo->prepare("INSERT INTO players (player_id, nickname) VALUES (?, ?)");
		$stmt->execute([$playerId, $nickname]);
	}
}

/**
 * Обработка действия с повторными попытками при deadlock
 */
function processActionWithRetries(PDO $pdo, array $input): array {
	$attempt = 0;
	$lastError = null;

	while ($attempt < MAX_RETRIES) {
		try {
			return processSingleAttempt($pdo, $input);
		} catch (PDOException $e) {
			if ($e->getCode() != '40001') { // Не deadlock - пробрасываем дальше
				throw $e;
			}

			$lastError = $e;
			$attempt++;
			usleep(INITIAL_RETRY_DELAY_MS * 1000 * $attempt); // Экспоненциальная задержка
		}
	}

	throw $lastError ?? new Exception("Неизвестная ошибка при обработке действия");
}

/**
 * Одиночная попытка обработки действия
 */
function processSingleAttempt(PDO $pdo, array $input): array {
	$pdo->beginTransaction();

	try {
		// Получаем следующий номер последовательности с блокировкой
		$nextSeq = getNextSequenceNumber($pdo, $input['hand_id']);

		// Получаем текущую максимальную ставку
		$currentBet = getCurrentMaxBet($pdo, $input['hand_id'], $input['street']);

		// Проверяем предыдущие повышения
		$hasPreviousRaises = hasPreviousRaises($pdo, $input['hand_id'], $input['street']);

		// Обрабатываем действие
		$processedData = processActionData($pdo, $input, $currentBet, $hasPreviousRaises);

		// Вставляем действие
		$actionId = insertAction($pdo, [
			'hand_id' => $input['hand_id'],
			'player_id' => $input['player_id'],
			'position' => $input['position'],
			'street' => $input['street'],
			'action_type' => $processedData['finalActionType'],
			'amount' => $processedData['amount'],
			'sequence_num' => $nextSeq,
			'is_voluntary' => $processedData['isVoluntary'],
			'is_aggressive' => $processedData['isAggressive'],
			'is_first_action' => $processedData['isFirstAction']
		]);

		// Обновляем время изменения раздачи
		updateHandTimestamp($pdo, $input['hand_id']);

		$pdo->commit();

		return [
			'success' => true,
			'action_id' => $actionId,
			'message' => 'Действие успешно обработано',
			'processed_action_type' => $processedData['finalActionType'],
			'current_max_bet' => max($currentBet, $processedData['amount'] ?? 0)
		];
	} catch (Exception $e) {
		$pdo->rollBack();
		throw $e;
	}
}

/**
 * Вспомогательные функции для обработки действий
 */

function getNextSequenceNumber(PDO $pdo, string $handId): int {
	$stmt = $pdo->prepare("SELECT COALESCE(MAX(sequence_num), 0) + 1 FROM actions WHERE hand_id = ? FOR UPDATE");
	$stmt->execute([$handId]);
	return (int)$stmt->fetchColumn();
}

function getCurrentMaxBet(PDO $pdo, string $handId, string $street): float {
	$stmt = $pdo->prepare("SELECT COALESCE(MAX(amount), 0) FROM actions WHERE hand_id = ? AND street = ?");
	$stmt->execute([$handId, $street]);
	return (float)$stmt->fetchColumn();
}

function hasPreviousRaises(PDO $pdo, string $handId, string $street): bool {
	$stmt = $pdo->prepare("
        SELECT 1 FROM actions 
        WHERE hand_id = ? AND street = ? 
        AND action_type IN ('bet', 'raise', 'all-in')
        LIMIT 1
    ");
	$stmt->execute([$handId, $street]);
	return (bool)$stmt->fetch();
}

function processActionData(PDO $pdo, array $input, float $currentBet, bool $hasPreviousRaises): array {
	$isBlindAction = in_array($input['position'], ['SB', 'BB']) && $input['street'] === 'preflop';
	$isFirstAction = isFirstPlayerAction($pdo, $input['hand_id'], $input['player_id']);

	$result = [
		'finalActionType' => $input['action_type'],
		'amount' => null,
		'isVoluntary' => true,
		'isAggressive' => false,
		'isFirstAction' => $isFirstAction
	];

	if ($isBlindAction) {
		processBlindAction($input, $hasPreviousRaises, $result);
	} else {
		processRegularAction($input, $result);
	}

	// Преобразование первого raise в bet
	if ($result['finalActionType'] === 'raise') {
		$result['finalActionType'] = $hasPreviousRaises ? 'raise' : 'bet';
	}

	return $result;
}

function isFirstPlayerAction(PDO $pdo, string $handId, string $playerId): bool {
	$stmt = $pdo->prepare("SELECT COUNT(*) FROM actions WHERE hand_id = ? AND player_id = ?");
	$stmt->execute([$handId, $playerId]);
	return $stmt->fetchColumn() == 0;
}

function processBlindAction(array $input, bool $hasPreviousRaises, array &$result): void {
	switch ($input['action_type']) {
		case 'fold':
			$result['amount'] = $input['position'] === 'SB' ? 0.5 : 1;
			$result['isVoluntary'] = false;
			break;

		case 'check':
			if ($input['position'] === 'BB' && !$hasPreviousRaises) {
				$result['amount'] = 1;
			} elseif ($input['position'] === 'SB' && !$hasPreviousRaises) {
				$result['finalActionType'] = 'call';
				$result['amount'] = 1;
			} else {
				throw new Exception("Чек невозможен в данной ситуации");
			}
			break;

		case 'call':
			if ($hasPreviousRaises) {
				if (!isset($input['amount'])) {
					throw new Exception("Не указана сумма для колла");
				}
				$result['amount'] = (float)$input['amount'];
			} else {
				$result['amount'] = 1;
			}
			break;

		case 'bet':
		case 'raise':
		case 'all-in':
			if (!isset($input['amount'])) {
				throw new Exception("Не указана сумма для ставки/рейза");
			}
			$result['amount'] = (float)$input['amount'];
			$result['isAggressive'] = true;
			break;
	}
}

function processRegularAction(array $input, array &$result): void {
	switch ($input['action_type']) {
		case 'fold':
		case 'check':
			$result['amount'] = null;
			break;

		case 'call':
			if (!isset($input['amount'])) {
				throw new Exception("Не указана сумма для колла");
			}
			$result['amount'] = (float)$input['amount'];
			break;

		case 'bet':
		case 'raise':
		case 'all-in':
			if (!isset($input['amount'])) {
				throw new Exception("Не указана сумма для действия");
			}
			$result['amount'] = (float)$input['amount'];
			$result['isAggressive'] = true;
			break;
	}
}

function insertAction(PDO $pdo, array $data): int {
	$stmt = $pdo->prepare("
        INSERT INTO actions (
            hand_id, player_id, position, street, 
            action_type, amount, sequence_num,
            is_voluntary, is_aggressive, is_first_action
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
	$stmt->execute([
		$data['hand_id'],
		$data['player_id'],
		$data['position'],
		$data['street'],
		$data['action_type'],
		$data['amount'] > 0 ? $data['amount'] : null,
		$data['sequence_num'],
		$data['is_voluntary'] ? 1 : 0,
		$data['is_aggressive'] ? 1 : 0,
		$data['is_first_action'] ? 1 : 0
	]);

	return $pdo->lastInsertId();
}

function updateHandTimestamp(PDO $pdo, string $handId): void {
	$stmt = $pdo->prepare("UPDATE hands SET updated_at = NOW() WHERE hand_id = ?");
	$stmt->execute([$handId]);
}
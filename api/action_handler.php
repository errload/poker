<?php
header('Content-Type: application/json');
require_once __DIR__.'/../db/config.php';

$response = ['success' => false, 'error' => null];

try {
	// Получаем и проверяем входные данные
	$input = json_decode(file_get_contents('php://input'), true);
	if (!$input) {
		throw new Exception('Неверный JSON-ввод');
	}

	// Проверяем обязательные поля
	$required = ['hand_id', 'player_id', 'street', 'action_type'];
	foreach ($required as $field) {
		if (!isset($input[$field])) {
			throw new Exception("Обязательное поле отсутствует: $field");
		}
	}

	// Валидация типов действий
	$validActions = ['fold', 'check', 'call', 'bet', 'raise', 'all-in'];
	if (!in_array($input['action_type'], $validActions)) {
		throw new Exception("Недопустимый тип действия: {$input['action_type']}");
	}

	// Проверка для ставок/рейзов
	if (in_array($input['action_type'], ['bet', 'raise', 'all-in']) && !isset($input['amount'])) {
		throw new Exception("amount обязателен для действий типа {$input['action_type']}");
	}

	// Проверка current_stack для всех действий кроме fold
	if ($input['action_type'] != 'fold' && !isset($input['current_stack'])) {
		throw new Exception("current_stack обязателен для действий, кроме fold");
	}

	// Валидация числовых значений
	if (isset($input['amount']) && (!is_numeric($input['amount']) || $input['amount'] <= 0)) {
		throw new Exception("Некорректная сумма ставки");
	}

	if (isset($input['current_stack']) && (!is_numeric($input['current_stack']) || $input['current_stack'] < 0)) {
		throw new Exception("Некорректный размер стека");
	}

	// Подключение к базе данных
	$pdo = new PDO(
		"mysql:host=".DB_HOST.";dbname=".DB_NAME.";charset=utf8mb4",
		DB_USER,
		DB_PASS,
		[
			PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
			PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
		]
	);

	$pdo->beginTransaction();

	try {
		// Проверяем существование раздачи
		$stmt = $pdo->prepare("SELECT 1 FROM hands WHERE hand_id = ?");
		$stmt->execute([$input['hand_id']]);
		if (!$stmt->fetch()) {
			$pdo->commit();
			echo json_encode([
				'success' => true,
				'message' => "Раздача с ID {$input['hand_id']} не найдена",
				'hand_id' => $input['hand_id']
			]);
			exit;
		}

		// Проверяем/создаем игрока
		$player_id = $input['player_id'];
		$stmt = $pdo->prepare("SELECT * FROM players WHERE player_id = ? FOR UPDATE");
		$stmt->execute([$player_id]);
		$player = $stmt->fetch();

		if (!$player) {
			// Извлекаем первую цифру из ID игрока
			preg_match('/\d/', $player_id, $matches);
			$firstDigit = $matches[0] ?? '0';
			$nickname = "Player{$firstDigit}";

			$stmt = $pdo->prepare("
                INSERT INTO players 
                (player_id, nickname, last_seen, created_at) 
                VALUES (?, ?, NOW(), NOW())
            ");
			$stmt->execute([$player_id, $nickname]);
			$player = ['hands_played' => 0, 'vpip' => 0, 'pfr' => 0];
		}

		// Получаем следующий номер последовательности
		$stmt = $pdo->prepare("SELECT COALESCE(MAX(sequence_num), 0) + 1 FROM actions WHERE hand_id = ?");
		$stmt->execute([$input['hand_id']]);
		$nextSeq = $stmt->fetchColumn();

		// Вставляем действие
		$stmt = $pdo->prepare("
            INSERT INTO actions (
                hand_id, player_id, street, action_type, amount, current_stack, sequence_num
            ) VALUES (?, ?, ?, ?, ?, ?, ?)
        ");

		$stmt->execute([
			$input['hand_id'],
			$player_id,
			$input['street'],
			$input['action_type'],
			isset($input['amount']) ? round($input['amount'], 2) : null,
			isset($input['current_stack']) ? round($input['current_stack'], 2) : null,
			$nextSeq
		]);

		$action_id = $pdo->lastInsertId();

		// Обновляем статистику
		updatePlayerStats($pdo, $player_id, $input['action_type'], $input['street']);

		$pdo->commit();

		$response = [
			'success' => true,
			'action_id' => $action_id,
			'message' => 'Действие успешно записано'
		];

	} catch (Exception $e) {
		$pdo->rollBack();
		throw $e;
	}

} catch (Exception $e) {
	$response['error'] = $e->getMessage();
} finally {
	echo json_encode($response);
}

function updatePlayerStats($pdo, $player_id, $action_type, $street) {
	$stmt = $pdo->prepare("SELECT * FROM players WHERE player_id = ? FOR UPDATE");
	$stmt->execute([$player_id]);
	$player = $stmt->fetch();

	if (!$player) return;

	$updateFields = ['last_seen' => date('Y-m-d H:i:s')];
	$hands_played = $player['hands_played'] ?? 0;

	// Обновление VPIP
	if ($street == 'preflop' && $action_type != 'fold') {
		$new_hands_played = $hands_played + 1;
		$updateFields['hands_played'] = $new_hands_played;
		$updateFields['vpip'] = (($player['vpip'] * $hands_played) + 1) / $new_hands_played;
	}

	// Обновление PFR
	if ($street == 'preflop' && in_array($action_type, ['raise', 'all-in'])) {
		$new_preflop_raises = ($player['preflop_raises'] ?? 0) + 1;
		$updateFields['preflop_raises'] = $new_preflop_raises;
		$updateFields['pfr'] = $hands_played > 0
			? (($player['pfr'] * $hands_played) + 1) / $hands_played
			: 1;
	}

	// Применяем обновления
	if (!empty($updateFields)) {
		$setParts = array_map(fn($f) => "$f = ?", array_keys($updateFields));
		$sql = "UPDATE players SET " . implode(', ', $setParts) . " WHERE player_id = ?";
		$stmt = $pdo->prepare($sql);
		$stmt->execute([...array_values($updateFields), $player_id]);
	}
}
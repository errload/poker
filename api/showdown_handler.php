<?php
header('Content-Type: application/json');
require_once __DIR__.'/../db/config.php';

try {
	$pdo = new PDO(
		"mysql:host=".DB_HOST.";dbname=".DB_NAME.";charset=utf8mb4",
		DB_USER,
		DB_PASS,
		[
			PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
			PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
		]
	);

	$input = json_decode(file_get_contents('php://input'), true);
	if (!$input) throw new Exception('Invalid JSON input');

	// Обязательные поля
	$required = ['hand_id', 'players'];
	foreach ($required as $field) {
		if (!isset($input[$field])) throw new Exception("Missing required field: $field");
	}

	// Проверяем существование руки
	$stmt = $pdo->prepare("SELECT COUNT(*) FROM hands WHERE hand_id = ?");
	$stmt->execute([$input['hand_id']]);
	if (!$stmt->fetchColumn()) {
		throw new Exception("Hand not found");
	}

	// Начинаем транзакцию
	$pdo->beginTransaction();

	try {
		// Обрабатываем каждого игрока
		foreach ($input['players'] as $player) {
			if (!isset($player['player_id']) || !isset($player['cards'])) {
				throw new Exception("Each player must have player_id and cards");
			}

			if (strlen($player['cards']) != 4 && strlen($player['cards']) != 5) {
				throw new Exception("Invalid cards format for player {$player['player_id']}");
			}

			// Проверяем/добавляем игрока
			$stmt = $pdo->prepare("SELECT COUNT(*) FROM players WHERE player_id = ?");
			$stmt->execute([$player['player_id']]);
			if (!$stmt->fetchColumn()) {
				// Получаем первую цифру ID игрока
				$firstDigit = substr($player['player_id'], 0, 1);
				// Если первая цифра не число, используем 0
				$firstDigit = is_numeric($firstDigit) ? $firstDigit : '0';

				$pdo->prepare("INSERT INTO players (player_id, nickname) VALUES (?, ?)")
					->execute([$player['player_id'], 'Player' . $firstDigit]);
			}

			// Вставляем или обновляем showdown
			$stmt = $pdo->prepare("
                INSERT INTO showdowns (hand_id, player_id, cards)
                VALUES (:hand_id, :player_id, :cards)
                ON DUPLICATE KEY UPDATE cards = :cards
            ");
			$stmt->execute([
				':hand_id' => $input['hand_id'],
				':player_id' => $player['player_id'],
				':cards' => $player['cards']
			]);
		}

		// Помечаем руку как завершенную
		$stmt = $pdo->prepare("
            UPDATE hands 
            SET is_completed = 1, 
                updated_at = NOW() 
            WHERE hand_id = ?
        ");
		$stmt->execute([$input['hand_id']]);

		// Фиксируем транзакцию
		$pdo->commit();

		// Обновляем статистику игроков после успешной записи
		foreach ($input['players'] as $player) {
			updatePlayerStats($pdo, $player['player_id']);
		}

		echo json_encode([
			'success' => true,
			'message' => 'Showdown recorded for ' . count($input['players']) . ' players'
		]);

	} catch (Exception $e) {
		$pdo->rollBack();
		throw $e;
	}

} catch (Exception $e) {
	echo json_encode([
		'success' => false,
		'error' => $e->getMessage()
	]);
}

function updatePlayerStats($pdo, $player_id) {
	// Получаем текущие данные игрока
	$stmt = $pdo->prepare("
        SELECT hands_played, showdowns 
        FROM players 
        WHERE player_id = ?
        FOR UPDATE
    ");
	$stmt->execute([$player_id]);
	$stats = $stmt->fetch();

	if (!$stats) return;

	// Безопасное обновление WTSD (защита от деления на 0)
	$new_showdowns = $stats['showdowns'] + 1;
	$wtsd = ($stats['hands_played'] > 0)
		? ($new_showdowns / $stats['hands_played']) * 100
		: 0;

	$stmt = $pdo->prepare("
        UPDATE players 
        SET 
            showdowns = :showdowns,
            wtsd = :wtsd,
            last_seen = NOW()
        WHERE player_id = :player_id
    ");
	$stmt->execute([
		':showdowns' => $new_showdowns,
		':wtsd' => $wtsd,
		':player_id' => $player_id
	]);
}
?>
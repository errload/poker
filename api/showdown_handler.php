<?php
header('Content-Type: application/json');
require_once __DIR__.'/../db/config.php';

$response = ['success' => false];

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
	if (!$input) {
		$response['error'] = 'Invalid JSON input';
		echo json_encode($response);
		exit;
	}

	// Проверка обязательных полей
	if (!isset($input['hand_id'])) {
		$response['error'] = "Missing required field: hand_id";
		echo json_encode($response);
		exit;
	}

	if (!isset($input['players']) || !is_array($input['players'])) {
		$response['error'] = "Players data must be an array";
		echo json_encode($response);
		exit;
	}

	// Проверяем существование руки
	$stmt = $pdo->prepare("SELECT COUNT(*) FROM hands WHERE hand_id = ?");
	$stmt->execute([$input['hand_id']]);
	if (!$stmt->fetchColumn()) {
		$response['error'] = "Hand not found";
		echo json_encode($response);
		exit;
	}

	// Начинаем транзакцию
	$pdo->beginTransaction();

	$processedPlayers = [];
	$errors = [];

	try {
		// Сначала собираем информацию о всех игроках в раздаче
		$stmt = $pdo->prepare("
            SELECT player_id, action_type 
            FROM actions 
            WHERE hand_id = ? 
            ORDER BY sequence_num DESC
        ");
		$stmt->execute([$input['hand_id']]);
		$allActions = $stmt->fetchAll();

		// Определяем, кто сбросился
		$foldedPlayers = [];
		foreach ($allActions as $action) {
			if ($action['action_type'] == 'fold') {
				$foldedPlayers[$action['player_id']] = true;
			}
		}

		// Обрабатываем каждого игрока из входных данных
		foreach ($input['players'] as $player) {
			if (!isset($player['player_id']) || !isset($player['cards'])) {
				$errors[] = "Each player must have player_id and cards";
				continue;
			}

			$playerId = $player['player_id'];
			$processedPlayers[] = $playerId;

			// Проверка формата карт
			if (!preg_match('/^([2-9TJQKA][hdcs])(\s[2-9TJQKA][hdcs])?$/i', $player['cards'])) {
				$errors[] = "Invalid cards format for player {$playerId}. Valid examples: '7c 6s', 'Ah', 'Kd Jc'";
				continue;
			}

			// Проверяем существование игрока
			$stmt = $pdo->prepare("SELECT COUNT(*) FROM players WHERE player_id = ?");
			$stmt->execute([$playerId]);
			if (!$stmt->fetchColumn()) {
				$errors[] = "Player {$playerId} not found";
				continue;
			}

			// Вставляем или обновляем showdown
			$stmt = $pdo->prepare("
                INSERT INTO showdown (hand_id, player_id, cards)
                VALUES (:hand_id, :player_id, :cards)
                ON DUPLICATE KEY UPDATE cards = :cards
            ");
			$stmt->execute([
				':hand_id' => $input['hand_id'],
				':player_id' => $playerId,
				':cards' => $player['cards']
			]);

			// Обновляем время последней активности
			$pdo->prepare("UPDATE players SET last_seen = NOW() WHERE player_id = ?")
				->execute([$playerId]);
		}

		// Если есть ошибки, откатываем транзакцию
		if (!empty($errors)) {
			$pdo->rollBack();
			$response['error'] = implode("; ", $errors);
			$response['processed_players'] = $processedPlayers;
			echo json_encode($response);
			exit;
		}

		// Помечаем руку как завершенную
		$stmt = $pdo->prepare("
            UPDATE hands 
            SET is_completed = 1, 
                updated_at = NOW() 
            WHERE hand_id = ?
        ");
		$stmt->execute([$input['hand_id']]);

		$pdo->commit();

		$response = [
			'success' => true,
			'message' => 'Showdown recorded for ' . count($input['players']) . ' players',
			'hand_id' => $input['hand_id'],
			'players_processed' => $processedPlayers
		];

	} catch (Exception $e) {
		$pdo->rollBack();
		$response['error'] = $e->getMessage();
	}

} catch (Exception $e) {
	$response['error'] = $e->getMessage();
}

echo json_encode($response);
?>
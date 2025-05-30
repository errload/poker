<?php

$api_key = 'sk-JBDhoWZZwZSn8q2xmqmi9zETz12StFzC';
$url = 'https://api.proxyapi.ru/openai/v1/chat/completions';
$headers = ['Content-Type: application/json', 'Authorization: Bearer ' . $api_key];
$post = json_decode(file_get_contents('php://input'));

//class ResponseFront
//{
//	public $game_stage = 'средняя';
//	public $my_cards = 'Девятка бубен:Девятка червей';
//	public $my_stack = '100';
//	public $my_position = 'UTG';
//	public $board = 'Девятка треф:Пятерка бубен:Четверка треф:Шестерка червей:Король пик';
//	public $tournament = '8';
//	public $positions = ['SB', 'BB', 'UTG', 'UTG1', 'LJ', 'HJ', 'CO', 'BTN'];
//	public $player_actions_preflop = ['aaa:UTG:check', 'bbb:UTG1:check', 'ccc:LJ:call', 'ddd:HJ:raise 5 BB'];
//	public $player_actions_flop = ['kkk:SB:check', 'ddd:BB:check', 'aaa:UTG:call', 'bbb:UTG1:fold'];
//	public $player_actions_tern = ['ddd:SB:raise 30 BB', 'bbb:BB:check'];
//	public $player_actions_river = ['aaa:SB:call', 'bbb:BB:check'];
//	public $is_reset = true;
//}
//$post = new ResponseFront();

$file = 'data.txt';
if (file_exists($file)) $data = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
else $data = [];

$positions = implode(', ', $post->positions);
$my_cards = str_replace([':'], ', ', $post->my_cards);

//$preflop = str_replace([':'], ' ', implode(', ', $post->player_actions_preflop));
//$preflop = str_replace([$post->my_position . ' '], 'Я ', $preflop);
//$flop = str_replace([':'], ' ', implode(', ', $post->player_actions_flop));
//$flop = str_replace([$post->my_position . ' '], 'Я ', $flop);
//$tern = str_replace([':'], ' ', implode(', ', $post->player_actions_tern));
//$tern = str_replace([$post->my_position . ' '], 'Я ', $tern);
//$river = str_replace([':'], ' ', implode(', ', $post->player_actions_river));
//$river = str_replace([$post->my_position . ' '], 'Я ', $river);

$preflop = implode(', ', $post->player_actions_preflop);
$flop = implode(', ', $post->player_actions_flop);
$tern = implode(', ', $post->player_actions_tern);
$river = implode(', ', $post->player_actions_river);

$board = [];
if ($post->board) $board = explode(':', $post->board);

//$my_cards = $post->my_cards;
//$my_cards = implode(', ', $my_cards);

//Cтадия турнира
//MTT турнирах



$pre_content = "Ты — профессиональный покерный AI. ";
$pre_content .= "Это холдем онлайн турнир Bounty $post->tournament max. ";
$pre_content .= "Существующие позиции за столом: $positions. ";
//$pre_content .= "Я буду передавать некоторые раздачи, в которых принимал участие, в виде своих карт, стека, ставок игроков. ";
$pre_content .= "Ставки будут в формате никнейм:позиция:ставка. Мой никнейм определяй по моей позиции. ";
$pre_content .= "Анализизируй ситуацию за столом, поведения игроков и тд, и принимай решение. ";
$pre_content .= "Отвечай максимально коротко: какое действие (если рейз, то сколько) | короткое описание (буквально несколько слов на русском языке). ";

//$pre_content = "Представь, что ты модель GTO по типу GTO Wizard, с опытом самых сильных игроков в техасском холдем онлайн турнире Bounty.
//Действие происходит в онлайн $tournament.
//Существующие позиции за столом: $positions.
//Я буду показывать некоторые раздачи, в которых я участвовал ранее.
//Попробуй проанализировать и построить динамику игры на основании этих данных. ";
//
//$content = '';

$content = "Новая раздача. ";
$content .= "Стадия турнира: $post->game_stage. ";
$content .= "Мой стек: $post->my_stack BB. ";
$content .= "Мои карты: [$my_cards]. ";
$content .= "Моя позиция: $post->my_position. ";

//if (!count($post->player_actions_preflop) && count($board) < 3) $content .= "Мои действия? ";
if (count($post->player_actions_preflop)) {
	$content .= "Ставки на префлопе: $preflop. ";
//	if (count($board) < 3) $content .= "Мои действия? ";
}

//if (!count($post->player_actions_flop) && count($board) === 3) $content .= "Мои действия? ";
if (count($post->player_actions_flop)) {
	if (count($board) >= 3) $content .= "Флоп: [$board[0], $board[1], $board[2]]. ";
	$content .= "Ставки на флопе: $flop. ";
//	if (count($board) === 3) $content .= "Мои действия? ";
}

//if (!count($post->player_actions_tern) && count($board) === 4) $content .= "Мои действия? ";
if (count($post->player_actions_tern)) {
	if (count($board) >= 4) $content .= "Терн: [$board[3]]. ";
	$content .= "Ставки на терне: $tern. ";
//	if (count($board) === 4) $content .= "Мои действия? ";
}

//if (!count($post->player_actions_river) && count($board) === 5) $content .= "Мои действия? ";
if (count($post->player_actions_river)) {
	if (count($board) === 5) $content .= "Ривер: [$board[4]]. ";
	$content .= "Ставки на ривере: $river. ";
//	if (count($board) === 5) $content .= "Мои действия? ";
}

//if ($post->my_cards) $content .= "У меня карты ". str_replace(':',' и ', $post->my_cards). ". ";
//
//$board = [];
//if ($post->board) $board = explode(':', $post->board);
//
//if (count($board) < 3) $content .= "Флоп еще не открыт. ";
//if (!count($post->player_actions_preflop) && count($board) < 3) $content .= "Я в позиции $post->my_position и мое слово говорить первым. ";
//
//if (count($post->player_actions_preflop)) {
//	$content .= "На префлопе";
//	foreach ($post->player_actions_preflop as $item) {
//		$item = explode(':', $item);
//		if ($post->my_position === $item[0]) $content .= ", я в позиции $item[0] сказал $item[1]";
//		else $content .= ", игрок в позиции $item[0] сказал $item[1]";
//	}
//	$content .= ". ";
//	if (count($board) < 3) $content .= "Я в позиции $post->my_position и теперь мое слово. ";
//}
//
//if (count($board) === 3) $content .= "Открылся флоп $board[0], $board[1] и $board[2], терн еще не открыт. ";
//else if (count($board) >= 3) $content .= "Открылся флоп $board[0], $board[1] и $board[2]. ";
//if (!count($post->player_actions_flop) && count($board) === 3) $content .= "Я в позиции $post->my_position и мое слово говорить первым. ";
//
//if (count($post->player_actions_flop)) {
//	$content .= "На флопе";
//	foreach ($post->player_actions_flop as $item) {
//		$item = explode(':', $item);
//		if ($post->my_position === $item[0]) $content .= ", я в позиции $item[0] сказал $item[1]";
//		else $content .= ", игрок в позиции $item[0] сказал $item[1]";
//	}
//	$content .= ". ";
//	if (count($board) === 3) $content .= "Я в позиции $post->my_position и теперь мое слово. ";
//}
//
//if (count($board) === 4) $content .= "Открылась карта на терне $board[3], ривер еще не открыт. ";
//else if (count($board) >= 4) $content .= "Открылась карта на терне $board[3]. ";
//if (!count($post->player_actions_tern) && count($board) === 4) $content .= "Я в позиции $post->my_position и мое слово говорить первым. ";
//
//if (count($post->player_actions_tern)) {
//	$content .= "На терне";
//	foreach ($post->player_actions_tern as $item) {
//		$item = explode(':', $item);
//		if ($post->my_position === $item[0]) $content .= ", я в позиции $item[0] сказал $item[1]";
//		else $content .= ", игрок в позиции $item[0] сказал $item[1]";
//	}
//	$content .= ". ";
//	if (count($board) === 4) $content .= "Я в позиции $post->my_position и теперь мое слово. ";
//}
//
//if (count($board) === 5) $content .= "Открылась карта на ривере $board[4]. ";
//if (!count($post->player_actions_river) && count($board) === 5) $content .= "Я в позиции $post->my_position и мое слово говорить первым. ";
//
//if (count($post->player_actions_river)) {
//	$content .= "На ривере";
//	foreach ($post->player_actions_river as $item) {
//		$item = explode(':', $item);
//		if ($post->my_position === $item[0]) $content .= ", я в позиции $item[0] сказал $item[1]";
//		else $content .= ", игрок в позиции $item[0] сказал $item[1]";
//	}
//	$content .= ". ";
//	if (count($board) === 5) $content .= "Я в позиции $post->my_position и теперь мое слово. ";
//}
//
$content .= "Мои действия?";
//$content .= "Мои действия?";
//$content .= "Отвечай максимально коротко: действие (если рейз, то сколько) | короткое описание.";

$messages = [];
$messages[] = ['role' => 'user', 'content' => $pre_content];

foreach ($data as $line) {
	$line = explode('=', $line);
	$role = $line[0];
	$old_content = $line[1];
	if (str_contains($old_content, 'Мои действия?')) $old_content = mb_substr($old_content, 0, -15);
	if (str_contains($content, $old_content)) continue;
	if ($content === $old_content) continue;
	$messages[] = ['role' => $role, 'content' => $old_content];
}

$messages[] = ['role' => 'user', 'content' => $content];

if (!$post->is_reset) {
	$ch = curl_init($url);
	curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
	curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
	curl_setopt($ch, CURLOPT_POST, true);
	curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode([
		'model' => 'gpt-4.1',
//	'messages' => [[ 'role' => 'user', 'content' => $content ]],
		'messages' => $messages,
		'temperature' => 0.3
	]));
	$response = curl_exec($ch);

	if (curl_errno($ch)) echo json_encode('Ошибка cURL: ' . curl_error($ch));
	else {
		$response = json_decode($response);
		$response = $response->choices[0]->message->content;
//		foreach ($messages as $message) {
//			$puts[] = $message['role'] . '=' . $message['content'];
//		}
//		unset($puts[0]);
//		file_put_contents($file, implode(PHP_EOL, $puts));
		echo json_encode(['response' => $response, 'content' => $content]);
	}

	curl_close($ch);
} else {
//	foreach ($messages as $message) {
//		$puts[] = $message['role'] . '=' . $message['content'];
//	}
//	unset($puts[0]);
//	file_put_contents($file, implode(PHP_EOL, $puts));
	echo json_encode(['response' => '', 'content' => '']);
}

//echo json_encode($messages);

const positions = ['BTN', 'SB', 'BB', 'UTG', 'UTG1', 'LJ', 'HJ', 'CO']
let bid_counter = 2.2

// remove class list
const removeClassCards = elem => {
	elem.classList.remove('blue')
	elem.classList.remove('red')
	elem.classList.remove('green')
	elem.classList.remove('black')
	elem.classList.remove('check')
	elem.textContent = ''
}

async function sendAjax(url, params) {
	const response = await fetch(url, {
		method: 'POST',
		headers: { 'Content-Type': 'application/json' },
		body: JSON.stringify(params)
	})

	return result = await response.json()
}

// ставка
async function sendPlayerAction(handId, playerId, street, actionType, amount = null) {
	try {
		const response = await fetch('/4bet/api/action_handler.php', {
			method: 'POST',
			headers: { 'Content-Type': 'application/json' },
			body: JSON.stringify({
				"hand_id": 2,
				"player_id": 1,
				"street": "preflop",
				"action_type": "raise",
				"amount": 5.0,
				"current_stack": 43.0
			})
		});

		const data = await response.json();
		if (!data.success) throw new Error(data.error);

		console.log('Action saved:', data.action_id);
		return data;
	} catch (error) {
		console.error('Error:', error);
	}
}

const changeStack = elem => {
	select_value = parseFloat(elem.querySelector('.select_stack').value)
	select_value = Math.floor(select_value - bid_counter)
	if (select_value < 0) select_value = 0
	elem.querySelector('.select_stack').value = select_value
	return select_value
}

// add options select stacks
document
	.querySelectorAll('.player .select_stack')
	.forEach(elem => {
		for (let i = 125; i >= 0; i--) {
			const option = document.createElement('option')
			option.value = i
			option.textContent = i
			elem.appendChild(option)
		}
	})

// click radio player
document
	.querySelectorAll('.player_radio .radio')
	.forEach(radio => {
		radio.addEventListener('change', async function () {
			let radio_ID = parseInt(this.getAttribute('data-id'))
			bid_counter = 0

			positions.forEach((value, key) => {
				const player = document.querySelector(`.player.player${radio_ID}`)

				// меняем позиции
				player.querySelector('.player_position').textContent = value

				// отображаем игроков
				player.querySelector('.player_buttons').style.display = 'flex'

				// чистим карты
				player
					.querySelectorAll('.stack_cards .slot')
					.forEach(elem => {
						removeClassCards(elem)
						elem.classList.add('check')
					})

				radio_ID++
				if (radio_ID > 8) radio_ID = 1
			})

			// чистим борд
			document
				.querySelectorAll('.board_slot')
				.forEach(elem => {
					removeClassCards(elem)
				})

			// новая раздача
			try {
				const response = await fetch('/4bet/api/new_hand_mysql.php', {
					method: 'POST',
					headers: {
						'Content-Type': 'application/json'
					},
					body: JSON.stringify({
						"hero_position": "BTN",
						"hero_stack": 50.5,
						"stacks": {
							"player_1": 48.0,
							"player_2": 52.5
						},
						"hero_cards": "AhKh"
					})
				});

				const data = await response.json();

				if (data.success) {
					console.log('Новая раздача создана! ID:', data.hand_id);
					sessionStorage.setItem('hand_id', data.hand_id);
				} else {
					console.error('Ошибка:', data.error);
				}
			} catch (error) {
				console.error('Network error:', error);
			}
		})
	})

// click cards
document
	.querySelectorAll('.slots .slot')
	.forEach(elem => {
		elem.addEventListener('click', function () {
			const classCard = (this.className).split(' ')[1]

			if (!document.querySelectorAll('.player1 .slot')[0].textContent) {
				document.querySelectorAll('.player1 .slot')[0].textContent = this.textContent
				document.querySelectorAll('.player1 .slot')[0].classList.remove('check')
				document.querySelectorAll('.player1 .slot')[0].classList.add(classCard)
				return false
			}

			if (!document.querySelectorAll('.player1 .slot')[1].textContent) {
				document.querySelectorAll('.player1 .slot')[1].textContent = this.textContent
				document.querySelectorAll('.player1 .slot')[1].classList.remove('check')
				document.querySelectorAll('.player1 .slot')[1].classList.add(classCard)
				return false
			}

			if (!document.querySelectorAll('.board_slot')[0].textContent) {
				document.querySelectorAll('.board_slot')[0].textContent = this.textContent
				document.querySelectorAll('.board_slot')[0].classList.add(classCard)
				return false
			}

			if (!document.querySelectorAll('.board_slot')[1].textContent) {
				document.querySelectorAll('.board_slot')[1].textContent = this.textContent
				document.querySelectorAll('.board_slot')[1].classList.add(classCard)
				return false
			}

			if (!document.querySelectorAll('.board_slot')[2].textContent) {
				document.querySelectorAll('.board_slot')[2].textContent = this.textContent
				document.querySelectorAll('.board_slot')[2].classList.add(classCard)
				return false
			}

			if (!document.querySelectorAll('.board_slot')[3].textContent) {
				document.querySelectorAll('.board_slot')[3].textContent = this.textContent
				document.querySelectorAll('.board_slot')[3].classList.add(classCard)
				return false
			}

			if (!document.querySelectorAll('.board_slot')[4].textContent) {
				document.querySelectorAll('.board_slot')[4].textContent = this.textContent
				document.querySelectorAll('.board_slot')[4].classList.add(classCard)
				return false
			}
		})
	})

// click kick
document
	.querySelector('.select_kick')
	.addEventListener('change', async function () {
		if (this.value === 'kick') return false
		console.log(this.value)

		try {
			const response = await fetch('/4bet/api/delete_player.php', {
				method: 'POST',
				headers: {
					'Content-Type': 'application/json',
				},
				body: JSON.stringify({ player_id: this.value })
			});

			const result = await response.json();

			if (response.ok) {
				console.log('Player deleted successfully:', result.message);
				// Обновляем UI или выполняем другие действия после успешного удаления
			} else {
				console.error('Error deleting player:', result.error);
			}
		} catch (error) {
			console.error('Network error:', error);
		}

		this.value = 'kick'
	})

// click clear
document
	.querySelector('.clear')
	.addEventListener('click', async function () {
		console.log('clear')

		try {
			const response = await fetch('/4bet/api/reset_database.php', {
				method: 'POST',
				headers: {
					'Content-Type': 'application/json',
				},
				body: JSON.stringify({ confirm: true })
			});

			const result = await response.json();

			if (response.ok) {
				console.log('Database reset successfully:', result.message);
				alert('База данных успешно очищена!');
				// Обновляем UI или выполняем другие действия после сброса
			} else {
				console.error('Error resetting database:', result.error);
				alert('Ошибка при очистке базы данных: ' + result.error);
			}
		} catch (error) {
			console.error('Network error:', error);
			alert('Сетевая ошибка: ' + error.message);
		}
	})

// click fold
document
	.querySelectorAll('.player .fold')
	.forEach(elem => {
		elem.addEventListener('click', function () {
			const player = this.closest('.player')

			player.querySelector('.player_buttons').style.display = 'none'

			removeClassCards(player.querySelectorAll('.stack_cards .slot')[0])
			removeClassCards(player.querySelectorAll('.stack_cards .slot')[1])

			result = sendAjax('/4bet/api/action_handler.php', {
				'hand_id': sessionStorage.getItem('hand_id'),
				'player_id': player.querySelector('.radio').value,
				"street": 'preflop',
				'action_type': 'fold',
				'amount': null,
				'current_stack': player.querySelector('.select_stack').value
			})
		})
	})

// click call
document
	.querySelectorAll('.player .call')
	.forEach(elem => {
		elem.addEventListener('click', async function () {
			const player = this.closest('.player')
			const player_ID = player.querySelector('.radio').value
			const player_name = player.querySelector('.radio').getAttribute('id')
			const position = player.querySelector('.player_position').textContent
			const stack = player.querySelector('.select_stack').value

			console.log([player_ID, player_name, position, stack, 'call'])
		})
	})

// click check
document
	.querySelectorAll('.player .check')
	.forEach(elem => {
		elem.addEventListener('click', function () {
			const player = this.closest('.player')
			const player_ID = player.querySelector('.radio').value
			const player_name = player.querySelector('.radio').getAttribute('id')
			const position = player.querySelector('.player_position').textContent
			const stack = player.querySelector('.select_stack').value

			console.log([player_ID, player_name, position, stack, 'check'])
		})
	})

// click raise
document
	.querySelectorAll('.player .raise')
	.forEach(elem => {
		elem.addEventListener('click', function () {
			const player = this.closest('.player')

			const overlay = document.createElement('div')
			overlay.classList.add('popup-overlay')
			const popup = document.createElement('div')
			popup.classList.add('popup-window')

			const addBidRaise = (sum) => {
				const bid_raise = document.createElement('div')
				bid_raise.classList.add('bid_raise')
				bid_raise.textContent = sum
				popup.appendChild(bid_raise)
			}

			const bids = [
				1, 1.5, 1.7, 1.9, 2, 2.1, 2.2, 2.3, 2.5, 2.8, 3, 3.5, 4, 4.5, 5, 5.5, 6, 6.5, 7, 8, 9,
				10, 11, 12, 14, 16, 18, 20, 23, 27, 30, 35, 40, 45, 50, 60, 70, 80, 90, 100
			]

			for (let bid in bids) {
				const bid_raise = document.createElement('div')
				bid_raise.classList.add('bid_raise')
				bid_raise.textContent = bids[bid]
				popup.appendChild(bid_raise)
			}

			overlay.appendChild(popup);
			document.body.appendChild(overlay);

			document
				.querySelector('.popup-overlay')
				.addEventListener('click', e => {
					if (!e.target.classList.contains('popup-overlay')) return false
					document.body.removeChild(overlay);
				})

			document
				.querySelectorAll('.bid_raise')
				.forEach(bid_raise => {
					bid_raise.addEventListener('click', e => {
						const player_ID = player.querySelector('.radio').value
						const player_name = player.querySelector('.radio').getAttribute('id')
						const position = player.querySelector('.player_position').textContent
						const stack = player.querySelector('.select_stack').value
						const bid = e.target.textContent

						console.log([player_ID, player_name, position, stack, `raise ${bid} BB`])
						document.body.removeChild(overlay);
					})
				})
		})
	})

// click all-in
document
	.querySelectorAll('.player .all-in')
	.forEach(elem => {
		elem.addEventListener('click', function () {
			const player = this.closest('.player')
			const player_ID = player.querySelector('.radio').value
			const player_name = player.querySelector('.radio').getAttribute('id')
			const position = player.querySelector('.player_position').textContent
			const stack = player.querySelector('.select_stack').value

			console.log([player_ID, player_name, position, stack, 'all-in'])
		})
	})

// click write
document
	.querySelector('.write')
	.addEventListener('click', async function () {

		const handId = 2; // ID раздачи
		// const players = [
		// 	{ player_id: 111, cards: 'AhKh' }, // Герой
		// 	{ player_id: 333, cards: 'QcQd' }, // Оппонент 1
		// ];

		try {
			const response = await fetch('/4bet/api/showdown_handler.php', {
				method: 'POST',
				headers: {
					'Content-Type': 'application/json',
				},
				body: JSON.stringify({
					"hand_id": handId,
					"players": [
						{
							"player_id": 1,
							"cards": "Ah9h"
						},
						{
							"player_id": 2,
							"cards": "QcQd"
						}
					]
				})
			});

			const result = await response.json();

			if (!result.success) {
				console.error('Showdown error:', result.error);
				return false;
			}

			console.log('Showdown recorded:', result.message);
			return true;

		} catch (error) {
			console.error('Network error:', error);
			return false;
		}

	})

// click gto
document
	.querySelector('.gto')
	.addEventListener('click', async function () {

		const handId = 2; // ID текущей раздачи
		const currentStreet = 'flop'; // Текущая улица: 'preflop', 'flop', 'turn', 'river'
		const heroPos = 'BTN'; // Позиция героя

		try {
			const response = await fetch('/4bet/api/get_hand_analysis.php', {
				method: 'POST',
				headers: {
					'Content-Type': 'application/json',
				},
				body: JSON.stringify({
					hand_id: handId,
					current_street: currentStreet,
					hero_position: heroPos
				})
			});

			const result = await response.json();

			if (!result.success) {
				console.error('Analysis error:', result.error);
				return null;
			}

			// Обработка рекомендации ИИ
			console.log('AI Recommendation:', result.ai_recommendation);

		} catch (error) {
			console.error('Network error:', error);
			return null;
		}

	})

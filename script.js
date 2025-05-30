const positions = ['BTN', 'SB', 'BB', 'UTG', 'UTG+1', 'MP', 'HJ', 'CO']
let hand_id = null
let bid_counter = 1

// remove class list
const removeClassCards = elem => {
	elem.classList.remove('blue')
	elem.classList.remove('red')
	elem.classList.remove('green')
	elem.classList.remove('black')
	elem.classList.remove('check')
	elem.textContent = ''
}

function showNotification(message) {
	const notification = document.getElementById('notification')
	let fadeOutTimeout

	clearTimeout(fadeOutTimeout);
	notification.style.transition = 'none'
	notification.textContent = message
	notification.style.opacity = '1'
	notification.style.display = 'block'

	setTimeout(() => {
		notification.style.transition = 'opacity 1s ease'

		fadeOutTimeout = setTimeout(() => {
			notification.style.opacity = '0'

			setTimeout(() => {
				notification.style.display = 'none'
			}, 500);
		}, 300)
	}, 10)
}

const getBoardStatus = () => {
	let counter = 0

	document
		.querySelectorAll('.board_slot')
		.forEach(elem => {
			if (elem.textContent) counter++
		})

	if (counter < 3) return 'preflop'
	if (counter === 3) return 'flop'
	if (counter === 4) return 'turn'
	if (counter === 5) return 'river'
}

async function sendAjax(url, params) {
	const response = await fetch(url, {
		method: 'POST',
		headers: { 'Content-Type': 'application/json' },
		body: JSON.stringify(params)
	})

	const result = await response.json()
	return result
}

const changeSelectStack = (elem, is_allin) => {
	select_value = parseFloat(elem.querySelector('.select_stack').value)

	if (is_allin) {
		bid_counter = select_value > bid_counter ? select_value : bid_counter
		select_value = 0
	} else {
		select_value = Math.floor(select_value - bid_counter)
		if (select_value < 0) select_value = 0
	}

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
			if (i === 125) option.setAttribute('selected', 'selected')
			elem.appendChild(option)
		}
	})

// click radio player
document
	.querySelectorAll('.player_radio .radio')
	.forEach(radio => {
		radio.addEventListener('change', async function () {
			let radio_ID = parseInt(this.getAttribute('data-id'))
			hand_id = null
			bid_counter = 1

			positions.forEach((value, key) => {
				const player = document.querySelector(`.player.player${radio_ID}`)

				player.querySelector('.player_position').textContent = value
				player.querySelector('.player_buttons').style.display = 'flex'
				player
					.querySelectorAll('.stack_cards .slot')
					.forEach(elem => {
						removeClassCards(elem)
						elem.classList.add('check')
					})

				radio_ID++
				if (radio_ID > 8) radio_ID = 1
			})

			document
				.querySelectorAll('.board_slot')
				.forEach(elem => {
					removeClassCards(elem)
				})

			document
				.querySelectorAll('.player')
				.forEach(elem => {
					elem.style.border = '1px solid #939393'
				})
			document
				.querySelector(`.player.player${radio_ID}`)
				.style.border = '3px solid #228B22'

			const result = await sendAjax('/4bet/api/new_hand_mysql.php', {
				hero_position: document.querySelector('.player1 .player_position').textContent,
				hero_stack: document.querySelector('.player1 .select_stack').value,
				stacks: {
					player2: document.querySelector('.player2 .select_stack').value,
					player3: document.querySelector('.player3 .select_stack').value,
					player4: document.querySelector('.player4 .select_stack').value,
					player5: document.querySelector('.player5 .select_stack').value,
					player6: document.querySelector('.player6 .select_stack').value,
					player7: document.querySelector('.player7 .select_stack').value,
					player8: document.querySelector('.player8 .select_stack').value
				},
				hero_cards: null
			})

			hand_id = result.hand_id
			console.log(result)
		})
	})

// click cards
document
	.querySelectorAll('.slots .slot')
	.forEach(elem => {
		elem.addEventListener('click', async function () {
			const classCard = (this.className).split(' ')[1]
			let cards = null

			if (!document.querySelectorAll('.player1 .slot')[0].textContent) {
				document.querySelectorAll('.player1 .slot')[0].textContent = this.textContent
				document.querySelectorAll('.player1 .slot')[0].classList.remove('check')
				document.querySelectorAll('.player1 .slot')[0].classList.add(classCard)
				document.querySelectorAll('.player1 .slot')[0].dataset.card = this.dataset.slot
				return false
			}

			if (!document.querySelectorAll('.player1 .slot')[1].textContent) {
				document.querySelectorAll('.player1 .slot')[1].textContent = this.textContent
				document.querySelectorAll('.player1 .slot')[1].classList.remove('check')
				document.querySelectorAll('.player1 .slot')[1].classList.add(classCard)
				document.querySelectorAll('.player1 .slot')[1].dataset.card = this.dataset.slot

				cards = document.querySelectorAll('.player1 .slot')[0].textContent
				cards += document.querySelectorAll('.player1 .slot')[0].dataset.card
				cards += document.querySelectorAll('.player1 .slot')[1].textContent
				cards += document.querySelectorAll('.player1 .slot')[1].dataset.card

				const result = await sendAjax('/4bet/api/update_hero_cards.php', {
					hand_id: hand_id,
					hero_cards: cards
				})

				console.log(result)
				return false
			}

			if (!document.querySelectorAll('.board_slot')[0].textContent) {
				document.querySelectorAll('.board_slot')[0].textContent = this.textContent
				document.querySelectorAll('.board_slot')[0].classList.add(classCard)
				document.querySelectorAll('.board_slot')[0].dataset.card = this.dataset.slot
				return false
			}

			if (!document.querySelectorAll('.board_slot')[1].textContent) {
				document.querySelectorAll('.board_slot')[1].textContent = this.textContent
				document.querySelectorAll('.board_slot')[1].classList.add(classCard)
				document.querySelectorAll('.board_slot')[1].dataset.card = this.dataset.slot
				return false
			}

			if (!document.querySelectorAll('.board_slot')[2].textContent) {
				document.querySelectorAll('.board_slot')[2].textContent = this.textContent
				document.querySelectorAll('.board_slot')[2].classList.add(classCard)
				document.querySelectorAll('.board_slot')[2].dataset.card = this.dataset.slot

				cards = document.querySelectorAll('.board_slot')[0].textContent
				cards += document.querySelectorAll('.board_slot')[0].dataset.card
				cards += document.querySelectorAll('.board_slot')[1].textContent
				cards += document.querySelectorAll('.board_slot')[1].dataset.card
				cards += document.querySelectorAll('.board_slot')[2].textContent
				cards += document.querySelectorAll('.board_slot')[2].dataset.card

				const result = await sendAjax('/4bet/api/update_board.php', {
					hand_id: hand_id,
					board: cards
				});

				console.log(result)
				return false
			}

			if (!document.querySelectorAll('.board_slot')[3].textContent) {
				document.querySelectorAll('.board_slot')[3].textContent = this.textContent
				document.querySelectorAll('.board_slot')[3].classList.add(classCard)
				document.querySelectorAll('.board_slot')[3].dataset.card = this.dataset.slot

				cards = document.querySelectorAll('.board_slot')[0].textContent
				cards += document.querySelectorAll('.board_slot')[0].dataset.card
				cards += document.querySelectorAll('.board_slot')[1].textContent
				cards += document.querySelectorAll('.board_slot')[1].dataset.card
				cards += document.querySelectorAll('.board_slot')[2].textContent
				cards += document.querySelectorAll('.board_slot')[2].dataset.card
				cards += ' ' + document.querySelectorAll('.board_slot')[3].textContent
				cards += document.querySelectorAll('.board_slot')[3].dataset.card

				const result = await sendAjax('/4bet/api/update_board.php', {
					hand_id: hand_id,
					board: cards
				});

				console.log(result)
				return false
			}

			if (!document.querySelectorAll('.board_slot')[4].textContent) {
				document.querySelectorAll('.board_slot')[4].textContent = this.textContent
				document.querySelectorAll('.board_slot')[4].classList.add(classCard)
				document.querySelectorAll('.board_slot')[4].dataset.card = this.dataset.slot

				cards = document.querySelectorAll('.board_slot')[0].textContent
				cards += document.querySelectorAll('.board_slot')[0].dataset.card
				cards += document.querySelectorAll('.board_slot')[1].textContent
				cards += document.querySelectorAll('.board_slot')[1].dataset.card
				cards += document.querySelectorAll('.board_slot')[2].textContent
				cards += document.querySelectorAll('.board_slot')[2].dataset.card
				cards += ' ' + document.querySelectorAll('.board_slot')[3].textContent
				cards += document.querySelectorAll('.board_slot')[3].dataset.card
				cards += ' ' + document.querySelectorAll('.board_slot')[4].textContent
				cards += document.querySelectorAll('.board_slot')[4].dataset.card

				const result = await sendAjax('/4bet/api/update_board.php', {
					hand_id: hand_id,
					board: cards
				});

				console.log(result)
				return false
			}

			let start_position = null
			document
				.querySelectorAll('.player')
				.forEach((elem, key) => {
					key++
					if (elem.querySelector('.player_position').textContent === 'BTN') {
						start_position = key
					}
				})

			for (let i = 0; i < 8; i++) {
				start_position++
				if (start_position > 8) start_position = 1
				player = document.querySelector(`.player${start_position}`)
				if (player.querySelector('.player_buttons').style.display === 'none') continue

				if (!player.querySelectorAll('.slot')[0].textContent) {
					player.querySelectorAll('.slot')[0].textContent = this.textContent
					player.querySelectorAll('.slot')[0].classList.remove('check')
					player.querySelectorAll('.slot')[0].classList.add(classCard)
					player.querySelectorAll('.slot')[0].dataset.card = this.dataset.slot
					return false
				}

				if (!player.querySelectorAll('.slot')[1].textContent) {
					player.querySelectorAll('.slot')[1].textContent = this.textContent
					player.querySelectorAll('.slot')[1].classList.remove('check')
					player.querySelectorAll('.slot')[1].classList.add(classCard)
					player.querySelectorAll('.slot')[1].dataset.card = this.dataset.slot
					return false
				}
			}
		})
	})

// click kick
document
	.querySelector('.select_kick')
	.addEventListener('change', async function () {
		if (this.value === 'kick') return false
		const result = await sendAjax('/4bet/api/delete_player.php', { player_id: this.value })
		this.value = 'kick'
		console.log(result)
	})

// click clear
document
	.querySelector('.clear')
	.addEventListener('click', async function () {
		const result = await sendAjax('/4bet/api/reset_database.php', { confirm: true })
		console.log(result)
	})

// click fold
document
	.querySelectorAll('.player .fold')
	.forEach(elem => {
		elem.addEventListener('click', async function () {
			const player = this.closest('.player')
			console.log(bid_counter)

			player.querySelector('.player_buttons').style.display = 'none'

			// removeClassCards(player.querySelectorAll('.stack_cards .slot')[0])
			// removeClassCards(player.querySelectorAll('.stack_cards .slot')[1])

			const result = await sendAjax('/4bet/api/action_handler.php', {
				'hand_id': hand_id,
				'player_id': player.querySelector('.radio').value,
				"street": getBoardStatus(),
				'action_type': 'fold',
				'amount': null,
				'current_stack': player.querySelector('.select_stack').value
			})

			console.log(result)
			showNotification('fold')
		})
	})

// click call
document
	.querySelectorAll('.player .call')
	.forEach(elem => {
		elem.addEventListener('click', async function () {
			const player = this.closest('.player')
			changeSelectStack(player, false)
			console.log(bid_counter)

			const result = await sendAjax('/4bet/api/action_handler.php', {
				'hand_id': hand_id,
				'player_id': player.querySelector('.radio').value,
				"street": getBoardStatus(),
				'action_type': 'call',
				'amount': bid_counter,
				'current_stack': player.querySelector('.select_stack').value
			})

			console.log(result)
			showNotification('call')
		})
	})

// click check
document
	.querySelectorAll('.player .check')
	.forEach(elem => {
		elem.addEventListener('click', async function () {
			const player = this.closest('.player')
			console.log(bid_counter)

			const result = await sendAjax('/4bet/api/action_handler.php', {
				'hand_id': hand_id,
				'player_id': player.querySelector('.radio').value,
				"street": getBoardStatus(),
				'action_type': 'check',
				'amount': null,
				'current_stack': player.querySelector('.select_stack').value
			})

			console.log(result)
			showNotification('check')
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
				return bid_raise
			};

			const bidRows = [
				[1, 1.1, 1.2, 1.3, 1.4, 1.5, 1.6, 1.7, 1.8, 1.9],
				[2, 2.1, 2.2, 2.3, 2.4, 2.5, 2.6, 2.7, 2.8, 2.9],
				[3, 3.1, 3.2, 3.3, 3.4, 3.5, 3.6, 3.7, 3.8, 3.9],
				[4, 4.2, 4.4, 4.6, 4.8],
				[5, 5.2, 5.4, 5.6, 5.8],
				[6, 6.5, 7, 7.5, 8, 8.5, 9],
				[10, 15, 20, 25, 30, 35, 40, 45, 50],
				[55, 60, 65, 70, 75, 80, 85, 90, 95, 100]
			]

			bidRows.forEach(row => {
				const rowContainer = document.createElement('div')
				rowContainer.classList.add('bid-row')
				row.forEach(bid => { rowContainer.appendChild(addBidRaise(bid)) })
				popup.appendChild(rowContainer)
			})

			overlay.appendChild(popup)
			document.body.appendChild(overlay)

			document.querySelector('.popup-overlay').addEventListener('click', e => {
				if (!e.target.classList.contains('popup-overlay')) return false
				document.body.removeChild(overlay)
			})

			document
				.querySelectorAll('.bid_raise')
				.forEach(bid_raise => {
					bid_raise.addEventListener('click', async (e) => {
						bid_counter = parseFloat(e.target.textContent)
						changeSelectStack(player, false)

						const result = await sendAjax('/4bet/api/action_handler.php', {
							'hand_id': hand_id,
							'player_id': player.querySelector('.radio').value,
							"street": getBoardStatus(),
							'action_type': 'raise',
							'amount': bid_counter,
							'current_stack': player.querySelector('.select_stack').value
						})

						console.log(result)
						showNotification('raise ' + bid_counter + ' bb')
						document.body.removeChild(overlay);
					})
				})
		})
	})

// click all-in
document
	.querySelectorAll('.player .all-in')
	.forEach(elem => {
		elem.addEventListener('click', async function () {
			const player = this.closest('.player')
			changeSelectStack(player, true)
			console.log(bid_counter)

			const result = await sendAjax('/4bet/api/action_handler.php', {
				'hand_id': hand_id,
				'player_id': player.querySelector('.radio').value,
				"street": getBoardStatus(),
				'action_type': 'all-in',
				'amount': bid_counter,
				'current_stack': player.querySelector('.select_stack').value
			})

			console.log(result)
			showNotification('all-in ' + bid_counter + ' bb')
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

		const result = await sendAjax('/4bet/api/get_hand_analysis.php', {
			'hand_id': hand_id,
			'current_street': getBoardStatus(),
			"hero_position": document.querySelector('.player .player_position').textContent
		})

		console.log(result)
		document.querySelector('.line_result').textContent = result.data
	})

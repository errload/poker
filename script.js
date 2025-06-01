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

// // update stacks
// (() => {
// 	document
// 		.querySelectorAll('.player .select_stack')
// 		.forEach(elem => {
// 			elem.addEventListener('change', async function () {
// 				const howdown = await sendAjax('/4bet/api/update_stack.php', {
// 					hand_id: hand_id,
// 					player_id: elem.closest('.player').querySelector('.radio').value,
// 					new_stack: elem.value
// 				})
//
// 				console.log(result)
// 			})
// 		})
// })()

async function sendAjax(url, params) {
	const response = await fetch(url, {
		method: 'POST',
		headers: { 'Content-Type': 'application/json' },
		body: JSON.stringify(params)
	})

	const result = await response.json()
	return result
}

// const changeSelectStack = (elem, is_allin) => {
// 	select_value = parseFloat(elem.querySelector('.stack_cards .stack').textContent)
//
// 	if (is_allin) {
// 		bid_counter = select_value > bid_counter ? select_value : bid_counter
// 		select_value = 0
// 	} else {
// 		select_value = Math.floor(select_value - bid_counter)
// 		if (select_value < 0) select_value = 0
// 	}
//
// 	elem.querySelector('.select_stack').value = select_value
// 	return select_value
// }

// // add options select stacks
// document
// 	.querySelectorAll('.player .select_stack')
// 	.forEach(elem => {
// 		for (let i = 125; i >= 0; i--) {
// 			const option = document.createElement('option')
// 			option.value = i
// 			option.textContent = i
// 			if (i === 125) option.setAttribute('selected', 'selected')
// 			elem.appendChild(option)
// 		}
// 	})

// click radio player
document
	.querySelectorAll('.player_radio .radio')
	.forEach(radio => {
		radio.addEventListener('change', async function () {
			let players = []

			document
				.querySelectorAll('.player')
				.forEach(elem => {
					if (elem.querySelectorAll('.slot')[0].textContent && elem.querySelectorAll('.slot')[1].textContent) {
						let cards = ''
						let player_id = parseInt(elem.querySelector('.radio').value)
						if (player_id === 111) return

						cards += elem.querySelectorAll('.slot')[0].textContent
						cards += elem.querySelectorAll('.slot')[0].dataset.card
						cards += elem.querySelectorAll('.slot')[1].textContent
						cards += elem.querySelectorAll('.slot')[1].dataset.card

						players.push({
							player_id: elem.querySelector('.radio').value,
							cards: cards
						})

						console.log(result)
					}
				})

			const howdown = await sendAjax('/4bet/api/showdown_handler.php', {
				'hand_id': hand_id,
				'players': players
			})

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
				hero_stack: document.querySelector('.stack_cards .stack').textContent,
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

// change stack
document
	.querySelectorAll('.player .select_stack')
	.forEach(elem => {
		elem.addEventListener('click', e => {
			const player = e.target.closest('.player')
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
				[1, 2, 3, 4, 5, 6, 7, 8, 9, 10],
				[11, 12, 13, 14, 15, 16, 17, 18, 19, 20],
				[21, 22, 23, 24, 25, 26, 27, 28, 29, 30],
				[31, 32, 33, 34, 35, 36, 37, 38, 39, 40],
				[41, 42, 43, 44, 45, 46, 47, 48, 49, 50],
				[51, 52, 53, 54, 55, 56, 57, 58, 59, 60],
				[61, 62, 63, 64, 65, 66, 67, 68, 69, 70],
				[71, 72, 73, 74, 75, 76, 77, 78, 79, 80],
				[81, 82, 83, 84, 85, 86, 87, 88, 89, 90],
				[91, 92, 93, 94, 95, 96, 97, 98, 99, 100],
				[101, 102, 103, 104, 105, 106, 107, 108, 109, 110],
				[111, 112, 113, 114, 115, 116, 117, 118, 119, 120],
				[121, 122, 123, 124, 125],
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
					bid_raise.addEventListener('click', async e => {
						const result = await sendAjax('/4bet/api/update_stack.php', {
							hand_id: hand_id,
							player_id: player.querySelector('.radio').value,
							new_stack: e.target.textContent
						})

						player.querySelector('.stack_cards .stack').textContent = e.target.textContent
						document.body.removeChild(overlay)
						console.log(result)
					})
				})
		})
	})

// click fold
document
	.querySelectorAll('.player .fold')
	.forEach(elem => {
		elem.addEventListener('click', async function () {
			const player = this.closest('.player')
			console.log(bid_counter)

			player.querySelector('.player_buttons').style.display = 'none'

			const result = await sendAjax('/4bet/api/action_handler.php', {
				'hand_id': hand_id,
				'player_id': player.querySelector('.radio').value,
				"street": getBoardStatus(),
				'action_type': 'fold',
				'amount': null,
				'current_stack': player.querySelector('.stack_cards .stack').textContent
			})

			player.querySelector('.stack_cards .stack').textContent = result.new_stack
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
			// changeSelectStack(player, false)
			console.log(bid_counter)

			const result = await sendAjax('/4bet/api/action_handler.php', {
				'hand_id': hand_id,
				'player_id': player.querySelector('.radio').value,
				"street": getBoardStatus(),
				'action_type': 'call',
				'amount': bid_counter,
				'current_stack': player.querySelector('.stack_cards .stack').textContent
			})

			player.querySelector('.stack_cards .stack').textContent = result.new_stack
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
				'current_stack': player.querySelector('.stack_cards .stack').textContent
			})

			player.querySelector('.stack_cards .stack').textContent = result.new_stack
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
				[4, 4.1, 4.2, 4.3, 4.4, 4.5, 4.6, 4.7, 4.8, 4.9],
				[5, 5.1, 5.2, 5.3, 5.4, 5.5, 5.6, 5.7, 5.8, 5.9],
				[6, 6.1, 6.2, 6.3, 6.4, 6.5, 6.6, 6.7, 6.8, 6.9],
				[7, 7.1, 7.2, 7.3, 7.4, 7.5, 7.6, 7.7, 7.8, 7.9],
				[8, 8.1, 8.2, 8.3, 8.4, 8.5, 8.6, 8.8, 8.8, 8.9],
				[9, 9.1, 9.2, 9.3, 9.4, 9.5, 9.6, 9.9, 9.9, 9.9],
				[10, 11, 12, 13, 14, 15, 16, 17, 18, 19],
				[20, 22, 24, 26, 28, 30, 32, 34, 36, 38],
				[40, 42, 44, 46, 48, 50],
				[55, 60, 65, 70, 75, 80, 85, 90, 95],
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
						// changeSelectStack(player, false)
						bid_counter = parseFloat(e.target.textContent)

						const result = await sendAjax('/4bet/api/action_handler.php', {
							'hand_id': hand_id,
							'player_id': player.querySelector('.radio').value,
							"street": getBoardStatus(),
							'action_type': 'raise',
							'amount': bid_counter,
							'current_stack': player.querySelector('.stack_cards .stack').textContent
						})

						player.querySelector('.stack_cards .stack').textContent = result.new_stack
						console.log(result)
						showNotification('raise ' + bid_counter + ' bb')
						document.body.removeChild(overlay)
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
			// changeSelectStack(player, true)

			let player_stack = parseFloat(player.querySelector('.stack_cards .stack').textContent)
			bid_counter = player_stack > bid_counter ? player_stack : bid_counter
			console.log(bid_counter)

			const result = await sendAjax('/4bet/api/action_handler.php', {
				'hand_id': hand_id,
				'player_id': player.querySelector('.radio').value,
				"street": getBoardStatus(),
				'action_type': 'all-in',
				'amount': bid_counter,
				'current_stack': player.querySelector('.stack_cards .stack').textContent
			})

			player.querySelector('.stack_cards .stack').textContent = result.new_stack
			console.log(result)
			showNotification('all-in ' + bid_counter + ' bb')
		})
	})

// click gto
document
	.querySelector('.gto')
	.addEventListener('click', async function () {
		const result = await sendAjax('/4bet/api/get_hand_analysis.php', {
			'hand_id': hand_id,
			'current_street': getBoardStatus(),
			"hero_position": document.querySelector('.player .player_position').textContent,
			"stady": document.querySelector('.line_content .radio:checked').value
		})

		console.log(result)
		document.querySelector('.line_result').textContent = result.data
	})

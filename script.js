const positions = ['SB', 'BB', 'UTG', 'UTG+1', 'MP', 'HJ', 'CO', 'BTN']
let actions = []
let hand_id = null
let bid_counter = 1 // начало раздачи, по умолчанию ставка 1BB
let position_id = 999999
let position_ids = []
let start_position = 2 // начало раздачи, UTG

// получение рандомного ID
function generateRandomId(length = 6) {
	const chars = '123456789'
	let result = ''
	for (let i = 0; i < length; i++) {
		const random_index = Math.floor(Math.random() * chars.length);
		result += chars[random_index];
	}
	return result;
}

// присвоение ID позициям
document
	.querySelectorAll('[name="position"]')
	.forEach((position, key) => {
		position.dataset.id = generateRandomId()
		if (key === 0) {
			position.checked = true
			position.dataset.id = position_id
		}
		document.querySelectorAll('[name="board_stady"]')[0].checked = true
		position_ids.push(position.dataset.id)
	})

// статус улицы
const getBoardStatus = () => {
	return document.querySelector('[name="board_stady"]:checked').value
}

// обнуление bid_counter
const setStreetBidCounter = () => {
	if (getBoardStatus() === 'preflop') {
		bid_counter = 1
		start_position = 2
	} else {
		bid_counter = 0
		start_position = 0
	}
}

const addBidDescription = description => {
	const bid_description = document.createElement('div')
	bid_description.classList.add('bid_description')
	bid_description.textContent = description
	document.querySelector('.line_bids').appendChild(bid_description)
}

// отправка ajax
const sendAjax = async (url, params) => {
	const response = await fetch(url, {
		method: 'POST',
		headers: { 'Content-Type': 'application/json' },
		body: JSON.stringify(params)
	})

	const result = await response.json()
	return result
}

// удаление игрока с БД
document
	.querySelector('.select_kick')
	.addEventListener('change', async function () {
		if (this.value === 'kick') return false
		const result = await sendAjax(
			'/4bet/api/delete_player.php', {
				player_id: this.value
			})
		this.value = 'kick'
	})

// чистка БД
document
	.querySelector('.clear')
	.addEventListener('click', async function () {
		const result = await sendAjax(
			'/4bet/api/reset_database.php', {
				confirm: true
			})
	})

// выбор позиции
document
	.querySelectorAll('[name="position"]')
	.forEach(elem => {
		elem.addEventListener('change', async function () {
			let current_position = null
			actions = []
			actions.splice(actions.length, 0, ...positions)

			document
				.querySelectorAll('[name="position"]')
				.forEach((elem, key) => {
					if (elem.value === this.value) current_position = key
				})

			new_position_ids = [
				...position_ids.slice(-current_position),
				...position_ids.slice(0, -current_position)
			]

			for (let i = 0; i < 8; i++) document
				.querySelectorAll('[name="position"]')[i]
				.dataset.id = new_position_ids[i]

			// чистка борда
			document
				.querySelectorAll('.board_slot')
				.forEach(elem => {
					elem.textContent = ''
					elem.dataset.card = ''
					elem.classList.remove('red', 'blue', 'green', 'black')
				})

			document.querySelector('.line_result').textContent = ''
			document.querySelector('.line_bids').textContent = ''
			document.querySelectorAll('[name="board_stady"]')[0].checked = true

			const result = await sendAjax('/4bet/api/new_hand_mysql.php', {
				hero_position: document.querySelector('[name="position"][data-id="999999"]').value,
				hero_stack: document.querySelector('.stack').textContent,
				hero_cards: null
			})

			hand_id = result.hand_id
			setStreetBidCounter()
		})
	})

// выбор карт
document
	.querySelectorAll('.slots .slot')
	.forEach(elem => {
		elem.addEventListener('click', async function () {
			const classCard = (this.className).split(' ')[1]
			let cards = null

			const my_slot1 = document.querySelectorAll('.my_cards .board_slot')[0]
			const my_slot2 = document.querySelectorAll('.my_cards .board_slot')[1]
			const board_slot1 = document.querySelectorAll('.board_street .board_slot')[0]
			const board_slot2 = document.querySelectorAll('.board_street .board_slot')[1]
			const board_slot3 = document.querySelectorAll('.board_street .board_slot')[2]
			const board_slot4 = document.querySelectorAll('.board_street .board_slot')[3]
			const board_slot5 = document.querySelectorAll('.board_street .board_slot')[4]

			const line_result = document.querySelector('.line_result')
			const line_bids = document.querySelector('.line_bids')

			if (!my_slot1.textContent) {
				my_slot1.textContent = this.textContent
				my_slot1.classList.remove('check')
				my_slot1.classList.add(classCard)
				my_slot1.dataset.card = this.dataset.slot
				return false
			}

			if (!my_slot2.textContent) {
				my_slot2.textContent = this.textContent
				my_slot2.classList.remove('check')
				my_slot2.classList.add(classCard)
				my_slot2.dataset.card = this.dataset.slot

				cards = my_slot1.textContent + my_slot1.dataset.card
				cards += my_slot2.textContent + my_slot2.dataset.card

				await sendAjax('/4bet/api/update_hero_cards.php', {
					hand_id: hand_id,
					hero_cards: cards
				})

				return false
			}

			if (!board_slot1.textContent) {
				board_slot1.textContent = this.textContent
				board_slot1.classList.add(classCard)
				board_slot1.dataset.card = this.dataset.slot
				return false
			}

			if (!board_slot2.textContent) {
				board_slot2.textContent = this.textContent
				board_slot2.classList.add(classCard)
				board_slot2.dataset.card = this.dataset.slot
				return false
			}

			if (!board_slot3.textContent) {
				board_slot3.textContent = this.textContent
				board_slot3.classList.add(classCard)
				board_slot3.dataset.card = this.dataset.slot

				cards = board_slot1.textContent + board_slot1.dataset.card
				cards += board_slot2.textContent + board_slot2.dataset.card
				cards += board_slot3.textContent + board_slot3.dataset.card

				await sendAjax('/4bet/api/update_board.php', {
					hand_id: hand_id,
					board: cards
				});

				setStreetBidCounter()
				line_result.textContent = ''
				line_bids.textContent = ''
				document.querySelectorAll('[name="board_stady"]')[1].checked = true
				return false
			}

			if (!board_slot4.textContent) {
				board_slot4.textContent = this.textContent
				board_slot4.classList.add(classCard)
				board_slot4.dataset.card = this.dataset.slot

				cards = board_slot1.textContent + board_slot1.dataset.card
				cards += board_slot2.textContent + board_slot2.dataset.card
				cards += board_slot3.textContent + board_slot3.dataset.card
				cards += ' ' + board_slot4.textContent + board_slot4.dataset.card

				await sendAjax('/4bet/api/update_board.php', {
					hand_id: hand_id,
					board: cards
				});

				setStreetBidCounter()
				line_result.textContent = ''
				line_bids.textContent = ''
				document.querySelectorAll('[name="board_stady"]')[2].checked = true
				return false
			}

			if (!board_slot5.textContent) {
				board_slot5.textContent = this.textContent
				board_slot5.classList.add(classCard)
				board_slot5.dataset.card = this.dataset.slot

				cards = board_slot1.textContent + board_slot1.dataset.card
				cards += board_slot2.textContent + board_slot2.dataset.card
				cards += board_slot3.textContent + board_slot3.dataset.card
				cards += ' ' + board_slot4.textContent + board_slot4.dataset.card
				cards += ' ' + board_slot5.textContent + board_slot5.dataset.card

				await sendAjax('/4bet/api/update_board.php', {
					hand_id: hand_id,
					board: cards
				});

				setStreetBidCounter()
				line_result.textContent = ''
				line_bids.textContent = ''
				document.querySelectorAll('[name="board_stady"]')[3].checked = true
				return false
			}
		})
	})

// сброс раздачи
document
	.querySelector('.reset')
	.addEventListener('click', async () => {
		let current_index = null
		let next_index = null

		const radios = document.querySelectorAll('[name="position"]')
		actions = []
		actions.splice(actions.length, 0, ...positions)

		// смена позиции на 1
		current_index = Array
			.from(radios)
			.findIndex(radio => radio.checked)
		next_index = (current_index - 1 + radios.length) % radios.length
		radios[next_index].checked = true

		// сдвиг ID игроков на 1
		player_ids = []
		document
			.querySelectorAll('[name="position"]')
			.forEach(elem => {
				player_ids.push(elem.dataset.id)
			})

		const last_position = player_ids.shift()
		player_ids.push(last_position)
		for (let i = 0; i < 8; i++) {
			bb = document.querySelectorAll('[name="position"]')[i]
			bb.dataset.id = player_ids[i]
		}

		// чистка борда
		document
			.querySelectorAll('.board_slot')
			.forEach(elem => {
				elem.textContent = ''
				elem.dataset.card = ''
				elem.classList.remove('red', 'blue', 'green', 'black')
			})

		document.querySelector('.line_result').textContent = ''
		document.querySelector('.line_bids').textContent = ''
		document.querySelectorAll('[name="board_stady"]')[0].checked = true

		const result = await sendAjax('/4bet/api/new_hand_mysql.php', {
			hero_position: document.querySelector('[name="position"][data-id="999999"]').value,
			hero_stack: document.querySelector('.stack').textContent,
			hero_cards: null
		})

		hand_id = result.hand_id
		setStreetBidCounter()
	})

// обновление стека
document
	.querySelector('.stack')
	.addEventListener('click', () => {
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

		const bid_rows = [
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
			[121, 122, 123, 124, 125]
		]

		bid_rows.forEach(row => {
			const row_container = document.createElement('div')
			row_container.classList.add('bid-row')
			row.forEach(bid => { row_container.appendChild(addBidRaise(bid)) })
			popup.appendChild(row_container)
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
						player_id: position_id,
						new_stack: e.target.textContent
					})

					document.querySelector('.stack').textContent = result.updated_stack
					document.body.removeChild(overlay)
				})
			})
	})

// fold
document
	.querySelector('.fold')
	.addEventListener('click', async function () {

		let current_position = document.querySelectorAll('[name="position"]')[start_position]
		if (!current_position) {
			start_position = 0
			current_position = document.querySelectorAll('[name="position"]')[start_position]
		}

		console.log(start_position)
		addBidDescription(`${current_position.value}:fold`)



		start_position++

		// const player = this.closest('.player')
		// const position = player.querySelector('.player_position').textContent
		// console.log(bid_counter)
		//
		// player.querySelector('.player_buttons').style.display = 'none'
		//
		// const result = await sendAjax('/4bet/api/action_handler.php', {
		// 	'hand_id': hand_id,
		// 	'player_id': player.querySelector('.radio').value,
		// 	"street": getBoardStatus(),
		// 	'action_type': 'fold',
		// 	'amount': null,
		// 	'position': position
		// })
		//
		// addBidDescription(`${position}:fold`)
		//
		// // player.querySelector('.stack_cards .stack').textContent = result.new_stack
		// console.log(result)
		// showNotification('fold')
	})









// remove class list
// const removeClassCards = elem => {
// 	elem.classList.remove('blue')
// 	elem.classList.remove('red')
// 	elem.classList.remove('green')
// 	elem.classList.remove('black')
// 	elem.classList.remove('check')
// 	elem.textContent = ''
// }

// const addBidDescription = description => {
// 	const bid_description = document.createElement('div')
// 	bid_description.classList.add('bid_description')
// 	bid_description.textContent = description
// 	document.querySelector('.line_bids').appendChild(bid_description)
// }

// function showNotification(message) {
// 	const notification = document.getElementById('notification')
// 	let fadeOutTimeout
//
// 	clearTimeout(fadeOutTimeout);
// 	notification.style.transition = 'none'
// 	notification.textContent = message
// 	notification.style.opacity = '1'
// 	notification.style.display = 'block'
//
// 	setTimeout(() => {
// 		notification.style.transition = 'opacity 1s ease'
//
// 		fadeOutTimeout = setTimeout(() => {
// 			notification.style.opacity = '0'
//
// 			setTimeout(() => {
// 				notification.style.display = 'none'
// 			}, 500);
// 		}, 300)
// 	}, 10)
// }



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

// async function sendAjax(url, params) {
// 	const response = await fetch(url, {
// 		method: 'POST',
// 		headers: { 'Content-Type': 'application/json' },
// 		body: JSON.stringify(params)
// 	})
//
// 	const result = await response.json()
// 	return result
// }

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
// document
// 	.querySelectorAll('.player_radio .radio')
// 	.forEach(radio => {
// 		radio.addEventListener('change', async function () {
// 			let players = []
// 			document.querySelector('.line_result').textContent = ''
// 			document.querySelector('.line_bids').textContent = ''
//
// 			document
// 				.querySelectorAll('.board_street .board_slot')
// 				.forEach(elem => {
// 					elem.dataset.card = ''
// 				})
//
// 			// document
// 			// 	.querySelectorAll('.player')
// 			// 	.forEach(elem => {
// 			// 		if (elem.querySelectorAll('.slot')[0].textContent && elem.querySelectorAll('.slot')[1].textContent) {
// 			// 			let cards = ''
// 			// 			let player_id = parseInt(elem.querySelector('.radio').value)
// 			// 			if (player_id === 111) return
// 			//
// 			// 			cards += elem.querySelectorAll('.slot')[0].textContent
// 			// 			cards += elem.querySelectorAll('.slot')[0].dataset.card
// 			// 			cards += elem.querySelectorAll('.slot')[1].textContent
// 			// 			cards += elem.querySelectorAll('.slot')[1].dataset.card
// 			//
// 			// 			players.push({
// 			// 				player_id: elem.querySelector('.radio').value,
// 			// 				cards: cards
// 			// 			})
// 			// 		}
// 			// 	})
// 			//
// 			// const showdown = await sendAjax('/4bet/api/showdown_handler.php', {
// 			// 	'hand_id': hand_id,
// 			// 	'players': players
// 			// })
//
// 			// console.log(showdown)
//
// 			let radio_ID = parseInt(this.getAttribute('data-id'))
// 			hand_id = null
// 			bid_counter = getBoardStatus() === 'preflop' ? 1 : 0
// 			console.log('getBoardStatus', getBoardStatus())
// 			console.log('bid_counter', bid_counter)
//
// 			positions.forEach((value, key) => {
// 				const player = document.querySelector(`.player.player${radio_ID}`)
//
// 				player.querySelector('.player_position').textContent = value
// 				player.querySelector('.player_buttons').style.display = 'flex'
// 				// player
// 				// 	.querySelectorAll('.board_slot')
// 				// 	.forEach(elem => {
// 				// 		removeClassCards(elem)
// 				// 		elem.classList.add('check')
// 				// 	})
//
// 				radio_ID++
// 				if (radio_ID > 8) radio_ID = 1
// 			})
//
// 			document
// 				.querySelectorAll('.board_slot')
// 				.forEach(elem => {
// 					removeClassCards(elem)
// 				})
//
// 			document
// 				.querySelectorAll('.player')
// 				.forEach(elem => {
// 					elem.style.border = '1px solid #939393'
// 				})
//
// 			document
// 				.querySelector(`.player.player${radio_ID}`)
// 				.style.border = '3px solid #228B22'
//
// 			const result = await sendAjax('/4bet/api/new_hand_mysql.php', {
// 				hero_position: document.querySelector('.player1 .player_position').textContent,
// 				hero_stack: document.querySelector('.player1 .stack').textContent,
// 				hero_cards: null
// 			})
//
// 			hand_id = result.hand_id
// 			console.log(result)
// 		})
// 	})

// click cards
// document
// 	.querySelectorAll('.slots .slot')
// 	.forEach(elem => {
// 		elem.addEventListener('click', async function () {
// 			const classCard = (this.className).split(' ')[1]
// 			let cards = null
//
// 			if (!document.querySelectorAll('.my_cards .board_slot')[0].textContent) {
// 				document.querySelectorAll('.my_cards .board_slot')[0].textContent = this.textContent
// 				document.querySelectorAll('.my_cards .board_slot')[0].classList.remove('check')
// 				document.querySelectorAll('.my_cards .board_slot')[0].classList.add(classCard)
// 				document.querySelectorAll('.my_cards .board_slot')[0].dataset.card = this.dataset.slot
// 				return false
// 			}
//
// 			if (!document.querySelectorAll('.my_cards .board_slot')[1].textContent) {
// 				document.querySelectorAll('.my_cards .board_slot')[1].textContent = this.textContent
// 				document.querySelectorAll('.my_cards .board_slot')[1].classList.remove('check')
// 				document.querySelectorAll('.my_cards .board_slot')[1].classList.add(classCard)
// 				document.querySelectorAll('.my_cards .board_slot')[1].dataset.card = this.dataset.slot
//
// 				cards = document.querySelectorAll('.my_cards .board_slot')[0].textContent
// 				cards += document.querySelectorAll('.my_cards .board_slot')[0].dataset.card
// 				cards += document.querySelectorAll('.my_cards .board_slot')[1].textContent
// 				cards += document.querySelectorAll('.my_cards .board_slot')[1].dataset.card
//
// 				const result = await sendAjax('/4bet/api/update_hero_cards.php', {
// 					hand_id: hand_id,
// 					hero_cards: cards
// 				})
//
// 				console.log(result)
// 				return false
// 			}
//
// 			if (!document.querySelectorAll('.board_street .board_slot')[0].textContent) {
// 				document.querySelectorAll('.board_street .board_slot')[0].textContent = this.textContent
// 				document.querySelectorAll('.board_street .board_slot')[0].classList.add(classCard)
// 				document.querySelectorAll('.board_street .board_slot')[0].dataset.card = this.dataset.slot
// 				return false
// 			}
//
// 			if (!document.querySelectorAll('.board_street .board_slot')[1].textContent) {
// 				document.querySelectorAll('.board_street .board_slot')[1].textContent = this.textContent
// 				document.querySelectorAll('.board_street .board_slot')[1].classList.add(classCard)
// 				document.querySelectorAll('.board_street .board_slot')[1].dataset.card = this.dataset.slot
// 				return false
// 			}
//
// 			if (!document.querySelectorAll('.board_street .board_slot')[2].textContent) {
// 				document.querySelectorAll('.board_street .board_slot')[2].textContent = this.textContent
// 				document.querySelectorAll('.board_street .board_slot')[2].classList.add(classCard)
// 				document.querySelectorAll('.board_street .board_slot')[2].dataset.card = this.dataset.slot
//
// 				cards = document.querySelectorAll('.board_street .board_slot')[0].textContent
// 				cards += document.querySelectorAll('.board_street .board_slot')[0].dataset.card
// 				cards += document.querySelectorAll('.board_street .board_slot')[1].textContent
// 				cards += document.querySelectorAll('.board_street .board_slot')[1].dataset.card
// 				cards += document.querySelectorAll('.board_street .board_slot')[2].textContent
// 				cards += document.querySelectorAll('.board_street .board_slot')[2].dataset.card
//
// 				const result = await sendAjax('/4bet/api/update_board.php', {
// 					hand_id: hand_id,
// 					board: cards
// 				});
//
// 				bid_counter = getBoardStatus() === 'preflop' ? 1 : 0
// 				document.querySelector('.line_result').textContent = ''
// 				document.querySelector('.line_bids').textContent = ''
// 				console.log(result)
// 				return false
// 			}
//
// 			if (!document.querySelectorAll('.board_street .board_slot')[3].textContent) {
// 				document.querySelectorAll('.board_street .board_slot')[3].textContent = this.textContent
// 				document.querySelectorAll('.board_street .board_slot')[3].classList.add(classCard)
// 				document.querySelectorAll('.board_street .board_slot')[3].dataset.card = this.dataset.slot
//
// 				cards = document.querySelectorAll('.board_street .board_slot')[0].textContent
// 				cards += document.querySelectorAll('.board_street .board_slot')[0].dataset.card
// 				cards += document.querySelectorAll('.board_street .board_slot')[1].textContent
// 				cards += document.querySelectorAll('.board_street .board_slot')[1].dataset.card
// 				cards += document.querySelectorAll('.board_street .board_slot')[2].textContent
// 				cards += document.querySelectorAll('.board_street .board_slot')[2].dataset.card
// 				cards += ' ' + document.querySelectorAll('.board_street .board_slot')[3].textContent
// 				cards += document.querySelectorAll('.board_street .board_slot')[3].dataset.card
//
// 				const result = await sendAjax('/4bet/api/update_board.php', {
// 					hand_id: hand_id,
// 					board: cards
// 				});
//
// 				bid_counter = getBoardStatus() === 'preflop' ? 1 : 0
// 				document.querySelector('.line_result').textContent = ''
// 				document.querySelector('.line_bids').textContent = ''
// 				console.log(result)
// 				return false
// 			}
//
// 			if (!document.querySelectorAll('.board_street .board_slot')[4].textContent) {
// 				document.querySelectorAll('.board_street .board_slot')[4].textContent = this.textContent
// 				document.querySelectorAll('.board_street .board_slot')[4].classList.add(classCard)
// 				document.querySelectorAll('.board_street .board_slot')[4].dataset.card = this.dataset.slot
//
// 				cards = document.querySelectorAll('.board_street .board_slot')[0].textContent
// 				cards += document.querySelectorAll('.board_street .board_slot')[0].dataset.card
// 				cards += document.querySelectorAll('.board_street .board_slot')[1].textContent
// 				cards += document.querySelectorAll('.board_street .board_slot')[1].dataset.card
// 				cards += document.querySelectorAll('.board_street .board_slot')[2].textContent
// 				cards += document.querySelectorAll('.board_street .board_slot')[2].dataset.card
// 				cards += ' ' + document.querySelectorAll('.board_street .board_slot')[3].textContent
// 				cards += document.querySelectorAll('.board_street .board_slot')[3].dataset.card
// 				cards += ' ' + document.querySelectorAll('.board_street .board_slot')[4].textContent
// 				cards += document.querySelectorAll('.board_street .board_slot')[4].dataset.card
//
// 				const result = await sendAjax('/4bet/api/update_board.php', {
// 					hand_id: hand_id,
// 					board: cards
// 				});
//
// 				bid_counter = getBoardStatus() === 'preflop' ? 1 : 0
// 				document.querySelector('.line_result').textContent = ''
// 				document.querySelector('.line_bids').textContent = ''
// 				console.log(result)
// 				return false
// 			}
//
// 			// let start_position = null
// 			// document
// 			// 	.querySelectorAll('.player')
// 			// 	.forEach((elem, key) => {
// 			// 		console.log(elem)
// 			// 		key++
// 			// 		if (elem.querySelector('.player_position').textContent === 'BTN') {
// 			// 			start_position = key
// 			// 		}
// 			// 	})
// 			//
// 			// for (let i = 0; i < 8; i++) {
// 			// 	start_position++
// 			// 	if (start_position > 8) start_position = 1
// 			// 	player = document.querySelector(`.player${start_position}`)
// 			// 	if (player.querySelector('.player_buttons').style.display === 'none') continue
// 			//
// 			// 	if (!player.querySelectorAll('.slot')[0].textContent) {
// 			// 		player.querySelectorAll('.slot')[0].textContent = this.textContent
// 			// 		player.querySelectorAll('.slot')[0].classList.remove('check')
// 			// 		player.querySelectorAll('.slot')[0].classList.add(classCard)
// 			// 		player.querySelectorAll('.slot')[0].dataset.card = this.dataset.slot
// 			// 		return false
// 			// 	}
// 			//
// 			// 	if (!player.querySelectorAll('.slot')[1].textContent) {
// 			// 		player.querySelectorAll('.slot')[1].textContent = this.textContent
// 			// 		player.querySelectorAll('.slot')[1].classList.remove('check')
// 			// 		player.querySelectorAll('.slot')[1].classList.add(classCard)
// 			// 		player.querySelectorAll('.slot')[1].dataset.card = this.dataset.slot
// 			// 		return false
// 			// 	}
// 			// }
// 		})
// 	})

// click kick




// // click fold
// document
// 	.querySelectorAll('.player .fold')
// 	.forEach(elem => {
// 		elem.addEventListener('click', async function () {
// 			const player = this.closest('.player')
// 			const position = player.querySelector('.player_position').textContent
// 			console.log(bid_counter)
//
// 			player.querySelector('.player_buttons').style.display = 'none'
//
// 			const result = await sendAjax('/4bet/api/action_handler.php', {
// 				'hand_id': hand_id,
// 				'player_id': player.querySelector('.radio').value,
// 				"street": getBoardStatus(),
// 				'action_type': 'fold',
// 				'amount': null,
// 				'position': position,
// 				// 'current_stack': player.querySelector('.stack_cards .stack').textContent
// 			})
//
// 			addBidDescription(`${position}:fold`)
//
// 			// player.querySelector('.stack_cards .stack').textContent = result.new_stack
// 			console.log(result)
// 			showNotification('fold')
// 		})
// 	})

// click call
document
	.querySelectorAll('.player .call')
	.forEach(elem => {
		elem.addEventListener('click', async function () {
			const player = this.closest('.player')
			const position = player.querySelector('.player_position').textContent
			// changeSelectStack(player, false)
			console.log(bid_counter)

			const result = await sendAjax('/4bet/api/action_handler.php', {
				'hand_id': hand_id,
				'player_id': player.querySelector('.radio').value,
				"street": getBoardStatus(),
				'action_type': 'call',
				'amount': bid_counter,
				'position': player.querySelector('.player_position').textContent,
				// 'current_stack': player.querySelector('.stack_cards .stack').textContent
			})

			addBidDescription(`${position}:coll ${bid_counter} bb`)

			// player.querySelector('.stack_cards .stack').textContent = result.new_stack
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
			const position = player.querySelector('.player_position').textContent
			console.log(bid_counter)

			const result = await sendAjax('/4bet/api/action_handler.php', {
				'hand_id': hand_id,
				'player_id': player.querySelector('.radio').value,
				"street": getBoardStatus(),
				'action_type': 'check',
				'amount': null,
				'position': player.querySelector('.player_position').textContent,
				// 'current_stack': player.querySelector('.stack_cards .stack').textContent
			})

			addBidDescription(`${position}:check`)

			// player.querySelector('.stack_cards .stack').textContent = result.new_stack
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
			const position = player.querySelector('.player_position').textContent

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
				[55, 60, 65, 70, 75, 80, 85, 90, 95]
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
							'position': player.querySelector('.player_position').textContent,
							// 'current_stack': player.querySelector('.stack_cards .stack').textContent
						})

						addBidDescription(`${position}:raise ${bid_counter} bb`)

						// player.querySelector('.stack_cards .stack').textContent = result.new_stack
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
			const position = player.querySelector('.player_position').textContent
			// changeSelectStack(player, true)

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
				[121, 122, 123, 124, 125]
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
						let allin = parseFloat(e.target.textContent)
						if (allin > bid_counter) bid_counter = allin

						const result = await sendAjax('/4bet/api/action_handler.php', {
							'hand_id': hand_id,
							'player_id': player.querySelector('.radio').value,
							"street": getBoardStatus(),
							'action_type': 'all-in',
							'amount': allin,
							'position': player.querySelector('.player_position').textContent,
							// 'current_stack': player.querySelector('.stack_cards .stack').textContent
						})

						addBidDescription(`${position}:all-in ${bid_counter} bb`)

						// player.querySelector('.stack_cards .stack').textContent = result.new_stack
						console.log(result)
						showNotification('all-in ' + bid_counter + ' bb')
						document.body.removeChild(overlay)
					})
				})

			// let player_stack = parseFloat(player.querySelector('.stack_cards .stack').textContent)
			// bid_counter = player_stack > bid_counter ? player_stack : bid_counter
			// console.log(bid_counter)
			//
			// const result = await sendAjax('/4bet/api/action_handler.php', {
			// 	'hand_id': hand_id,
			// 	'player_id': player.querySelector('.radio').value,
			// 	"street": getBoardStatus(),
			// 	'action_type': 'all-in',
			// 	'amount': bid_counter,
			// 	'position': player.querySelector('.player_position').textContent,
			// 	'current_stack': player.querySelector('.stack_cards .stack').textContent
			// })
			//
			// // player.querySelector('.stack_cards .stack').textContent = result.new_stack
			// console.log(result)
			// showNotification('all-in ' + bid_counter + ' bb')
		})
	})

// click gto
document
	.querySelector('.gto')
	.addEventListener('click', async function () {
		document.querySelector('.line_result').textContent = ''

		const result = await sendAjax('/4bet/api/get_hand_analysis.php', {
			'hand_id': hand_id,
			'current_street': getBoardStatus(),
			"hero_position": document.querySelector('.player .player_position').textContent,
			"stady": document.querySelector('.line_content .radio:checked').value
		})

		console.log(result)
		document.querySelector('.line_result').textContent = result.data
	})

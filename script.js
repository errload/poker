const positions = ['SB', 'BB', 'UTG', 'UTG+1', 'MP', 'HJ', 'CO', 'BTN']
let actions = []
let hand_id = null
let bid_counter = 1 // начало раздачи, по умолчанию ставка 1BB
let position_id = 999999
let position_ids = []
let start_position = 2 // начало раздачи, UTG
let cards_showdown = []

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
		const random_id = generateRandomId()
		position.dataset.id = random_id
		position.closest('.position_wrapper').querySelector('.position_del_button').dataset.id = random_id
		if (key === 0) {
			position.dataset.id = position_id
			position.closest('.position_wrapper').querySelector('.position_del_button').dataset.id = position_id
			position.checked = true
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

// отображение ставки
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
	.querySelectorAll('.position_del_button')
	.forEach(button => {
		button.addEventListener('click', async function () {
			await sendAjax(
			'/4bet/api/delete_player.php', {
				player_id: this.dataset.id
			})

			const random_id = generateRandomId()
			this.dataset.id = random_id
			this
				.closest('.position_wrapper')
				.querySelector('[name="position"]')
				.dataset.id = random_id
		})
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

// отображение игрока текущего действия
const showCurrentPlayer = () => {
	getCurrentPosition()

	document
		.querySelectorAll('.position_wrapper')
		.forEach(elem => {
			elem.style.border = 'none'
		})

	document
		.querySelectorAll('.position_wrapper')[start_position]
		.style.border = '1px solid #217c21'
}

// выбор позиции
document
	.querySelectorAll('[name="position"]')
	.forEach(elem => {
		elem.addEventListener('change', async function () {
			let current_position = null

			// сохранение карт оставшихся игроков
			let players = []
			document
				.querySelectorAll('[name="position"]')
				.forEach(elem => {
					if (elem.dataset.action === 'inactive') return
					if (!cards_showdown.length) return
					players.push({
						player_id: elem.dataset.id,
						cards: cards_showdown.shift()
					})
				})

			await sendAjax('/4bet/api/showdown_handler.php', {
				hand_id: hand_id,
				players: players
			})

			const radios = document.querySelectorAll('[name="position"]')
			current_position = Array
				.from(radios)
				.findIndex(radio => radio.checked)

			actions = []
			actions.splice(actions.length, 0, ...positions)

			new_position_ids = [
				...position_ids.slice(-current_position),
				...position_ids.slice(0, -current_position)
			]
			console.log(new_position_ids)

			for (let i = 0; i < 8; i++) {
				change_position = document.querySelectorAll('[name="position"]')[i]
				change_position.dataset.id = new_position_ids[i]
				change_position
					.closest('.position_wrapper')
					.querySelector('.position_del_button')
					.dataset.id = new_position_ids[i]
			}

			document
				.querySelectorAll('[name="position"]')
				.forEach((elem, key) => {
					if (elem.value === this.value) current_position = key
					elem.dataset.action = 'active'
					elem.nextElementSibling.style.color = '#000000'
				})

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
			showCurrentPlayer()
		})
	})

// сброс раздачи
document
	.querySelector('.reset')
	.addEventListener('click', async () => {
		if (!document.querySelectorAll('[name="position"]:checked').length) return false

		let players = []
		document
			.querySelectorAll('[name="position"]')
			.forEach(elem => {
				if (elem.dataset.action === 'inactive') return
				if (!cards_showdown.length) return
				players.push({
					player_id: elem.dataset.id,
					cards: cards_showdown.shift()
				})
			})

		await sendAjax('/4bet/api/showdown_handler.php', {
			hand_id: hand_id,
			players: players
		})

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
			.forEach((elem, key) => {
				player_ids.push(elem.dataset.id)
				elem.dataset.action = 'active'
				elem.nextElementSibling.style.color = '#000000'
			})

		const last_position = player_ids.shift()
		player_ids.push(last_position)

		for (let i = 0; i < 8; i++) {
			change_position = document.querySelectorAll('[name="position"]')[i]
			change_position.dataset.id = player_ids[i]
			change_position
				.closest('.position_wrapper')
				.querySelector('.position_del_button')
				.dataset.id = player_ids[i]
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
		showCurrentPlayer()
	})

// выбор карт
document
	.querySelectorAll('.slots .slot')
	.forEach(elem => {
		elem.addEventListener('click', async function () {
			if (!document.querySelectorAll('[name="position"]:checked').length) return false

			const classCard = (this.className).split(' ')[1]
			let cards = null

			if (getBoardStatus() === 'showdown') {
				const showdown_slot1 = document.querySelectorAll('.board_showdown .board_slot')[0]
				const showdown_slot2 = document.querySelectorAll('.board_showdown .board_slot')[1]
				const showdown_slot3 = document.querySelectorAll('.board_showdown .board_slot')[2]
				const showdown_slot4 = document.querySelectorAll('.board_showdown .board_slot')[3]
				const showdown_slot5 = document.querySelectorAll('.board_showdown .board_slot')[4]
				const showdown_slot6 = document.querySelectorAll('.board_showdown .board_slot')[5]
				const showdown_slot7 = document.querySelectorAll('.board_showdown .board_slot')[6]
				const showdown_slot8 = document.querySelectorAll('.board_showdown .board_slot')[7]

				if (!showdown_slot1.textContent) {
					showdown_slot1.textContent = this.textContent
					showdown_slot1.classList.add(classCard)
					showdown_slot1.dataset.card = this.dataset.slot
					return false
				}

				if (!showdown_slot2.textContent) {
					showdown_slot2.textContent = this.textContent
					showdown_slot2.classList.add(classCard)
					showdown_slot2.dataset.card = this.dataset.slot

					cards = showdown_slot1.textContent + showdown_slot1.dataset.card
					cards += showdown_slot2.textContent + showdown_slot2.dataset.card
					cards_showdown.push(cards)
					return false
				}

				if (!showdown_slot3.textContent) {
					showdown_slot3.textContent = this.textContent
					showdown_slot3.classList.add(classCard)
					showdown_slot3.dataset.card = this.dataset.slot
					return false
				}

				if (!showdown_slot4.textContent) {
					showdown_slot4.textContent = this.textContent
					showdown_slot4.classList.add(classCard)
					showdown_slot4.dataset.card = this.dataset.slot

					cards = showdown_slot3.textContent + showdown_slot3.dataset.card
					cards += showdown_slot4.textContent + showdown_slot4.dataset.card
					cards_showdown.push(cards)
					return false
				}

				if (!showdown_slot5.textContent) {
					showdown_slot5.textContent = this.textContent
					showdown_slot5.classList.add(classCard)
					showdown_slot5.dataset.card = this.dataset.slot
					return false
				}

				if (!showdown_slot6.textContent) {
					showdown_slot6.textContent = this.textContent
					showdown_slot6.classList.add(classCard)
					showdown_slot6.dataset.card = this.dataset.slot

					cards = showdown_slot5.textContent + showdown_slot5.dataset.card
					cards += showdown_slot6.textContent + showdown_slot6.dataset.card
					cards_showdown.push(cards)
					return false
				}

				if (!showdown_slot7.textContent) {
					showdown_slot7.textContent = this.textContent
					showdown_slot7.classList.add(classCard)
					showdown_slot7.dataset.card = this.dataset.slot
					return false
				}

				if (!showdown_slot8.textContent) {
					showdown_slot8.textContent = this.textContent
					showdown_slot8.classList.add(classCard)
					showdown_slot8.dataset.card = this.dataset.slot

					cards = showdown_slot7.textContent + showdown_slot7.dataset.card
					cards += showdown_slot8.textContent + showdown_slot8.dataset.card
					cards_showdown.push(cards)
					return false
				}
			} else {
				const my_slot1 = document.querySelectorAll('.my_cards .board_slot')[0]
				const my_slot2 = document.querySelectorAll('.my_cards .board_slot')[1]
				const board_slot1 = document.querySelectorAll('.board_street .board_slot')[0]
				const board_slot2 = document.querySelectorAll('.board_street .board_slot')[1]
				const board_slot3 = document.querySelectorAll('.board_street .board_slot')[2]
				const board_slot4 = document.querySelectorAll('.board_street .board_slot')[3]
				const board_slot5 = document.querySelectorAll('.board_street .board_slot')[4]

				if (!my_slot1.textContent) {
					my_slot1.textContent = this.textContent
					my_slot1.classList.add(classCard)
					my_slot1.dataset.card = this.dataset.slot
					return false
				}

				if (!my_slot2.textContent) {
					my_slot2.textContent = this.textContent
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

					document.querySelectorAll('[name="board_stady"]')[1].click()
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

					document.querySelectorAll('[name="board_stady"]')[2].click()
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

					document.querySelectorAll('[name="board_stady"]')[3].click()
					return false
				}
			}
		})
	})

// обновление стека
document
	.querySelector('.stack')
	.addEventListener('click', () => {
		if (!document.querySelectorAll('[name="position"]:checked').length) return false

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

// смена улицы
document
	.querySelectorAll('[name="board_stady"]')
	.forEach(elem => {
		elem.addEventListener('change', function () {
			document.querySelector('.line_result').textContent = ''
			document.querySelector('.line_bids').textContent = ''
			setStreetBidCounter()
			showCurrentPlayer()
		})
	})

// поиск следующей позиции для ставки
const getCurrentPosition = () => {
	const elements = document.querySelectorAll('[name="position"]')
	const current_element = document.querySelectorAll('[name="position"]')[start_position]
	let found_element = null

	if (current_element) {
		const start_index = Array.from(elements).indexOf(current_element)

		if (start_index !== -1) {
			for (let i = 0; i < elements.length; i++) {
				const current_index = (start_index + i) % elements.length
				if (elements[current_index].dataset.action === 'active') {
					found_element = elements[current_index]
					start_position = current_index
					break
				}
			}
		}
	}

	return found_element
}

// fold
document
	.querySelector('.fold')
	.addEventListener('click', async function () {
		if (getBoardStatus() === 'showdown') return false
		if (!document.querySelectorAll('[name="position"]:checked').length) return false

		const current_element = getCurrentPosition()
		if (!current_element) return false
		current_element.dataset.action = 'inactive'
		current_element.nextElementSibling.style.color = '#e7e7e7'

		await sendAjax('/4bet/api/action_handler.php', {
			'hand_id': hand_id,
			'player_id': current_element.dataset.id,
			"street": getBoardStatus(),
			'action_type': 'fold',
			'amount': null,
			'position': current_element.value
		})

		addBidDescription(`${current_element.value}:fold`)
		start_position++
		if (start_position > 7) start_position = 0
		showCurrentPlayer()
	})

// call
document
	.querySelector('.call')
	.addEventListener('click', async function () {
		if (getBoardStatus() === 'showdown') return false
		if (!document.querySelectorAll('[name="position"]:checked').length) return false

		const current_element = getCurrentPosition()
		if (!current_element) return false

		await sendAjax('/4bet/api/action_handler.php', {
			'hand_id': hand_id,
			'player_id': current_element.dataset.id,
			"street": getBoardStatus(),
			'action_type': 'call',
			'amount': bid_counter,
			'position': current_element.value
		})

		addBidDescription(`${current_element.value}:call ${bid_counter} bb`)
		start_position++
		if (start_position > 7) start_position = 0
		showCurrentPlayer()
	})

// check
document
	.querySelector('.check')
	.addEventListener('click', async function () {
		if (getBoardStatus() === 'showdown') return false
		if (!document.querySelectorAll('[name="position"]:checked').length) return false

		const current_element = getCurrentPosition()
		if (!current_element) return false

		await sendAjax('/4bet/api/action_handler.php', {
			'hand_id': hand_id,
			'player_id': current_element.dataset.id,
			"street": getBoardStatus(),
			'action_type': 'check',
			'amount': null,
			'position': current_element.value
		})

		addBidDescription(`${current_element.value}:check`)
		start_position++
		if (start_position > 7) start_position = 0
		showCurrentPlayer()
	})

// raise
document
	.querySelector('.raise')
	.addEventListener('click', async function () {
		if (getBoardStatus() === 'showdown') return false
		if (!document.querySelectorAll('[name="position"]:checked').length) return false

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
			[1.1, 1.2, 1.3, 1.4, 1.5, 1.6, 1.7, 1.8, 1.9, 2],
			[2.1, 2.2, 2.3, 2.4, 2.5, 2.6, 2.7, 2.8, 2.9, 3],
			[3.1, 3.2, 3.3, 3.4, 3.5, 3.6, 3.7, 3.8, 3.9, 4],
			[4.1, 4.2, 4.3, 4.4, 4.5, 4.6, 4.7, 4.8, 4.9, 5],
			[5.1, 5.2, 5.3, 5.4, 5.5, 5.6, 5.7, 5.8, 5.9, 6],
			[6.1, 6.2, 6.3, 6.4, 6.5, 6.6, 6.7, 6.8, 6.9, 7],
			[7.1, 7.2, 7.3, 7.4, 7.5, 7.6, 7.7, 7.8, 7.9, 8],
			[8.1, 8.2, 8.3, 8.4, 8.5, 8.6, 8.8, 8.8, 8.9, 9],
			[9.1, 9.2, 9.3, 9.4, 9.5, 9.6, 9.9, 9.9, 9.9, 10],
			[11, 12, 13, 14, 15, 16, 17, 18, 19, 20],
			[22, 24, 26, 28, 30, 32, 34, 36, 38, 40],
			[42, 44, 46, 48, 50],
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
					bid_counter = parseFloat(e.target.textContent)

					const current_element = getCurrentPosition()
					if (!current_element) return false

					await sendAjax('/4bet/api/action_handler.php', {
						'hand_id': hand_id,
						'player_id': current_element.dataset.id,
						"street": getBoardStatus(),
						'action_type': 'raise',
						'amount': bid_counter,
						'position': current_element.value
					})

					addBidDescription(`${current_element.value}:raise ${bid_counter} bb`)
					start_position++
					if (start_position > 7) start_position = 0
					showCurrentPlayer()
					document.body.removeChild(overlay)
				})
			})
	})

// all-in
document
	.querySelector('.all-in')
	.addEventListener('click', async function () {
		if (getBoardStatus() === 'showdown') return false
		if (!document.querySelectorAll('[name="position"]:checked').length) return false

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
			[1.1, 1.2, 1.3, 1.4, 1.5, 1.6, 1.7, 1.8, 1.9, 2],
			[2.1, 2.2, 2.3, 2.4, 2.5, 2.6, 2.7, 2.8, 2.9, 3],
			[3.1, 3.2, 3.3, 3.4, 3.5, 3.6, 3.7, 3.8, 3.9, 4],
			[4.1, 4.2, 4.3, 4.4, 4.5, 4.6, 4.7, 4.8, 4.9, 5],
			[5.1, 5.2, 5.3, 5.4, 5.5, 5.6, 5.7, 5.8, 5.9, 6],
			[6.1, 6.2, 6.3, 6.4, 6.5, 6.6, 6.7, 6.8, 6.9, 7],
			[7.1, 7.2, 7.3, 7.4, 7.5, 7.6, 7.7, 7.8, 7.9, 8],
			[8.1, 8.2, 8.3, 8.4, 8.5, 8.6, 8.8, 8.8, 8.9, 9],
			[9.1, 9.2, 9.3, 9.4, 9.5, 9.6, 9.9, 9.9, 9.9, 10],
			[11, 12, 13, 14, 15, 16, 17, 18, 19, 20],
			[22, 24, 26, 28, 30, 32, 34, 36, 38, 40],
			[42, 44, 46, 48, 50],
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
					bid_counter = parseFloat(e.target.textContent)

					const current_element = getCurrentPosition()
					if (!current_element) return false
					current_element.dataset.action = 'all-in'
					current_element.nextElementSibling.style.color = '#e7e7e7'

					await sendAjax('/4bet/api/action_handler.php', {
						'hand_id': hand_id,
						'player_id': current_element.dataset.id,
						"street": getBoardStatus(),
						'action_type': 'all-in',
						'amount': bid_counter,
						'position': current_element.value
					})

					addBidDescription(`${current_element.value}:all-in ${bid_counter} bb`)
					start_position++
					if (start_position > 7) start_position = 0
					showCurrentPlayer()
					document.body.removeChild(overlay)
				})
			})
	})

// gto
document
	.querySelector('.gto')
	.addEventListener('click', async function () {
		if (!document.querySelectorAll('[name="position"]:checked').length) return false
		document.querySelector('.line_result').textContent = ''

		const result = await sendAjax('/4bet/api/get_hand_analysis.php', {
			hand_id: hand_id,
			current_street: getBoardStatus(),
			hero_position: document.querySelector('[name="position"][data-id="999999"]').value,
			stady: document.querySelector('.line_content .radio:checked').value
		})

		document.querySelector('.line_result').textContent = result.data
	})

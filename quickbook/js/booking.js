/**
 * Quick Booking: public booking page.
 * Plain JavaScript (no build step): date & time -> details -> review -> booked.
 */
(function () {
	'use strict'

	const APP = 'quickbook'

	const t = (text, vars) => window.t(APP, text, vars, undefined, { escape: false })

	function loadState(key) {
		const input = document.getElementById(`initial-state-${APP}-${key}`)
		if (!input) {
			throw new Error(`Missing initial state ${key}`)
		}
		const binary = atob(input.value)
		const bytes = Uint8Array.from(binary, (c) => c.charCodeAt(0))
		return JSON.parse(new TextDecoder().decode(bytes))
	}

	/** Small DOM helper: h('button', { class: 'x', onclick: fn }, 'text', child) */
	function h(tag, attrs, ...children) {
		const node = document.createElement(tag)
		for (const [key, value] of Object.entries(attrs || {})) {
			if (value === false || value === null || value === undefined) {
				continue
			}
			if (key.startsWith('on') && typeof value === 'function') {
				node.addEventListener(key.slice(2), value)
			} else if (key === 'class') {
				node.className = value
			} else if (value === true) {
				node.setAttribute(key, '')
			} else {
				node.setAttribute(key, String(value))
			}
		}
		for (const child of children.flat(Infinity)) {
			if (child === null || child === undefined || child === false) {
				continue
			}
			node.append(child instanceof Node ? child : document.createTextNode(String(child)))
		}
		return node
	}

	// ---------------------------------------------------------------- setup

	const config = loadState('booking')
	const root = document.getElementById('quickbook-app')
	const visitorTz = Intl.DateTimeFormat().resolvedOptions().timeZone || 'UTC'
	const locale = (document.documentElement.dataset.locale || document.documentElement.lang || navigator.language || 'en').replace('_', '-')
	const sameTimezone = visitorTz === config.organizerTimezone
	const WEEKDAY_CODES = ['SU', 'MO', 'TU', 'WE', 'TH', 'FR', 'SA']

	const fmt = {
		longDate: new Intl.DateTimeFormat(locale, { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' }),
		month: new Intl.DateTimeFormat(locale, { month: 'long', year: 'numeric' }),
		weekdayShort: new Intl.DateTimeFormat(locale, { weekday: 'short' }),
		weekdayLong: new Intl.DateTimeFormat(locale, { weekday: 'long' }),
		time: new Intl.DateTimeFormat(locale, { hour: 'numeric', minute: '2-digit' }),
	}

	const earliestDate = startOfDay(new Date(config.earliestStart * 1000))
	const latestDate = config.latestEnd ? startOfDay(new Date(config.latestEnd * 1000)) : null

	const state = {
		step: 'date',
		month: new Date(earliestDate.getFullYear(), earliestDate.getMonth(), 1),
		date: null, // 'YYYY-MM-DD'
		slots: [],
		slotsLoading: false,
		slotsError: '',
		slot: null,
		form: {
			displayName: config.visitor?.displayName || '',
			email: config.visitor?.email || '',
			description: '',
		},
		submitting: false,
		error: '',
	}

	// ---------------------------------------------------------------- dates

	function startOfDay(date) {
		return new Date(date.getFullYear(), date.getMonth(), date.getDate())
	}

	function isoDate(date) {
		const pad = (n) => String(n).padStart(2, '0')
		return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}`
	}

	function parseIsoDate(value) {
		const [y, m, d] = value.split('-').map(Number)
		return new Date(y, m - 1, d)
	}

	function isBookableDay(date) {
		if (date < earliestDate || (latestDate && date > latestDate)) {
			return false
		}
		// Weekday rules are in the organizer's timezone; only trust them when it matches the visitor's
		if (sameTimezone && config.weekdays.length > 0) {
			return config.weekdays.includes(WEEKDAY_CODES[date.getDay()])
		}
		return true
	}

	function formatRange(slot) {
		return `${fmt.time.format(new Date(slot.start * 1000))} – ${fmt.time.format(new Date(slot.end * 1000))}`
	}

	function formatDuration(seconds) {
		const minutes = Math.round(seconds / 60)
		if (minutes < 60) {
			return t('{minutes} min', { minutes })
		}
		const hours = Math.floor(minutes / 60)
		const rest = minutes % 60
		return rest === 0 ? t('{hours} h', { hours }) : t('{hours} h {minutes} min', { hours, minutes: rest })
	}

	// ---------------------------------------------------------------- network

	async function fetchSlots(date) {
		state.date = date
		state.slot = null
		state.slots = []
		state.slotsError = ''
		state.slotsLoading = true
		render()
		try {
			const url = `${config.slotsUrl}?date=${encodeURIComponent(date)}&timeZone=${encodeURIComponent(visitorTz)}`
			const response = await fetch(url, { headers: { Accept: 'application/json' }, credentials: 'same-origin' })
			const body = await response.json().catch(() => ({}))
			if (state.date !== date) {
				return // the visitor picked another day meanwhile
			}
			if (response.status === 503) {
				return showUnavailable(body.fallbackUrl)
			}
			if (!response.ok) {
				throw new Error(response.status === 429 ? t('Too many requests. Please wait a moment and try again.') : t('Could not load the free times.'))
			}
			state.slots = body.slots || []
		} catch (e) {
			state.slotsError = e.message || t('Could not load the free times.')
		} finally {
			if (state.date === date) {
				state.slotsLoading = false
				render()
			}
		}
	}

	async function submitBooking() {
		state.submitting = true
		state.error = ''
		render()
		try {
			const response = await fetch(config.bookUrl, {
				method: 'POST',
				credentials: 'same-origin',
				headers: {
					'Content-Type': 'application/json',
					Accept: 'application/json',
					requesttoken: document.head.dataset.requesttoken || '',
				},
				body: JSON.stringify({
					start: state.slot.start,
					end: state.slot.end,
					displayName: state.form.displayName,
					email: state.form.email,
					description: state.form.description,
					timeZone: visitorTz,
				}),
			})
			const body = await response.json().catch(() => ({}))

			if (response.status === 201) {
				state.step = 'done'
				return
			}
			if (response.status === 409) {
				state.step = 'date'
				state.error = t('Sorry, this time was just taken. Please choose another time.')
				fetchSlots(state.date)
				return
			}
			if (response.status === 422) {
				state.step = ['displayName', 'email', 'description'].includes(body.field) ? 'details' : 'date'
				state.error = body.message || t('Please check your input.')
				return
			}
			if (response.status === 429) {
				state.error = body.error === 'email_limit'
					? t('This email address already has several bookings. Please try again tomorrow or contact the organizer.')
					: t('You made too many bookings in a short time. Please try again later.')
				return
			}
			if (response.status === 503) {
				showUnavailable(body.fallbackUrl)
				return
			}
			state.error = t('Something went wrong while booking. Please try again.')
		} catch (e) {
			state.error = t('Could not reach the server. Please check your connection and try again.')
		} finally {
			state.submitting = false
			if (state.step !== 'unavailable') {
				render(true)
			}
		}
	}

	function showUnavailable(fallbackUrl) {
		state.step = 'unavailable'
		state.fallbackUrl = fallbackUrl || config.fallbackUrl
		render(true)
	}

	// ---------------------------------------------------------------- views

	function goTo(step) {
		state.step = step
		state.error = ''
		render(true)
	}

	function header() {
		const facts = [
			h('li', {}, h('span', { class: 'quickbook__icon quickbook__icon--clock', 'aria-hidden': 'true' }), formatDuration(config.lengthSeconds)),
			config.location ? h('li', {}, h('span', { class: 'quickbook__icon quickbook__icon--location', 'aria-hidden': 'true' }), config.location) : null,
		]
		return h('header', { class: 'quickbook__header' },
			h('h2', { class: 'quickbook__title' }, config.name),
			h('p', { class: 'quickbook__organizer' }, t('with {name}', { name: config.organizer })),
			h('ul', { class: 'quickbook__facts' }, facts),
			config.description ? h('p', { class: 'quickbook__description' }, config.description) : null,
		)
	}

	function steps() {
		const labels = [['date', t('Date & time')], ['details', t('Your details')], ['review', t('Confirm')]]
		const order = ['date', 'details', 'review', 'done']
		const current = order.indexOf(state.step)
		return h('ol', { class: 'quickbook__steps', 'aria-label': t('Booking steps') },
			labels.map(([key, label], index) => h('li', {
				class: 'quickbook__step' + (index === current ? ' quickbook__step--current' : '') + (index < current ? ' quickbook__step--done' : ''),
				'aria-current': index === current ? 'step' : false,
			}, h('span', { class: 'quickbook__step-number' }, String(index + 1)), label)),
		)
	}

	function errorBox() {
		return state.error ? h('p', { class: 'quickbook__error', role: 'alert' }, state.error) : null
	}

	function viewDate() {
		const month = state.month
		const firstOfMonth = new Date(month.getFullYear(), month.getMonth(), 1)
		const daysInMonth = new Date(month.getFullYear(), month.getMonth() + 1, 0).getDate()
		const leadingBlanks = (firstOfMonth.getDay() + 6) % 7 // weeks start on Monday
		const today = startOfDay(new Date())

		const prevMonth = new Date(month.getFullYear(), month.getMonth() - 1, 1)
		const nextMonth = new Date(month.getFullYear(), month.getMonth() + 1, 1)
		const canPrev = prevMonth >= new Date(earliestDate.getFullYear(), earliestDate.getMonth(), 1)
		const canNext = !latestDate || nextMonth <= latestDate

		const weekdayHeaders = []
		for (let i = 0; i < 7; i++) {
			const day = new Date(2024, 0, 1 + i) // 1 Jan 2024 is a Monday
			weekdayHeaders.push(h('span', { class: 'quickbook__weekday', title: fmt.weekdayLong.format(day), 'aria-hidden': 'true' }, fmt.weekdayShort.format(day)))
		}

		const cells = []
		for (let i = 0; i < leadingBlanks; i++) {
			cells.push(h('span', { class: 'quickbook__day quickbook__day--blank', 'aria-hidden': 'true' }))
		}
		for (let d = 1; d <= daysInMonth; d++) {
			const date = new Date(month.getFullYear(), month.getMonth(), d)
			const iso = isoDate(date)
			const bookable = isBookableDay(date)
			const selected = state.date === iso
			cells.push(h('button', {
				type: 'button',
				class: 'quickbook__day' + (selected ? ' quickbook__day--selected' : '') + (date.getTime() === today.getTime() ? ' quickbook__day--today' : ''),
				disabled: !bookable,
				'aria-pressed': selected ? 'true' : 'false',
				'aria-label': fmt.longDate.format(date),
				onclick: () => fetchSlots(iso),
			}, String(d)))
		}

		const calendar = h('section', { class: 'quickbook__calendar', 'aria-label': t('Choose a date') },
			h('div', { class: 'quickbook__month' },
				h('button', { type: 'button', class: 'quickbook__nav', disabled: !canPrev, 'aria-label': t('Previous month'), onclick: () => { state.month = prevMonth; render() } }, '‹'),
				h('h3', { class: 'quickbook__month-name' }, fmt.month.format(month)),
				h('button', { type: 'button', class: 'quickbook__nav', disabled: !canNext, 'aria-label': t('Next month'), onclick: () => { state.month = nextMonth; render() } }, '›'),
			),
			h('div', { class: 'quickbook__grid' }, weekdayHeaders, cells),
		)

		let slotPanel
		if (!state.date) {
			slotPanel = h('p', { class: 'quickbook__hint' }, t('Choose a date to see the free times.'))
		} else if (state.slotsLoading) {
			slotPanel = h('p', { class: 'quickbook__hint', role: 'status' }, h('span', { class: 'icon-loading-small quickbook__spinner', 'aria-hidden': 'true' }), t('Loading free times…'))
		} else if (state.slotsError) {
			slotPanel = h('p', { class: 'quickbook__error', role: 'alert' }, state.slotsError)
		} else if (state.slots.length === 0) {
			slotPanel = h('p', { class: 'quickbook__hint' }, t('No free times on this day. Please choose another date.'))
		} else {
			slotPanel = h('div', { class: 'quickbook__slot-list', role: 'group', 'aria-label': t('Free times') },
				state.slots.map((slot) => {
					const selected = state.slot && state.slot.start === slot.start
					return h('button', {
						type: 'button',
						class: 'quickbook__slot' + (selected ? ' quickbook__slot--selected' : ''),
						'aria-pressed': selected ? 'true' : 'false',
						onclick: () => { state.slot = slot; render() },
						'aria-label': formatRange(slot),
					}, fmt.time.format(new Date(slot.start * 1000)))
				}),
			)
		}

		const slots = h('section', { class: 'quickbook__slots', 'aria-label': t('Choose a time') },
			h('h3', { class: 'quickbook__slots-title' }, state.date ? fmt.longDate.format(parseIsoDate(state.date)) : t('Free times')),
			h('p', { class: 'quickbook__tz' }, t('Times are shown in {timezone}', { timezone: visitorTz })),
			slotPanel,
		)

		return [
			errorBox(),
			h('div', { class: 'quickbook__picker' }, calendar, slots),
			h('div', { class: 'quickbook__actions' },
				h('button', { type: 'button', class: 'quickbook__button quickbook__button--primary', disabled: !state.slot, onclick: () => goTo('details') }, t('Continue')),
			),
		]
	}

	function chosenSlotSummary() {
		return h('p', { class: 'quickbook__chosen' },
			h('strong', {}, fmt.longDate.format(new Date(state.slot.start * 1000))), ' · ', formatRange(state.slot),
			' ', h('button', { type: 'button', class: 'quickbook__link', onclick: () => goTo('date') }, t('Change')),
		)
	}

	function viewDetails() {
		const field = (id, label, input, hint) => h('div', { class: 'quickbook__field' },
			h('label', { for: id }, label), input, hint ? h('p', { class: 'quickbook__field-hint' }, hint) : null)

		const form = h('form', {
			class: 'quickbook__form',
			onsubmit: (event) => {
				event.preventDefault()
				const data = new FormData(event.target)
				state.form.displayName = String(data.get('displayName') || '').trim()
				state.form.email = String(data.get('email') || '').trim()
				state.form.description = String(data.get('description') || '').trim()
				if (event.target.reportValidity()) {
					goTo('review')
				}
			},
		},
		field('qb-name', t('Name'), h('input', { id: 'qb-name', name: 'displayName', type: 'text', required: true, maxlength: 128, autocomplete: 'name', value: state.form.displayName })),
		field('qb-email', t('Email'), h('input', { id: 'qb-email', name: 'email', type: 'email', required: true, autocomplete: 'email', value: state.form.email }), t('Your booking confirmation is sent to this address.')),
		field('qb-description', t('Comment (optional)'), (() => {
			const area = h('textarea', { id: 'qb-description', name: 'description', rows: 4, maxlength: 2000 })
			area.value = state.form.description
			return area
		})()),
		h('div', { class: 'quickbook__actions' },
			h('button', { type: 'button', class: 'quickbook__button', onclick: () => goTo('date') }, t('Back')),
			h('button', { type: 'submit', class: 'quickbook__button quickbook__button--primary' }, t('Review booking')),
		),
		)

		return [errorBox(), chosenSlotSummary(), form]
	}

	function summaryList() {
		const rows = [
			[t('Appointment'), config.name],
			[t('With'), config.organizer],
			[t('Date'), fmt.longDate.format(new Date(state.slot.start * 1000))],
			[t('Time'), `${formatRange(state.slot)} (${visitorTz})`],
			config.location ? [t('Location'), config.location] : null,
			[t('Name'), state.form.displayName],
			[t('Email'), state.form.email],
			state.form.description ? [t('Comment'), state.form.description] : null,
		].filter(Boolean)
		return h('dl', { class: 'quickbook__summary' }, rows.map(([term, value]) => [h('dt', {}, term), h('dd', {}, value)]))
	}

	function viewReview() {
		return [
			errorBox(),
			h('p', {}, t('Please check your booking.')),
			summaryList(),
			h('div', { class: 'quickbook__actions' },
				h('button', { type: 'button', class: 'quickbook__button', disabled: state.submitting, onclick: () => goTo('details') }, t('Back')),
				h('button', {
					type: 'button',
					class: 'quickbook__button quickbook__button--primary',
					disabled: state.submitting,
					onclick: submitBooking,
				}, state.submitting ? t('Booking…') : t('Confirm booking')),
			),
		]
	}

	function viewDone() {
		return [
			h('div', { class: 'quickbook__done' },
				h('span', { class: 'quickbook__done-icon', 'aria-hidden': 'true' }, '✓'),
				h('h3', {}, t('Your appointment is booked')),
				h('p', {}, t('A confirmation email is on its way to {email}.', { email: state.form.email })),
			),
			summaryList(),
			h('div', { class: 'quickbook__actions' },
				h('a', { class: 'quickbook__button', href: window.location.pathname }, t('Book another appointment')),
			),
		]
	}

	function viewUnavailable() {
		return [
			h('p', { class: 'quickbook__error', role: 'alert' }, t('Online booking on this page is temporarily unavailable.')),
			state.fallbackUrl
				? h('div', { class: 'quickbook__actions' }, h('a', { class: 'quickbook__button quickbook__button--primary', href: state.fallbackUrl }, t('Continue on the standard booking page')))
				: h('p', {}, t('Please try again later.')),
		]
	}

	const views = { date: viewDate, details: viewDetails, review: viewReview, done: viewDone, unavailable: viewUnavailable }

	function render(moveFocus = false) {
		const content = h('div', { class: 'quickbook__content', tabindex: '-1' }, views[state.step]())
		root.replaceChildren(
			h('div', { class: 'quickbook__card' },
				header(),
				state.step === 'done' || state.step === 'unavailable' ? null : steps(),
				content,
			),
		)
		if (moveFocus) {
			// Errors are announced through role="alert"; otherwise start at the first field or the new step
			const target = (!state.error && content.querySelector('input')) || content
			target.focus()
		}
	}

	render()
})()

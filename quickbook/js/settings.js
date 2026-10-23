/**
 * Quick Booking: personal (links) and admin (self-check) settings.
 */
(function () {
	'use strict'

	const APP = 'quickbook'
	const t = (text) => (typeof window.t === 'function' ? window.t(APP, text) : text)
	const token = () => document.head.dataset.requesttoken || ''

	async function request(method, url, body) {
		const response = await fetch(url, {
			method,
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/json', Accept: 'application/json', requesttoken: token() },
			body: body === undefined ? undefined : JSON.stringify(body),
		})
		const data = await response.json().catch(() => ({}))
		if (!response.ok) {
			throw new Error(data.message || t('Could not save'))
		}
		return data
	}

	function init() {
		document.querySelectorAll('button.quickbook-copy').forEach((button) => {
			button.addEventListener('click', async () => {
				try {
					await navigator.clipboard.writeText(button.dataset.url)
					button.textContent = t('Copied')
				} catch (e) {
					button.previousElementSibling?.select()
				}
			})
		})

		const check = document.getElementById('quickbook-run-check')
		if (check) {
			const status = document.getElementById('quickbook-check-status')
			check.addEventListener('click', async () => {
				check.disabled = true
				status.textContent = t('Checking…')
				try {
					await request('POST', check.dataset.checkUrl)
					window.location.reload()
				} catch (e) {
					status.textContent = e.message
					check.disabled = false
				}
			})
		}
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init)
	} else {
		init()
	}
})()

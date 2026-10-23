/**
 * Quick Booking: admin settings (run the self-check).
 */
(function () {
	'use strict'

	const t = (text) => window.t('quickbook', text)

	function init() {
		const check = document.getElementById('quickbook-run-check')
		if (check) {
			const status = document.getElementById('quickbook-check-status')
			check.addEventListener('click', async () => {
				check.disabled = true
				status.textContent = t('Checking…')
				try {
					const response = await fetch(check.dataset.checkUrl, {
						method: 'POST',
						credentials: 'same-origin',
						headers: { Accept: 'application/json', requesttoken: document.head.dataset.requesttoken || '' },
					})
					if (!response.ok) {
						throw new Error(t('The self-check could not run.'))
					}
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

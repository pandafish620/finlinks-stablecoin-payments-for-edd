/* global finlstpa_admin */
(function () {
	'use strict';
	if (!window.finlstpa_admin || typeof finlstpa_admin.url !== 'string') {
		return;
	}
	document.querySelectorAll('.finlstpa-tools').forEach(function (form) {
		let busy = false;
		form.addEventListener('submit', async function (event) {
			if (!window.fetch || !event.submitter) { return; }
			event.preventDefault();
			if (busy) { return; }
			busy = true;
			const body = new FormData(form);
			body.set('action', 'finlstpa_tools');
			body.set('operation', event.submitter.value);
			const buttons = form.querySelectorAll('button');
			const output = form.querySelector('.finlstpa-tool-result');
			buttons.forEach(function (button) { button.disabled = true; });
			if (output) {
				output.textContent = finlstpa_admin.working;
			}
			const controller = new AbortController();
			const timer = window.setTimeout(function () { controller.abort(); }, 45000);
			try {
				const response = await fetch(finlstpa_admin.url, { method: 'POST', credentials: 'same-origin', redirect: 'error', body: body, signal: controller.signal });
				const result = await response.json();
				if (!response.ok || !result.data || typeof result.data.message !== 'string') { throw new Error('Unconfirmed response'); }
				if (output) {
					output.textContent = result.data.message;
				}
			} catch (error) {
				if (output) {
					output.textContent = finlstpa_admin.unconfirmed;
				}
			} finally {
				window.clearTimeout(timer);
				buttons.forEach(function (button) { button.disabled = false; });
				busy = false;
			}
		});
	});
}());

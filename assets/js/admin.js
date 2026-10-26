/* global FinLinksEDD */
(function () {
	'use strict';
	document.querySelectorAll('.finlinks-edd-tools').forEach(function (form) {
		let busy = false;
		form.addEventListener('submit', async function (event) {
			if (!window.fetch || !event.submitter) { return; }
			event.preventDefault();
			if (busy) { return; }
			busy = true;
			const body = new FormData(form);
			body.set('action', 'finlinks_edd_tools');
			body.set('operation', event.submitter.value);
			const buttons = form.querySelectorAll('button');
			const output = form.querySelector('.finlinks-edd-tool-result');
			buttons.forEach(function (button) { button.disabled = true; });
			output.textContent = FinLinksEDD.working;
			const controller = new AbortController();
			const timer = window.setTimeout(function () { controller.abort(); }, 45000);
			try {
				const response = await fetch(FinLinksEDD.url, { method: 'POST', credentials: 'same-origin', redirect: 'error', body: body, signal: controller.signal });
				const result = await response.json();
				if (!response.ok || !result.data || typeof result.data.message !== 'string') { throw new Error('Unconfirmed response'); }
				output.textContent = result.data.message;
			} catch (error) {
				output.textContent = FinLinksEDD.unconfirmed;
			} finally {
				window.clearTimeout(timer);
				buttons.forEach(function (button) { button.disabled = false; });
				busy = false;
			}
		});
	});
}());


(function () {
	'use strict';

	const active = new Set();
	const config = () => window.context?.extensions?.fetch_and_summarize;

	function addButtons() {
		const settings = config();
		if (!settings) return;

		document.querySelectorAll('[id^="flux_"]').forEach((entry) => {
			if (entry.querySelector('.fetchandsummarize-button')) return;
			const button = document.createElement('button');
			button.className = 'btn fetchandsummarize-button';
			button.type = 'button';
			button.textContent = settings.i18n.button;
			button.dataset.entryId = entry.id.slice(5);
			button.setAttribute('aria-describedby', `fetchandsummarize-result-${button.dataset.entryId}`);
			const header = entry.querySelector('ul.flux_header, .flux_header');
			if (header) {
				const item = document.createElement('li');
				item.className = 'item fetchandsummarize-item';
				item.append(button);
				header.append(item);
			} else {
				const toolbar = document.createElement('div');
				toolbar.className = 'fetchandsummarize-toolbar';
				toolbar.append(button);
				entry.prepend(toolbar);
			}

			const result = document.createElement('div');
			result.className = 'fetchandsummarize-result';
			result.id = `fetchandsummarize-result-${button.dataset.entryId}`;
			result.setAttribute('aria-live', 'polite');
			result.setAttribute('role', 'status');
			entry.append(result);
		});
	}

	function configureProviderDefaults() {
		const provider = document.getElementById('fas-provider');
		const url = document.getElementById('fas-api-url');
		const model = document.getElementById('fas-model');
		if (!provider || !url || !model) return;
		url.addEventListener('input', () => { url.dataset.edited = 'true'; });
		model.addEventListener('input', () => { model.dataset.edited = 'true'; });
		provider.addEventListener('change', () => {
			const option = provider.selectedOptions[0];
			if (url.dataset.edited !== 'true') url.value = option.dataset.url;
			if (model.dataset.edited !== 'true') model.value = option.dataset.model;
		});
	}

	document.addEventListener('click', async (event) => {
		const button = event.target.closest('.fetchandsummarize-button');
		if (!button) return;
		event.preventDefault();
		const id = button.dataset.entryId;
		if (active.has(id)) return;
		const settings = config();
		const result = document.getElementById(`fetchandsummarize-result-${id}`);
		if (!settings || !result) return;

		active.add(id);
		button.disabled = true;
		button.textContent = settings.i18n.loading;
		result.setAttribute('role', 'status');
		result.textContent = settings.i18n.loading;
		try {
			const response = await fetch(settings.url, {
				method: 'POST',
				credentials: 'same-origin',
				headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
				body: new URLSearchParams({ id, _csrf: window.context.csrf }),
			});
			let data;
			try {
				data = await response.json();
			} catch {
				throw new Error(settings.i18n.error);
			}
			if (!response.ok) throw new Error(data.error || settings.i18n.error);
			result.textContent = data.summary;
			result.classList.add('fetchandsummarize-summary');
		} catch (error) {
			result.setAttribute('role', 'alert');
			result.textContent = error instanceof Error ? error.message : settings.i18n.error;
		} finally {
			active.delete(id);
			button.disabled = false;
			button.textContent = settings.i18n.button;
		}
	});

	document.addEventListener('freshrss:globalContextLoaded', addButtons);
	document.body?.addEventListener('freshrss:load-more', addButtons);
	configureProviderDefaults();
	if (config()) addButtons();
}());

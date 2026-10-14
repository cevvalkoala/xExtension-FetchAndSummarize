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
		const key = document.getElementById('fas-api-key');
		const loadButton = document.getElementById('fas-load-models');
		const modelList = document.getElementById('fas-model-list');
		const status = document.getElementById('fas-model-status');
		if (!provider || !url || !model || !key || !loadButton || !modelList || !status) return;
		let previousValue = provider.value;
		provider.addEventListener('change', () => {
			const previousOption = Array.from(provider.options).find((option) => option.value === previousValue);
			const nextOption = provider.selectedOptions[0];
			const modelWasKnown = previousOption && (
				model.value === previousOption.dataset.model ||
				Array.from(modelList.children).some((option) => option.value === model.value)
			);
			if (previousOption && (url.value === '' || url.value === previousOption.dataset.url)) {
				url.value = nextOption.dataset.url;
			}
			if (previousOption && (model.value === '' || modelWasKnown)) {
				model.value = nextOption.dataset.model;
			}
			if (previousValue !== nextOption.value) key.value = '';
			modelList.replaceChildren();
			status.textContent = '';
			previousValue = nextOption.value;
		});
		loadButton.addEventListener('click', async () => {
			const settings = config();
			if (!settings?.modelsUrl) return;
			const requestedProvider = provider.value;
			const requestedUrl = url.value;
			loadButton.disabled = true;
			loadButton.textContent = settings.i18n.loadingModels;
			status.textContent = settings.i18n.loadingModels;
			status.setAttribute('role', 'status');
			modelList.replaceChildren();
			try {
				const response = await fetch(settings.modelsUrl, {
					method: 'POST',
					credentials: 'same-origin',
					headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
					body: new URLSearchParams({
						provider: requestedProvider,
						api_url: requestedUrl,
						api_key: key.value,
						_csrf: window.context.csrf,
					}),
				});
				let data;
				try {
					data = await response.json();
				} catch {
					throw new Error(settings.i18n.modelsError);
				}
				if (!response.ok || !Array.isArray(data.models)) {
					throw new Error(typeof data.error === 'string' ? data.error : settings.i18n.modelsError);
				}
				if (provider.value !== requestedProvider || url.value !== requestedUrl) return;
				for (const name of data.models) {
					if (typeof name !== 'string') continue;
					const option = document.createElement('option');
					option.value = name;
					modelList.append(option);
				}
				status.textContent = data.models.length === 0
					? settings.i18n.noModels
					: settings.i18n.modelsLoaded.replace('{count}', String(data.models.length));
			} catch (error) {
				status.setAttribute('role', 'alert');
				status.textContent = error instanceof Error ? error.message : settings.i18n.modelsError;
			} finally {
				loadButton.disabled = false;
				loadButton.textContent = config()?.i18n?.loadModels || settings.i18n.loadModels;
			}
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

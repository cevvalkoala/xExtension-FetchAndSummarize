(function () {
	'use strict';

	function initialize() {
		const form = document.getElementById('fas-configure');
		if (!form || form.dataset.fasInitialized === 'true') return;

		const provider = document.getElementById('fas-provider');
		const url = document.getElementById('fas-api-url');
		const model = document.getElementById('fas-model');
		const key = document.getElementById('fas-api-key');
		const csrf = document.getElementById('fas-csrf');
		const loadButton = document.getElementById('fas-load-models');
		const modelList = document.getElementById('fas-model-list');
		const status = document.getElementById('fas-model-status');
		if (!provider || !url || !model || !key || !csrf || !loadButton || !modelList || !status) return;
		form.dataset.fasInitialized = 'true';

		let previousValue = provider.value;
		provider.addEventListener('change', () => {
			const previousOption = Array.from(provider.options).find((option) => option.value === previousValue);
			const nextOption = provider.selectedOptions[0];
			if (!nextOption) return;
			const modelWasKnown = previousOption && (
				model.value === previousOption.dataset.model ||
				Array.from(modelList.children).some((option) => option.value === model.value)
			);
		url.value = nextOption.dataset.url;
			if (previousOption && (model.value === '' || modelWasKnown)) {
				model.value = nextOption.dataset.model;
			}
			if (previousValue !== nextOption.value) key.value = '';
			modelList.replaceChildren();
			status.textContent = '';
			previousValue = nextOption.value;
		});

 		loadButton.addEventListener('click', async () => {
			const requestedProvider = provider.value;
			const requestedUrl = url.value;
			loadButton.disabled = true;
			loadButton.textContent = form.dataset.loadingLabel;
			status.textContent = form.dataset.loadingLabel;
			status.setAttribute('role', 'status');
			modelList.replaceChildren();
			try {
				const response = await fetch(form.dataset.modelsUrl, {
					method: 'POST',
					credentials: 'same-origin',
					headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
					body: new URLSearchParams({
						provider: requestedProvider,
						api_url: requestedUrl,
						api_key: key.value,
						_csrf: csrf.value,
					}),
				});
				let data;
				try {
					data = await response.json();
				} catch {
					throw new Error(`FreshRSS returned a non-JSON model response (HTTP ${response.status}). Reload the page and verify the extension is enabled.`);
				}
				if (!response.ok || !Array.isArray(data.models)) {
					throw new Error(typeof data.error === 'string' ? data.error : form.dataset.errorLabel);
				}
				if (provider.value !== requestedProvider || url.value !== requestedUrl) return;
				for (const name of data.models) {
					if (typeof name !== 'string') continue;
					const option = document.createElement('option');
					option.value = name;
					modelList.append(option);
				}
				status.textContent = data.models.length === 0
					? form.dataset.emptyLabel
					: form.dataset.loadedLabel.replace('{count}', String(data.models.length));
			} catch (error) {
				status.setAttribute('role', 'alert');
				status.textContent = error instanceof Error ? error.message : form.dataset.errorLabel;
			} finally {
				loadButton.disabled = false;
				loadButton.textContent = form.dataset.loadLabel;
			}
		});
	}

	initialize();
	if (typeof MutationObserver === 'function' && document.documentElement && !window.__fetchAndSummarizeConfigureObserver) {
		window.__fetchAndSummarizeConfigureObserver = true;
		new MutationObserver(initialize).observe(document.documentElement, { childList: true, subtree: true });
	}
}());

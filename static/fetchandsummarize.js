(function () {
	'use strict';

	const active = new Set();
	const activeFeedJobs = new Set();
	const batchSummaries = new Map();
	const cacheLookupsPending = new Set();
	const cacheLookupsDone = new Set();
	const MAX_BATCH_CACHE_ENTRIES = 300;
	const MAX_BATCH_CACHE_BYTES = 3_000_000;
	const config = () => window.context?.extensions?.fetch_and_summarize;

	function rememberBatchSummary(id, data) {
		const item = {
			summary: data.summary,
			source: data.source === 'feed' ? 'feed' : 'article',
			bytes: new TextEncoder().encode(data.summary).length + 128,
		};
		batchSummaries.delete(id);
		batchSummaries.set(id, item);
		let totalBytes = Array.from(batchSummaries.values()).reduce((total, cached) => total + cached.bytes, 0);
		while (batchSummaries.size > MAX_BATCH_CACHE_ENTRIES || totalBytes > MAX_BATCH_CACHE_BYTES) {
			const oldestId = batchSummaries.keys().next().value;
			totalBytes -= batchSummaries.get(oldestId).bytes;
			batchSummaries.delete(oldestId);
		}
		return item;
	}

	function showSummary(id, data, settings) {
		const cached = rememberBatchSummary(id, data);
		const result = document.getElementById(`fetchandsummarize-result-${id}`);
		if (!result) return;
		const sourceLine = cached.source === 'feed' ? settings.i18n.summaryFeed : settings.i18n.summaryArticle;
		result.textContent = `${sourceLine}\n\n${cached.summary}`;
		result.classList.add('fetchandsummarize-summary');
	}

	async function postJson(url, body, fallbackMessage) {
		const response = await fetch(url, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
			body: new URLSearchParams(body),
		});
		let data;
		try {
			data = await response.json();
		} catch {
			throw new Error(`FreshRSS returned a non-JSON response (HTTP ${response.status}). Reload the page and verify the extension is enabled.`);
		}
		if (!response.ok) {
			const error = new Error(typeof data.error === 'string' ? data.error : fallbackMessage);
			error.httpStatus = response.status;
			throw error;
		}
		return data;
	}

	function currentFeedId() {
		const get = new URLSearchParams(window.location?.search || '').get('get') || '';
		if (/^f_\d{1,20}$/.test(get)) return get.slice(2);
		const pathMatch = (window.location?.pathname || '').match(/(?:^|\/)f_(\d{1,20})(?:\/|$)/);
		if (pathMatch) return pathMatch[1];
		const selected = document.querySelector('#aside_feed li.active[id^="f_"]');
		return selected?.id.startsWith('f_') ? selected.id.slice(2) : '';
	}

	function addUnreadButton(settings) {
		const stream = document.querySelector('#stream.normal');
		const feedId = currentFeedId();
		if (!stream || feedId === '') return;
		const oldToolbar = stream.querySelector('.fetchandsummarize-feed-toolbar');
		if (oldToolbar?.dataset.feedId === feedId) return;
		oldToolbar?.remove();

		const toolbar = document.createElement('div');
		toolbar.className = 'fetchandsummarize-feed-toolbar';
		toolbar.dataset.feedId = feedId;
		const description = document.createElement('span');
		description.className = 'fetchandsummarize-feed-label';
		description.textContent = settings.i18n.unreadDescription;
		const button = document.createElement('button');
		button.className = 'btn fetchandsummarize-unread';
		button.type = 'button';
		button.dataset.feedId = feedId;
		button.textContent = settings.i18n.unreadButton;
		const status = document.createElement('span');
		status.className = 'fetchandsummarize-unread-status';
		status.id = `fetchandsummarize-unread-status-${feedId}`;
		status.setAttribute('role', 'status');
		status.setAttribute('aria-live', 'polite');
		button.setAttribute('aria-describedby', status.id);
		toolbar.append(description, button, status);
		const firstEntry = stream.querySelector('.flux');
		if (firstEntry) stream.insertBefore(toolbar, firstEntry);
		else stream.prepend(toolbar);
	}

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
			const articleText = entry.querySelector('.flux_content .content > .text');
			const content = entry.querySelector('.flux_content .content');
			if (articleText?.parentElement) {
				articleText.parentElement.insertBefore(result, articleText);
			} else if (content) {
				content.prepend(result);
			} else if (header) {
				header.after(result);
			} else {
				entry.prepend(result);
			}
			const cached = batchSummaries.get(button.dataset.entryId);
			if (cached) showSummary(button.dataset.entryId, cached, settings);
		});
		addUnreadButton(settings);
	}

	document.addEventListener('freshrss:openArticle', async (event) => {
		const entry = event.target.closest('[id^="flux_"]');
		if (!entry) return;
		const id = entry.id.slice(5);
		if (cacheLookupsDone.has(id) || cacheLookupsPending.has(id)) return;
		const settings = config();
		if (!settings?.cachedUrl) return;
		cacheLookupsPending.add(id);
		try {
			const data = await postJson(settings.cachedUrl, { id, _csrf: window.context.csrf }, settings.i18n.error);
			cacheLookupsDone.add(id);
			if (data.cached === true && typeof data.summary === 'string') showSummary(id, data, settings);
		} catch {
			// Cache lookup is opportunistic; article opening should not show a failure for a cache miss or stale session.
		} finally {
			cacheLookupsPending.delete(id);
		}
	});

	document.addEventListener('click', async (event) => {
		const unreadButton = event.target.closest('.fetchandsummarize-unread');
		if (unreadButton) {
			event.preventDefault();
			const feedId = unreadButton.dataset.feedId;
			if (activeFeedJobs.has(feedId)) return;
			const settings = config();
			const status = document.getElementById(`fetchandsummarize-unread-status-${feedId}`);
			if (!settings || !status) return;

			activeFeedJobs.add(feedId);
			unreadButton.disabled = true;
			status.setAttribute('role', 'status');
			try {
				const unread = await postJson(settings.unreadUrl, { feed_id: feedId, _csrf: window.context.csrf }, settings.i18n.unreadError);
				const ids = Array.isArray(unread.ids) ? unread.ids.filter((id) => typeof id === 'string' && /^\d{1,20}$/.test(id)) : [];
				if (ids.length === 0) {
					status.textContent = settings.i18n.unreadEmpty;
					return;
				}
				let completed = 0;
				let failed = 0;
				for (let index = 0; index < ids.length; index++) {
					status.textContent = settings.i18n.unreadProgress
						.replace('{current}', String(index + 1))
						.replace('{total}', String(ids.length));
					try {
						const data = await postJson(settings.url, { id: ids[index], _csrf: window.context.csrf }, settings.i18n.error);
						showSummary(ids[index], data, settings);
						completed++;
					} catch (error) {
						failed++;
						if (error?.httpStatus === 401 || error?.httpStatus === 403) throw error;
					}
				}
				status.textContent = failed === 0
					? settings.i18n.unreadComplete.replace('{count}', String(completed))
					: settings.i18n.unreadPartial.replace('{count}', String(completed)).replace('{total}', String(ids.length)).replace('{failed}', String(failed));
			} catch (error) {
				status.setAttribute('role', 'alert');
				status.textContent = error instanceof Error ? error.message : settings.i18n.unreadError;
			} finally {
				activeFeedJobs.delete(feedId);
				unreadButton.disabled = false;
			}
			return;
		}

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
			const data = await postJson(settings.url, { id, _csrf: window.context.csrf }, settings.i18n.error);
			showSummary(id, data, settings);
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
	if (config()) addButtons();
}());

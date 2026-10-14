'use strict';

const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');

class Element {
	constructor(tagName) {
		this.tagName = tagName;
		this.children = [];
		this.dataset = {};
		this.attributes = {};
		this.className = '';
		this.id = '';
		this.value = '';
		this.options = [];
		this.selectedOptions = [];
		this.listeners = new Map();
		this.disabled = false;
		this.parentElement = null;
		this.textContent = '';
	}

	append(child) {
		child.parentElement = this;
		this.children.push(child);
	}

	insertBefore(child, reference) {
		child.parentElement = this;
		const index = this.children.indexOf(reference);
		this.children.splice(index < 0 ? this.children.length : index, 0, child);
	}

	replaceChildren(...children) {
		this.children = [];
		children.forEach((child) => this.append(child));
	}

	prepend(child) {
		child.parentElement = this;
		this.children.unshift(child);
	}

	after(sibling) {
		if (this.parentElement) this.parentElement.insertBefore(sibling, this.parentElement.children[this.parentElement.children.indexOf(this) + 1] || null);
	}

	setAttribute(name, value) { this.attributes[name] = value; }
	addEventListener(name, handler) { this.listeners.set(name, handler); }

	closest(selector) {
		return selector === '.fetchandsummarize-button' && this.className.includes('fetchandsummarize-button') ? this : null;
	}

	querySelector(selector) {
		if (selector === 'ul.flux_header, .flux_header') return this.header || null;
		if (selector === '.flux_content .content > .text') return this.articleText || null;
		if (selector === '.flux_content .content') return this.contentArea || null;
		const className = selector.slice(1);
		for (const child of this.children) {
			if (child.className.includes(className)) return child;
			const nested = child.querySelector(selector);
			if (nested) return nested;
		}
		return null;
	}

	get classList() {
		return { add: (name) => { if (!this.className.includes(name)) this.className += ` ${name}`; } };
	}
}

class Entry extends Element {
	constructor(id, withHeader = true) {
		super('article');
		this.id = id;
		this.header = withHeader ? new Element('ul') : null;
		if (this.header) {
			this.header.className = 'horizontal-list flux_header';
			this.append(this.header);
		}
		const fluxContent = new Element('div');
		fluxContent.className = 'flux_content';
		this.contentArea = new Element('div');
		this.contentArea.className = 'content';
		this.articleText = new Element('div');
		this.articleText.className = 'text';
		this.contentArea.append(this.articleText);
		fluxContent.append(this.contentArea);
		this.append(fluxContent);
	}
}

const entries = [new Entry('flux_101'), new Entry('flux_202', false)];
const handlers = new Map();
const bodyHandlers = new Map();
const options = [
	{ value: 'openai', dataset: { url: 'https://api.openai.com/v1', model: 'gpt-4o-mini' } },
	{ value: 'anthropic', dataset: { url: 'https://api.anthropic.com', model: 'claude-3-7-sonnet-latest' } },
	{ value: 'gemini', dataset: { url: 'https://generativelanguage.googleapis.com', model: 'gemini-2.5-flash' } },
];
const providerControl = new Element('select');
providerControl.value = 'openai';
providerControl.options = options;
providerControl.selectedOptions = [options[0]];
const urlControl = new Element('input');
urlControl.value = options[0].dataset.url;
const keyControl = new Element('input');
keyControl.value = 'unsaved-gemini-key';
const modelControl = new Element('input');
modelControl.value = options[0].dataset.model;
const loadModelsButton = new Element('button');
const modelList = new Element('datalist');
const modelStatus = new Element('p');
const csrfControl = new Element('input');
csrfControl.value = 'csrf-test';
const configureForm = new Element('form');
configureForm.dataset = {
	modelsUrl: './?c=extension&a=configure&e=FetchAndSummarize&fas_action=models',
	loadLabel: 'Load models',
	loadingLabel: 'Loading models...',
	loadedLabel: 'Loaded {count} models.',
	emptyLabel: 'No models.',
	errorLabel: 'Model list error.',
};
const controls = new Map([
	['fas-configure', configureForm],
	['fas-provider', providerControl],
	['fas-api-url', urlControl],
	['fas-api-key', keyControl],
	['fas-model', modelControl],
	['fas-load-models', loadModelsButton],
	['fas-model-list', modelList],
	['fas-model-status', modelStatus],
	['fas-csrf', csrfControl],
]);
const allElements = () => {
	const result = [];
	const visit = (element) => { result.push(element); element.children.forEach(visit); };
	entries.forEach(visit);
	return result;
};

global.window = { context: { csrf: 'csrf-test', extensions: { fetch_and_summarize: {
	url: './?c=extension&a=configure&e=FetchAndSummarize&fas_action=summarize',
i18n: {
		button: 'Fetch and Summarize', loading: 'Loading...', error: 'Could not summarize.',
		loadModels: 'Load models', loadingModels: 'Loading models...', modelsLoaded: 'Loaded {count} models.',
		noModels: 'No models.', modelsError: 'Model list error.',
	},
modelsUrl: './?c=extension&a=configure&e=FetchAndSummarize&fas_action=models',
} } } };
global.document = {
	body: { addEventListener: (name, handler) => bodyHandlers.set(name, handler) },
	addEventListener: (name, handler) => handlers.set(name, handler),
	querySelectorAll: (selector) => selector === '[id^="flux_"]' ? entries : [],
	createElement: (tagName) => new Element(tagName),
	getElementById: (id) => controls.get(id) || allElements().find((element) => element.id === id) || null,
};

let requests = 0;
let modelRequests = 0;
let completeFirst;
global.fetch = (url, options) => {
	if (url === './?c=extension&a=configure&e=FetchAndSummarize&fas_action=models') {
		modelRequests++;
		assert.equal(options.method, 'POST');
		assert.equal(options.credentials, 'same-origin');
		const body = new URLSearchParams(options.body);
		assert.equal(body.get('provider'), 'gemini');
		assert.equal(body.get('api_url'), 'https://custom.example/v1');
		assert.equal(body.get('api_key'), 'unsaved-gemini-key');
		assert.equal(body.get('_csrf'), 'csrf-test');
		return Promise.resolve({ ok: true, json: async () => ({ models: ['gemini-flash', 'gemini-pro'] }) });
	}
	requests++;
	assert.equal(url, './?c=extension&a=configure&e=FetchAndSummarize&fas_action=summarize');
	assert.equal(options.method, 'POST');
	assert.equal(options.credentials, 'same-origin');
	assert.equal(options.headers['Content-Type'], 'application/x-www-form-urlencoded; charset=UTF-8');
	assert.equal(new URLSearchParams(options.body).get('_csrf'), 'csrf-test');
	const id = new URLSearchParams(options.body).get('id');
	if (id === '101') return new Promise((resolve) => { completeFirst = resolve; });
	if (id === '303') return Promise.resolve({ ok: false, status: 404, json: async () => { throw new SyntaxError('HTML error page'); } });
	return Promise.resolve({ ok: false, json: async () => ({ error: 'The article target was rejected.' }) });
};

require(path.join(__dirname, '..', 'static', 'fetchandsummarize.js'));
require(path.join(__dirname, '..', 'static', 'fetchandsummarize-configure.js'));

async function run() {
	const providerChange = providerControl.listeners.get('change');
	providerControl.value = 'anthropic';
	providerControl.selectedOptions = [options[1]];
	providerChange();
	assert.equal(urlControl.value, options[1].dataset.url, 'provider selection should update a default API URL');
	assert.equal(modelControl.value, options[1].dataset.model, 'provider selection should update its example model');
	assert.equal(keyControl.value, '', 'provider changes should clear unsaved credentials for the previous provider');
	urlControl.value = 'https://custom.example/v1';
	providerControl.value = 'gemini';
	providerControl.selectedOptions = [options[2]];
	providerChange();
	assert.equal(urlControl.value, options[2].dataset.url, 'every provider change should select that provider default');
	assert.equal(modelControl.value, options[2].dataset.model);
	urlControl.value = 'https://custom.example/v1';
	keyControl.value = 'unsaved-gemini-key';
	await loadModelsButton.listeners.get('click')();
	assert.equal(modelRequests, 1);
	assert.equal(modelList.children.length, 2, 'returned names should populate the model suggestions');
	assert.equal(modelStatus.textContent, 'Loaded 2 models.');
	modelControl.value = modelList.children[0].value;
	assert.equal(modelControl.value, 'gemini-flash', 'a returned model can be selected in the editable field');
	providerControl.value = 'openai';
	providerControl.selectedOptions = [options[0]];
	providerChange();
	assert.equal(urlControl.value, options[0].dataset.url, 'provider changes should reset editable API URL defaults');
	assert.equal(modelControl.value, options[0].dataset.model, 'switching providers should discard previously loaded model choices');
	assert.equal(keyControl.value, '', 'provider changes should not send a prior key to a different service');
	assert.equal(window.context.extensions.fetch_and_summarize.api_key, undefined, 'keys must not be injected into global context');
	const configure = fs.readFileSync(path.join(__dirname, '..', 'configure.phtml'), 'utf8');
	assert.ok(configure.indexOf('name="api_key"') < configure.indexOf('name="model"'), 'API key control should precede model selection');
	assert.match(configure, /fetchandsummarize-configure\.js/, 'the configuration script should be loaded by its own view');

	const button101 = entries[0].querySelector('.fetchandsummarize-button');
	const button202 = entries[1].querySelector('.fetchandsummarize-button');
	assert.ok(button101 && button202, 'controls should be inserted into multiple entries');
	assert.equal(button101.dataset.entryId, '101');
	assert.ok(entries[1].children[0].className.includes('fetchandsummarize-toolbar'), 'entries without a standard header need a fallback toolbar');
	assert.equal(document.getElementById('fetchandsummarize-result-101').attributes.role, 'status');
	assert.equal(document.getElementById('fetchandsummarize-result-101').attributes['aria-live'], 'polite');
	assert.equal(entries[0].contentArea.children[0].id, 'fetchandsummarize-result-101', 'summary box should precede the article text');

	const click = handlers.get('click');
	const firstClick = click({ target: button101, preventDefault() {} });
	const duplicateClick = click({ target: button101, preventDefault() {} });
	assert.equal(requests, 1, 'a duplicate click must not start a second request');
	assert.equal(button101.disabled, true, 'button should be disabled while its request is active');
	assert.equal(document.getElementById('fetchandsummarize-result-101').textContent, 'Loading...');
	completeFirst({ ok: true, json: async () => ({ summary: '<img src=x onerror=alert(1)> plain text' }) });
	await Promise.all([firstClick, duplicateClick]);
	const summary = document.getElementById('fetchandsummarize-result-101');
	assert.equal(summary.textContent, '<img src=x onerror=alert(1)> plain text', 'model output must remain plain text');
	assert.equal(summary.children.length, 0, 'model output must never create HTML nodes');
	assert.equal(button101.disabled, false);

	await click({ target: button202, preventDefault() {} });
	const error = document.getElementById('fetchandsummarize-result-202');
	assert.equal(error.attributes.role, 'alert');
	assert.equal(error.textContent, 'The article target was rejected.');

	entries.push(new Entry('flux_303'));
	bodyHandlers.get('freshrss:load-more')();
	assert.ok(entries[2].querySelector('.fetchandsummarize-button'), 'load-more entries should receive a control');
	await click({ target: entries[2].querySelector('.fetchandsummarize-button'), preventDefault() {} });
	assert.match(document.getElementById('fetchandsummarize-result-303').textContent, /non-JSON summary response \(HTTP 404\)/);

	const css = fs.readFileSync(path.join(__dirname, '..', 'static', 'fetchandsummarize.css'), 'utf8');
	assert.match(css, /@media\s*\(max-width:\s*600px\)/, 'mobile layout rules should be present');
	assert.match(css, /word-break:\s*break-word/);
	const js = fs.readFileSync(path.join(__dirname, '..', 'static', 'fetchandsummarize.js'), 'utf8');
	assert.doesNotMatch(js, /\.innerHTML\s*=/, 'untrusted summary output must not use innerHTML');
	console.log('PASS frontend multi-entry, duplicate-click, accessibility, text-only, error, and mobile checks');
}

run().catch((error) => {
	console.error(error);
	process.exitCode = 1;
});

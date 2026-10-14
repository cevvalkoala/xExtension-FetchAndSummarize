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
		this.disabled = false;
		this.parentElement = null;
		this.textContent = '';
	}

	append(child) {
		child.parentElement = this;
		this.children.push(child);
	}

	prepend(child) {
		child.parentElement = this;
		this.children.unshift(child);
	}

	setAttribute(name, value) { this.attributes[name] = value; }

	closest(selector) {
		return selector === '.fetchandsummarize-button' && this.className.includes('fetchandsummarize-button') ? this : null;
	}

	querySelector(selector) {
		if (selector === 'ul.flux_header, .flux_header') return this.header || null;
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
	}
}

const entries = [new Entry('flux_101'), new Entry('flux_202', false)];
const handlers = new Map();
const bodyHandlers = new Map();
const allElements = () => {
	const result = [];
	const visit = (element) => { result.push(element); element.children.forEach(visit); };
	entries.forEach(visit);
	return result;
};

global.window = { context: { csrf: 'csrf-test', extensions: { fetch_and_summarize: {
	url: '/?c=FetchAndSummarize&a=summarize',
	i18n: { button: 'Fetch and Summarize', loading: 'Loading...', error: 'Could not summarize.' },
} } } };
global.document = {
	body: { addEventListener: (name, handler) => bodyHandlers.set(name, handler) },
	addEventListener: (name, handler) => handlers.set(name, handler),
	querySelectorAll: (selector) => selector === '[id^="flux_"]' ? entries : [],
	createElement: (tagName) => new Element(tagName),
	getElementById: (id) => allElements().find((element) => element.id === id) || null,
};

let requests = 0;
let completeFirst;
global.fetch = (url, options) => {
	requests++;
	assert.equal(url, '/?c=FetchAndSummarize&a=summarize');
	assert.equal(options.method, 'POST');
	assert.equal(options.credentials, 'same-origin');
	assert.equal(options.headers['Content-Type'], 'application/x-www-form-urlencoded; charset=UTF-8');
	assert.equal(new URLSearchParams(options.body).get('_csrf'), 'csrf-test');
	const id = new URLSearchParams(options.body).get('id');
	if (id === '101') return new Promise((resolve) => { completeFirst = resolve; });
	return Promise.resolve({ ok: false, json: async () => ({ error: 'The article target was rejected.' }) });
};

require(path.join(__dirname, '..', 'static', 'fetchandsummarize.js'));

async function run() {
	const button101 = entries[0].querySelector('.fetchandsummarize-button');
	const button202 = entries[1].querySelector('.fetchandsummarize-button');
	assert.ok(button101 && button202, 'controls should be inserted into multiple entries');
	assert.equal(button101.dataset.entryId, '101');
	assert.ok(entries[1].children[0].className.includes('fetchandsummarize-toolbar'), 'entries without a standard header need a fallback toolbar');
	assert.equal(document.getElementById('fetchandsummarize-result-101').attributes.role, 'status');
	assert.equal(document.getElementById('fetchandsummarize-result-101').attributes['aria-live'], 'polite');

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

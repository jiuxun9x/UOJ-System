// What web/js/uoj.js makes of the names of users on a page: a span with the class uoj-username
// becomes a link to the profile, and its text is the name. The text may be anything somebody
// wrote (a blog, a statement keep such spans for @mentions), so it is never put back as HTML.
//
//   node web/tests/js/username_test.js

'use strict';

const assert = require('assert');
const fs = require('fs');
const path = require('path');
const vm = require('vm');

// jQuery as far as uoj.js uses it when it is loaded, and as an element of the page for the
// functions under test: whatever else is asked of it answers with itself
function anything() {
	const it = new Proxy(function () {}, {
		get: (target, key) => (key === Symbol.toPrimitive ? () => '' : it),
		set: () => true,
		apply: () => it,
		construct: () => it,
	});
	return it;
}
const page = anything();
// an element of the page: its text, its data and attributes, and what it was replaced with
function element(text, data, attrs) {
	return { fake: true, text, data: data || {}, attrs: attrs || {}, replacedWith: null };
}
function $(arg) {
	if (arg && arg.fake === true) {
		return {
			text: () => arg.text,
			data: (key) => arg.data[key],
			attr: (key) => arg.attrs[key],
			replaceWith: (html) => {
				arg.replacedWith = html;
			},
			css: () => ({
				html: (html) => {
					arg.replacedWith = html;
				},
			}),
		};
	}
	return page;
}
$.fn = {};
$.cookie = () => undefined;
$.removeCookie = () => true;

// and what the page has from its other scripts: the address of the site, and colours
const colors = { toRGB: () => ({}), toStr: () => '#123456' };
const context = {
	$, jQuery: $, window: page, document: page, navigator: page, location: page, localStorage: page, sessionStorage: page, console,
	uojHome: 'https://oj.example', ColorConverter: colors, HSV: function () {},
};
context.window = context;
vm.createContext(context);
vm.runInContext(fs.readFileSync(path.join(__dirname, '../../js/uoj.js'), 'utf8'), context, { filename: 'uoj.js' });
// what the function that replaces a span does to it, as $.fn.uoj_highlight calls it
function highlighted(el) {
	context.replaceWithHighlightUsername.call(el);
	return el.replacedWith;
}

let failed = 0;
function test(name, body) {
	try {
		body();
		console.log('ok   ' + name);
	} catch (e) {
		failed++;
		console.log('FAIL ' + name + '\n' + e.stack);
	}
}

test('a username becomes a link to its profile', () => {
	const html = highlighted(element('alice_1', { rating: 1500 }));
	assert.match(html, /^<a class="uoj-username" href="[^"]*\/user\/profile\/alice_1" style="color:[^"]*">alice_1<\/a>$/);
});

test('an @mention becomes a link', () => {
	const html = highlighted(element('@bob', { rating: 1500 }));
	assert.match(html, /\/user\/profile\/bob"/);
	assert.match(html, />@bob<\/a>$/);
});

test('text that is no username is left as it is', () => {
	for (const text of ['<img src=x onerror=alert(1)>', 'a b', '"><script>alert(1)</script>', 'x'.repeat(21), '']) {
		const el = element(text, { rating: 1 });
		assert.strictEqual(highlighted(el), null, text);
	}
});

test('a nickname is escaped and the username follows it', () => {
	const html = highlighted(element('carol', { rating: 1500 }, { 'data-alias': '<b>C</b>' }));
	assert.match(html, />&lt;b&gt;C&lt;\/b&gt;（carol）<\/a>$/);
});

test('what makes the text of a link is escaped', () => {
	assert.strictEqual(context.getUserText('<x>'), '&lt;x&gt;');
	assert.match(context.getUserLink('a"b', 1500), /href="[^"]*\/user\/profile\/a%22b"/);
	assert.doesNotMatch(context.getUserSpan('<i>', 1500), /<i>/);
});

test('an honor is put back as text', () => {
	const el = element('<img src=x onerror=alert(1)>', { rating: 1500 });
	context.$.fn.uoj_honor.call({ each: (f) => f.call(el) });
	assert.strictEqual(el.replacedWith, '&lt;img src=x onerror=alert(1)&gt;');
});

if (failed) {
	console.log(failed + ' failed');
	process.exit(1);
}
console.log('all passed');

import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import vm from 'node:vm';
import { CheckinQueue } from '../../resources/js/checkin-queue.js';

const source = readFileSync(new URL('../../resources/js/ticketing.js', import.meta.url), 'utf8')
    .replace("import { CheckinQueue } from './checkin-queue';", '');

test('recovers a query typed before search listeners attach without repeating server-rendered searches', async () => {
    for (const [query, url, expected] of [
        ['Anna', 'https://example.test/gestione-biglietti/1', 1],
        ['Anna', 'https://example.test/gestione-biglietti/1?q=Anna', 0],
        ['', 'https://example.test/gestione-biglietti/1', 0],
    ]) {
        const requests = [];
        let replaced = false;
        const status = { textContent: '' };
        const form = { action: url, addEventListener() {} };
        vm.runInNewContext(source, {
            CheckinQueue,
            document: {
                querySelectorAll: selector => selector === '[data-ticket-search]' ? [form] : [],
                querySelector: selector => selector === '[data-search-status]' ? status : { replaceWith() { replaced = true; } },
                addEventListener() {},
            },
            location: { href: url }, history: { replaceState() {} },
            URL, URLSearchParams, AbortController,
            FormData: class { get() { return query; } *[Symbol.iterator]() { yield ['q', query]; } },
            DOMParser: class { parseFromString() { return { querySelector: () => ({}) }; } },
            fetch: async url => { requests.push(String(url)); return { ok: true, text: async () => '<html></html>' }; },
        });
        await new Promise(setImmediate);
        assert.equal(requests.length, expected);
        assert.equal(replaced, expected === 1);
        if (expected) assert.equal(new URL(requests[0]).searchParams.get('q'), query);
    }
});

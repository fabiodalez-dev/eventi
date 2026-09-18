import { test } from 'node:test';
import assert from 'node:assert/strict';
import { contentAnalytics } from '../../resources/js/content-analytics.js';
import { nativeShare } from '../../resources/js/native-share.js';

function analyticsHarness(allowed = true) {
    const listeners = {};
    const calls = [];
    globalThis.location = { href: 'https://example.test/eventi/concerto/1', origin: 'https://example.test' };
    globalThis.document = {
        hidden: false,
        querySelector: selector => selector.includes('csrf') ? { content: 'csrf-test' } : { dataset: { allowed: allowed ? '1' : '0', url: '/misure/event/1' } },
        addEventListener: (name, fn) => { listeners[name] = fn; },
    };
    globalThis.fetch = async (url, options) => { calls.push({ url, options, metric: JSON.parse(options.body).metric }); };
    contentAnalytics();
    calls.length = 0;
    return { listeners, calls };
}

test('short-link share actions use their own endpoint exactly once, never website or email clicks', () => {
    const { listeners, calls } = analyticsHarness();
    for (const href of ['https://wa.me/', 'https://t.me/share/url', 'mailto:test']) {
        const link = { href, dataset: { shareMetric: '/s/Ab3kP9x/share' }, closest: () => true, hasAttribute: name => name === 'data-share-channel' };
        listeners.click({ target: { closest: () => link } });
    }
    listeners['content:shared']({ detail: { metric: '/s/Native1/share' } });
    assert.equal(calls.length, 4);
    assert.ok(calls.every(call => call.url.startsWith('/s/') && call.metric === 'shares'));
    assert.ok(calls.every(call => call.options.keepalive && call.options.headers['X-CSRF-TOKEN'] === 'csrf-test'));
});

test('sharing obeys consent changes, and ordinary profile shares retain their counter', () => {
    const { listeners, calls } = analyticsHarness(false);
    listeners['content:shared']({ detail: { metric: '/s/Native1/share' } });
    assert.equal(calls.length, 0);
    listeners['consent:changed']({ detail: { statistics: true } });
    listeners['content:shared']({});
    assert.deepEqual(calls.map(c => c.metric), ['views', 'shares']);
    assert.equal(calls[1].url, '/misure/event/1');
    listeners['consent:changed']({ detail: { statistics: false } });
    listeners['content:shared']({});
    assert.equal(calls.length, 2);
});

function shareHarness(navigator) {
    let click;
    const dispatched = [];
    const label = { textContent: 'Condividi' };
    const status = { textContent: '' };
    globalThis.HTMLElement = class {};
    const button = Object.assign(new HTMLElement(), {
        dataset: { shareUrl: 'https://example.test/s/Native1', shareTitle: 'Un concerto', shareText: 'Una serata', shareMetric: '/s/Native1/share', shareCopied: 'Link copiato' },
        classList: { remove() {} },
        addEventListener: (_, callback) => { click = callback; },
        querySelector: () => label,
        parentElement: { querySelector: () => status },
    });
    globalThis.document = { querySelectorAll: () => [button], dispatchEvent: event => dispatched.push(event) };
    globalThis.window = { setTimeout() {}, location: { href: 'https://example.test/eventi/originale' } };
    globalThis.CustomEvent = class { constructor(type, options) { this.type = type; this.detail = options.detail; } };
    Object.defineProperty(globalThis, 'navigator', { configurable: true, value: navigator });
    nativeShare();
    return { click: () => click(), dispatched, label, status };
}

test('native sharing receives the short URL synchronously, counting only after success', async () => {
    let resolve;
    let payload;
    const harness = shareHarness({ share: data => { payload = data; return new Promise(done => { resolve = done; }); } });
    const pending = harness.click();
    assert.equal(payload.url, 'https://example.test/s/Native1');
    assert.equal(payload.title, 'Un concerto');
    assert.equal(harness.dispatched.length, 0);
    resolve();
    await pending;
    assert.equal(harness.dispatched.length, 1);
    assert.equal(harness.dispatched[0].detail.metric, '/s/Native1/share');
});

test('cancelled native sharing and failed clipboard writes do not count', async () => {
    for (const navigator of [{ share: async () => { throw new Error('AbortError'); } }, { clipboard: { writeText: async () => { throw new Error('NotAllowedError'); } } }]) {
        const harness = shareHarness(navigator);
        await harness.click();
        assert.equal(harness.dispatched.length, 0);
    }
});

test('clipboard fallback copies the short URL and announces successful copying', async () => {
    let copied;
    const harness = shareHarness({ clipboard: { writeText: async text => { copied = text; } } });
    await harness.click();
    assert.equal(copied, 'https://example.test/s/Native1');
    assert.equal(harness.dispatched.length, 1);
    assert.equal(harness.status.textContent, 'Link copiato');
});

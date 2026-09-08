import { test } from 'node:test';
import assert from 'node:assert/strict';
import { sponsorshipBanners } from '../../resources/js/sponsorship-banner.js';

const flush = () => new Promise(resolve => setImmediate(resolve));
function fixture(t) {
    const node = () => ({ hidden: false, listeners: {}, addEventListener(name, fn) { this.listeners[name] = fn; }, getAttribute(name) { return this[name]; } });
    const link = node();
    const picture = { ...node(), previousElementSibling: node() };
    const fields = Object.fromEntries(['title', 'when', 'place', 'by'].map(key => [`[data-banner-${key}]`, node()]));
    const slot = { ...node(), hidden: true, dataset: { endpoint: '/banner', metricBase: '/metrics' }, querySelector: selector => ({ a: link, img: picture, ...fields })[selector] };
    const calls = [];
    const intervals = [];
    const expiries = [];
    let observer;
    let data = { id: 1, title: '<script>test</script>', when: 'Oggi', price: 'Gratis', place: 'Teatro', category: 'Musica', advertiser: 'Teatro', image: '/broken.jpg', url: '/eventi/prova', expires_at: new Date(Date.now() + 60000).toISOString(), metric_token: 'test' };
    globalThis.document = { hidden: false, querySelectorAll: () => [slot], addEventListener() {} };
    globalThis.window = { location: { pathname: '/' }, addEventListener() {} };
    globalThis.IntersectionObserver = class {
        constructor(callback) { observer = callback; }
        observe() {} unobserve() {}
    };
    t.after(() => { delete globalThis.document; delete globalThis.window; delete globalThis.IntersectionObserver; });
    t.mock.method(globalThis, 'setInterval', fn => intervals.push(fn));
    t.mock.method(globalThis, 'setTimeout', fn => expiries.push(fn));
    t.mock.method(globalThis, 'clearTimeout', () => {});
    t.mock.method(globalThis, 'fetch', async (url, options) => { calls.push({ url, options }); return { ok: true, json: async () => ({ data }) }; });
    sponsorshipBanners();
    return { slot, picture, fields, link, calls, intervals, expiries, setData(value) { data = value; }, visible() { observer([{ isIntersecting: true }]); } };
}

test('renders text safely and counts only visible impressions once', async t => {
    const f = fixture(t); await flush();
    assert.equal(f.slot.hidden, false);
    assert.equal(f.fields['[data-banner-title]'].textContent, '<script>test</script>');
    assert.equal(f.calls.length, 1);
    f.visible(); f.visible(); await flush();
    assert.equal(f.calls.filter(call => call.url.endsWith('/impressions')).length, 1);
});

test('records separate clicks with different delivery identifiers', async t => {
    const f = fixture(t); await flush();
    f.link.listeners.click({ preventDefault() {} });
    f.link.listeners.click({ preventDefault() {} });
    const clicks = f.calls.filter(call => call.url.endsWith('/clicks'));
    assert.equal(clicks.length, 2);
    const first = JSON.parse(clicks[0].options.body);
    const second = JSON.parse(clicks[1].options.body);
    assert.notEqual(first.click_id, second.click_id);
    assert.equal(first.page, 'home');
    assert.equal(first.placement, 'banner');
});

test('keeps the placeholder after a failed image even across live refreshes', async t => {
    const f = fixture(t); await flush();
    f.picture.listeners.error();
    assert.equal(f.picture.hidden, true);
    f.intervals[0](); await flush();
    assert.equal(f.picture.hidden, true);
    assert.equal(f.picture.previousElementSibling.hidden, false);
});

test('backend off and lease expiry both remove the banner', async t => {
    const f = fixture(t); await flush();
    f.expiries[0]();
    assert.equal(f.slot.hidden, true);
    let prevented = false;
    f.link.listeners.click({ preventDefault() { prevented = true; } });
    assert.equal(prevented, true);
    f.setData(null); f.intervals[0](); await flush();
    assert.equal(f.slot.hidden, true);
});

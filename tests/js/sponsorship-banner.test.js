import { test } from 'node:test';
import assert from 'node:assert/strict';
import { sponsorshipBanners } from '../../resources/js/sponsorship-banner.js';

const flush = () => new Promise(resolve => setImmediate(resolve));
function fixture(t, { impressionToken = null, viewableMs = null, webMetricBase = null } = {}) {
    const node = () => ({ hidden: false, listeners: {}, addEventListener(name, fn) { this.listeners[name] = fn; }, getAttribute(name) { return this[name]; } });
    const link = node();
    const picture = { ...node(), previousElementSibling: node() };
    const fields = Object.fromEntries(['title', 'when', 'place', 'by'].map(key => [`[data-banner-${key}]`, node()]));
    const slot = { ...node(), hidden: true, dataset: { endpoint: '/banner', metricBase: '/metrics', ...(viewableMs ? { viewableMs: String(viewableMs) } : {}), ...(webMetricBase ? { webMetricBase } : {}) }, querySelector: selector => ({ a: link, img: picture, ...fields })[selector] };
    const calls = [];
    const intervals = [];
    const timers = [];
    const documentListeners = {};
    let observer;
    let data = { id: 1, title: '<script>test</script>', when: 'Oggi', price: 'Gratis', place: 'Teatro', category: 'Musica', advertiser: 'Teatro', image: '/broken.jpg', url: '/eventi/prova', expires_at: new Date(Date.now() + 60000).toISOString(), metric_token: 'test', ...(impressionToken ? { impression_token: impressionToken } : {}) };
    globalThis.document = { hidden: false, querySelectorAll: () => [slot], querySelector: selector => (selector === 'meta[name="csrf-token"]' ? { content: 'csrf-di-prova' } : null), addEventListener(name, fn) { (documentListeners[name] ??= []).push(fn); } };
    globalThis.window = { location: { pathname: '/' }, addEventListener() {} };
    globalThis.IntersectionObserver = class {
        constructor(callback) { observer = callback; }
        observe() {} unobserve() {}
    };
    t.after(() => { delete globalThis.document; delete globalThis.window; delete globalThis.IntersectionObserver; });
    t.mock.method(globalThis, 'setInterval', fn => intervals.push(fn));
    t.mock.method(globalThis, 'setTimeout', (fn, ms) => timers.push({ fn, ms, cleared: false }));
    t.mock.method(globalThis, 'clearTimeout', id => { if (timers[id - 1]) timers[id - 1].cleared = true; });
    t.mock.method(globalThis, 'fetch', async (url, options) => { calls.push({ url, options }); return { ok: true, json: async () => ({ data }) }; });
    sponsorshipBanners();
    const pending = ms => timers.filter(timer => !timer.cleared && !timer.ran && (ms === undefined || timer.ms === ms));
    const run = timer => { timer.ran = true; timer.fn(); };

    return {
        slot, picture, fields, link, calls, intervals, documentListeners,
        /* La scadenza del banner: l'unico timer che non è il secondo di permanenza. */
        get expiries() { return pending().filter(timer => timer.ms > 10000).map(timer => () => run(timer)); },
        setData(value) { data = value; },
        visible(ratio = 1) { observer([{ target: slot, isIntersecting: ratio > 0, intersectionRatio: ratio, intersectionRect: { height: 100 * ratio }, rootBounds: { height: 800 } }]); },
        elapseSecond() { pending(1000).forEach(run); },
        elapse(ms) { pending(ms).forEach(run); },
    };
}

test('renders text safely and counts only visible impressions once', async t => {
    const f = fixture(t); await flush();
    assert.equal(f.slot.hidden, false);
    assert.equal(f.fields['[data-banner-title]'].textContent, '<script>test</script>');
    assert.equal(f.calls.length, 1);
    f.visible(); f.elapseSecond(); f.visible(); f.elapseSecond(); await flush();
    assert.equal(f.calls.filter(call => call.url.endsWith('/impressions')).length, 1);
});

test('counts the banner only after one continuous second at half visibility', async t => {
    const f = fixture(t); await flush();
    f.visible();
    assert.equal(f.calls.filter(call => call.url.endsWith('/impressions')).length, 0);
    // Scorre via prima del secondo: il conto riparte da zero.
    f.visible(0); f.elapseSecond();
    assert.equal(f.calls.filter(call => call.url.endsWith('/impressions')).length, 0);
    // Meno di metà nello schermo non basta.
    f.visible(0.3); f.elapseSecond();
    assert.equal(f.calls.filter(call => call.url.endsWith('/impressions')).length, 0);
    f.visible(0.6); f.elapseSecond();
    assert.equal(f.calls.filter(call => call.url.endsWith('/impressions')).length, 1);
});

test('uses the viewability time the page declares', async t => {
    const f = fixture(t, { viewableMs: 2000 }); await flush();
    f.visible();
    f.elapseSecond();
    assert.equal(f.calls.filter(call => call.url.endsWith('/impressions')).length, 0, 'one second is not enough when the page asks for two');
    f.elapse(2000);
    assert.equal(f.calls.filter(call => call.url.endsWith('/impressions')).length, 1);
});

test('sends the one-time token next to the daily one when the server provides it', async t => {
    const f = fixture(t, { impressionToken: 'v1.1.banner.1.abcdef0123456789.firma' }); await flush();
    f.visible(); f.elapseSecond();
    const [impression] = f.calls.filter(call => call.url.endsWith('/impressions'));
    assert.equal(impression.options.headers['X-Impression-Token'], 'v1.1.banner.1.abcdef0123456789.firma');
    assert.equal(impression.options.headers['X-Metric-Token'], 'test');
});

test('with the one-time token on the site it counts through the web route, with the session', async t => {
    const f = fixture(t, { impressionToken: 'v1.1.banner.1.abcdef0123456789.firma', webMetricBase: '/sponsorizzazioni' }); await flush();
    f.visible(); f.elapseSecond();
    f.link.listeners.click({ preventDefault() {} });
    const impression = f.calls.find(call => call.url === '/sponsorizzazioni/1/impressions');
    const click = f.calls.find(call => call.url === '/sponsorizzazioni/1/clicks');
    assert.ok(impression && click);
    assert.equal(impression.options.credentials, 'same-origin');
    assert.equal(impression.options.headers['X-CSRF-TOKEN'], 'csrf-di-prova');
    assert.equal(impression.options.headers['X-Impression-Token'], 'v1.1.banner.1.abcdef0123456789.firma');
    assert.equal(impression.options.headers['X-Metric-Token'], undefined);
    assert.equal(f.calls.filter(call => call.url.includes('/metrics/')).length, 0);
});

test('without the one-time token it keeps the API route with the daily token', async t => {
    const f = fixture(t, { webMetricBase: '/sponsorizzazioni' }); await flush();
    f.visible(); f.elapseSecond();
    const [impression] = f.calls.filter(call => call.url.endsWith('/impressions'));
    assert.equal(impression.url, '/metrics/1/metrics/impressions');
    assert.equal(impression.options.headers['X-Metric-Token'], 'test');
});

test('a click before the second carries the view: no impression is sent afterwards', async t => {
    const f = fixture(t, { impressionToken: 'v1.1.banner.1.abcdef0123456789.firma' }); await flush();
    f.visible();
    f.link.listeners.click({ preventDefault() {} });
    f.elapseSecond();
    assert.equal(f.calls.filter(call => call.url.endsWith('/clicks')).length, 1);
    assert.equal(f.calls.filter(call => call.url.endsWith('/impressions')).length, 0);
});

test('sends nothing when the page is driven by automation', async t => {
    const original = Object.getOwnPropertyDescriptor(globalThis, 'navigator');
    Object.defineProperty(globalThis, 'navigator', { value: { webdriver: true }, configurable: true });
    t.after(() => Object.defineProperty(globalThis, 'navigator', original));
    const f = fixture(t); await flush();
    assert.equal(f.slot.hidden, false);
    f.visible(); f.elapseSecond();
    f.link.listeners.click({ preventDefault() {} });
    assert.equal(f.calls.filter(call => call.url.includes('/metrics/')).length, 0);
});

test('hides the current event without counting an impression even if returned by the server', async t => {
    const f = fixture(t);
    f.slot.dataset.excludeEvent = 'evento-aperto';
    f.setData({ id: 9, event_slug: 'evento-aperto', expires_at: new Date(Date.now() + 60000).toISOString() });
    await flush();
    assert.equal(f.slot.hidden, true);
    f.visible();
    assert.equal(f.calls.filter(call => call.url.endsWith('/impressions')).length, 0);
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

test('shares one in-flight backend request across equal slots', async t => {
    const makeNode = () => ({ hidden: false, addEventListener() {}, getAttribute() { return null; } });
    const makeSlot = () => {
        const link = makeNode();
        const picture = { ...makeNode(), previousElementSibling: makeNode() };
        const fields = Object.fromEntries(['title', 'when', 'place', 'by'].map(key => [`[data-banner-${key}]`, makeNode()]));

        return {
            ...makeNode(),
            hidden: true,
            dataset: { endpoint: '/shared-banner', metricBase: '/metrics' },
            querySelector: selector => ({ a: link, img: picture, ...fields })[selector],
        };
    };
    const slots = [makeSlot(), makeSlot()];
    const calls = [];
    let resolveRequest;
    const response = new Promise(resolve => { resolveRequest = resolve; });

    globalThis.document = { hidden: false, querySelectorAll: () => slots, addEventListener() {} };
    globalThis.window = { location: { pathname: '/' }, addEventListener() {} };
    globalThis.IntersectionObserver = class { observe() {} unobserve() {} };
    t.after(() => { delete globalThis.document; delete globalThis.window; delete globalThis.IntersectionObserver; });
    t.mock.method(globalThis, 'setInterval', () => 1);
    t.mock.method(globalThis, 'setTimeout', () => 1);
    t.mock.method(globalThis, 'clearTimeout', () => {});
    t.mock.method(globalThis, 'fetch', url => { calls.push(url); return response; });

    sponsorshipBanners();
    assert.equal(calls.length, 1);

    resolveRequest({ ok: true, json: async () => ({ data: null }) });
    await flush();
    assert.equal(slots.every(slot => slot.hidden), true);
});

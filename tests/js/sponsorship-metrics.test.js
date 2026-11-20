import { test } from 'node:test';
import assert from 'node:assert/strict';
import { halfVisible, sponsorshipMetrics, VIEWABLE_MS } from '../../resources/js/sponsorship-metrics.js';

/*
 * Le misure delle card sponsorizzate nel browser: vista a metà per un secondo
 * di fila, scheda visibile, gettone usa e getta, nessun identificativo salvato.
 */

const TOKEN = 'v1.7.home_card.29876543.0123456789abcdef.firma-di-prova-firma-di-prova-firma-di-p';

function element(dataset, listeners = {}) {
    return {
        dataset,
        listeners,
        addEventListener(name, fn) { (this.listeners[name] ??= []).push(fn); },
        fire(name, event) { for (const fn of this.listeners[name] ?? []) fn(event); },
    };
}

function card(extra = {}) {
    return element({
        sponsorship: '7',
        sponsorshipPlacement: 'home_card',
        sponsorshipImpression: '/sponsorizzazioni/7/impressions',
        sponsorshipClick: '/sponsorizzazioni/7/clicks',
        sponsorshipToken: TOKEN,
        ...extra,
    });
}

const OPPORTUNITY_TOKEN = 'o1.3.list_top.29000000.0123456789abcdef.firmadelloccasionefirmadelloccasionefirmadel';

function opportunity(extra = {}) {
    return element({ sponsorshipOpportunity: 'list_top', sponsorshipCity: '3', sponsorshipOpportunityToken: OPPORTUNITY_TOKEN, ...extra });
}

/** Una pagina finta: elementi, osservatore, orologio, rete, e niente memoria del dispositivo. */
function page(t, { cards = [], spaces = [], webdriver = false, automation = false, hidden = false, prerendering = false } = {}) {
    const calls = [];
    const timers = [];
    const docListeners = {};
    const touched = [];
    let observer;
    let observed = 0;

    const doc = {
        hidden,
        visibilityState: hidden ? 'hidden' : 'visible',
        prerendering,
        addEventListener(name, fn) { (docListeners[name] ??= []).push(fn); },
        querySelector: selector => (selector === 'meta[name="csrf-token"]' ? { content: 'csrf-di-prova' } : null),
        querySelectorAll: selector => (selector === '[data-sponsorship]' ? cards : selector === '[data-sponsorship-opportunity]' ? spaces : []),
        get cookie() { touched.push('cookie:get'); return ''; },
        set cookie(value) { touched.push(`cookie:set:${value}`); },
    };
    const storage = new Proxy({}, { get: (_, key) => { touched.push(`localStorage.${String(key)}`); return () => null; } });

    globalThis.document = doc;
    globalThis.window = { location: { pathname: '/' }, addEventListener() {} };
    globalThis.localStorage = storage;
    globalThis.sessionStorage = storage;
    globalThis.IntersectionObserver = class {
        constructor(callback) { observer = callback; }
        observe() { observed++; }
        unobserve() {}
    };
    const originalNavigator = Object.getOwnPropertyDescriptor(globalThis, 'navigator');
    Object.defineProperty(globalThis, 'navigator', { value: { webdriver }, configurable: true });
    if (automation) globalThis.__sponsorshipCountAutomation = true;
    t.after(() => {
        for (const key of ['document', 'window', 'localStorage', 'sessionStorage', 'IntersectionObserver', '__sponsorshipCountAutomation']) delete globalThis[key];
        Object.defineProperty(globalThis, 'navigator', originalNavigator);
    });
    t.mock.method(globalThis, 'setTimeout', (fn, ms) => timers.push({ fn, ms, cleared: false, ran: false }));
    t.mock.method(globalThis, 'clearTimeout', id => { if (timers[id - 1]) timers[id - 1].cleared = true; });
    t.mock.method(globalThis, 'fetch', (url, options) => { calls.push({ url, options, body: JSON.parse(options.body) }); return Promise.resolve({ ok: true }); });

    const pending = () => timers.filter(timer => !timer.cleared && !timer.ran);

    return {
        calls,
        timers,
        touched,
        get observed() { return observed; },
        start() { sponsorshipMetrics(); },
        see(target, ratio = 1) {
            observer([{ target, isIntersecting: ratio > 0, intersectionRatio: ratio, intersectionRect: { height: 300 * ratio }, rootBounds: { height: 800 } }]);
        },
        /* Lascia passare il secondo: scattano i timer ancora armati. */
        elapse() { for (const timer of pending()) { timer.ran = true; timer.fn(); } },
        setHidden(value) {
            doc.hidden = value;
            doc.visibilityState = value ? 'hidden' : 'visible';
            for (const fn of docListeners.visibilitychange ?? []) fn();
        },
        finishPrerender() { doc.prerendering = false; for (const fn of docListeners.prerenderingchange ?? []) fn(); },
        impressions() { return calls.filter(call => call.url.endsWith('/impressions')); },
        clicks() { return calls.filter(call => call.url.endsWith('/clicks')); },
    };
}

const sponsoredLink = { target: { closest: selector => (selector === 'a[rel~="sponsored"]' ? {} : null) } };

test('counts a card only after one continuous second at half visibility', t => {
    const c = card();
    const p = page(t, { cards: [c] });
    p.start();

    p.see(c);
    assert.equal(p.impressions().length, 0, 'nothing is sent before the second');
    assert.equal(p.timers.at(-1).ms, VIEWABLE_MS);
    p.elapse();
    assert.equal(p.impressions().length, 1);
});

test('scrolling past before the second does not count, and the timer starts over', t => {
    const c = card();
    const p = page(t, { cards: [c] });
    p.start();

    p.see(c);
    p.see(c, 0.4); // meno di metà: il conto si ferma
    p.elapse();
    assert.equal(p.impressions().length, 0);

    p.see(c);
    p.see(c, 0);
    p.elapse();
    assert.equal(p.impressions().length, 0);

    p.see(c, 0.5);
    p.elapse();
    assert.equal(p.impressions().length, 1);
});

test('a hidden tab never counts, and counts again from zero when it comes back', t => {
    const c = card();
    const p = page(t, { cards: [c] });
    p.start();

    p.see(c);
    p.setHidden(true);
    p.elapse();
    assert.equal(p.impressions().length, 0, 'the tab went to the background before the second');

    p.setHidden(false);
    assert.equal(p.impressions().length, 0, 'coming back starts a new second, it does not count at once');
    p.elapse();
    assert.equal(p.impressions().length, 1);
});

test('a tab opened in the background waits until it is shown', t => {
    const c = card();
    const p = page(t, { cards: [c], hidden: true });
    p.start();
    p.see(c);
    p.elapse();
    assert.equal(p.impressions().length, 0);
    p.setHidden(false);
    p.elapse();
    assert.equal(p.impressions().length, 1);
});

test('a prerendered page waits for activation', t => {
    const c = card();
    const p = page(t, { cards: [c], prerendering: true });
    p.start();
    p.see(c);
    p.elapse();
    assert.equal(p.impressions().length, 0);
    p.finishPrerender();
    p.elapse();
    assert.equal(p.impressions().length, 1);
});

test('sends the one-time token in its header, with CSRF and keepalive, once per card', t => {
    const c = card();
    const p = page(t, { cards: [c] });
    p.start();
    p.see(c); p.elapse();
    p.see(c, 0); p.see(c); p.elapse();

    assert.equal(p.impressions().length, 1);
    const [call] = p.impressions();
    assert.equal(call.url, '/sponsorizzazioni/7/impressions');
    assert.equal(call.options.method, 'POST');
    assert.equal(call.options.keepalive, true);
    assert.equal(call.options.headers['X-Impression-Token'], TOKEN);
    assert.equal(call.options.headers['X-CSRF-TOKEN'], 'csrf-di-prova');
    assert.deepEqual(call.body, { placement: 'home_card', page: 'home' });
});

test('a card without a token sends nothing: the server would not count it', t => {
    const c = card({ sponsorshipToken: undefined });
    const p = page(t, { cards: [c] });
    p.start();
    c.fire('click', sponsoredLink);
    assert.equal(p.observed, 0);
    assert.equal(p.calls.length, 0);
});

test('a click before the view sends the click once and no impression afterwards', t => {
    const c = card();
    const p = page(t, { cards: [c] });
    p.start();

    p.see(c);
    c.fire('click', sponsoredLink);
    c.fire('auxclick', { ...sponsoredLink, button: 1 });
    p.elapse();

    assert.equal(p.clicks().length, 1);
    assert.equal(p.impressions().length, 0);
    const [click] = p.clicks();
    assert.equal(click.options.headers['X-Impression-Token'], TOKEN);
    assert.match(click.body.click_id, /^[0-9a-f-]{36}$/);
});

test('a click outside the sponsored link is not a click on the sponsorship', t => {
    const c = card();
    const p = page(t, { cards: [c] });
    p.start();
    c.fire('click', { target: { closest: () => null } });
    assert.equal(p.clicks().length, 0);
});

test('automation sends nothing, unless a browser test asks for it explicitly', t => {
    const c = card();
    const s = opportunity();
    const p = page(t, { cards: [c], spaces: [s], webdriver: true });
    p.start();
    assert.equal(p.observed, 0);
    c.fire('click', sponsoredLink);
    assert.equal(p.calls.length, 0);
});

test('a browser test can opt in to counting under automation', t => {
    const c = card();
    const p = page(t, { cards: [c], webdriver: true, automation: true });
    p.start();
    p.see(c); p.elapse();
    assert.equal(p.impressions().length, 1);
});

test('an unsold space sends the opportunity signal with the same viewability rule', t => {
    const s = opportunity();
    const p = page(t, { spaces: [s] });
    p.start();

    p.see(s);
    assert.equal(p.calls.length, 0);
    p.elapse();
    p.see(s, 0); p.see(s); p.elapse();

    assert.equal(p.calls.length, 1);
    const [call] = p.calls;
    assert.equal(call.url, '/sponsorizzazioni/occasione');
    assert.deepEqual(call.body, { city: 3, placement: 'list_top', token: OPPORTUNITY_TOKEN });
    assert.equal(call.options.headers['X-Impression-Token'], undefined);
});

test('an unsold space without its opportunity token sends nothing', t => {
    const s = opportunity({ sponsorshipOpportunityToken: undefined });
    const p = page(t, { spaces: [s] });
    p.start();
    p.see(s); p.elapse();
    assert.equal(p.calls.length, 0);
});

test('a space without its city is not observed', t => {
    const s = opportunity({ sponsorshipCity: undefined });
    const p = page(t, { spaces: [s] });
    p.start();
    assert.equal(p.observed, 0);
});

test('calling it again after new content does not watch the same card twice', t => {
    const c = card();
    const p = page(t, { cards: [c] });
    p.start();
    p.start();
    assert.equal(p.observed, 1);
    p.see(c); p.elapse();
    assert.equal(p.impressions().length, 1);
});

test('never writes anything on the device: no cookie, no localStorage, no sessionStorage', t => {
    const c = card();
    const s = opportunity();
    const p = page(t, { cards: [c], spaces: [s] });
    p.start();
    p.see(c); p.see(s); p.elapse();
    c.fire('click', sponsoredLink);

    assert.equal(p.calls.length, 3);
    assert.deepEqual(p.touched, []);
});

test('an element taller than the screen counts when it covers half of it', () => {
    const tall = { isIntersecting: true, intersectionRatio: 0.3, intersectionRect: { height: 500 }, rootBounds: { height: 800 } };
    const thin = { isIntersecting: true, intersectionRatio: 0.3, intersectionRect: { height: 90 }, rootBounds: { height: 800 } };
    assert.equal(halfVisible(tall), true);
    assert.equal(halfVisible(thin), false);
    assert.equal(halfVisible({ isIntersecting: false, intersectionRatio: 0 }), false);
});

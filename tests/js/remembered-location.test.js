import { test } from 'node:test';
import assert from 'node:assert/strict';
import { rememberedLocation } from '../../resources/js/remembered-location.js';

function setup({ stored = '', permission = 'granted', denied = false, failed = false } = {}) {
    const handlers = {};
    const button = name => ({ disabled: false, hidden: false, addEventListener: (_, fn) => { handlers[name] = fn; } });
    const use = button('use'), forget = button('forget'), status = { textContent: 'fallback' };
    const calls = { gps: 0, reload: 0, requests: [] };
    const panel = { dataset: { position: stored, endpoint: '/posizione-ricordata', loading: 'loading', failed: 'failed', saved: 'saved', unavailable: 'unavailable' },
        querySelector: selector => selector === '[role="status"]' ? status : selector === '[data-location-use]' ? use : forget };
    globalThis.document = { querySelector: selector => selector === '[data-remembered-location]' ? panel : { content: 'csrf' } };
    globalThis.window = { location: { reload: () => calls.reload++ } };
    Object.defineProperty(globalThis, 'navigator', { configurable: true, value: {
        permissions: { query: async () => ({ state: permission }) },
        geolocation: { getCurrentPosition: (ok, fail) => { calls.gps++; denied ? fail({ code: 1 }) : ok({ coords: { latitude: 45.406733, longitude: 11.876814 } }); } },
    } });
    globalThis.fetch = async (_, request) => {
        calls.requests.push(request);
        return { ok: !failed, json: async () => ({ data: { lat: 45.41, lng: 11.88 } }) };
    };
    return { calls, handlers, status, use, forget };
}
const settle = () => new Promise(resolve => setImmediate(resolve));

test('never prompts or stores automatically without remembering consent', async () => {
    const { calls } = setup(); rememberedLocation(); await settle();
    assert.equal(calls.gps, 0); assert.equal(calls.requests.length, 0);
});
test('first explicit action obtains position and persists with consent and CSRF', async () => {
    const { handlers, calls } = setup(); rememberedLocation(); await handlers.use();
    assert.equal(calls.gps, 1); assert.equal(calls.reload, 1);
    assert.equal(JSON.parse(calls.requests[0].body).remember, true);
    assert.equal(calls.requests[0].headers['X-CSRF-TOKEN'], 'csrf');
});
test('refreshes granted remembered position without an endless reload', async () => {
    const { calls, status } = setup({ stored: '45.41,11.88' }); rememberedLocation(); await settle();
    assert.equal(calls.gps, 1); assert.equal(calls.requests.length, 1); assert.equal(calls.reload, 0);
    assert.equal(status.textContent, 'saved');
});
test('denied or prompt permission keeps server-rendered fallback without another request', async () => {
    for (const permission of ['denied', 'prompt']) {
        const { calls, status } = setup({ stored: '45.41,11.88', permission }); rememberedLocation(); await settle();
        assert.equal(calls.gps, 0); assert.equal(calls.reload, 0); assert.equal(status.textContent, 'fallback');
    }
});
test('unavailable current position does not overwrite remembered coordinates', async () => {
    const { calls, status } = setup({ stored: '45.41,11.88', denied: true }); rememberedLocation(); await settle();
    assert.equal(calls.requests.length, 0); assert.equal(calls.reload, 0); assert.equal(status.textContent, 'unavailable');
});
test('forget deletes remembered location and reloads default without a new GPS request', async () => {
    const { calls, handlers } = setup({ stored: '45.41,11.88', permission: 'denied' }); rememberedLocation(); await settle();
    await handlers.forget(); assert.equal(calls.requests[0].method, 'DELETE'); assert.equal(calls.gps, 0); assert.equal(calls.reload, 1);
});
test('network failure leaves controls available and avoids reload loops', async () => {
    const { calls, handlers, status, use } = setup({ failed: true }); rememberedLocation(); await handlers.use();
    assert.equal(status.textContent, 'failed'); assert.equal(calls.reload, 0); assert.equal(use.disabled, false);
});

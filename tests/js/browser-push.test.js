import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import vm from 'node:vm';

const source = readFileSync(new URL('../../resources/js/push.js', import.meta.url), 'utf8');
const flush = () => new Promise(resolve => setImmediate(resolve));
function fixture({ permission = 'default', existing = false, serverActive = false, fail = false, supported = true } = {}) {
    const node = () => ({ checked: false, disabled: false, listeners: {}, addEventListener(name, fn) { this.listeners[name] = fn; } });
    const toggle = { ...node(), checked: serverActive };
    const status = node(), reset = node(), preview = node(), help = node();
    const fields = { '[data-push-toggle]': toggle, '[data-push-status]': status, '[data-push-reset]': reset, '[data-push-test]': preview, '[data-push-help]': help };
    const calls = [];
    const section = { dataset: { push: 'AQID', pushOn: 'on', pushOff: 'off', pushDenied: 'denied', pushFailed: 'failed', pushUnsupported: 'unsupported' }, querySelector: s => fields[s] };
    const makeSubscription = () => ({ endpoint: 'https://push.example.test/current', options: { applicationServerKey: new Uint8Array([1, 2, 3]).buffer }, toJSON() { return { endpoint: this.endpoint, keys: { p256dh: 'key', auth: 'auth' } }; }, async unsubscribe() { calls.push('unsubscribe'); subscription = null; return true; } });
    let subscription = existing ? makeSubscription() : null;
    const registration = { pushManager: { async getSubscription() { return subscription; }, async subscribe() { calls.push('subscribe'); subscription = makeSubscription(); return subscription; } }, async showNotification(title) { calls.push({ title }); } };
    const notifications = { permission, async requestPermission() { calls.push('permission'); return this.permission; } };
    const window = { isSecureContext: supported, Notification: notifications, PushManager: class {}, atob, addEventListener() {} };
    const context = { window, Notification: notifications, navigator: { serviceWorker: { async getRegistration() { return registration; }, async register() { calls.push('register'); return registration; }, ready: Promise.resolve(registration) } }, document: { querySelector: s => s === '[data-push]' ? section : null, addEventListener() {} }, HTMLMetaElement: class {}, Uint8Array, location: { href: 'https://example.test/notifiche' }, fetch: async (url, options) => { calls.push({ method: options.method, body: JSON.parse(options.body) }); return { ok: !fail, status: fail ? 500 : 201 }; } };
    vm.runInNewContext(source, context);
    return { toggle, status, reset, preview, help, calls, notifications };
}

test('a denied permission can be retried after browser settings change', async () => {
    const f = fixture({ permission: 'denied' }); await flush();
    assert.equal(f.toggle.disabled, true);
    assert.equal(f.reset.disabled, false);
    assert.equal(f.help.open, true);
    assert.equal(f.calls.length, 0);
    f.notifications.permission = 'granted';
    await f.reset.listeners.click();
    assert.equal(f.calls[0], 'permission');
    assert.equal(f.toggle.checked, true);
    assert.equal(f.toggle.disabled, false);
    assert.equal(f.status.textContent, 'on');
    assert.equal(f.calls.some(c => c.method === 'DELETE'), false);
});

test('reinitialization revokes only the current endpoint and resubscribes', async () => {
    const f = fixture({ permission: 'granted', existing: true, serverActive: true }); await flush();
    f.calls.length = 0;
    await f.reset.listeners.click();
    assert.equal(f.calls[0], 'permission');
    assert.deepEqual(f.calls.find(c => c.method === 'DELETE').body, { endpoint: 'https://push.example.test/current' });
    assert.equal(f.calls.filter(c => c.method === 'POST').length, 1);
    assert.equal(f.toggle.checked, true);
});

test('an active subscription on another device does not turn this browser on', async () => {
    const f = fixture({ permission: 'granted', serverActive: true }); await flush();
    assert.equal(f.toggle.checked, false);
    assert.equal(f.calls.length, 0);
});

test('denial never subscribes and a failed server registration remains retryable', async () => {
    const denied = fixture({ permission: 'denied' }); await flush();
    await denied.reset.listeners.click();
    assert.deepEqual(denied.calls, ['permission']);
    const failed = fixture({ permission: 'granted', fail: true }); await flush();
    await failed.reset.listeners.click();
    assert.equal(failed.status.textContent, 'failed');
    assert.equal(failed.reset.disabled, false);
    assert.equal(failed.toggle.checked, false);
});

test('the explicit preview displays a notification through the service worker', async () => {
    const f = fixture({ permission: 'granted', existing: true, serverActive: true }); await flush();
    assert.equal(f.preview.disabled, false);
    await f.preview.listeners.click();
    assert.equal(f.calls.some(c => c.title === 'inCittà: notifica di prova'), true);
});

test('an unsupported context offers no unusable permission controls', async () => {
    const f = fixture({ supported: false }); await flush();
    assert.equal(f.reset.disabled, true);
    assert.equal(f.preview.disabled, true);
    assert.equal(f.status.textContent, 'unsupported');
});

test('the worker renders incoming push payloads and opens their event link', async () => {
    const listeners = {}, shown = [], opened = [];
    vm.runInNewContext(readFileSync(new URL('../../public/sw.js', import.meta.url), 'utf8'), { URL, self: { location: { origin: 'https://example.test' }, addEventListener: (name, fn) => { listeners[name] = fn; }, registration: { showNotification: async (title, options) => shown.push({ title, options }) }, clients: { matchAll: async () => [], openWindow: async url => opened.push(url) } } });
    let pending;
    listeners.push({ data: { json: () => ({ title: 'Promemoria', body: 'Il tuo evento', data: { url: 'https://example.test/eventi/prova/1' } }) }, waitUntil: promise => { pending = promise; } });
    await pending;
    assert.equal(shown[0].title, 'Promemoria');
    assert.equal(shown[0].options.body, 'Il tuo evento');
    listeners.notificationclick({ notification: { close() {}, data: shown[0].options.data }, waitUntil: promise => { pending = promise; } });
    await pending;
    assert.deepEqual(opened, ['https://example.test/eventi/prova/1']);
});

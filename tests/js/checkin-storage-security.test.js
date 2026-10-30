import test from 'node:test';
import assert from 'node:assert/strict';
import { CheckinQueue, CheckinManifest, forgetCheckinData } from '../../resources/js/checkin-queue.js';

function memoryStore(initial = null) {
    let value = initial;
    return {
        read: () => value,
        write: (next) => { value = JSON.parse(JSON.stringify(next)); },
        clear: () => { value = null; },
        get value() { return value; },
    };
}

// A localStorage-shaped object: Object.keys() lists the stored keys.
function fakeLocalStorage(entries) {
    const storage = { ...entries };
    Object.defineProperties(storage, {
        getItem: { value: (key) => (key in storage ? storage[key] : null) },
        setItem: { value: (key, value) => { storage[key] = String(value); } },
        removeItem: { value: (key) => { delete storage[key]; } },
    });
    return storage;
}

const hours = (n) => n * 3600000;

test('a settled read no longer keeps the QR code in clear, a pending one does', async () => {
    const store = memoryStore();
    let online = false;
    const queue = new CheckinQueue(async () => {
        if (!online) throw new Error('offline');
        return { accepted: true, message: 'Ada' };
    }, () => 'key', store);
    queue.add('a'.repeat(64));
    await queue.flush();
    assert.equal(store.value[0].code, 'a'.repeat(64));
    online = true;
    await queue.flush();
    assert.equal(store.value[0].state, 'accepted');
    assert.equal('code' in store.value[0], false);
});

test('codes and old results left by an earlier version are dropped on load', () => {
    const now = Date.parse('2026-10-30T12:00:00Z');
    const store = memoryStore([
        { code: 'old', state: 'accepted', message: 'Ada', scannedAt: '2026-10-27T20:00:00Z' },
        { code: 'recent', state: 'rejected', message: 'Bo', scannedAt: '2026-10-30T10:00:00Z' },
        { code: 'waiting', state: 'pending', message: '', scannedAt: '2026-10-20T20:00:00Z' },
    ]);
    const queue = new CheckinQueue(async () => ({ accepted: true }), () => 'key', store, 24, () => now);
    assert.deepEqual(queue.entries.map((entry) => entry.message), ['Bo', '']);
    assert.equal('code' in queue.entries[0], false);
    assert.equal(queue.entries[1].code, 'waiting');
});

test('the attendee list is discarded once the door window and the late-sync margin are over', () => {
    const store = memoryStore();
    const to = Date.parse('2026-10-29T23:00:00Z');
    new CheckinManifest(store, async (t) => t, 24, () => to).load({ generated_at: 'x', window: { to: new Date(to).toISOString() }, tickets: [{ h: 'k', name: 'Ada', status: 'valid' }] });
    assert.equal(new CheckinManifest(store, async (t) => t, 24, () => to + hours(23)).loaded, true);
    const expired = new CheckinManifest(store, async (t) => t, 24, () => to + hours(25));
    assert.equal(expired.loaded, false);
    assert.equal(store.value, null);
});

test('a stored list without a closing time is not trusted', () => {
    const store = memoryStore({ generatedAt: 'x', tickets: { k: { name: 'Ada', status: 'valid' } } });
    assert.equal(new CheckinManifest(store).loaded, false);
    assert.equal(store.value, null);
});

test('clearing the manifest empties the storage too', () => {
    const store = memoryStore();
    const manifest = new CheckinManifest(store);
    manifest.load({ generated_at: 'x', window: { to: new Date(Date.now() + hours(1)).toISOString() }, tickets: [{ h: 'k', name: 'Ada', status: 'valid' }] });
    manifest.clear();
    assert.equal(manifest.loaded, false);
    assert.equal(store.value, null);
});

test('logging out forgets the lists and the settled reads, keeping only unsynced admissions', () => {
    const storage = fakeLocalStorage({
        'checkin:7:manifest': JSON.stringify({ tickets: { k: { name: 'Ada' } } }),
        'checkin:7:queue': JSON.stringify([{ code: 'c1', state: 'pending' }, { state: 'accepted', message: 'Bo' }]),
        'checkin:8:queue': JSON.stringify([{ state: 'rejected', message: 'Cy' }]),
        theme: 'dark',
    });
    forgetCheckinData(storage);
    assert.deepEqual(Object.keys(storage).sort(), ['checkin:7:queue', 'theme']);
    assert.deepEqual(JSON.parse(storage['checkin:7:queue']), [{ code: 'c1', state: 'pending' }]);
});

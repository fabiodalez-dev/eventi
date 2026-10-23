import test from 'node:test';
import assert from 'node:assert/strict';
import { CheckinQueue, CheckinManifest, classifyScan } from '../../resources/js/checkin-queue.js';

function memoryStore(initial = null) {
    let value = initial;
    return { read: () => value, write: (next) => { value = JSON.parse(JSON.stringify(next)); }, get value() { return value; } };
}

test('pending reads survive a reload and keep their original scan time and key', async () => {
    const store = memoryStore();
    const first = new CheckinQueue(async () => { throw new Error('offline'); }, () => 'key-1', store);
    first.add('code');
    await first.flush();
    assert.equal(store.value.length, 1);
    const sent = [];
    const second = new CheckinQueue(async (entry) => { sent.push(entry); return { accepted: true, message: 'ok' }; }, () => 'key-2', store);
    assert.equal(second.pending().length, 1);
    await second.flush();
    assert.equal(sent[0].requestKey, 'key-1');
    assert.equal(sent[0].scannedAt, store.value[0].scannedAt);
    assert.equal(store.value[0].state, 'accepted');
});

test('the manifest recognises hashed codes offline and remembers a local admission', async () => {
    const digest = async (text) => `h(${text})`;
    const manifest = new CheckinManifest(memoryStore(), digest);
    assert.equal(manifest.loaded, false);
    assert.deepEqual(await manifest.lookup('abc'), { known: false });
    manifest.load({ generated_at: '2026-10-23T20:00:00Z', tickets: [{ h: 'h(abc)', name: 'Ada', status: 'valid' }] });
    assert.equal(manifest.size, 1);
    assert.deepEqual(await manifest.lookup('abc'), { known: true, name: 'Ada', status: 'valid' });
    assert.deepEqual(await manifest.lookup('zzz'), { known: false });
    await manifest.markUsed('abc');
    assert.equal((await manifest.lookup('abc')).status, 'checked_in');
});

test('a stored manifest is reused after a reload', async () => {
    const store = memoryStore();
    const digest = async (text) => text;
    new CheckinManifest(store, digest).load({ generated_at: 'x', tickets: [{ h: 'k', name: 'Bo', status: 'valid' }] });
    const again = new CheckinManifest(store, digest);
    assert.equal(again.loaded, true);
    assert.equal((await again.lookup('k')).name, 'Bo');
});

test('with network every scan goes to the server, even codes missing from the downloaded list', () => {
    assert.equal(classifyScan({ online: true, loaded: true, known: false }), 'send');
    assert.equal(classifyScan({ online: true, loaded: true, known: true, status: 'checked_in' }), 'send');
    assert.equal(classifyScan({ online: false, loaded: true, known: false }), 'pending_unknown');
    assert.equal(classifyScan({ online: false, loaded: true, known: true, status: 'checked_in' }), 'reject_used');
    assert.equal(classifyScan({ online: false, loaded: true, known: true, status: 'valid' }), 'pending');
    assert.equal(classifyScan({ online: false, loaded: false, known: false }), 'pending');
});

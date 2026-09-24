import test from 'node:test';
import assert from 'node:assert/strict';
import { CheckinQueue } from '../../resources/js/checkin-queue.js';

test('a dropped reply stays pending and retries the same idempotency key', async () => {
    const seen = [];
    const queue = new CheckinQueue(async (entry) => {
        seen.push(entry.requestKey);
        if (seen.length === 1) throw new Error('offline');
        return { accepted: true, message: 'Ada' };
    }, () => 'same-request');
    queue.add('code');
    queue.add('code');
    await queue.flush();
    assert.equal(queue.entries.length, 1);
    assert.equal(queue.entries[0].state, 'pending');
    await queue.flush();
    assert.deepEqual(seen, ['same-request', 'same-request']);
    assert.equal(queue.entries[0].state, 'accepted');
});

test('server rejection is final, and a concurrent flush cannot submit twice', async () => {
    let calls = 0;
    let complete;
    const queue = new CheckinQueue(() => { calls++; return new Promise((resolve) => { complete = resolve; }); }, () => 'request');
    queue.add('code');
    const first = queue.flush();
    await queue.flush();
    complete({ accepted: false, message: 'Already used' });
    await first;
    await queue.flush();
    assert.equal(calls, 1);
    assert.equal(queue.entries[0].state, 'rejected');
});


test('a new scan after acceptance uses a new key and displays the duplicate rejection', async () => {
    let count = 0;
    const queue = new CheckinQueue(async () => ({ accepted: ++count === 1, message: count === 1 ? 'Admitted' : 'Already used' }), () => `request-${count}`);
    queue.add('code');
    await queue.flush();
    queue.add('code');
    await queue.flush();
    assert.deepEqual(queue.entries.map(e => e.state), ['accepted', 'rejected']);
    assert.notEqual(queue.entries[0].requestKey, queue.entries[1].requestKey);
});

test('a scan added while the previous response is in flight is sent without a manual retry', async () => {
    let complete;
    const seen = [];
    const queue = new CheckinQueue(async (entry) => {
        seen.push(entry.code);
        if (entry.code === 'first') await new Promise(resolve => { complete = resolve; });
        return { accepted: true, message: 'Admitted' };
    });
    queue.add('first');
    const sending = queue.flush();
    queue.add('second');
    await queue.flush();
    complete();
    await sending;
    assert.deepEqual(seen, ['first', 'second']);
    assert.ok(queue.entries.every(e => e.state === 'accepted'));
});

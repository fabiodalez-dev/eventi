import test from 'node:test';
import assert from 'node:assert/strict';
import { closureOn, openingState } from '../../resources/js/open-now.js';

const hours = { fri: [{ open: '21:00', close: '02:00' }], sat: [{ open: '10:00', close: '13:00' }] };
const at = (iso) => new Date(iso);

test('open inside a range and closed outside, in the city timezone', () => {
    assert.deepEqual(openingState(hours, at('2026-10-24T09:30:00Z'), 'Europe/Rome'), { open: true, until: '13:00' });
    assert.deepEqual(openingState(hours, at('2026-10-24T12:00:00Z'), 'Europe/Rome'), { open: false, until: null });
});

test('an overnight range keeps the venue open after midnight', () => {
    assert.deepEqual(openingState(hours, at('2026-10-23T20:00:00Z'), 'Europe/Rome'), { open: true, until: '02:00' });
    assert.deepEqual(openingState(hours, at('2026-10-23T23:30:00Z'), 'Europe/Rome'), { open: true, until: '02:00' });
    assert.deepEqual(openingState(hours, at('2026-10-24T00:30:00Z'), 'Europe/Rome'), { open: false, until: null });
});

test('an extraordinary closure overrides the hours for its calendar days, in the city timezone', () => {
    const closures = [{ from: '2026-10-24', to: '2026-10-26', note: 'Ferie' }];
    assert.equal(openingState(hours, at('2026-10-24T09:30:00Z'), 'Europe/Rome', closures).open, false);
    assert.equal(openingState(hours, at('2026-10-24T09:30:00Z'), 'Europe/Rome', closures).closure.to, '2026-10-26');
    // Friday 23:30 in Rome is still before the closure; Saturday 00:30 in Rome is inside it.
    assert.deepEqual(openingState(hours, at('2026-10-23T21:30:00Z'), 'Europe/Rome', closures), { open: true, until: '02:00' });
    assert.equal(closureOn(closures, at('2026-10-23T22:30:00Z'), 'Europe/Rome')?.note, 'Ferie');
    assert.equal(closureOn(closures, at('2026-10-26T23:30:00Z'), 'Europe/Rome'), null);
    assert.equal(openingState(null, at('2026-10-25T12:00:00Z'), 'Europe/Rome', closures).open, false);
});

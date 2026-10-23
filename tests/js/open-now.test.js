import test from 'node:test';
import assert from 'node:assert/strict';
import { openingState } from '../../resources/js/open-now.js';

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

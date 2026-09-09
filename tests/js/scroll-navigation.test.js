import test from 'node:test';
import assert from 'node:assert/strict';
import { navigationMovement } from '../../resources/js/scroll-navigation.js';

test('down reveals after sufficient scrolling', () => {
    assert.deepEqual(navigationMovement(80, 16), { movement: 96, visible: true });
    assert.deepEqual(navigationMovement(0, -16), { movement: 0, visible: false });
});
test('small movements accumulate but opposite directions reset the threshold', () => {
    assert.deepEqual(navigationMovement(80, 7), { movement: 87, visible: null });
    assert.deepEqual(navigationMovement(96, -2), { movement: 94, visible: null });
    assert.deepEqual(navigationMovement(26, -4), { movement: 22, visible: false });
});
test('invalid and zero scroll do not hide a visible navigation', () => {
    assert.deepEqual(navigationMovement(2, 0), { movement: 2, visible: null });
    assert.deepEqual(navigationMovement(2, NaN), { movement: 2, visible: null });
});

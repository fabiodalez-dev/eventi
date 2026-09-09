import test from 'node:test';
import assert from 'node:assert/strict';
import { navigationMovement } from '../../resources/js/scroll-navigation.js';

test('down hides and up reveals at the threshold', () => {
    assert.deepEqual(navigationMovement(0, 16), { movement: 0, visible: false });
    assert.deepEqual(navigationMovement(0, -16), { movement: 0, visible: true });
});
test('small movements accumulate but opposite directions reset the threshold', () => {
    assert.deepEqual(navigationMovement(8, 7), { movement: 15, visible: null });
    assert.deepEqual(navigationMovement(15, -2), { movement: -2, visible: null });
    assert.deepEqual(navigationMovement(-12, -4), { movement: 0, visible: true });
});
test('invalid and zero scroll do not hide a visible navigation', () => {
    assert.deepEqual(navigationMovement(2, 0), { movement: 2, visible: null });
    assert.deepEqual(navigationMovement(2, NaN), { movement: 2, visible: null });
});

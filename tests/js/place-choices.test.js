import { test } from 'node:test';
import assert from 'node:assert/strict';
import { matchesPlace } from '../../resources/js/place-choices.js';

test('place search ignores accents, case and surrounding whitespace', () => {
    assert.equal(matchesPlace('Montà', ' MONTA '), true);
    assert.equal(matchesPlace('Brusegana', 'bruse'), true);
    assert.equal(matchesPlace('Guizza', 'bruse'), false);
    assert.equal(matchesPlace('Guizza', ''), true);
});

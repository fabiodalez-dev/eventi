import { test } from 'node:test';
import assert from 'node:assert/strict';
import { wouldEmptyResults, removesFilters } from '../../resources/js/event-filters.js';

test('does not replace a populated search with an empty filter combination', () => {
    assert.equal(wouldEmptyResults('1', '0'), true);
    assert.equal(wouldEmptyResults('15', '0'), true);
});
test('allows recovering an empty search and normal selections', () => {
    assert.equal(wouldEmptyResults('0', '1'), false);
    assert.equal(wouldEmptyResults('0', '0'), false);
    assert.equal(wouldEmptyResults('3', '1'), false);
});
test('preserves history navigation and pages without result metadata', () => {
    assert.equal(wouldEmptyResults('3', '0', false), false);
    assert.equal(wouldEmptyResults('3', undefined), false);
});

test('always permits reset and removal, including one of several categories', () => {
    const before = 'https://example.test/mappa?date=tomorrow&category=cinema,teatro&lat=45&lng=11&radius=25';
    assert.equal(removesFilters(before, 'https://example.test/mappa'), true);
    assert.equal(removesFilters(before, 'https://example.test/mappa?category=cinema&all_dates=1'), true);
    assert.equal(removesFilters(before, 'https://example.test/mappa?date=tomorrow&category=cinema,teatro'), true);
});
test('radius changes and added constraints are not removals', () => {
    const before = 'https://example.test/mappa?date=tomorrow&radius=25';
    assert.equal(removesFilters(before, 'https://example.test/mappa?date=tomorrow&radius=1'), false);
    assert.equal(removesFilters(before, 'https://example.test/mappa?date=tomorrow&accessible=1'), false);
});

import { test } from 'node:test';
import assert from 'node:assert/strict';
import { matchingVenues } from '../../resources/js/venue-autocomplete.js';

test('matches names regardless of accents and case after two characters', () => {
    const choices = [{ slug: 'caffe', name: 'Caffè della città' }, { slug: 'teatro', name: 'Teatro' }];
    assert.deepEqual(matchingVenues(choices, 'CAFFE'), [choices[0]]);
    assert.deepEqual(matchingVenues(choices, 'c'), []);
    assert.deepEqual(matchingVenues(choices, 'assente'), []);
});

test('keeps at most eight suggestions even with hundreds of venues', () => {
    const choices = Array.from({ length: 500 }, (_, index) => ({ slug: `locale-${index}`, name: `Locale ${index}` }));
    assert.equal(matchingVenues(choices, 'locale').length, 8);
    assert.deepEqual(matchingVenues(choices, 'Locale 499'), [choices[499]]);
});

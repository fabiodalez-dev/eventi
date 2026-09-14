import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import vm from 'node:vm';

const source = readFileSync(new URL('../../resources/js/page-transitions.js', import.meta.url), 'utf8');

test('animates public navigation while leaving forms and reduced motion immediate', () => {
    for (const [from, to, reduced, expectedSkip] of [
        ['/', '/eventi', false, false],
        ['/eventi', '/mappa', false, false],
        ['/eventi', '/eventi/concerto/1', false, false],
        ['/registrati', '/il-mio-profilo', false, true],
        ['/eventi', '/accedi', false, true],
        ['/', '/eventi', true, true],
    ]) {
        for (const direction of ['pageswap', 'pagereveal']) {
            const listeners = {};
            let skipped = false;
            vm.runInNewContext(source, {
                URL, location: new URL(direction === 'pageswap' ? from : to, 'https://example.test'),
                matchMedia: () => ({ matches: reduced }),
                window: {
                    addEventListener: (name, callback) => { listeners[name] = callback; },
                    navigation: { activation: { from: { url: `https://example.test${from}` } } },
                },
                document: { querySelectorAll: () => [], querySelector: () => null },
            });
            listeners[direction]({
                activation: { entry: { url: `https://example.test${to}` } },
                viewTransition: { skipTransition: () => { skipped = true; } },
            });
            assert.equal(skipped, expectedSkip, `${direction}: ${from} → ${to}`);
        }
    }
});

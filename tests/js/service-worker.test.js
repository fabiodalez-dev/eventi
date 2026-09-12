import { test } from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import vm from 'node:vm';

for (const [target, expected] of [
    ['/eventi/concerto/1', 'https://eventi.fabiodalez.it/eventi/concerto/1'],
    ['https://evil.example/phishing', 'https://eventi.fabiodalez.it/'],
    ['//evil.example', 'https://eventi.fabiodalez.it/'],
    ['javascript:alert(1)', 'https://eventi.fabiodalez.it/'],
    ['https://user:pass@eventi.fabiodalez.it/a', 'https://eventi.fabiodalez.it/'],
]) {
    test(`notification destination: ${target}`, async () => {
        const handlers = {};
        let opened;
        let completion;
        const self = {
            location: { origin: 'https://eventi.fabiodalez.it' },
            addEventListener: (event, handler) => { handlers[event] = handler; },
            clients: { matchAll: async () => [], openWindow: async (url) => { opened = url; } },
        };
        vm.runInNewContext(fs.readFileSync(new URL('../../public/sw.js', import.meta.url), 'utf8'), { self, URL });
        handlers.notificationclick({ notification: { close() {}, data: { url: target } }, waitUntil: promise => { completion = promise; } });
        await completion;
        assert.equal(opened, expected);
    });
}

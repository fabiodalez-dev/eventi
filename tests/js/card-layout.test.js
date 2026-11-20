import test from 'node:test';
import assert from 'node:assert/strict';
import { applyLayout, saveToProfile } from '../../resources/js/card-layout.js';

/* Un documento ridotto a ciò che lo script tocca: `<html>` coi suoi data-,
   il token CSRF, nessun selettore in pagina. */
function page({ url = null, saved = false, layout = 'full' } = {}) {
    const dataset = { cardLayout: layout };
    if (url) dataset.cardLayoutUrl = url;
    if (saved) dataset.cardLayoutSaved = '';
    const calls = [];
    globalThis.document = {
        documentElement: { dataset },
        querySelector: (selector) => (selector === 'meta[name="csrf-token"]' ? { content: 'token' } : null),
        querySelectorAll: () => [],
        dispatchEvent: () => true,
    };
    globalThis.CustomEvent = class { constructor(type, init) { this.type = type; this.detail = init?.detail; } };
    globalThis.localStorage = { setItem: () => {}, getItem: () => null };
    globalThis.fetch = async (target, init) => { calls.push({ target, init }); return { ok: true }; };
    return { dataset, calls };
}

const flush = () => new Promise((resolve) => setTimeout(resolve, 0));

test('chi è collegato salva la vista scelta nel profilo', async () => {
    const { dataset, calls } = page({ url: '/aspetto/vista-elenchi' });
    applyLayout('grid');
    await flush();
    assert.equal(dataset.cardLayout, 'grid');
    assert.equal(calls.length, 1);
    assert.equal(calls[0].init.method, 'PATCH');
    assert.deepEqual(JSON.parse(calls[0].init.body), { card_layout: 'grid' });
    assert.ok('cardLayoutSaved' in dataset);
});

test('l\'ospite non chiama il server', async () => {
    const { calls } = page();
    applyLayout('list');
    await flush();
    assert.equal(calls.length, 0);
    assert.equal(await saveToProfile('list'), false);
});

test('la stessa vista già salvata non riparte', async () => {
    const { calls } = page({ url: '/aspetto/vista-elenchi', saved: true, layout: 'grid' });
    applyLayout('grid');
    await flush();
    assert.equal(calls.length, 0);
});

test('un errore di rete lascia la vista cambiata', async () => {
    const { dataset } = page({ url: '/aspetto/vista-elenchi' });
    globalThis.fetch = async () => { throw new Error('offline'); };
    applyLayout('list');
    assert.equal(await saveToProfile('list'), false);
    assert.equal(dataset.cardLayout, 'list');
    assert.ok(!('cardLayoutSaved' in dataset));
});

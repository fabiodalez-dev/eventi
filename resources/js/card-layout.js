/*
 * La vista degli elenchi sul telefono: `full` (card a tutta riga), `grid`
 * (due colonne), `list` (riga compatta con la locandina a sinistra).
 *
 * La scelta sta in `localStorage['incitta:card-layout']` e vale per tutte le
 * pagine-elenco. Lo script in testa al layout la applica a
 * `<html data-card-layout>` prima del primo disegno; qui si tengono allineati
 * i selettori (`<x-card-layout-toggle>`) e si salva la scelta nuova. Un valore
 * sconosciuto vale `full`. La memoria del browser può mancare (navigazione
 * privata, dati bloccati): la vista cambia lo stesso, solo non si ricorda.
 *
 * Chi è collegato la salva anche nel profilo, come il tema
 * (`data-card-layout-url`): la ritrova sugli altri dispositivi e nell'app.
 * Se il profilo non ne ha ancora una, quella fatta in questo browser da
 * ospite ci passa da sola alla prima pagina dopo l'accesso.
 */
const KEY = 'incitta:card-layout';
const LAYOUTS = ['full', 'grid', 'list'];

export function normaliseLayout(value) {
    return LAYOUTS.includes(value) ? value : 'full';
}

function current() {
    return normaliseLayout(document.documentElement.dataset.cardLayout);
}

function sync(layout) {
    for (const option of document.querySelectorAll('[data-card-layout-option]')) {
        const checked = option.dataset.cardLayoutOption === layout;

        option.setAttribute('aria-checked', checked ? 'true' : 'false');
        option.tabIndex = checked ? 0 : -1;
    }
}

/**
 * Salva la scelta nel profilo. Se non riesce (rete, sessione scaduta) la
 * vista resta cambiata in questa pagina e nel browser: è una comodità, non un
 * dato da difendere con un messaggio d'errore.
 */
export function saveToProfile(layout) {
    const url = document.documentElement.dataset.cardLayoutUrl;
    const token = document.querySelector('meta[name="csrf-token"]')?.content;

    if (!url || !token) {
        return Promise.resolve(false);
    }

    return fetch(url, {
        method: 'PATCH',
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': token },
        body: JSON.stringify({ card_layout: layout }),
    })
        .then(response => {
            if (response.ok) {
                document.documentElement.dataset.cardLayoutSaved = '';
            }

            return response.ok;
        })
        .catch(() => false);
}

export function applyLayout(value, { save = true } = {}) {
    const layout = normaliseLayout(value);
    const changed = layout !== current() || !('cardLayoutSaved' in document.documentElement.dataset);

    document.documentElement.dataset.cardLayout = layout;

    try {
        localStorage.setItem(KEY, layout);
    } catch {
        /* Senza memoria la scelta vale per questa pagina. */
    }

    sync(layout);
    document.dispatchEvent(new CustomEvent('card-layout:change', { detail: { layout } }));

    if (save && changed) {
        void saveToProfile(layout);
    }

    return layout;
}

let listening = false;

/**
 * Allinea i selettori presenti e, la prima volta, ascolta i gesti.
 *
 * Si può richiamare quando una parte della pagina arriva dopo (i filtri di
 * `/eventi` sostituiscono l'elenco): gli ascoltatori stanno sul documento, e
 * il selettore nuovo funziona senza che nessuno glieli attacchi.
 */
export function cardLayout() {
    const toggles = [...document.querySelectorAll('[data-card-layout-toggle]')];

    /* Uno per pagina. Il server ne stampa uno solo, ma la pagina iniziale
       servita dalla cache ridisegna «In corso adesso» a ogni risposta, e quel
       frammento non sa che la pagina ha già il suo. */
    toggles.slice(1).forEach(toggle => toggle.remove());
    sync(current());

    if (listening) {
        return;
    }

    listening = true;

    /* Appena entrato, senza una scelta nel profilo: quella fatta qui da
       ospite diventa la sua. La vista predefinita non è una scelta. */
    const root = document.documentElement;

    if (root.dataset.cardLayoutUrl && !('cardLayoutSaved' in root.dataset) && current() !== 'full') {
        void saveToProfile(current());
    }

    document.addEventListener('click', event => {
        const option = event.target instanceof Element ? event.target.closest('[data-card-layout-option]') : null;

        if (option) {
            applyLayout(option.dataset.cardLayoutOption);
        }
    });

    /* Frecce come in ogni gruppo radio: si sposta e si sceglie insieme. */
    document.addEventListener('keydown', event => {
        const keys = { ArrowRight: 1, ArrowDown: 1, ArrowLeft: -1, ArrowUp: -1 };
        const option = event.target instanceof Element ? event.target.closest('[data-card-layout-option]') : null;

        if (!(event.key in keys) || !option) {
            return;
        }

        event.preventDefault();

        const options = [...option.closest('[role="radiogroup"]').querySelectorAll('[data-card-layout-option]')];
        const next = options[(options.indexOf(option) + keys[event.key] + options.length) % options.length];

        applyLayout(next.dataset.cardLayoutOption);
        next.focus();
    });

    /* Gli elenchi che arrivano dopo — l'anteprima di un giorno nel
       calendario — portano un selettore nuovo, da allineare. */
    document.addEventListener('calendar-day:updated', () => sync(current()));

    /* Un'altra scheda aperta sul sito ha cambiato vista. */
    window.addEventListener('storage', event => {
        if (event.key === KEY) {
            document.documentElement.dataset.cardLayout = normaliseLayout(event.newValue);
            sync(current());
        }
    });
}

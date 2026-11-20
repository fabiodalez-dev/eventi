/**
 * Le misure delle sponsorizzazioni nel browser: quando una card è stata vista
 * davvero, quando è stata aperta, e quando uno spazio sponsorizzabile è stato
 * visto vuoto.
 *
 * **Vista davvero** vuol dire: metà dell'elemento nello schermo per un secondo
 * di fila, con la scheda visibile e non in prerender. Chi scorre veloce non
 * conta; una card in fondo a una pagina che nessuno scorre non conta; una
 * scheda aperta in secondo piano non conta. È la definizione corrente di
 * visualizzazione visibile, e il numero che finisce in fattura.
 *
 * **Ogni misura porta il suo gettone** (`data-sponsorship-token`), che il
 * server ha firmato quando ha messo la card nella pagina e che vale per una
 * sola visualizzazione e un solo clic. Il gettone non si salva da nessuna
 * parte: niente cookie, niente `localStorage`. Appartiene alla visita della
 * pagina, non alla persona. Una card senza gettone non manda niente: il server
 * non la conterebbe.
 *
 * **I programmi automatici non mandano niente** (`navigator.webdriver`). Il
 * server fa la sua parte con l'intestazione `User-Agent`. I test nel browser
 * che vogliono vedere la misura mettono `window.__sponsorshipCountAutomation =
 * true` prima del caricamento: non apre niente a chi gonfia, che può già
 * chiamare il server da sé, e il gettone resta l'unica chiave.
 *
 * Se questo codice non gira, si perdono le misure — mai la navigazione: il
 * collegamento alla scheda è un `href` normale.
 */
import { sponsorshipContext } from './sponsorship-context.js';

/** Per quanto tempo di fila l'elemento deve restare visibile. Uguale a `sponsorship.viewable_ms`. */
export const VIEWABLE_MS = 1000;

/** Quanta parte dell'elemento deve stare nello schermo. */
export const VISIBLE_RATIO = 0.5;

/** L'indirizzo del segnale di occasione, se l'elemento non ne porta uno suo. */
export const OPPORTUNITY_URL = '/sponsorizzazioni/occasione';

const bound = new WeakSet();

/** Se a guidare la pagina è un programma che lo dichiara. */
export function automated() {
    return globalThis.navigator?.webdriver === true && globalThis.__sponsorshipCountAutomation !== true;
}

/**
 * Se una voce di `IntersectionObserver` vale come «metà nello schermo».
 *
 * Un elemento più alto di due schermi non arriva mai a metà della propria
 * altezza: per lui basta che copra metà dello schermo.
 */
export function halfVisible(entry) {
    if (!entry.isIntersecting) return false;
    if (entry.intersectionRatio >= VISIBLE_RATIO) return true;
    const root = entry.rootBounds;

    return !!root && root.height > 0 && entry.intersectionRect.height >= root.height * VISIBLE_RATIO;
}

/**
 * Osserva degli elementi e chiama `onViewable(elemento)` quando uno resta a
 * metà nello schermo per `ms` millisecondi di fila, con la scheda visibile e
 * fuori dal prerender. Se esce prima, o la scheda passa in secondo piano, il
 * conto riparte da zero.
 *
 * Non decide quante volte contare: lo decide chi la usa.
 */
export function watchViewable(onViewable, { ms = VIEWABLE_MS } = {}) {
    const doc = globalThis.document;
    const states = new Map();
    const paused = () => doc.hidden === true || doc.visibilityState === 'hidden' || doc.prerendering === true;

    const disarm = (state) => {
        if (state.timer !== null) clearTimeout(state.timer);
        state.timer = null;
    };
    const arm = (element, state) => {
        if (!state.visible || state.timer !== null || paused()) return;
        state.timer = setTimeout(() => {
            state.timer = null;
            if (state.visible && !paused() && states.get(element) === state) onViewable(element);
        }, ms);
    };

    const observer = typeof IntersectionObserver === 'function'
        ? new IntersectionObserver((entries) => {
            for (const entry of entries) {
                const state = states.get(entry.target);
                if (!state) continue;
                state.visible = halfVisible(entry);
                state.visible ? arm(entry.target, state) : disarm(state);
            }
        }, { threshold: [0, 0.25, VISIBLE_RATIO, 0.75, 1] })
        : null;

    const resume = () => {
        for (const [element, state] of states) paused() ? disarm(state) : arm(element, state);
    };
    doc.addEventListener('visibilitychange', resume);
    doc.addEventListener('prerenderingchange', resume);

    return {
        observe(element) {
            if (!observer || states.has(element)) return;
            states.set(element, { visible: false, timer: null });
            observer.observe(element);
        },
        unobserve(element) {
            const state = states.get(element);
            if (state) disarm(state);
            states.delete(element);
            observer?.unobserve(element);
        },
    };
}

function csrfToken() {
    return globalThis.document.querySelector('meta[name="csrf-token"]')?.content ?? '';
}

/**
 * Manda una misura alla rotta del sito, con la sessione e il token CSRF: è
 * quella che sa chi è collegato, e quindi chi non conta.
 */
export function post(url, body, token = null) {
    const headers = { 'X-CSRF-TOKEN': csrfToken(), Accept: 'application/json', 'Content-Type': 'application/json' };
    if (token) headers['X-Impression-Token'] = token;

    void fetch(url, { method: 'POST', keepalive: true, credentials: 'same-origin', headers, body: JSON.stringify(body) })
        .catch(() => {
            /* Una misura persa non è un problema di chi sta navigando: nessun
               messaggio, nessun tentativo ripetuto. */
        });
}

/**
 * Le card sponsorizzate e gli spazi vuoti dentro `root`. Si può chiamare di
 * nuovo dopo che una parte della pagina è stata sostituita: gli elementi già
 * seguiti non si contano due volte.
 */
export function sponsorshipMetrics(root = globalThis.document) {
    if (automated()) return;

    const cards = [...root.querySelectorAll('[data-sponsorship]')].filter((card) => !bound.has(card));
    const spaces = [...root.querySelectorAll('[data-sponsorship-opportunity]')].filter((space) => !bound.has(space));

    if (cards.length === 0 && spaces.length === 0) return;

    const seen = new WeakSet();
    const watcher = watchViewable((element) => {
        watcher.unobserve(element);
        if (seen.has(element)) return;
        seen.add(element);

        if (element.dataset.sponsorshipOpportunity !== undefined) {
            /* Il gettone dell'occasione dice al server città e collocazione:
               senza, il segnale non conta. */
            if (!element.dataset.sponsorshipOpportunityToken) return;
            post(element.dataset.sponsorshipOpportunityUrl || OPPORTUNITY_URL, {
                city: Number(element.dataset.sponsorshipCity),
                placement: element.dataset.sponsorshipOpportunity,
                token: element.dataset.sponsorshipOpportunityToken,
            });

            return;
        }

        const { sponsorshipImpression: url, sponsorshipToken: token, sponsorshipPlacement: placement } = element.dataset;
        if (url && token) post(url, sponsorshipContext(placement || 'card'), token);
    });

    for (const card of cards) {
        bound.add(card);
        const { sponsorshipToken: token } = card.dataset;
        if (!token) continue;

        let clicked = false;
        const click = (event) => {
            if (clicked || !event.target?.closest?.('a[rel~="sponsored"]')) return;
            clicked = true;
            /* Il clic porta con sé la visualizzazione, se non era ancora
               partita: il server le conta tutte e due, e qui non si manda più
               la visualizzazione dopo. */
            seen.add(card);
            watcher.unobserve(card);
            const { sponsorshipClick: url, sponsorshipPlacement: placement } = card.dataset;
            if (url) post(url, sponsorshipContext(placement || 'card', true), token);
        };
        card.addEventListener('click', click);
        card.addEventListener('auxclick', (event) => { if (event.button === 1) click(event); });
        watcher.observe(card);
    }

    for (const space of spaces) {
        bound.add(space);
        if (space.dataset.sponsorshipCity && space.dataset.sponsorshipOpportunity) watcher.observe(space);
    }
}

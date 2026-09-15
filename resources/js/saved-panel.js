/**
 * L'icona delle date salvate in testata, e la finestra che apre.
 *
 * **Perché esiste.** Il segnalibro su un evento funziona al primo click, anche
 * senza account: da anonimi le date finiscono nel `localStorage`. Solo che poi
 * non c'era nessun posto dove rivederle — `/i-miei-salvataggi` chiede
 * l'accesso, e su schermo largo non compariva nemmeno un collegamento. Si
 * poteva salvare e non ritrovare.
 *
 * **Come si comporta.** L'icona nasce nascosta e compare al primo salvataggio,
 * con il numero accanto. Cliccandola non si cambia pagina: si apre un
 * `<dialog>` che chiede l'elenco al server. Per chi è collegato il server sa
 * già quali sono; per chi non lo è gli identificativi partono da qui, letti dal
 * proprio browser, e il server restituisce solo ciò che è già pubblico.
 *
 * **Senza JavaScript** l'icona non compare per chi è anonimo (il browser non
 * ha modo di contare), e per chi è collegato resta un collegamento normale
 * verso la pagina dei salvataggi. Niente si rompe: si perde la finestra, non la
 * funzione.
 */

/**
 * @param {object} opzioni
 * @param {() => number[]} opzioni.dateLocali  Le date salvate nel browser.
 * @param {boolean} opzioni.collegato
 */
export function savedPanel({ dateLocali, collegato }) {
    const comando = document.querySelector('[data-saved-opener]');
    const finestra = document.querySelector('[data-saved-dialog]');

    if (!comando || !finestra || typeof finestra.showModal !== 'function') {
        return;
    }

    const corpo = finestra.querySelector('[data-saved-dialog-body]');
    const numero = comando.querySelector('[data-saved-opener-count]');
    const chiusura = finestra.querySelector('[data-saved-dialog-close]');

    /* Il conteggio del server vale come punto di partenza per chi è collegato;
       per chi non lo è il server non sa nulla e si parte dal browser. */
    let quante = collegato ? Number.parseInt(comando.dataset.savedCount ?? '0', 10) : dateLocali().length;

    const mostra = () => {
        if (numero) numero.textContent = String(quante);
        comando.hidden = quante === 0;
    };

    let revision = 0;
    const carica = async () => {
        const currentRevision = ++revision;
        const base = comando.dataset.savedPanelUrl;

        if (!base) return;

        /* Gli identificativi servono solo a chi non è collegato: per gli altri
           il server legge i propri, e mandarglieli sarebbe dirgli una cosa che
           sa già meglio di noi. */
        const indirizzo = new URL(base, window.location.origin);

        if (!collegato) {
            indirizzo.searchParams.set('ids', dateLocali().join(','));
        }

        try {
            const risposta = await fetch(indirizzo, { headers: { 'X-Requested-With': 'fetch' }, cache: 'no-store' });

            if (!risposta.ok) throw new Error(String(risposta.status));

            /*
             * Si analizza e si innestano i nodi, invece di assegnare `innerHTML`.
             * Il frammento arriva dal nostro server e Blade lo ha già messo in
             * salvo, quindi il rischio concreto non c'è; ma è la strada che
             * questo file usa già per lo scorrimento infinito e per l'elenco
             * filtrato, e una sola strada per la stessa cosa vale più di due.
             */
            const html = await risposta.text();
            if (currentRevision !== revision) return;
            const frammento = new DOMParser().parseFromString(html, 'text/html');
            const count = Number.parseInt(risposta.headers.get('X-Saved-Count'), 10);
            if (Number.isFinite(count)) {
                quante = count;
                mostra();
            }

            corpo.replaceChildren(...frammento.body.childNodes);

            /*
             * I segnalibri appena innestati non sono agganciati a niente: lo
             * script li aggancia una volta sola, al primo disegno. Questo è il
             * segnale con cui il sito dichiara «ci sono card nuove, riaggancia»
             * — lo stesso che usano l'elenco filtrato e il calendario.
             */
            document.dispatchEvent(new CustomEvent('event-browser:updated'));
        } catch {
            if (currentRevision !== revision) return;
            const avviso = document.createElement('p');
            avviso.className = 'm-0 py-6 text-center text-sm text-ink-muted';
            avviso.setAttribute('role', 'alert');
            avviso.textContent = comando.dataset.savedError ?? '';
            corpo.replaceChildren(avviso);
        }
    };

    for (const opener of document.querySelectorAll('[data-saved-opener], [data-saved-mobile-opener]')) {
        opener.addEventListener('click', event => {
            event.preventDefault();
            corpo.dataset.loading = '1';
            finestra.showModal();
            void carica().finally(() => { delete corpo.dataset.loading; });
        });
    }

    chiusura?.addEventListener('click', () => finestra.close());

    /* Il click fuori dal riquadro chiude: su un `<dialog>` l'area scura È
       l'elemento, quindi un click che arriva proprio su di lui — e non su
       qualcosa dentro — è un click sullo sfondo. */
    finestra.addEventListener('click', event => {
        if (event.target === finestra) finestra.close();
    });

    document.addEventListener('saved:changed', () => {
        void carica();
    });

    window.addEventListener('focus', () => { void carica(); });
    window.addEventListener('storage', () => { if (!collegato) void carica(); });

    mostra();
    void carica();
}

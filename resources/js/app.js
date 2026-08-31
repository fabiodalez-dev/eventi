/*
 * Miglioramenti progressivi del sito pubblico.
 *
 * Regola d'ingaggio: **nulla qui dentro è necessario**. La lista si sfoglia
 * con `?page=`, i filtri sono link, la condivisione ha i suoi collegamenti e
 * la distanza semplicemente non si offre a chi non ha la geolocalizzazione.
 * Ogni funzione parte solo se trova ciò che le serve, e se non parte la pagina
 * resta esattamente com'era (§11.3).
 */

/**
 * Scorrimento infinito sopra a una paginazione che funziona da sola.
 *
 * Il collegamento "successiva" resta nel documento fino all'ultima pagina: qui
 * si intercetta il momento in cui entra nella finestra, si scarica la pagina
 * seguente e si travasano le sue card in coda a quelle presenti. L'indirizzo
 * viene aggiornato con `replaceState`, così ricaricare non riporta all'inizio.
 */
function infiniteScroll() {
    const results = document.querySelector('[data-results]');
    const pagination = document.querySelector('[data-pagination]');

    if (!results || !pagination || !('IntersectionObserver' in window)) {
        return;
    }

    let loading = false;

    const nextUrl = () => {
        const link = pagination.querySelector('a[rel="next"]');

        return link instanceof HTMLAnchorElement ? link.href : null;
    };

    const load = async (observer) => {
        const url = nextUrl();

        if (loading || url === null) {
            return;
        }

        loading = true;

        try {
            const response = await fetch(url, { headers: { 'X-Requested-With': 'fetch' } });

            if (!response.ok) {
                return;
            }

            const document_ = new DOMParser().parseFromString(await response.text(), 'text/html');
            const nextGrid = document_.querySelector('[data-results] > *');
            const nextPagination = document_.querySelector('[data-pagination]');
            const grid = results.firstElementChild;

            if (nextGrid === null || nextPagination === null || grid === null) {
                return;
            }

            /* Le card si appendono alla griglia esistente, non al contenitore:
               è la griglia a portare le colonne. */
            grid.append(...nextGrid.children);
            pagination.replaceChildren(...nextPagination.childNodes);
            window.history.replaceState({}, '', url);

            if (nextUrl() === null) {
                observer.disconnect();
            }
        } catch {
            /* Una pagina che non arriva non deve rompere quella che c'è già:
               resta il collegamento "successiva", che funziona da solo. */
        } finally {
            loading = false;
        }
    };

    const observer = new IntersectionObserver((entries) => {
        for (const entry of entries) {
            if (entry.isIntersecting) {
                void load(observer);
            }
        }
    }, { rootMargin: '400px 0px' });

    observer.observe(pagination);
}

/**
 * Condivisione con il menu di sistema, dove esiste. Il pulsante è nascosto in
 * partenza e compare solo qui: senza JavaScript restano i link ai singoli
 * servizi, che funzionano ovunque.
 */
function nativeShare() {
    for (const button of document.querySelectorAll('[data-share]')) {
        if (!(button instanceof HTMLElement)) {
            continue;
        }

        const payload = {
            title: button.dataset.shareTitle ?? document.title,
            text: button.dataset.shareText ?? '',
            url: button.dataset.shareUrl ?? window.location.href,
        };

        const canShare = typeof navigator.share === 'function';
        const canCopy = navigator.clipboard !== undefined;

        if (!canShare && !canCopy) {
            continue;
        }

        button.classList.remove('hidden');

        button.addEventListener('click', async () => {
            try {
                if (canShare) {
                    await navigator.share(payload);

                    return;
                }

                await navigator.clipboard.writeText(payload.url);

                const original = button.textContent;
                button.textContent = button.dataset.shareCopied ?? original;
                window.setTimeout(() => {
                    button.textContent = original;
                }, 2000);
            } catch {
                /* L'utente ha annullato: non è un errore da mostrare. */
            }
        });
    }
}

/**
 * Il raggio scelto accanto al pulsante, se c'è un selettore; altrimenti quello
 * dichiarato dal pulsante stesso. Si legge **al momento del clic** e non prima:
 * chi cambia il raggio e poi concede la posizione si aspetta il raggio nuovo.
 */
function radius(button) {
    const select = button.parentElement?.querySelector('[data-geolocate-radius-input]')
        ?? document.querySelector('[data-geolocate-radius-input]');

    if (select instanceof HTMLSelectElement && select.value !== '') {
        return select.value;
    }

    return button.dataset.geolocateRadius ?? '5';
}

/**
 * "Vicino a me" (§11.7).
 *
 * La posizione si chiede **solo** quando qualcuno preme il pulsante, mai
 * all'apertura del sito, e non viene salvata da nessuna parte: finisce
 * nell'indirizzo della ricerca e muore con essa. Il riquadro che lo contiene
 * dice prima perché la si chiede: qui c'è solo il gesto.
 */
function geolocation() {
    for (const button of document.querySelectorAll('[data-geolocate]')) {
        if (!(button instanceof HTMLElement) || navigator.geolocation === undefined) {
            continue;
        }

        button.classList.remove('hidden');

        button.addEventListener('click', () => {
            button.setAttribute('aria-busy', 'true');

            navigator.geolocation.getCurrentPosition(
                (position) => {
                    const url = new URL(button.dataset.geolocateUrl ?? window.location.href, window.location.origin);

                    url.searchParams.set('lat', position.coords.latitude.toFixed(5));
                    url.searchParams.set('lng', position.coords.longitude.toFixed(5));
                    url.searchParams.set('radius', radius(button));
                    url.searchParams.delete('page');

                    window.location.assign(url.toString());
                },
                () => {
                    button.removeAttribute('aria-busy');
                    button.textContent = button.dataset.geolocateDenied ?? button.textContent;
                },
                { enableHighAccuracy: false, timeout: 10000, maximumAge: 300000 },
            );
        });
    }
}

/*
 * Salvataggi (§15.1). **Il cuore funziona al primo click, senza registrazione.**
 *
 * Da anonimi le date vivono nel `localStorage` e nessuna richiesta parte: è
 * quello a rendere il gesto immediato e a non interrompere niente. Dopo il
 * terzo salvataggio compare il riquadro che offre il promemoria — che è la
 * vera leva per registrarsi, non il salvataggio, che funziona già.
 *
 * Da collegati il cuore parla con il server. In entrambi i casi sotto c'è un
 * modulo vero: se questo file non venisse eseguito, il cuore resterebbe un
 * pulsante di invio che funziona con un ricaricamento di pagina.
 */
const SAVED_KEY = 'salvataggi';
const PROMPT_KEY = 'salvataggi.promemoria-nascosto';

function accountSettings() {
    const node = document.querySelector('[data-account]');

    return {
        authenticated: node?.dataset.accountAuthenticated === '1',
        promptAfter: Number.parseInt(node?.dataset.accountPromptAfter ?? '3', 10),
        mergeUrl: node?.dataset.accountMerge ?? null,
        token: document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') ?? '',
    };
}

/**
 * Le date salvate nel browser. Una lettura che fallisce — spazio esaurito,
 * modalità privata, contenuto manomesso — non deve rompere la pagina: vale
 * come "nessun salvataggio", che è il caso di chi arriva per la prima volta.
 */
function localSaves() {
    try {
        const raw = window.localStorage.getItem(SAVED_KEY);
        const ids = raw === null ? [] : JSON.parse(raw);

        return Array.isArray(ids) ? ids.map(Number).filter((id) => Number.isInteger(id) && id > 0) : [];
    } catch {
        return [];
    }
}

function writeLocalSaves(ids) {
    try {
        window.localStorage.setItem(SAVED_KEY, JSON.stringify([...new Set(ids)]));
    } catch {
        /* Senza spazio il salvataggio non si conserva, ma il gesto non deve
           comunque produrre un errore visibile. */
    }
}

/**
 * Lo stato visibile del cuore. Il colore, l'icona piena, il testo e
 * `aria-pressed`: chi naviga con la tastiera o con uno screen reader deve
 * sapere che cosa è appena successo quanto chi vede il riempimento.
 */
function paintHeart(form, saved) {
    const button = form.querySelector('[data-save-button]');
    const icon = form.querySelector('[data-save-icon]');
    const text = form.querySelector('[data-save-text]');

    if (!button) {
        return;
    }

    button.setAttribute('aria-pressed', saved ? 'true' : 'false');
    button.classList.toggle('bg-brand', saved);
    button.classList.toggle('text-on-brand', saved);
    button.classList.toggle('ring-brand', saved);
    button.classList.toggle('bg-surface', !saved);
    button.classList.toggle('text-ink-muted', !saved);
    button.classList.toggle('ring-line', !saved);

    if (icon) {
        icon.style.fill = saved ? 'currentColor' : 'none';
        icon.style.stroke = saved ? 'none' : 'currentColor';
        icon.style.strokeWidth = saved ? '0' : '2';
    }

    if (text) {
        text.textContent = saved ? form.dataset.saveLabelSaved : form.dataset.saveLabel;
    }
}

function showPromptIfDue() {
    const prompt = document.querySelector('[data-save-prompt]');
    const { promptAfter } = accountSettings();

    if (!prompt || localSaves().length < promptAfter) {
        return;
    }

    try {
        if (window.localStorage.getItem(PROMPT_KEY) === '1') {
            return;
        }
    } catch {
        /* Se non si può leggere la preferenza, si mostra: è meno peggio che
           nasconderla per sempre. */
    }

    prompt.hidden = false;
}

function dismissPrompt() {
    const prompt = document.querySelector('[data-save-prompt]');

    prompt?.querySelector('[data-save-prompt-dismiss]')?.addEventListener('click', () => {
        prompt.hidden = true;

        try {
            window.localStorage.setItem(PROMPT_KEY, '1');
        } catch {
            /* Nessuna preferenza conservata: ricomparirà. */
        }
    });
}

/**
 * Il verbo vero, non `_method` in un corpo JSON: quel campo Laravel lo legge
 * dai moduli, e in una richiesta JSON non lo vedrebbe — la cancellazione
 * arriverebbe come una scrittura.
 */
async function talkToServer(url, method, token, body) {
    try {
        const response = await fetch(url, {
            method,
            headers: {
                'Content-Type': 'application/json',
                Accept: 'application/json',
                'X-CSRF-TOKEN': token,
                'X-Requested-With': 'fetch',
            },
            body: method === 'DELETE' ? null : JSON.stringify(body),
        });

        return response.ok;
    } catch {
        return false;
    }
}

function savedHearts() {
    const { authenticated, token } = accountSettings();
    const saves = localSaves();

    for (const form of document.querySelectorAll('[data-save]')) {
        const id = Number.parseInt(form.dataset.saveId ?? '0', 10);

        if (!authenticated) {
            paintHeart(form, saves.includes(id));
        }

        form.addEventListener('submit', async (event) => {
            event.preventDefault();

            const wasSaved = form.querySelector('[data-save-button]')?.getAttribute('aria-pressed') === 'true';

            if (!authenticated) {
                const current = localSaves();

                writeLocalSaves(wasSaved ? current.filter((saved) => saved !== id) : [...current, id]);
                paintHeart(form, !wasSaved);
                showPromptIfDue();

                return;
            }

            const ok = wasSaved
                ? await talkToServer(form.dataset.saveDestroy, 'DELETE', token, {})
                : await talkToServer(form.dataset.saveStore, 'POST', token, { occurrence_id: id });

            if (ok) {
                paintHeart(form, !wasSaved);

                return;
            }

            /* Il server ha risposto male: si lascia partire il modulo, che
               porta a una pagina con l'errore scritto invece che a un cuore
               che cambia colore senza aver salvato niente. */
            form.submit();
        });
    }
}

/**
 * «Salva tutte le date» (§15.3). Per chi è collegato è il modulo così com'è;
 * per chi non lo è diventano altrettante voci nel `localStorage`.
 */
function saveAllDates() {
    const { authenticated } = accountSettings();

    for (const form of document.querySelectorAll('[data-save-all]')) {
        if (authenticated) {
            continue;
        }

        form.addEventListener('submit', (event) => {
            event.preventDefault();

            const ids = (form.dataset.saveIds ?? '')
                .split(',')
                .map((value) => Number.parseInt(value, 10))
                .filter((id) => Number.isInteger(id) && id > 0);

            writeLocalSaves([...localSaves(), ...ids]);

            for (const heart of document.querySelectorAll('[data-save]')) {
                if (ids.includes(Number.parseInt(heart.dataset.saveId ?? '0', 10))) {
                    paintHeart(heart, true);
                }
            }

            showPromptIfDue();
        });
    }
}

/**
 * La migrazione dei salvataggi fatti da anonimo (§15.1).
 *
 * Parte a ogni pagina di chi è collegato e trova qualcosa nel browser: vale
 * quindi anche per chi l'account ce l'aveva già e ha salvato da sloggato, non
 * solo per chi si è appena registrato.
 *
 * **Il `localStorage` si svuota solo dopo la conferma del server**: è la
 * differenza fra una migrazione e una perdita di dati.
 */
async function mergeGuestSaves() {
    const { mergeUrl, token } = accountSettings();
    const ids = localSaves();

    if (mergeUrl === null || ids.length === 0) {
        return;
    }

    try {
        const response = await fetch(mergeUrl, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                Accept: 'application/json',
                'X-CSRF-TOKEN': token,
                'X-Requested-With': 'fetch',
            },
            body: JSON.stringify({ occurrence_ids: ids }),
        });

        if (!response.ok) {
            return;
        }

        writeLocalSaves([]);

        for (const heart of document.querySelectorAll('[data-save]')) {
            paintHeart(heart, true);
        }
    } catch {
        /* Nessuna rete: le date restano nel browser e la migrazione ritenta
           alla pagina successiva. */
    }
}

/**
 * Il banner del consenso (§16), che senza JavaScript funziona già.
 *
 * Qui si toglie solo il ricaricamento di pagina: il modulo viene inviato in
 * sottofondo e il banner scompare. Se la richiesta fallisce si **ripiega
 * sull'invio normale**, portandosi dietro la scelta: chiudere il banner senza
 * averla registrata sarebbe la cosa peggiore possibile — la scelta andrebbe
 * persa e il sito si comporterebbe come se fosse stata rifiutata, senza dirlo
 * a nessuno.
 *
 * ## Due trappole del DOM, entrambe incontrate davvero
 *
 * 1. **`form.action` non è l'indirizzo del modulo.** Un modulo che contiene un
 *    controllo chiamato `action` — e questo lo contiene, sono i tre pulsanti —
 *    espone quel controllo al posto della propria proprietà: `form.action`
 *    restituisce una `RadioNodeList`, che concatenata in una stringa diventa
 *    `[object RadioNodeList]`. La richiesta parte verso un indirizzo
 *    inesistente e l'unico segnale è un 404 in console. Si legge quindi
 *    l'attributo, che non si lascia oscurare.
 * 2. **Un invio programmatico non porta con sé il pulsante premuto.** Il campo
 *    `action` viaggia solo se qualcuno preme davvero il pulsante: nel ripiego
 *    va aggiunto a mano, altrimenti il server riceve un modulo senza scelta e
 *    lo rimanda indietro con un errore di validazione — banner ancora lì, e
 *    nessuno capisce perché.
 */
function consentBanner() {
    const banner = document.querySelector('[data-consent-banner]');
    const form = banner?.querySelector('[data-consent-form]');

    if (!banner || !(form instanceof HTMLFormElement)) {
        return;
    }

    /* Invio normale, con la scelta allegata: è ciò che sarebbe successo senza
       questo script. */
    const fallback = (scelta) => {
        const campo = document.createElement('input');
        campo.type = 'hidden';
        campo.name = 'action';
        campo.value = scelta;
        form.append(campo);
        form.submit();
    };

    form.addEventListener('submit', async (event) => {
        const submitter = event.submitter;

        if (!(submitter instanceof HTMLButtonElement) || submitter.name !== 'action') {
            return;
        }

        event.preventDefault();

        const scelta = submitter.value;
        const data = new FormData(form);
        data.set('action', scelta);

        try {
            const response = await fetch(form.getAttribute('action'), {
                method: 'POST',
                headers: { Accept: 'application/json', 'X-Requested-With': 'fetch' },
                body: data,
            });

            if (!response.ok) {
                fallback(scelta);

                return;
            }

            banner.remove();
        } catch {
            /* Nessuna rete: l'invio normale resta l'unica strada, e il banner
               resta dov'è finché non riesce. */
            fallback(scelta);
        }
    });
}

function start() {
    infiniteScroll();
    nativeShare();
    geolocation();
    savedHearts();
    saveAllDates();
    dismissPrompt();
    showPromptIfDue();
    consentBanner();
    void mergeGuestSaves();
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', start);
} else {
    start();
}

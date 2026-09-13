import './appearance';
if (document.querySelector('[data-rich-input]')) {
    import('./rich-input').then(({ richInputs }) => richInputs());
}
import { sponsorshipBanners } from './sponsorship-banner';
import './calendar-preview';
import { placeChoices } from './place-choices';
import { navigationMovement } from './scroll-navigation';
import { peekTabs } from './peek-tabs';
import { tonightCounts } from './tonight-counts';
import { eventFilters } from './event-filters';

import { sponsorshipContext } from './sponsorship-context';

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
    const results = document.querySelector("[data-results]");
    const pagination = document.querySelector("[data-pagination]");

    if (!results || !pagination || !("IntersectionObserver" in window)) {
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
            const response = await fetch(url, {
                headers: { "X-Requested-With": "fetch" },
            });

            if (!response.ok) {
                return;
            }

            const document_ = new DOMParser().parseFromString(
                await response.text(),
                "text/html",
            );
            const nextGrid = document_.querySelector("[data-results] > *");
            const nextPagination = document_.querySelector("[data-pagination]");
            const grid = results.firstElementChild;

            if (!results.isConnected) return;

            if (nextGrid === null || nextPagination === null || grid === null) {
                return;
            }

            /* Le card si appendono alla griglia esistente, non al contenitore:
               è la griglia a portare le colonne. */
            grid.append(...nextGrid.children);
            pagination.replaceChildren(...nextPagination.childNodes);
            window.history.replaceState({}, "", url);

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

    const observer = new IntersectionObserver(
        (entries) => {
            for (const entry of entries) {
                if (entry.isIntersecting) {
                    void load(observer);
                }
            }
        },
        { rootMargin: "400px 0px" },
    );

    observer.observe(pagination);
    document.addEventListener('event-browser:before-update', () => observer.disconnect(), { once: true });

    // Pause automatic pagination while the user is choosing filters.
    // The ordinary next-page link remains available.
    document.addEventListener('click', event => {
        if (event.target.closest('[data-filter-jump]')) observer.disconnect();
    });
}

/**
 * Condivisione con il menu di sistema, dove esiste. Il pulsante è nascosto in
 * partenza e compare solo qui: senza JavaScript restano i link ai singoli
 * servizi, che funzionano ovunque.
 */
function nativeShare() {
    for (const button of document.querySelectorAll("[data-share]")) {
        if (!(button instanceof HTMLElement)) {
            continue;
        }

        const payload = {
            title: button.dataset.shareTitle ?? document.title,
            text: button.dataset.shareText ?? "",
            url: button.dataset.shareUrl ?? window.location.href,
        };

        const canShare = typeof navigator.share === "function";
        const canCopy = navigator.clipboard !== undefined;

        if (!canShare && !canCopy) {
            continue;
        }

        button.classList.remove("hidden");

        button.addEventListener("click", async () => {
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
    const select =
        button.parentElement?.querySelector("[data-geolocate-radius-input]") ??
        document.querySelector("[data-geolocate-radius-input]");

    if (select instanceof HTMLSelectElement && select.value !== "") {
        return select.value;
    }

    return button.dataset.geolocateRadius ?? "5";
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
    for (const button of document.querySelectorAll("[data-geolocate]")) {
        if (
            !(button instanceof HTMLElement) ||
            navigator.geolocation === undefined
        ) {
            continue;
        }

        button.classList.remove("hidden");

        button.addEventListener("click", () => {
            if (button.getAttribute('aria-busy') === 'true') return;
            button.setAttribute("aria-busy", "true");
            const status = button.closest('section')?.querySelector('[data-geolocate-status]');
            if (status) status.textContent = button.dataset.geolocateLoading;

            navigator.geolocation.getCurrentPosition(
                (position) => {
                    const url = new URL(
                        button.dataset.geolocateUrl ?? window.location.href,
                        window.location.origin,
                    );

                    url.searchParams.set(
                        "lat",
                        position.coords.latitude.toFixed(5),
                    );
                    url.searchParams.set(
                        "lng",
                        position.coords.longitude.toFixed(5),
                    );
                    url.searchParams.set("radius", radius(button));
                    url.searchParams.set("sort", "distance");
                    url.searchParams.delete("page");

                    if (document.querySelector('[data-event-browser]')) {
                        document.dispatchEvent(new CustomEvent('event-browser:navigate', { detail: url.toString() }));
                    } else window.location.assign(url.toString());
                    button.removeAttribute('aria-busy');
                },
                (error) => {
                    button.removeAttribute("aria-busy");
                    if (status) status.textContent = error.code === 1 ? button.dataset.geolocateDenied : button.dataset.geolocateUnavailable;
                },
                {
                    enableHighAccuracy: false,
                    timeout: 10000,
                    maximumAge: 300000,
                },
            );
        });
    }
}

/*
 * Salvataggi (§15.1). **Il cuore funziona al primo click, senza registrazione.**
 *
 * Da anonimi le date vivono nel `localStorage` e nessuna richiesta parte: è
 * quello a rendere il gesto immediato e a non interrompere niente. Subito
 * dopo il primo salvataggio si apre il dialogo che offre l'accesso (D49) — a
 * salvataggio già scritto, mai prima: quello che l'account aggiunge è
 * ritrovare le date su ogni dispositivo e il promemoria, non il salvataggio,
 * che funziona già.
 *
 * Da collegati il cuore parla con il server. In entrambi i casi sotto c'è un
 * modulo vero: se questo file non venisse eseguito, il cuore resterebbe un
 * pulsante di invio che funziona con un ricaricamento di pagina.
 */
const SAVED_KEY = "salvataggi";
const PROMPT_KEY = "salvataggi.promemoria-nascosto";

function accountSettings() {
    const node = document.querySelector("[data-account]");

    return {
        authenticated: node?.dataset.accountAuthenticated === "1",
        promptAfter: Number.parseInt(
            node?.dataset.accountPromptAfter ?? "3",
            10,
        ),
        mergeUrl: node?.dataset.accountMerge ?? null,
        token:
            document
                .querySelector('meta[name="csrf-token"]')
                ?.getAttribute("content") ?? "",
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

        return Array.isArray(ids)
            ? ids.map(Number).filter((id) => Number.isInteger(id) && id > 0)
            : [];
    } catch {
        return [];
    }
}

function writeLocalSaves(ids) {
    try {
        window.localStorage.setItem(
            SAVED_KEY,
            JSON.stringify([...new Set(ids)]),
        );
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
    const button = form.querySelector("[data-save-button]");
    const icon = form.querySelector("[data-save-icon]");
    const text = form.querySelector("[data-save-text]");

    if (!button) {
        return;
    }

    button.setAttribute("aria-pressed", saved ? "true" : "false");
    button.classList.toggle("bg-brand", saved);
    button.classList.toggle("text-on-brand", saved);
    button.classList.toggle("ring-brand", saved);
    button.classList.toggle("bg-surface", !saved);
    button.classList.toggle("text-ink-muted", !saved);
    button.classList.toggle("ring-line", !saved);

    if (icon) {
        icon.style.fill = saved ? "currentColor" : "none";
        icon.style.stroke = saved ? "none" : "currentColor";
        icon.style.strokeWidth = saved ? "0" : "2";
    }

    if (text) {
        text.textContent = saved
            ? form.dataset.saveLabelSaved
            : form.dataset.saveLabel;
    }
}

/*
 * L'invito ad accedere, dopo il primo salvataggio da anonimo (D49).
 *
 * **Si apre a salvataggio gia' fatto.** Chi chiama questa funzione lo fa dopo
 * aver scritto nel `localStorage` e aver aggiornato il cuore: il click e'
 * andato a segno, e questo dialogo e' una proposta su quello che si e' appena
 * ottenuto — non un cancello messo davanti.
 *
 * Compare una volta sola per chi dice di no. Un invito che ricompare a ogni
 * cuore e' il modo piu' rapido di far chiudere la scheda, che e' esattamente
 * il rischio contro cui §15.1 metteva in guardia.
 */
function showPromptIfDue() {
    const prompt = document.querySelector("[data-save-prompt]");
    const { promptAfter } = accountSettings();

    if (!prompt || prompt.open || localSaves().length < promptAfter) {
        return;
    }

    try {
        if (window.localStorage.getItem(PROMPT_KEY) === "1") {
            return;
        }
    } catch {
        /* Se non si può leggere la preferenza, si mostra: è meno peggio che
           nasconderla per sempre. */
    }

    /*
     * `showModal` e non l'attributo `open`: solo il primo intrappola il focus,
     * rende inerte il resto della pagina e accende lo sfondo. Non esiste piu'
     * da nessuna parte che conti, ma se mancasse il dialogo resterebbe chiuso
     * e il salvataggio funzionerebbe comunque.
     */
    if (typeof prompt.showModal === "function") {
        prompt.showModal();
    } else {
        prompt.setAttribute("open", "");
    }
}

/**
 * Chiudere e' una risposta, non un rinvio: chi ha appena salvato ha gia'
 * ottenuto quello per cui aveva premuto, e non gli si richiede piu'.
 *
 * Vale per tutti i modi di chiudere — il pulsante, Esc, il click fuori — ed e'
 * per questo che la memoria si scrive sull'evento `close` del dialogo e non
 * dentro al gestore del pulsante: Esc non passa di li'.
 */
function dismissPrompt() {
    const prompt = document.querySelector("[data-save-prompt]");

    if (!prompt) {
        return;
    }

    prompt
        .querySelector("[data-save-prompt-dismiss]")
        ?.addEventListener("click", () => {
            prompt.close();
        });

    /*
     * Anche premere «crea un account» o «ho gia un account» e' una risposta.
     *
     * Quei due sono collegamenti: portano via dalla pagina, ma il dialogo non
     * viene mai chiuso e l'evento `close` non scatta — quindi la risposta non
     * si registra. Chi va alla registrazione, ci ripensa e torna indietro se
     * lo ritrova davanti; e nel frattempo resta a schermo per tutto il tempo
     * del caricamento, sopra una pagina che sta gia' cambiando.
     *
     * Si chiude prima di lasciare andare il click: il collegamento parte lo
     * stesso, perche' `close()` non lo annulla.
     */
    for (const azione of prompt.querySelectorAll("a[href]")) {
        azione.addEventListener("click", () => {
            prompt.close();
        });
    }

    /* Il click sullo sfondo: nel dialogo nativo l'evento arriva all'elemento
       stesso, mentre dentro arriva ai figli. */
    prompt.addEventListener("click", (event) => {
        if (event.target === prompt) {
            prompt.close();
        }
    });

    prompt.addEventListener("close", () => {
        try {
            window.localStorage.setItem(PROMPT_KEY, "1");
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
                "Content-Type": "application/json",
                Accept: "application/json",
                "X-CSRF-TOKEN": token,
                "X-Requested-With": "fetch",
            },
            body: method === "DELETE" ? null : JSON.stringify(body),
        });

        return response.ok;
    } catch {
        return false;
    }
}

let savedHeartsInitialized = false;
function savedHearts() {
    if (savedHeartsInitialized) return;
    savedHeartsInitialized = true;
    const { authenticated, token } = accountSettings();
    const pending = new Set();
    let saveRevision = 0;
    let savedSnapshot = null;
    const paintAll = (id, saved) => document.querySelectorAll('[data-save]').forEach(form => {
        if (Number(form.dataset.saveId) === id) paintHeart(form, saved);
    });
    let syncing = false;
    const synchronize = async () => {
        const url = document.querySelector('meta[name="saved-state-url"]')?.content;
        if (!authenticated || !url || document.hidden || syncing || pending.size) return;
        syncing = true;
        const revision = saveRevision;
        try {
            const response = await fetch(url, { headers: { Accept: 'application/json' }, cache: 'no-store' });
            if (!response.ok) return;
            const { ids } = await response.json();
            if (!Array.isArray(ids) || pending.size || revision !== saveRevision) return;
            const saved = new Set(ids.map(Number));
            const snapshot = [...saved].sort((a, b) => a - b).join(',');
            if (savedSnapshot !== null && savedSnapshot !== snapshot && document.querySelector('[data-saved-page]')) {
                window.location.reload();
                return;
            }
            savedSnapshot = snapshot;
            document.querySelectorAll('[data-save]').forEach(form => paintHeart(form, saved.has(Number(form.dataset.saveId))));
        } catch { /* Preserve the last confirmed state while offline. */ }
        finally { syncing = false; }
    };
    window.addEventListener('focus', synchronize);
    document.addEventListener('visibilitychange', synchronize);
    window.setInterval(synchronize, 30_000);
    synchronize();

    const attach = () => {
    const saves = localSaves();
    for (const form of document.querySelectorAll("[data-save]")) {
        if (form.dataset.saveBound) continue;
        form.dataset.saveBound = '1';
        const id = Number.parseInt(form.dataset.saveId ?? "0", 10);

        if (!authenticated) {
            paintHeart(form, saves.includes(id));
        }

        form.addEventListener("submit", async (event) => {
            event.preventDefault();
            if (pending.has(id)) return;

            const wasSaved =
                form
                    .querySelector("[data-save-button]")
                    ?.getAttribute("aria-pressed") === "true";

            if (!authenticated) {
                const current = localSaves();

                writeLocalSaves(
                    wasSaved
                        ? current.filter((saved) => saved !== id)
                        : [...current, id],
                );
                paintAll(id, !wasSaved);

                /* Solo quando si aggiunge: proporre un account a chi ha appena
                   tolto una data e' chiedere il contrario di quello che ha
                   appena detto. */
                if (!wasSaved) {
                    showPromptIfDue();
                }

                return;
            }

            pending.add(id);
            saveRevision++;
            const ok = wasSaved
                ? await talkToServer(
                      form.dataset.saveDestroy,
                      "DELETE",
                      token,
                      {},
                  )
                : await talkToServer(form.dataset.saveStore, "POST", token, {
                      occurrence_id: id,
                  });

            pending.delete(id);
            if (ok) {
                paintAll(id, !wasSaved);

                return;
            }

            /* Il server ha risposto male: si lascia partire il modulo, che
               porta a una pagina con l'errore scritto invece che a un cuore
               che cambia colore senza aver salvato niente. */
            form.submit();
        });
    } };
    attach();
    for (const name of ['event-browser:updated', 'calendar-day:updated']) {
        document.addEventListener(name, () => { attach(); void synchronize(); });
    }
}

/**
 * «Salva tutte le date» (§15.3). Per chi è collegato è il modulo così com'è;
 * per chi non lo è diventano altrettante voci nel `localStorage`.
 */
function saveAllDates() {
    const { authenticated } = accountSettings();

    for (const form of document.querySelectorAll("[data-save-all]")) {
        if (authenticated) {
            continue;
        }

        form.addEventListener("submit", (event) => {
            event.preventDefault();

            const ids = (form.dataset.saveIds ?? "")
                .split(",")
                .map((value) => Number.parseInt(value, 10))
                .filter((id) => Number.isInteger(id) && id > 0);

            writeLocalSaves([...localSaves(), ...ids]);

            for (const heart of document.querySelectorAll("[data-save]")) {
                if (
                    ids.includes(
                        Number.parseInt(heart.dataset.saveId ?? "0", 10),
                    )
                ) {
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
            method: "POST",
            headers: {
                "Content-Type": "application/json",
                Accept: "application/json",
                "X-CSRF-TOKEN": token,
                "X-Requested-With": "fetch",
            },
            body: JSON.stringify({ occurrence_ids: ids }),
        });

        if (!response.ok) {
            return;
        }

        writeLocalSaves([]);

        for (const heart of document.querySelectorAll("[data-save]")) {
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
    const banner = document.querySelector("[data-consent-banner]");
    const form = banner?.querySelector("[data-consent-form]");

    if (!banner || !(form instanceof HTMLFormElement)) {
        return;
    }

    /* Invio normale, con la scelta allegata: è ciò che sarebbe successo senza
       questo script. */
    const fallback = (scelta) => {
        const campo = document.createElement("input");
        campo.type = "hidden";
        campo.name = "action";
        campo.value = scelta;
        form.append(campo);
        form.submit();
    };

    form.addEventListener("submit", async (event) => {
        const submitter = event.submitter;

        if (
            !(submitter instanceof HTMLButtonElement) ||
            submitter.name !== "action"
        ) {
            return;
        }

        event.preventDefault();

        const scelta = submitter.value;
        const data = new URLSearchParams(new FormData(form));
        data.set("action", scelta);

        try {
            const response = await fetch(form.getAttribute("action"), {
                method: "POST",
                headers: {
                    Accept: "application/json",
                    "X-Requested-With": "fetch",
                },
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

/**
 * Le misure di una campagna sponsorizzata.
 *
 * **Perché dal browser e non dal server.** Le pagine pubbliche stanno in cache
 * per un minuto: il server disegna la card una volta e poi serve la stessa
 * pagina a tutti fino alla scadenza. Un contatore incrementato mentre si
 * disegna conterebbe una visualizzazione al minuto invece che una per
 * visitatore.
 *
 * **Una visualizzazione è quando la card è stata davvero vista**, non quando è
 * stata mandata: `IntersectionObserver` con soglia a metà elemento, e una sola
 * volta per campagna per pagina. Contare una card che sta a tremila pixel di
 * distanza, in fondo a una pagina che nessuno scorre, significa vendere aria.
 *
 * `keepalive` sulle chiamate: l'apertura porta via dalla pagina, e senza
 * quell'opzione il browser annulla la richiesta a metà.
 *
 * Se questo codice non gira, si perdono le misure — mai la navigazione: il
 * collegamento alla scheda è un `href` normale.
 */
function sponsorshipMetrics() {
    const cards = document.querySelectorAll("[data-sponsorship]");

    if (cards.length === 0) {
        return;
    }

    const token =
        document.querySelector('meta[name="csrf-token"]')?.content ?? "";

    const conta = (url, placement = 'card', click = false) => {
        if (!url) {
            return;
        }

        void fetch(url, {
            method: "POST",
            keepalive: true,
            headers: { "X-CSRF-TOKEN": token, Accept: "application/json", "Content-Type": "application/json" },
            body: JSON.stringify(sponsorshipContext(placement, click)),
        }).catch(() => {
            /* Una misura persa non è un problema di chi sta navigando: nessun
               messaggio, nessun tentativo ripetuto. */
        });
    };

    for (const card of cards) {
        const click = (event) => {
            if (!event.target.closest('a[rel~="sponsored"]')) return;
            conta(card.dataset.sponsorshipClick, card.dataset.sponsorshipPlacement || 'card', true);
        };
        card.addEventListener('click', click);
        card.addEventListener('auxclick', event => { if (event.button === 1) click(event); });
    }

    if (typeof IntersectionObserver !== "function") {
        return;
    }

    const osservatore = new IntersectionObserver(
        (voci) => {
            for (const voce of voci) {
                if (!voce.isIntersecting) {
                    continue;
                }

                conta(voce.target.dataset.sponsorshipImpression);
                osservatore.unobserve(voce.target);
            }
        },
        { threshold: 0.5 },
    );

    for (const card of cards) {
        osservatore.observe(card);
    }
}

/**
 * I dialoghi che si aprono da un pulsante: per ora la locandina di un evento.
 *
 * **`<dialog>` e non un riquadro fatto a mano.** Porta con se' il fondo
 * oscurato, la chiusura con Esc, il fuoco intrappolato dentro e il ritorno del
 * fuoco al pulsante che l'ha aperto — tutte cose che un `div` con
 * `position: fixed` finisce per rifare peggio.
 *
 * Il collegamento e' per attributo e non per classe: chi scrive il markup
 * dichiara *quale* dialogo apre, e due locandine sulla stessa pagina non si
 * aprono a vicenda.
 */
function dialoghi() {
    for (const bottone of document.querySelectorAll("[data-apre-dialogo]")) {
        const dialogo = document.getElementById(bottone.dataset.apreDialogo);

        if (!dialogo) {
            continue;
        }

        bottone.addEventListener("click", () => dialogo.showModal());

        /*
         * Un clic sul fondo scuro chiude, come ci si aspetta. L'`<dialog>` non
         * distingue il fondo dal contenuto: entrambi sono lo stesso elemento, e
         * l'unico modo di riconoscere il fondo e' che il bersaglio del clic sia
         * il dialogo stesso e non qualcosa dentro di lui.
         */
        dialogo.addEventListener("click", (evento) => {
            if (evento.target === dialogo || evento.target.hasAttribute("data-dialog-backdrop")) {
                dialogo.close();
            }
        });

        for (const chiusura of dialogo.querySelectorAll("[data-chiude-dialogo]")) {
            chiusura.addEventListener("click", () => dialogo.close());
        }
    }
}

/** Fa entrare le locandine in bianco e nero e restituisce il colore soltanto
 * quando il file è davvero pronto, evitando che la transizione finisca mentre
 * il browser sta ancora scaricando l'immagine. */
function rivelaLocandine() {
    for (const immagine of document.querySelectorAll("[data-poster-reveal]")) {
        const rivela = () => {
            requestAnimationFrame(() => requestAnimationFrame(() => immagine.classList.add("is-color")));
        };

        if (immagine.complete) {
            rivela();
        } else {
            immagine.addEventListener("load", rivela, { once: true });
        }
    }
}

/** Filtra l'archivio dei locali sul server mentre si scrive. Il modulo GET
 * resta pienamente funzionante senza JavaScript; qui sostituiamo soltanto il
 * blocco dei risultati e teniamo l'indirizzo condivisibile. */
function filtriLocaliRealtime() {
    const form = document.querySelector("[data-venue-filters]");
    let results = document.querySelector("[data-venue-results]");

    if (!(form instanceof HTMLFormElement) || results === null) {
        return;
    }

    let timer = null;
    let controller = null;

    const aggiorna = async () => {
        controller?.abort();
        controller = new AbortController();
        const parameters = new URLSearchParams(new FormData(form));
        parameters.delete("page");
        const url = `${form.action}?${parameters.toString()}`;
        results.setAttribute("aria-busy", "true");

        try {
            const response = await fetch(url, {
                headers: { "X-Requested-With": "fetch" },
                signal: controller.signal,
            });

            if (!response.ok) {
                return;
            }

            const nextDocument = new DOMParser().parseFromString(await response.text(), "text/html");
            const nextResults = nextDocument.querySelector("[data-venue-results]");

            if (nextResults !== null) {
                results.replaceWith(nextResults);
                results = nextResults;
                window.history.replaceState({}, "", url);
            }
        } catch (error) {
            if (error?.name !== "AbortError") {
                results.setAttribute("aria-busy", "false");
            }
        }
    };

    form.addEventListener("submit", (event) => {
        event.preventDefault();
        void aggiorna();
    });

    for (const field of form.querySelectorAll("select")) {
        field.addEventListener("change", () => void aggiorna());
    }

    const search = form.querySelector('input[name="q"]');
    search?.addEventListener("input", () => {
        window.clearTimeout(timer);
        timer = window.setTimeout(() => void aggiorna(), 260);
    });
}

/**
 * «Segui» senza ricaricare la pagina.
 *
 * Il modulo funziona da se': senza JavaScript invia, il server risponde con un
 * rimando e la pagina si ricarica. Qui lo si intercetta soltanto — non lo si
 * sostituisce — cosi' il gesto resta a un click in entrambi i casi, ed e' la
 * stessa scelta fatta per il cuore dei salvataggi.
 *
 * **Il pulsante cambia prima della risposta.** Chi preme «segui» ha gia'
 * deciso: aspettare mezzo secondo un riscontro dal server fa sembrare il sito
 * lento anche quando non lo e'. Se la richiesta fallisce si torna indietro, e
 * a quel punto l'errore e' vero e va mostrato.
 */
function follows() {
    for (const form of document.querySelectorAll("[data-follow]")) {
        const bottone = form.querySelector("button");

        if (!bottone) {
            continue;
        }

        form.addEventListener("submit", async (evento) => {
            evento.preventDefault();

            if (bottone.disabled) return;
            bottone.disabled = true;
            const seguiva = bottone.getAttribute("aria-pressed") === "true";
            dipingiFollow(bottone, form, !seguiva);

            try {
                const risposta = await fetch(seguiva ? form.dataset.followDestroy : form.dataset.followStore, {
                    method: seguiva ? "DELETE" : "POST",
                    headers: {
                        "Content-Type": "application/json",
                        Accept: "application/json",
                        "X-CSRF-TOKEN": form.querySelector("[name=_token]")?.value ?? "",
                    },
                    body: seguiva
                        ? null
                        : JSON.stringify({ type: form.dataset.followType, id: form.dataset.followId }),
                });

                if (!risposta.ok) {
                    throw new Error(String(risposta.status));
                }
                if (form.dataset.followReload === "true") window.location.reload();
            } catch {
                /* Si rimette com'era: un pulsante che dice «segui gia'» mentre
                   il server non ha registrato niente e' peggio di un gesto
                   fallito, perche' chi lo guarda non ha modo di saperlo. */
                dipingiFollow(bottone, form, seguiva);
            } finally {
                bottone.disabled = false;
            }
        });
    }
}

function dipingiFollow(bottone, form, segue) {
    bottone.setAttribute("aria-pressed", segue ? "true" : "false");
    bottone.textContent = segue ? form.dataset.followLabelFollowing : form.dataset.followLabel;

    bottone.classList.toggle("border-accent", segue);
    bottone.classList.toggle("bg-accent", segue);
    bottone.classList.toggle("text-on-accent", segue);
    bottone.classList.toggle("border-line", !segue);
    bottone.classList.toggle("bg-surface", !segue);
    bottone.classList.toggle("text-ink", !segue);
}

import { liveSearch, continuousTicker } from './live-search';
import { venueAutocomplete } from './venue-autocomplete';

function start() {
    eventFilters();
    document.addEventListener('event-browser:updated', () => {
        infiniteScroll();
        savedHearts();
        geolocation();
        rivelaLocandine();
        sponsorshipMetrics();
    });
    peekTabs();
    tonightCounts();
    const navigation = document.querySelector('[data-scroll-navigation]');
    if (navigation) {
        let previous = Math.max(0, window.scrollY);
        let movement = previous;
        const alwaysVisible = navigation.hasAttribute('data-navigation-always') || document.documentElement.scrollHeight <= window.innerHeight + 96;
        let navigationVisible = false;
        const updateNavigationSpace = () => {
            document.documentElement.style.setProperty('--visible-navigation-height', `${navigationVisible ? navigation.getBoundingClientRect().height : 0}px`);
        };
        const show = visible => {
            navigationVisible = visible;
            navigation.style.opacity = visible ? '1' : '0';
            navigation.style.pointerEvents = visible ? '' : 'none';
            navigation.inert = !visible;
            updateNavigationSpace();
        };
        show(alwaysVisible || previous >= 96);
        new ResizeObserver(updateNavigationSpace).observe(navigation);
        navigation.style.transition = window.matchMedia('(prefers-reduced-motion: reduce)').matches ? 'none' : 'opacity 200ms ease-out';
        window.addEventListener('scroll', () => {
            const current = Math.max(0, window.scrollY);
            const delta = current - previous;
            previous = current;
            const next = navigationMovement(movement, delta);
            movement = next.movement;
            if (next.visible !== null && !alwaysVisible && !navigation.contains(document.activeElement)) show(next.visible);
        }, { passive: true });
        // Place lists scroll independently of the page in the evening wizard.
        const listOffsets = new WeakMap();
        document.addEventListener('scroll', event => {
            const list = event.target;
            if (!(list instanceof HTMLElement) || !list.closest('[data-place-choices]')) return;
            const delta = list.scrollTop - (listOffsets.get(list) ?? 0);
            listOffsets.set(list, list.scrollTop);
            const next = navigationMovement(movement, delta);
            movement = next.movement;
            if (next.visible !== null && !alwaysVisible && !navigation.contains(document.activeElement)) show(next.visible);
        }, { capture: true, passive: true });
        // Keyboard navigation must remain possible without scrolling.
        window.addEventListener('keydown', event => { if (event.key === 'Tab') show(true); });
    }
    placeChoices();
    for (const button of document.querySelectorAll('[data-copy-calendar]')) {
        button.addEventListener('click', async () => {
            try {
                await navigator.clipboard.writeText(button.dataset.copyCalendar);
                button.textContent = button.dataset.copied;
            } catch { button.previousElementSibling?.querySelector('input')?.select(); }
        });
    }
    liveSearch();
    venueAutocomplete();
    continuousTicker();
    dialoghi();
    rivelaLocandine();
    filtriLocaliRealtime();
    follows();
    infiniteScroll();
    nativeShare();
    geolocation();
    savedHearts();
    saveAllDates();
    dismissPrompt();
    /*
     * `showPromptIfDue()` NON si chiama qui.
     *
     * Con il vecchio riquadro in fondo alla pagina aveva senso: chi tornava
     * con gia' tre salvataggi lo trovava li'. Ora e' un dialogo, e un dialogo
     * che si apre da solo appena si arriva su una pagina e' la cosa piu'
     * invadente che si possa fare — per giunta a chi non ha appena toccato
     * niente. Si apre solo come conseguenza di un gesto: il cuore, o «salva
     * tutte le date».
     */
    consentBanner();
    sponsorshipMetrics();
    sponsorshipBanners();
    void mergeGuestSaves();
}

if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", start);
} else {
    start();
}
import './ticketing';

import { eventWeather } from "./event-weather";
eventWeather();

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

function start() {
    infiniteScroll();
    nativeShare();
    geolocation();
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', start);
} else {
    start();
}

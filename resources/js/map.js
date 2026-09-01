/*
 * La mappa degli eventi (§11.6), su Leaflet e tessere OpenStreetMap.
 *
 * **Perché Leaflet e non MapLibre** (D46). Il disegno adottato mostra una mappa
 * in bianco e nero invertito, e la ottiene con una riga di CSS sul riquadro
 * delle tessere. MapLibre le disegna in WebGL — sono texture su una tela, e i
 * filtri CSS non le raggiungono: lo stesso risultato richiederebbe uno stile di
 * tessere su misura. Leaflet le dispone come normali elementi del documento,
 * quindi il filtro funziona. In più pesa una frazione.
 *
 * Tre regole d'ingaggio, invariate:
 *
 * - **Se questo file non parte, la pagina resta leggibile.** Sotto al riquadro
 *   c'è l'elenco degli stessi eventi, disegnato dal server.
 * - **La posizione non si chiede mai da soli.** La geolocalizzazione parte al
 *   tocco e basta (§11.7).
 * - **Le card non si disegnano qui.** Il foglio inferiore chiede al server la
 *   `<x-event-card>` già pronta: una seconda card scritta in JavaScript
 *   divergerebbe dalla prima al primo cambio di badge.
 */
import L from 'leaflet';

/*
 * I due fogli di stile di Leaflet NON si importano qui: stanno in
 * `resources/css/app.css`, prima delle nostre regole. Importati da questo
 * script finivano in un file caricato dopo il nostro, e a parità di
 * specificità vincevano loro — i controlli della mappa restavano bianchi.
 */

/*
 * `leaflet.markercluster` e un innesto scritto prima dei moduli: si aggancia a
 * `window.L` invece di esportare qualcosa. Importato con `import`, viene
 * valutato PRIMA che questo file possa assegnare quel simbolo — e allora
 * `L.markerClusterGroup` non esiste, la mappa muore nel costruttore e in
 * console non compare niente, perche l'errore avviene mentre il modulo si
 * valuta.
 *
 * Per questo `L` va messo sul contesto globale prima, e l'innesto va caricato
 * dopo, con un import a richiesta.
 */
window.L = L;

/*
 * Le icone predefinite di Leaflet arrivano da file PNG che la libreria cerca
 * accanto al proprio foglio di stile: dopo il raggruppamento di Vite quel
 * percorso non esiste più, e i marcatori sparirebbero senza un errore. Qui non
 * servono comunque — ogni marcatore è un `divIcon`, cioè un elemento del
 * documento che prende il colore dalla categoria.
 */
delete L.Icon.Default.prototype._getIconUrl;

/**
 * Da carico compatto a marcatori. I dati arrivano come liste posizionali
 * — `[idLocale, lng, lat, categoria, quante, nome]` — perché ripetere i nomi
 * dei campi cinquecento volte costa più dei dati stessi.
 *
 * **Tutti i punti hanno lo stesso colore.** Le categorie ne dichiarano uno
 * ciascuna, e per un po' i marcatori lo usavano: dieci tinte accese su una
 * mappa in scala di grigi, con una legenda sotto per decifrarle. Non regge in
 * questa tavolozza, che ha un accento solo — e non serve: a colpo d'occhio da
 * una mappa si legge DOVE succedono le cose e QUANTE ce ne sono, non di che
 * genere sono. Il genere lo dice il foglio che si apre toccando un punto.
 *
 * Il campo della categoria resta nel carico: lo usa chi filtra.
 *
 * @returns {Array<{venue: string, lat: number, lng: number, color: string, count: number}>}
 */
function toMarkers(payload, color) {
    return (payload.markers ?? [])
        .map((marker) => {
            const [venue, lng, lat, , count, name] = marker;

            if (typeof lat !== 'number' || typeof lng !== 'number') {
                return null;
            }

            return {
                venue: String(venue),
                name: String(name ?? ''),
                lat,
                lng,
                color,
                count: Number(count) || 1,
            };
        })
        .filter(Boolean);
}

/** Il riquadro visibile, nel formato che l'API si aspetta. */
function boundingBox(map) {
    const bounds = map.getBounds();

    return [
        bounds.getWest().toFixed(5),
        bounds.getSouth().toFixed(5),
        bounds.getEast().toFixed(5),
        bounds.getNorth().toFixed(5),
    ].join(',');
}

/** Un marcatore singolo: un punto pieno che pulsa, del colore della categoria. */
function pin(marker) {
    return L.divIcon({
        className: 'rm-pin',
        html:
            `<i class="rm-pin__halo" style="border-color:${marker.color}"></i>` +
            `<i class="rm-pin__dot" style="background:${marker.color}"></i>`,
        iconSize: [26, 26],
        iconAnchor: [13, 13],
    });
}

/** Un gruppo: il numero delle DATE, non dei locali. */
function clusterIcon(cluster, fallbackColor) {
    /* Si somma `count`, che è quante serate ha quel locale: "12" su un cerchio
       deve voler dire dodici serate, non dodici puntini. */
    const total = cluster
        .getAllChildMarkers()
        .reduce((sum, child) => sum + (child.options.eventCount ?? 1), 0);

    const size = total >= 20 ? 44 : total >= 5 ? 36 : 30;

    return L.divIcon({
        className: 'rm-cluster',
        html: `<span style="background:${fallbackColor}">${total}</span>`,
        iconSize: [size, size],
        iconAnchor: [size / 2, size / 2],
    });
}

/**
 * Accende UN riquadro.
 *
 * Ogni riquadro sta dentro un `[data-map-shell]` che contiene anche la propria
 * configurazione: e' cosi' che una pagina puo' averne piu' d'uno — la scheda
 * di un evento ha la mappa del locale, la lista ne ha una accanto ai risultati.
 * Cercare i pezzi nel documento intero, com'era prima, funzionava finche' la
 * mappa era una sola: dalla seconda in poi tutti i riquadri avrebbero preso la
 * configurazione del primo.
 *
 * La configurazione sta ACCANTO al riquadro e non dentro, perche' Leaflet
 * svuota il nodo di cui prende possesso.
 */
function avviaRiquadro(shell) {
    const container = shell.querySelector('[data-map]');

    if (!container) {
        return;
    }

    const configNode = shell.querySelector('[data-map-config]');

    if (!configNode) {
        console.error('[mappa] manca il nodo [data-map-config]', shell);

        return;
    }

    let config;

    try {
        config = JSON.parse(configNode.textContent ?? '{}');
    } catch (errore) {
        console.error('[mappa] configurazione illeggibile', errore);

        return;
    }

    const placeholder = container.querySelector('[data-map-placeholder]');
    const searchButton = shell.querySelector('[data-map-search]');
    const sheet = shell.querySelector('[data-map-sheet]');
    const sheetBody = shell.querySelector('[data-map-sheet-body]');
    const sheetClose = shell.querySelector('[data-map-sheet-close]');
    const truncated = shell.querySelector('[data-map-truncated]');

    placeholder?.remove();

    const map = L.map(container, {
        center: [config.center[1], config.center[0]],
        zoom: config.zoom,
        zoomControl: false,
        attributionControl: false,
    });

    /*
     * L'attribuzione è un obbligo della licenza ODbL, non una decorazione: può
     * cambiare posto, corpo e colore per stare nel disegno, ma resta leggibile
     * (D46). Il foglio di stile la rimpicciolisce e la incornicia.
     */
    L.control
        .attribution({ position: 'bottomright', prefix: false })
        .addAttribution(config.attribution)
        .addTo(map);

    /* L'indirizzo delle tessere arriva dalla configurazione e non e' scritto
       qui: se un giorno cambia fornitore, cambia in `config/map.php` insieme
       alla nota di attribuzione, che deve dire il vero. */
    L.tileLayer(config.tiles, {
        maxZoom: config.maxZoom ?? 19,
        subdomains: config.subdomains || 'abc',
        /* Sugli schermi fitti Leaflet sostituisce `{r}` con `@2x` e chiede la
           versione a doppia risoluzione, se il fornitore la serve. */
        detectRetina: true,
        /* L'attribuzione la governa il controllo qui sopra: se la dichiarasse
           anche il livello, comparirebbe due volte. */
        attribution: '',
    }).addTo(map);

    if (!config.static) {
        L.control.zoom({ position: 'topright' }).addTo(map);
    }

    /* La geolocalizzazione parte al tocco: nessuna richiesta all'apertura
       (§11.7), e la posizione non viene mai salvata — serve solo a spostare
       la vista. */
    const locate = L.control({ position: 'topright' });

    locate.onAdd = () => {
        const wrapper = L.DomUtil.create('div', 'leaflet-bar rm-locate');
        const button = L.DomUtil.create('a', '', wrapper);
        button.href = '#';
        button.title = config.labels.locate ?? '';
        button.setAttribute('role', 'button');
        button.textContent = '◎';

        L.DomEvent.on(button, 'click', (event) => {
            L.DomEvent.stop(event);
            map.locate({ setView: true, maxZoom: 15 });
        });

        return wrapper;
    };

    if (!config.static) {
        locate.addTo(map);
    }

    const cluster = L.markerClusterGroup({
        maxClusterRadius: 48,
        disableClusteringAtZoom: 16,
        showCoverageOnHover: false,
        spiderfyOnMaxZoom: false,
        iconCreateFunction: (group) => clusterIcon(group, config.fallbackColor),
    });

    map.addLayer(cluster);

    if (config.bounds && typeof config.bounds === 'object') {
        const { min_lng: minLng, min_lat: minLat, max_lng: maxLng, max_lat: maxLat } = config.bounds;

        if ([minLng, minLat, maxLng, maxLat].every((value) => typeof value === 'number')) {
            map.fitBounds(
                [
                    [minLat, minLng],
                    [maxLat, maxLng],
                ],
                { padding: [32, 32], animate: false },
            );
        }
    }

    let loading = false;

    const apply = (payload) => {
        cluster.clearLayers();

        cluster.addLayers(
            toMarkers(payload, config.fallbackColor).map((marker) =>
                L.marker([marker.lat, marker.lng], {
                    icon: pin(marker),
                    eventCount: marker.count,
                    keyboard: true,
                    /* Il NOME del locale, non il suo numero: il marcatore è un
                       elemento con `role="button"`, e il titolo è ciò che ne
                       annuncia uno screen reader. Con l'identificativo diceva
                       «pulsante 24». */
                    title: marker.name || undefined,
                    alt: marker.name || undefined,
                }).on('click', () => openSheet(marker.venue)),
            ),
        );

        if (truncated) {
            truncated.hidden = !payload.truncated;
        }
    };

    const load = async () => {
        if (loading) {
            return;
        }

        loading = true;

        if (searchButton) {
            searchButton.hidden = true;
            searchButton.textContent = config.labels.searching;
        }

        try {
            const url = new URL(config.endpoints.markers, window.location.origin);
            url.searchParams.set('bbox', boundingBox(map));

            const response = await fetch(url, { headers: { Accept: 'application/json' } });

            if (response.ok) {
                apply(await response.json());
            }
        } catch {
            /* Una richiesta che non arriva lascia sulla mappa i punti di prima:
               è meglio di una mappa che si svuota senza spiegazioni. */
        } finally {
            loading = false;

            if (searchButton) {
                searchButton.textContent = config.labels.searchHere;
            }
        }
    };

    const openSheet = async (venue) => {
        if (!sheet || !sheetBody) {
            return;
        }

        sheet.hidden = false;
        sheetBody.textContent = config.labels.searching;

        try {
            const response = await fetch(
                `${config.endpoints.venue.base}${venue}${config.endpoints.venue.query}`,
                { headers: { 'X-Requested-With': 'fetch' } },
            );

            sheetBody.innerHTML = response.ok ? await response.text() : '';

            if (!response.ok) {
                sheetBody.textContent = config.labels.error;
            }
        } catch {
            sheetBody.textContent = config.labels.error;
        }

        sheetClose?.focus();
    };

    const closeSheet = () => {
        if (sheet) {
            sheet.hidden = true;
        }

        if (sheetBody) {
            sheetBody.replaceChildren();
        }
    };

    sheetClose?.addEventListener('click', closeSheet);

    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape') {
            closeSheet();
        }
    });

    apply(config.payload ?? { markers: [], categories: [] });

    /* Spostata la vista, il pulsante "cerca in quest'area" compare: la mappa
       non ricarica da sé, perché chi trascina sta guardando, non chiedendo. */
    map.on('moveend', () => {
        if (searchButton) {
            searchButton.hidden = false;
        }
    });

    searchButton?.addEventListener('click', () => {
        void load();
    });

    /*
     * Un riquadro «fermo» — la mappa di un locale sulla sua scheda — mostra un
     * punto e basta: niente trascinamento, niente rotella, niente pulsanti.
     * E' una figura, non uno strumento, e trattarla come uno strumento significa
     * rubare lo scorrimento della pagina a chi passa sopra con il dito.
     */
    if (config.static) {
        map.dragging.disable();
        map.scrollWheelZoom.disable();
        map.doubleClickZoom.disable();
        map.touchZoom.disable();
        map.keyboard.disable();
    }
}

async function start() {
    const riquadri = document.querySelectorAll('[data-map-shell]');

    if (riquadri.length === 0) {
        return;
    }

    /* L'innesto dei gruppi si carica una volta sola, prima di tutti. */
    await import('leaflet.markercluster');

    for (const shell of riquadri) {
        avviaRiquadro(shell);
    }
}

/*
 * Un guasto qui non deve restare muto: sotto al riquadro c'e comunque l'elenco
 * degli eventi (§11.6), ma chi guarda i registri deve sapere perche la mappa
 * non c'e.
 */
const avvia = () => {
    start().catch((errore) => {
        console.error('[mappa] avvio fallito', errore);
    });
};

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', avvia);
} else {
    avvia();
}

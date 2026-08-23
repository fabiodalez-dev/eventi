/*
 * La mappa degli eventi (§11.6).
 *
 * Sta in un pacchetto suo, caricato dalla sola pagina `/mappa`: MapLibre pesa
 * più di tutto il resto del sito messo insieme, e chi apre la pagina iniziale
 * non deve pagarlo.
 *
 * Tre regole d'ingaggio:
 *
 * - **Se questo file non parte, la pagina resta leggibile.** Sotto al riquadro
 *   c'è l'elenco degli stessi eventi, disegnato dal server.
 * - **La posizione non si chiede mai da soli.** Il controllo di
 *   geolocalizzazione parte al tocco e basta (§11.7).
 * - **Le card non si disegnano qui.** Il foglio inferiore chiede al server la
 *   `<x-event-card>` già pronta: una seconda card scritta in JavaScript
 *   divergerebbe dalla prima al primo cambio di badge.
 */
/* MapLibre 6 non ha più un'esportazione predefinita: si importa ciò che
   serve, e `MapLibreMap` è l'alias di `Map` che non copre l'omonimo tipo
   nativo di JavaScript. */
import { AttributionControl, GeolocateControl, MapLibreMap, NavigationControl, setWorkerUrl } from 'maplibre-gl';
import 'maplibre-gl/dist/maplibre-gl.css';

/*
 * MapLibre disegna le tessere dentro un web worker, e da sé lo cerca **accanto
 * al proprio file**: deduce l'indirizzo da `import.meta.url`, che dopo il
 * raggruppamento di Vite è quello di questo pacchetto, non quello del
 * pacchetto originale. Il risultato è un 404 sul worker e una mappa che resta
 * grigia **senza sollevare alcun errore** — il guasto più difficile da
 * riconoscere, perché tutto il resto (controlli, attribuzione, tela) c'è.
 *
 * `?worker&url` chiede a Vite di costruire il worker come pacchetto a sé, con
 * dentro le sue dipendenze, e di restituirne l'indirizzo definitivo.
 */
import workerUrl from 'maplibre-gl/dist/maplibre-gl-worker.mjs?worker&url';

setWorkerUrl(workerUrl);

const SOURCE = 'eventi';
const CLUSTER_LAYER = 'eventi-gruppi';
const CLUSTER_COUNT_LAYER = 'eventi-gruppi-numero';
const POINT_LAYER = 'eventi-punti';

/**
 * Da carico compatto a GeoJSON. I marcatori arrivano come liste posizionali
 * — `[locale, lng, lat, categoria, quante]` — perché ripetere i nomi dei
 * campi cinquecento volte costa più dei dati stessi.
 */
function toGeoJson(payload, fallbackColor) {
    const categories = payload.categories ?? [];

    return {
        type: 'FeatureCollection',
        features: (payload.markers ?? []).map(([venue, lng, lat, category, count]) => ({
            type: 'Feature',
            geometry: { type: 'Point', coordinates: [lng, lat] },
            properties: {
                venue,
                count,
                color: categories[category]?.color ?? fallbackColor,
            },
        })),
    };
}

function boundingBox(map) {
    const bounds = map.getBounds();

    return [
        bounds.getWest().toFixed(5),
        bounds.getSouth().toFixed(5),
        bounds.getEast().toFixed(5),
        bounds.getNorth().toFixed(5),
    ].join(',');
}

/**
 * Quanto è cambiata l'inquadratura, in gradi. Serve a non far comparire
 * "Cerca in quest'area" a ogni tremolio del dito.
 */
function drift(previous, current) {
    if (previous === null) {
        return Number.POSITIVE_INFINITY;
    }

    const a = previous.split(',').map(Number);
    const b = current.split(',').map(Number);

    return Math.max(...a.map((value, index) => Math.abs(value - b[index])));
}

function start() {
    const container = document.querySelector('[data-map]');
    const configNode = document.querySelector('[data-map-config]');

    if (!container || !configNode) {
        return;
    }

    let config;

    try {
        config = JSON.parse(configNode.textContent ?? '{}');
    } catch {
        return;
    }

    const placeholder = container.querySelector('[data-map-placeholder]');
    const searchButton = document.querySelector('[data-map-search]');
    const sheet = document.querySelector('[data-map-sheet]');
    const sheetBody = document.querySelector('[data-map-sheet-body]');
    const sheetClose = document.querySelector('[data-map-sheet-close]');
    const legend = document.querySelector('[data-map-legend]');
    const truncated = document.querySelector('[data-map-truncated]');

    placeholder?.remove();

    const map = new MapLibreMap({
        container,
        style: config.style,
        center: config.center,
        zoom: config.zoom,
        attributionControl: false,
    });

    map.addControl(
        new AttributionControl({ compact: true, customAttribution: config.attribution }),
        'bottom-right',
    );

    map.addControl(new NavigationControl({ showCompass: false }), 'top-right');

    /* La geolocalizzazione parte al tocco: nessuna richiesta all'apertura. */
    map.addControl(
        new GeolocateControl({ trackUserLocation: false, showAccuracyCircle: true }),
        'top-right',
    );

    if (config.bounds && typeof config.bounds === 'object') {
        const { min_lng: minLng, min_lat: minLat, max_lng: maxLng, max_lat: maxLat } = config.bounds;

        if ([minLng, minLat, maxLng, maxLat].every((value) => typeof value === 'number')) {
            map.fitBounds([[minLng, minLat], [maxLng, maxLat]], { padding: 32, animate: false });
        }
    }

    let lastFetched = null;
    let loading = false;

    const setLegend = (payload) => {
        if (!legend) {
            return;
        }

        legend.replaceChildren(
            ...(payload.categories ?? []).map((category) => {
                const item = document.createElement('li');
                item.className = 'flex items-center gap-1.5';

                const dot = document.createElement('span');
                dot.setAttribute('aria-hidden', 'true');
                dot.className = 'size-2.5 rounded-pill';
                dot.style.backgroundColor = category.color;

                item.append(dot, document.createTextNode(category.name));

                return item;
            }),
        );
    };

    const apply = (payload) => {
        const source = map.getSource(SOURCE);

        if (source) {
            source.setData(toGeoJson(payload, config.fallbackColor));
        }

        setLegend(payload);

        if (truncated) {
            truncated.hidden = !payload.truncated;
        }
    };

    const load = async () => {
        if (loading) {
            return;
        }

        loading = true;
        lastFetched = boundingBox(map);

        if (searchButton) {
            searchButton.hidden = true;
            searchButton.textContent = config.labels.searching;
        }

        try {
            const url = new URL(config.endpoints.markers, window.location.origin);
            url.searchParams.set('bbox', lastFetched);

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
            const response = await fetch(`${config.endpoints.venue.base}${venue}${config.endpoints.venue.query}`, {
                headers: { 'X-Requested-With': 'fetch' },
            });

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

    map.on('load', () => {
        map.addSource(SOURCE, {
            type: 'geojson',
            data: toGeoJson(config.payload ?? { markers: [], categories: [] }, config.fallbackColor),
            cluster: true,
            clusterRadius: 48,
            clusterMaxZoom: 15,
            /* Il gruppo somma le date, non i locali: "12" su un cerchio deve
               voler dire dodici serate, non dodici puntini. */
            clusterProperties: { date: ['+', ['get', 'count']] },
        });

        map.addLayer({
            id: CLUSTER_LAYER,
            type: 'circle',
            source: SOURCE,
            filter: ['has', 'point_count'],
            paint: {
                'circle-color': config.fallbackColor,
                'circle-opacity': 0.85,
                'circle-radius': ['step', ['get', 'point_count'], 16, 5, 22, 20, 30],
                'circle-stroke-width': 2,
                'circle-stroke-color': '#ffffff',
            },
        });

        map.addLayer({
            id: CLUSTER_COUNT_LAYER,
            type: 'symbol',
            source: SOURCE,
            filter: ['has', 'point_count'],
            layout: {
                'text-field': ['get', 'date'],
                'text-font': ['Noto Sans Bold'],
                'text-size': 12,
            },
            paint: { 'text-color': '#ffffff' },
        });

        map.addLayer({
            id: POINT_LAYER,
            type: 'circle',
            source: SOURCE,
            filter: ['!', ['has', 'point_count']],
            paint: {
                'circle-color': ['get', 'color'],
                'circle-radius': ['step', ['get', 'count'], 8, 3, 11, 8, 14],
                'circle-stroke-width': 2,
                'circle-stroke-color': '#ffffff',
            },
        });

        lastFetched = boundingBox(map);
        setLegend(config.payload ?? {});

        map.on('click', POINT_LAYER, (event) => {
            const feature = event.features?.[0];

            if (feature) {
                void openSheet(feature.properties.venue);
            }
        });

        map.on('click', CLUSTER_LAYER, (event) => {
            const feature = event.features?.[0];

            if (feature) {
                map.easeTo({ center: feature.geometry.coordinates, zoom: map.getZoom() + 2 });
            }
        });

        for (const layer of [POINT_LAYER, CLUSTER_LAYER]) {
            map.on('mouseenter', layer, () => {
                map.getCanvas().style.cursor = 'pointer';
            });

            map.on('mouseleave', layer, () => {
                map.getCanvas().style.cursor = '';
            });
        }

        map.on('moveend', () => {
            if (!searchButton) {
                return;
            }

            searchButton.hidden = drift(lastFetched, boundingBox(map)) < config.panThreshold;
        });
    });

    /* Se lo stile non arriva (rete chiusa, servizio giù) la pagina resta
       quella di prima: l'elenco sotto al riquadro non dipende dalla mappa.
       L'errore però si scrive in console: un guasto silenzioso è la cosa più
       difficile da diagnosticare, e qui il sintomo sarebbe un rettangolo
       grigio senza alcuna spiegazione. */
    map.on('error', (event) => {
        console.error(event.error ?? event);

        if (searchButton) {
            searchButton.hidden = true;
        }
    });

    searchButton?.addEventListener('click', () => {
        void load();
    });
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', start);
} else {
    start();
}

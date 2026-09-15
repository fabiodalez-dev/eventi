import { revealPanel } from './motion';
/* Mappa pubblica vettoriale. Vie ed etichette restano leggibili a ogni zoom;
 * il foglio di un locale continua a essere disegnato dal server. */
let maplibregl = null;
let library = null;
import mapWorkerUrl from 'maplibre-gl/dist/maplibre-gl-worker.mjs?worker&url';

function loadLibrary() {
    library ??= import("maplibre-gl").then((module) => {
        maplibregl = module.default ?? module;
        maplibregl.setWorkerUrl(mapWorkerUrl);
    });

    return library;
}

function toMarkers(payload) {
    return (payload.markers ?? []).map((marker) => {
        const [venue, lng, lat, category, count, name] = marker;
        if (typeof lat !== "number" || typeof lng !== "number") return null;

        return {
            venue: String(venue), name: String(name ?? ""),
            category: String(category ?? ""), count: Number(count) || 1,
            lat, lng,
        };
    }).filter(Boolean);
}

function featureCollection(payload) {
    return {
        type: "FeatureCollection",
        features: toMarkers(payload).map((marker) => ({
            type: "Feature",
            geometry: { type: "Point", coordinates: [marker.lng, marker.lat] },
            properties: {
                venue: marker.venue, name: marker.name,
                category: marker.category, count: marker.count,
            },
        })),
    };
}

function boundingBox(map) {
    const bounds = map.getBounds();
    return [bounds.getWest(), bounds.getSouth(), bounds.getEast(), bounds.getNorth()]
        .map((value) => value.toFixed(5)).join(",");
}

const CONFRONTI_NUMERICI = new Set(["<", "<=", ">", ">="]);

/**
 * Mette al riparo i confronti numerici dello stile su proprietà che possono
 * non esserci.
 *
 * **Il difetto a monte.** Lo stile confronta così:
 *
 *     ["<=", ["get", "ref_length"], 6]
 *
 * Su una geometria che quella proprietà non ce l'ha, `get` restituisce `null`,
 * `<=` si aspetta un numero, e MapLibre scrive in console
 * «Expected value to be of type number, but found null instead. Falling back to
 * false» — una riga per ogni riquadro di mappa scaricato, quindi decine per
 * ogni spostamento. Non rompe niente: il ripiego è `false`, ed è anche il
 * comportamento giusto (senza la sigla di una strada non c'è scudo da
 * disegnare). Solo che riempie la console e nasconde gli errori veri.
 *
 * **La riparazione** aggiunge la condizione che manca, `["has", "ref_length"]`:
 * se la proprietà non c'è il confronto non viene nemmeno tentato e il risultato
 * complessivo resta `false`. Identico a prima, in silenzio.
 *
 * Si ripara **lo schema, non i tre casi che fanno rumore oggi**: gli stessi
 * confronti scoperti sono sei nello stile chiaro e quattro nello scuro, e
 * quali facciano rumore dipende da quali proprietà i riquadri contengono
 * davvero — cioè da quale città si sta guardando.
 *
 * I filtri in forma vecchia (`["<=", "ref_length", 6]`, con il nome della
 * proprietà al posto di un `get`) non vengono toccati: là MapLibre applica
 * regole proprie e non segnala nulla.
 */
function proteggiConfronti(nodo) {
    if (!Array.isArray(nodo)) return nodo;

    const protetto = nodo.map(proteggiConfronti);

    if (protetto.length === 3 && CONFRONTI_NUMERICI.has(protetto[0])) {
        const proprieta = [protetto[1], protetto[2]]
            .filter(lato => Array.isArray(lato) && lato.length === 2 && lato[0] === "get" && typeof lato[1] === "string")
            .map(lato => lato[1]);

        if (proprieta.length > 0) {
            return ["all", ...proprieta.map(nome => ["has", nome]), protetto];
        }
    }

    return protetto;
}

async function vectorStyle(url) {
    const response = await fetch(url, { headers: { Accept: "application/json" } });
    if (!response.ok) throw new Error(`stile mappa non disponibile (${response.status})`);
    const style = await response.json();

    /* Lo stile upstream nomina ancora alcuni font rimossi dal relativo server.
       Noto Sans è disponibile nello stesso endpoint e impedisce 404 e fallback
       diversi da browser a browser. */
    for (const layer of style.layers ?? []) {
        if (layer.type === "symbol" && layer.layout?.["text-field"] !== undefined) {
            layer.layout["text-font"] = ["Noto Sans Regular"];
        }

        if (layer.filter !== undefined) {
            layer.filter = proteggiConfronti(layer.filter);
        }
    }

    return style;
}

const startedMaps = new WeakSet();

async function startMap(shell) {
    if (startedMaps.has(shell)) return;
    startedMaps.add(shell);
    const mobileMarkers = window.matchMedia('(max-width: 767px)').matches;
    const container = shell.querySelector("[data-map]");
    const configNode = shell.querySelector("[data-map-config]");
    if (!container || !configNode) return;

    let config = JSON.parse(configNode.textContent ?? "{}");
    const searchButton = shell.querySelector("[data-map-search]");
    const sheet = shell.querySelector("[data-map-sheet]");
    const sheetBody = shell.querySelector("[data-map-sheet-body]");
    const sheetClose = shell.querySelector("[data-map-sheet-close]");
    const markerList = shell.querySelector("[data-map-marker-list]");
    const truncated = shell.parentElement?.querySelector("[data-map-truncated]");

    /*
     * Da qui in poi la mappa e' in arrivo: il riquadro si spegne.
     *
     * Si dichiara PRIMA di scaricare lo stile, che e' una richiesta di rete.
     * Nei primi centocinquanta millisecondi, altrimenti, si vede lampeggiare
     * il messaggio «mappa non disponibile» — che e' il ripiego per chi non ha
     * JavaScript, non un messaggio di caricamento — poi un rettangolo grigio
     * vuoto, e infine la mappa che scatta dentro di colpo. Tre stati in mezzo
     * secondo, di cui uno dice il falso.
     *
     * Con JavaScript spento questo attributo non viene mai messo e il ripiego
     * resta visibile: e' esattamente il suo mestiere.
     */
    container.dataset.mapState = "loading";
    container.querySelector("[data-map-placeholder]")?.remove();

    const isLight = () => document.documentElement.dataset.theme === 'light';
    const colors = () => ({ accent: isLight() ? '#b54d23' : config.fallbackColor, canvas: isLight() ? '#faf9f6' : '#0b0b0b', onAccent: isLight() ? '#faf9f6' : '#0b0b0b' });
    const styleUrl = () => isLight() ? (config.lightStyle ?? config.style) : config.style;
    const initialStyleUrl = styleUrl();
    const initialTheme = isLight() ? 'light' : 'dark';
    let style;
    try { style = await vectorStyle(initialStyleUrl); } catch (error) { startedMaps.delete(shell); throw error; }
    container.dataset.mapTheme = initialTheme;
    config = JSON.parse(configNode.textContent ?? "{}");
    if (!shell.isConnected) return;
    const map = new maplibregl.Map({
        container,
        style,
        center: config.center,
        zoom: config.zoom,
        maxZoom: config.maxZoom ?? 19,
        attributionControl: false,
        pitchWithRotate: false,
        dragRotate: false,
        touchPitch: false,
    });

    let attesaInCorso = false;
    let reteDiSicurezza = null;

    const mappaPronta = () => {
        window.clearTimeout(reteDiSicurezza);
        attesaInCorso = false;
        delete container.dataset.mapState;
    };

    const attendi = (stato, evento, tetto) => {
        container.dataset.mapState = stato;
        window.clearTimeout(reteDiSicurezza);
        reteDiSicurezza = window.setTimeout(mappaPronta, tetto);
        if (attesaInCorso) return;
        attesaInCorso = true;
        map.once(evento, mappaPronta);
    };

    /*
     * Quando rivelare la mappa: il PRIMO fra «pronta» e un breve tetto.
     *
     * I fatti misurati su questo sito, non le definizioni della libreria:
     *
     *   canvas creato      ~150ms
     *   mappa disegnata    ~400ms   (guardata: strade, toponimi, marcatori)
     *   evento `load`     ~1600ms
     *   evento `idle`     ~1900ms
     *
     * Gli eventi della libreria arrivano quando la mappa e' finita per DAVVERO
     * — glifi, sorgenti, riquadri ai bordi — e quel di piu' non si vede.
     * Aspettarli significa tenere un rettangolo grigio per un secondo e mezzo
     * e poi mostrare la stessa identica immagine che c'era gia'.
     *
     * Quindi si rivela al primo dei due: `load` se arriva presto (rete veloce,
     * riquadri in cache), altrimenti il tetto. Il tetto e' un numero scelto a
     * mano e va dichiarato per quello che e': 300ms dalla COSTRUZIONE della
     * mappa, cioe' appena sopra i ~200ms che le servono per disegnarsi. Prima
     * di questo punto il riquadro e' grigio comunque, perche' lo stile non e'
     * ancora arrivato e non c'e' nulla da mostrare.
     *
     * Su una rete lenta la mappa comparira' ancora incompleta — ma incompleta
     * e in dissolvenza e' meglio di completa dopo un secondo e mezzo di grigio.
     */
    container.dataset.mapState = "loading";
    reteDiSicurezza = window.setTimeout(mappaPronta, 300);
    map.once("load", mappaPronta);
    map.on("remove", () => window.clearTimeout(reteDiSicurezza));

    document.addEventListener('event-browser:before-update', () => map.remove(), { once: true });
    map.on("styleimagemissing", (event) => {
        if (!map.hasImage(event.id)) {
            map.addImage(event.id, { width: 1, height: 1, data: new Uint8Array([0, 0, 0, 0]) });
        }
    });

    if (config.static) {
        map.boxZoom.disable();
        map.scrollZoom.disable();
        map.dragPan.disable();
        map.keyboard.disable();
        map.doubleClickZoom.disable();
        map.touchZoomRotate.disable();
    } else {
        map.addControl(new maplibregl.NavigationControl({ showCompass: false }), "top-right");
        map.addControl(new maplibregl.GeolocateControl({
            positionOptions: { enableHighAccuracy: true },
            fitBoundsOptions: { maxZoom: 15 },
            trackUserLocation: false,
            showUserHeading: false,
        }), "top-right");
    }

    let userMarker;
    const updateUserPosition = () => {
        userMarker?.remove();
        userMarker = null;
        if (!Array.isArray(config.userPosition) || !config.userPosition.every(Number.isFinite)) return;
        const element = document.createElement('div');
        element.className = 'map-user-location';
        element.setAttribute('role', 'img');
        element.setAttribute('aria-label', config.labels.yourPosition);
        element.title = config.labels.yourPosition;
        element.innerHTML = '<svg aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="8" r="3"/><path d="M6 20v-2a6 6 0 0 1 12 0v2"/></svg>';
        userMarker = new maplibregl.Marker({ element }).setLngLat(config.userPosition).addTo(map);
    };
    updateUserPosition();

    let loading = false;
    let settled = false;
    let currentPayload = config.payload ?? { markers: [], categories: [] };
    let ready = false;
    let automaticFrame = false;
    let frameRequest = null;

    const frameResults = () => {
        // Explicit venue/event centres stay fixed. Result maps include every
        // loaded marker, even beyond the city boundary or after a resize.
        if (!config.bounds) return;
        const markers = toMarkers(currentPayload);
        if (Array.isArray(config.userPosition)) markers.push({ lng: config.userPosition[0], lat: config.userPosition[1] });
        const bounds = markers.length ? {
            min_lng: Math.min(...markers.map(marker => marker.lng)),
            max_lng: Math.max(...markers.map(marker => marker.lng)),
            min_lat: Math.min(...markers.map(marker => marker.lat)),
            max_lat: Math.max(...markers.map(marker => marker.lat)),
        } : config.bounds;
        if (!Object.values(bounds).every(Number.isFinite)) return;
        map.fitBounds([
            [bounds.min_lng, bounds.min_lat],
            [bounds.max_lng, bounds.max_lat],
        ], { padding: { top: 56, bottom: 56, left: 56, right: 88 }, maxZoom: 14, duration: 0 });
        if (searchButton) searchButton.hidden = true;
    };

    const scheduleFraming = () => {
        if (!ready) return;
        automaticFrame = true;
        cancelAnimationFrame(frameRequest);
        frameRequest = requestAnimationFrame(() => {
            frameResults();
            automaticFrame = false;
        });
    };
    // MapLibre observes the container, including responsive column changes.
    map.on("resize", scheduleFraming);

    let sheetRevision = 0;
    let filterRevision = 0;
    const closeSheet = () => {
        sheetRevision++;
        document.querySelectorAll('.is-map-selected').forEach(card => card.classList.remove('is-map-selected'));
        if (sheet) sheet.hidden = true;
        sheetBody?.replaceChildren();
    };

    const openSheet = async (venue) => {
        if (!sheet || !sheetBody) return;
        document.querySelectorAll('[data-card-venue]').forEach(card => {
            card.classList.toggle('is-map-selected', card.dataset.cardVenue === String(venue));
        });
        const requestRevision = ++sheetRevision;
        sheet.hidden = false;
        revealPanel(sheet);
        sheetBody.textContent = config.labels.searching;

        try {
            const response = await fetch(
                `${config.endpoints.venue.base}${venue}${config.endpoints.venue.query}`,
                { headers: { "X-Requested-With": "fetch" } },
            );
            const html = response.ok ? await response.text() : "";
            if (requestRevision !== sheetRevision) return;
            sheetBody.innerHTML = html;
            if (!response.ok) sheetBody.textContent = config.labels.error;
        } catch {
            if (requestRevision !== sheetRevision) return;
            sheetBody.textContent = config.labels.error;
        }

        sheetClose?.focus();
    };

    const renderAccessibleMarkers = (payload) => {
        if (!markerList) return;
        markerList.replaceChildren();

        for (const marker of toMarkers(payload)) {
            const item = document.createElement("li");
            const button = document.createElement("button");
            button.type = "button";
            button.textContent = marker.name;
            button.addEventListener("click", () => void openSheet(marker.venue));
            item.append(button);
            markerList.append(item);
        }
    };

    /*
     * Quando la mappa si puo' mostrare: a `load`, non a `idle`.
     *
     * Sembrano equivalenti e non lo sono. `load` e' definito come «il primo
     * disegno visivamente completo»; `idle` significa «non ho piu'
     * assolutamente niente da fare», quindi aspetta anche i riquadri ai bordi,
     * il raggruppamento dei marcatori, le richieste in coda.
     *
     * Misurato su questo sito: a 400ms la mappa e' gia' disegnata per intero —
     * strade, toponimi, marcatori — e `idle` arriva a 1900ms. Rivelare a
     * `idle` fa aspettare un secondo e mezzo davinti a un rettangolo grigio
     * vuoto per mostrare esattamente la stessa immagine. Una dissolvenza che
     * arriva tardi non e' piu' gentile di uno scatto: e' solo piu' lenta.
     *
     * Per l'AGGIORNAMENTO dei filtri vale `idle`, che li' e' la domanda giusta
     * — i marcatori nuovi devono essersi posati — ma con un tetto piu' basso,
     * perche' la mappa e' gia' sotto gli occhi e tenerla attenuata a lungo si
     * nota piu' dell'aggiornamento stesso.
     *
     * **La rete di sicurezza non e' un dettaglio.** Se i riquadri non arrivano
     * — niente rete, server della cartografia giu' — l'evento non arriva mai,
     * e senza tetto il riquadro resterebbe invisibile per sempre: avremmo
     * sostituito una comparsa brusca con una mappa che non c'e'.
     */

    const apply = (payload) => {
        currentPayload = payload;
        map.getSource("events")?.setData(featureCollection(payload));
        renderAccessibleMarkers(payload);
        if (truncated) truncated.hidden = !payload.truncated;
    };

    const updateFilters = event => {
        filterRevision++;
        config = event.detail;
        /* Cambiare filtro rifa' i marcatori e riquadra la mappa: finche' non si
           e' fermata, il riquadro si attenua invece di mostrare la transizione
           a meta'. Non si spegne del tutto come all'avvio — qui una mappa c'e'
           gia', e farla sparire a ogni filtro sarebbe peggio del difetto. */
        attendi("updating", "idle", 900);
        updateUserPosition();
        closeSheet();
        apply(config.payload ?? { markers: [], categories: [] });
        scheduleFraming();
        if (searchButton) searchButton.hidden = true;
    };
    shell.addEventListener('map:filters', updateFilters);
    const visibility = new IntersectionObserver(entries => {
        if (!entries[0].isIntersecting) closeSheet();
    });
    visibility.observe(shell);
    map.on('remove', () => {
        visibility.disconnect();
        userMarker?.remove();
        shell.removeEventListener('map:filters', updateFilters);
        startedMaps.delete(shell);
    });

    const load = async () => {
        if (loading) return;
        loading = true;
        const requestRevision = filterRevision;
        if (searchButton) {
            searchButton.hidden = true;
            searchButton.textContent = config.labels.searching;
        }

        try {
            const url = new URL(config.endpoints.markers, window.location.origin);
            url.searchParams.set("bbox", boundingBox(map));
            const response = await fetch(url, { headers: { Accept: "application/json" } });
            if (response.ok) {
                const payload = await response.json();
                if (requestRevision === filterRevision) apply(payload);
            }
        } catch {
            /* Mantiene i punti precedenti se la rete non risponde. */
        } finally {
            loading = false;
            if (searchButton) searchButton.textContent = config.labels.searchHere;
        }
    };

    sheetClose?.addEventListener("click", closeSheet);
    document.addEventListener("keydown", (event) => {
        if (event.key === "Escape") closeSheet();
    });

    map.on("style.load", () => {
        map.addSource("events", {
            type: "geojson", data: featureCollection(currentPayload),
            cluster: true, clusterMaxZoom: 15, clusterRadius: 48,
        });

        map.addLayer({
            id: "event-clusters", type: "circle", source: "events",
            filter: ["has", "point_count"],
            paint: {
                "circle-color": colors().accent,
                "circle-radius": mobileMarkers ? ["step", ["get", "point_count"], 30, 5, 33, 20, 36] : ["step", ["get", "point_count"], 24, 5, 27, 20, 30],
                "circle-stroke-color": colors().canvas, "circle-stroke-width": 2,
            },
        });
        map.addLayer({
            id: "event-cluster-count", type: "symbol", source: "events",
            filter: ["has", "point_count"],
            layout: {
                "text-field": ["get", "point_count_abbreviated"],
                "text-size": 15,
                "text-font": ["Noto Sans Bold"],
            },
            paint: { "text-color": colors().onAccent },
        });
        map.addLayer({
            id: "event-points-halo", type: "circle", source: "events",
            filter: ["!", ["has", "point_count"]],
            paint: { "circle-color": colors().accent, "circle-radius": mobileMarkers ? 27 : 19, "circle-opacity": 0.22 },
        });
        map.addLayer({
            id: "event-points", type: "circle", source: "events",
            filter: ["!", ["has", "point_count"]],
            paint: {
                "circle-color": colors().accent, "circle-radius": mobileMarkers ? 18 : 12,
                "circle-stroke-color": colors().canvas, "circle-stroke-width": 2,
            },
        });

        // A 48px touch target, independent of the visible dot; still opens the preview sheet.
        map.addLayer({
            id: "event-points-hit", type: "circle", source: "events",
            filter: ["!", ["has", "point_count"]],
            paint: { "circle-radius": mobileMarkers ? 32 : 24, "circle-opacity": 0 },
        });

    });

    map.on("load", () => {
        map.on("click", "event-clusters", async (event) => {
            const feature = map.queryRenderedFeatures(event.point, { layers: ["event-clusters"] })[0];
            const clusterId = feature?.properties?.cluster_id;
            if (clusterId === undefined) return;
            const zoom = await map.getSource("events").getClusterExpansionZoom(clusterId);
            map.easeTo({ center: feature.geometry.coordinates, zoom });
        });
        map.on("click", "event-points-hit", (event) => {
            const venue = event.features?.[0]?.properties?.venue;
            if (venue !== undefined) void openSheet(String(venue));
        });

        for (const layer of ["event-clusters", "event-points-hit"]) {
            map.on("mouseenter", layer, () => { map.getCanvas().style.cursor = "pointer"; });
            map.on("mouseleave", layer, () => { map.getCanvas().style.cursor = ""; });
        }

        ready = true;
        scheduleFraming();

        renderAccessibleMarkers(currentPayload);
        map.once("idle", () => { settled = true; });
    });

    let themeRevision = 0;
    let requestedStyleUrl = initialStyleUrl;
    const changeTheme = async () => {
        const nextUrl = styleUrl();
        if (requestedStyleUrl === nextUrl) return;
        requestedStyleUrl = nextUrl;
        const revision = ++themeRevision;
        try {
            const nextStyle = await vectorStyle(nextUrl);
            if (revision === themeRevision && shell.isConnected) {
                map.setStyle(nextStyle);
                container.dataset.mapTheme = isLight() ? 'light' : 'dark';
            }
        } catch { if (revision === themeRevision) requestedStyleUrl = null; }
    };
    const themeObserver = new MutationObserver(changeTheme);
    themeObserver.observe(document.documentElement, { attributes: true, attributeFilter: ['data-theme'] });
    window.addEventListener('appearance:change', changeTheme);
    // Catch a theme switch while the initial vector style was downloading.
    changeTheme();
    map.on('remove', () => {
        themeObserver.disconnect();
        themeRevision++;
        ready = false;
        cancelAnimationFrame(frameRequest);
        window.removeEventListener('appearance:change', changeTheme);
    });
    const highlightCard = event => {
        const card = event.target.closest?.('[data-card-venue]');
        if (!card || !map.getLayer('event-points-halo')) return;
        map.setPaintProperty('event-points-halo', 'circle-opacity', ['case', ['==', ['get', 'venue'], card.dataset.cardVenue], 0.65, 0.22]);
    };
    const clearCardHighlight = event => {
        const card = event.target.closest?.('[data-card-venue]');
        if (card && !card.contains(event.relatedTarget) && map.getLayer('event-points-halo')) {
            map.setPaintProperty('event-points-halo', 'circle-opacity', 0.22);
        }
    };
    document.addEventListener('pointerover', highlightCard);
    document.addEventListener('focusin', highlightCard);
    document.addEventListener('pointerout', clearCardHighlight);
    document.addEventListener('focusout', clearCardHighlight);
    map.on('remove', () => {
        document.removeEventListener('pointerover', highlightCard);
        document.removeEventListener('focusin', highlightCard);
        document.removeEventListener('pointerout', clearCardHighlight);
        document.removeEventListener('focusout', clearCardHighlight);
    });
    map.on("moveend", () => {
        if (settled && !automaticFrame && searchButton && !config.static) searchButton.hidden = false;
    });
    searchButton?.addEventListener("click", () => void load());
}

async function start() {
    const shells = [...document.querySelectorAll("[data-map-shell]")].filter(shell => !startedMaps.has(shell));
    if (shells.length === 0) return;
    const activate = async (shell) => { await loadLibrary(); await startMap(shell); };

    if (!("IntersectionObserver" in window)) {
        for (const shell of shells) await activate(shell);
        return;
    }

    const observer = new IntersectionObserver((entries, self) => {
        for (const entry of entries) {
            if (!entry.isIntersecting) continue;
            self.unobserve(entry.target);
            activate(entry.target).catch((error) => console.error("[mappa] avvio fallito", error));
        }
    }, { rootMargin: "500px 0px" });
    for (const shell of shells) observer.observe(shell);
    document.addEventListener('event-browser:before-update', () => observer.disconnect(), { once: true });
}

const boot = () => start().catch((error) => console.error("[mappa] avvio fallito", error));
if (document.readyState === "loading") document.addEventListener("DOMContentLoaded", boot);
else boot();
document.addEventListener('event-browser:updated', boot);

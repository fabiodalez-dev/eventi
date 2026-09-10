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
    }

    return style;
}

async function startMap(shell) {
    const mobileMarkers = window.matchMedia('(max-width: 767px)').matches;
    const container = shell.querySelector("[data-map]");
    const configNode = shell.querySelector("[data-map-config]");
    if (!container || !configNode) return;

    const config = JSON.parse(configNode.textContent ?? "{}");
    const searchButton = shell.querySelector("[data-map-search]");
    const sheet = shell.querySelector("[data-map-sheet]");
    const sheetBody = shell.querySelector("[data-map-sheet-body]");
    const sheetClose = shell.querySelector("[data-map-sheet-close]");
    const markerList = shell.querySelector("[data-map-marker-list]");
    const truncated = shell.parentElement?.querySelector("[data-map-truncated]");

    container.querySelector("[data-map-placeholder]")?.remove();

    const isLight = () => document.documentElement.dataset.theme === 'light';
    const colors = () => ({ accent: isLight() ? '#b54d23' : config.fallbackColor, canvas: isLight() ? '#faf9f6' : '#0b0b0b', onAccent: isLight() ? '#faf9f6' : '#0b0b0b' });
    const styleUrl = () => isLight() ? (config.lightStyle ?? config.style) : config.style;
    const style = await vectorStyle(styleUrl());
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

    const closeSheet = () => {
        if (sheet) sheet.hidden = true;
        sheetBody?.replaceChildren();
    };

    const openSheet = async (venue) => {
        if (!sheet || !sheetBody) return;
        sheet.hidden = false;
        sheetBody.textContent = config.labels.searching;

        try {
            const response = await fetch(
                `${config.endpoints.venue.base}${venue}${config.endpoints.venue.query}`,
                { headers: { "X-Requested-With": "fetch" } },
            );
            sheetBody.innerHTML = response.ok ? await response.text() : "";
            if (!response.ok) sheetBody.textContent = config.labels.error;
        } catch {
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

    const apply = (payload) => {
        currentPayload = payload;
        map.getSource("events")?.setData(featureCollection(payload));
        renderAccessibleMarkers(payload);
        if (truncated) truncated.hidden = !payload.truncated;
    };

    const load = async () => {
        if (loading) return;
        loading = true;
        if (searchButton) {
            searchButton.hidden = true;
            searchButton.textContent = config.labels.searching;
        }

        try {
            const url = new URL(config.endpoints.markers, window.location.origin);
            url.searchParams.set("bbox", boundingBox(map));
            const response = await fetch(url, { headers: { Accept: "application/json" } });
            if (response.ok) apply(await response.json());
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
    const changeTheme = async () => {
        const revision = ++themeRevision;
        try {
            const nextStyle = await vectorStyle(styleUrl());
            if (revision === themeRevision && shell.isConnected) map.setStyle(nextStyle);
        } catch { /* Keep the working map if the tile provider is unavailable. */ }
    };
    window.addEventListener('appearance:change', changeTheme);
    map.on('remove', () => {
        themeRevision++;
        ready = false;
        cancelAnimationFrame(frameRequest);
        window.removeEventListener('appearance:change', changeTheme);
    });
    map.on("moveend", () => {
        if (settled && !automaticFrame && searchButton && !config.static) searchButton.hidden = false;
    });
    searchButton?.addEventListener("click", () => void load());
}

async function start() {
    const shells = document.querySelectorAll("[data-map-shell]");
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

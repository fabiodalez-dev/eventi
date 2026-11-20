// Ads never live in the page cache. A short server lease also handles midnight,
// lost connectivity, campaign pauses and the backend switch on already open pages.
import { sponsorshipContext } from './sponsorship-context.js';
import { automated, post, VIEWABLE_MS, watchViewable } from './sponsorship-metrics.js';

/* Share only concurrent requests. Once settled, the entry is removed so the
   lease-expiry refresh still asks the backend for current campaign data. */
const inFlightRequests = new Map();

function requestBanner(endpoint) {
    if (!inFlightRequests.has(endpoint)) {
        const request = fetch(endpoint, { cache: 'no-store', headers: { Accept: 'application/json' } })
            .then((response) => {
                if (!response.ok) throw new Error('Unavailable');

                return response.json();
            })
            .finally(() => inFlightRequests.delete(endpoint));

        inFlightRequests.set(endpoint, request);
    }

    return inFlightRequests.get(endpoint);
}

export function sponsorshipBanners() {
    for (const slot of document.querySelectorAll('[data-live-sponsorship]')) {
        let banner = null;
        let expiry;
        let loading = false;
        const seen = new Set();
        const failedImages = new Set();
        const link = slot.querySelector('a');
        const picture = slot.querySelector('img');
        const hide = () => { slot.hidden = true; banner = null; };
        /* Il gettone usa e getta, quando il server lo manda, vale per una
           visualizzazione e un clic e passa davanti a quello giornaliero; il
           giornaliero resta per i server che non lo mandano ancora. */
        const metric = (kind) => {
            if (!banner || Date.parse(banner.expires_at) <= Date.now() || automated()) return;
            /* Con il gettone usa e getta la misura va alla rotta del sito, con
               la sessione: è lì che il server sa chi è collegato, e non conta
               la redazione né il committente che guarda il proprio banner.
               La rotta dell'API resta per il gettone giornaliero. */
            if (banner.impression_token && slot.dataset.webMetricBase) {
                post(`${slot.dataset.webMetricBase}/${banner.id}/${kind}`, sponsorshipContext('banner', kind === 'clicks'), banner.impression_token);
                return;
            }
            const city = slot.dataset.city ? `?city=${encodeURIComponent(slot.dataset.city)}` : '';
            const headers = { Accept: 'application/json', 'Content-Type': 'application/json', 'X-Metric-Token': banner.metric_token };
            if (banner.impression_token) headers['X-Impression-Token'] = banner.impression_token;
            void fetch(`${slot.dataset.metricBase}/${banner.id}/metrics/${kind}${city}`, {
                method: 'POST', keepalive: true, headers,
                body: JSON.stringify(sponsorshipContext('banner', kind === 'clicks')),
            }).catch(() => {});
        };
        /* Vista davvero: metà del banner nello schermo per un secondo di fila,
           con la scheda visibile. Una volta per banner mostrato. */
        const observer = watchViewable(() => {
            if (banner && !seen.has(banner.id)) {
                seen.add(banner.id);
                metric('impressions');
            }
        }, { ms: Number(slot.dataset.viewableMs) > 0 ? Number(slot.dataset.viewableMs) : VIEWABLE_MS });
        const click = (event) => {
            if (!banner || Date.parse(banner.expires_at) <= Date.now()) { event.preventDefault(); hide(); return; }
            /* Con il gettone usa e getta un clic prima del secondo porta con
               sé la visualizzazione (il server le conta insieme): dopo non la
               si manda più. Con quello giornaliero il server conta solo il clic. */
            if (banner.impression_token) seen.add(banner.id);
            metric('clicks');
        };
        link.addEventListener('click', click);
        link.addEventListener('auxclick', event => { if (event.button === 1) click(event); });
        picture.addEventListener('error', () => { failedImages.add(picture.getAttribute('src')); picture.hidden = true; picture.previousElementSibling.hidden = false; });
        const refresh = async () => {
            if (loading || document.hidden) return;
            loading = true;
            try {
                const { data } = await requestBanner(slot.dataset.endpoint);
                clearTimeout(expiry);
                if (!data || Date.parse(data.expires_at) <= Date.now()) { hide(); return; }
                if (slot.dataset.excludeEvent && data.event_slug === slot.dataset.excludeEvent) { hide(); return; }
                banner = data;
                link.href = data.url;
                slot.querySelector('[data-banner-title]').textContent = data.title;
                slot.querySelector('[data-banner-when]').textContent = [data.when, data.price].filter(Boolean).join(' · ');
                slot.querySelector('[data-banner-place]').textContent = [data.place, data.category].filter(Boolean).join(' · ');
                slot.querySelector('[data-banner-by]').textContent = `Sponsorizzato da ${data.advertiser}`;
                const showImage = data.image && !failedImages.has(data.image);
                picture.hidden = !showImage;
                picture.previousElementSibling.hidden = !!showImage;
                if (showImage && picture.getAttribute('src') !== data.image) picture.src = data.image;
                slot.hidden = false;
                observer.unobserve(slot);
                observer.observe(slot);
                expiry = setTimeout(hide, Math.max(0, Date.parse(data.expires_at) - Date.now()));
            } catch { hide(); } finally { loading = false; }
        };
        void refresh();
        setInterval(() => void refresh(), 45_000);
        document.addEventListener('visibilitychange', () => { if (!document.hidden) { hide(); void refresh(); } });
        window.addEventListener('pageshow', () => { hide(); void refresh(); });
    }
}

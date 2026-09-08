// Ads never live in the page cache. A short server lease also handles midnight,
// lost connectivity, campaign pauses and the backend switch on already open pages.
export function sponsorshipBanners() {
    for (const slot of document.querySelectorAll('[data-live-sponsorship]')) {
        let banner = null;
        let expiry;
        let loading = false;
        const seen = new Set();
        const clicked = new Set();
        const failedImages = new Set();
        const link = slot.querySelector('a');
        const picture = slot.querySelector('img');
        const hide = () => { slot.hidden = true; banner = null; };
        const metric = (kind) => {
            if (!banner || Date.parse(banner.expires_at) <= Date.now()) return;
            void fetch(`${slot.dataset.metricBase}/${banner.id}/metrics/${kind}`, {
                method: 'POST', keepalive: true,
                headers: { Accept: 'application/json', 'X-Metric-Token': banner.metric_token },
            }).catch(() => {});
        };
        const observer = new IntersectionObserver((entries) => {
            if (banner && entries.some(entry => entry.isIntersecting) && !seen.has(banner.id)) {
                metric('impressions');
                seen.add(banner.id);
            }
        }, { threshold: 0.5 });
        link.addEventListener('click', (event) => {
            if (!banner || Date.parse(banner.expires_at) <= Date.now()) { event.preventDefault(); hide(); return; }
            if (!clicked.has(banner.id)) { metric('clicks'); clicked.add(banner.id); }
        });
        picture.addEventListener('error', () => { failedImages.add(picture.getAttribute('src')); picture.hidden = true; picture.previousElementSibling.hidden = false; });
        const refresh = async () => {
            if (loading || document.hidden) return;
            loading = true;
            try {
                const response = await fetch(slot.dataset.endpoint, { cache: 'no-store', headers: { Accept: 'application/json' } });
                if (!response.ok) throw new Error('Unavailable');
                const { data } = await response.json();
                clearTimeout(expiry);
                if (!data || Date.parse(data.expires_at) <= Date.now()) { hide(); return; }
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

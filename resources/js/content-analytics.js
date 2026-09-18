/** Aggregated first-party metrics. Consent is checked again by the server. */
export function contentAnalytics() {
    const context = document.querySelector('[data-content-analytics]');
    if (!context) return;
    let allowed = context.dataset.allowed === '1';
    let viewed = false;
    const send = (metric, endpoint = context.dataset.url) => {
        if (!allowed) return;
        void fetch(endpoint, {
            method: 'POST', keepalive: true, credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json', Accept: 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content ?? '' },
            body: JSON.stringify({ metric }),
        }).catch(() => {});
    };
    const view = () => {
        if (allowed && !viewed && !document.hidden) { viewed = true; send('views'); }
    };
    document.addEventListener('visibilitychange', view);
    document.addEventListener('consent:changed', event => {
        allowed = event.detail?.statistics === true;
        view();
    });
    document.addEventListener('content:shared', event => send('shares', event.detail?.metric || context.dataset.url));
    document.addEventListener('click', event => {
        const link = event.target.closest('a');
        if (!link || !link.closest('main')) return;
        if (link.hasAttribute('data-share-channel')) {
            send('shares', link.dataset.shareMetric || context.dataset.url);
            return;
        }
        const explicit = link.dataset.contentMetric;
        const url = new URL(link.href, location.href);
        let metric = explicit;
        if (!metric && link.closest('.venue-directions')) metric = 'direction_clicks';
        if (!metric && url.protocol === 'tel:') metric = 'phone_clicks';
        if (!metric && url.protocol === 'mailto:') metric = 'email_clicks';
        if (!metric && ['http:', 'https:'].includes(url.protocol) && url.origin !== location.origin) metric = 'website_clicks';
        if (metric) send(metric);
    });
    view();
}

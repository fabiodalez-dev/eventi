export function sponsorshipContext(placement, click = false) {
    const path = window.location.pathname;
    const page = /\/il-mio-feed\/?$/.test(path) ? 'feed'
        : /\/il-mio-profilo\/?$/.test(path) ? 'profile'
        : /\/locali(?:\/|$)/.test(path) ? 'venues'
        : /\/cerca(?:\/|$)/.test(path) ? 'search'
        : /\/eventi\/.+/.test(path) ? 'event'
        : /\/eventi\/?$/.test(path) ? 'search'
        : path === '/' || /^\/[^/]+\/?$/.test(path) ? 'home' : 'other';
    return { placement, page, ...(click ? { click_id: crypto.randomUUID() } : {}) };
}

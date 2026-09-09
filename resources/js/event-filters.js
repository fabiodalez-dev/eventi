export function eventFilters() {
    if (!document.querySelector('[data-event-browser]')) return;
    let pending;
    let revision = 0;
    const navigate = async (url, push = true) => {
        pending?.abort();
        pending = new AbortController();
        const current = ++revision;
        const region = document.querySelector('[data-event-browser]');
        region.setAttribute('aria-busy', 'true');
        try {
            const response = await fetch(url, { signal: pending.signal, headers: { 'X-Requested-With': 'fetch' } });
            if (!response.ok) throw new Error('response');
            const page = new DOMParser().parseFromString(await response.text(), 'text/html');
            const next = page.querySelector('[data-event-browser]');
            if (!next) throw new Error('page');
            if (revision !== current) return;
            if (region.querySelector('aside details[open]')) next.querySelector('aside details')?.setAttribute('open', '');
            document.dispatchEvent(new Event('event-browser:before-update'));
            region.replaceWith(next);
            document.title = page.title;
            for (const selector of ['link[rel="canonical"]', 'meta[name="description"]', 'meta[name="robots"]']) {
                const previous = document.head.querySelector(selector);
                const replacement = page.head.querySelector(selector);
                if (replacement) {
                    if (previous) previous.replaceWith(replacement);
                    else document.head.append(replacement);
                } else previous?.remove();
            }
            if (push) history.pushState({}, '', response.url);
            document.dispatchEvent(new Event('event-browser:updated'));
            const heading = next.querySelector('h1');
            heading?.setAttribute('tabindex', '-1');
            heading?.focus({ preventScroll: true });
        } catch (error) {
            if (error.name !== 'AbortError' && current === revision) {
                const panel = region.querySelector('[data-filter-panel]');
                const status = panel?.querySelector('[data-filter-status]');
                if (status) { status.hidden = false; status.textContent = panel.dataset.filterError; }
            }
        } finally {
            if (current === revision) document.querySelector('[data-event-browser]')?.removeAttribute('aria-busy');
        }
    };
    document.addEventListener('click', event => {
        const link = event.target.closest('[data-event-browser] [data-filter-panel] a, [data-event-browser] [data-filter-link]');
        if (!link || event.defaultPrevented || event.button !== 0 || event.ctrlKey || event.metaKey || event.shiftKey || event.altKey || link.target || link.origin !== location.origin) return;
        event.preventDefault();
        void navigate(link.href);
    });
    document.addEventListener('submit', event => {
        const form = event.target;
        if (!form.matches('[data-event-browser] aside form') || form.method.toLowerCase() !== 'get') return;
        event.preventDefault();
        const url = new URL(form.action);
        url.search = new URLSearchParams(new FormData(form)).toString();
        void navigate(url.href);
    });
    document.addEventListener('change', event => {
        const input = event.target;
        const form = input.closest('[data-event-browser] aside form');
        if (form?.method.toLowerCase() === 'get' && input.matches('select, input[type="checkbox"], input[type="date"]')) form.requestSubmit();
    });
    window.addEventListener('popstate', () => void navigate(location.href, false));
    document.addEventListener('event-browser:navigate', event => void navigate(event.detail));
}

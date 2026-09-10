export function wouldEmptyResults(previous, next, push = true) {
    return push && Number(previous) > 0 && next !== undefined && Number(next) === 0;
}

export function removesFilters(previous, next) {
    const before = new URL(previous).searchParams;
    return [...new URL(next).searchParams].every(([key, value]) => {
        if (!value || ['all_dates', 'sort', 'page'].includes(key)) return true;
        if (['category', 'tag', 'access'].includes(key)) {
            return value.split(',').every(item => (before.get(key) ?? '').split(',').includes(item));
        }
        return before.get(key) === value;
    });
}

async function fadeFilterParts(region, from, to, duration, signal) {
    if (!region.hasAttribute('data-filter-fade') || signal.aborted
        || window.matchMedia('(prefers-reduced-motion: reduce)').matches) return;

    const animations = [...region.querySelectorAll('[data-filter-transition]')]
        .filter(element => typeof element.animate === 'function')
        .map(element => element.animate([{ opacity: from }, { opacity: to }], {
            duration, easing: 'cubic-bezier(0.25, 1, 0.5, 1)', fill: 'both',
        }));
    const cancel = () => animations.forEach(animation => animation.cancel());
    signal.addEventListener('abort', cancel, { once: true });
    try {
        await Promise.allSettled(animations.map(animation => animation.finished));
    } finally {
        signal.removeEventListener('abort', cancel);
        // Never leave an interrupted navigation transparent.
        cancel();
    }
}

export function eventFilters() {
    if (!document.querySelector('[data-event-browser]')) return;
    let pending;
    let revision = 0;
    const navigate = async (url, push = true) => {
        pending?.abort();
        pending = new AbortController();
        const signal = pending.signal;
        const current = ++revision;
        const region = document.querySelector('[data-event-browser]');
        region.setAttribute('aria-busy', 'true');
        region.querySelector('aside')?.setAttribute('inert', '');
        try {
            const response = await fetch(url, { signal, headers: { 'X-Requested-With': 'fetch' } });
            if (!response.ok) throw new Error('response');
            const page = new DOMParser().parseFromString(await response.text(), 'text/html');
            const next = page.querySelector('[data-event-browser]');
            if (!next) throw new Error('page');
            if (revision !== current) return;
            if (wouldEmptyResults(region.dataset.resultCount, next.dataset.resultCount, push && !removesFilters(location.href, url))) {
                region.querySelectorAll('aside form').forEach(form => form.reset());
                const status = region.querySelector('[data-filter-status]');
                if (status) {
                    status.hidden = false;
                    status.textContent = region.querySelector('[data-filter-panel]').dataset.filterEmpty;
                    status.scrollIntoView({ block: 'nearest' });
                }
                const geoStatus = region.querySelector('[data-geolocate-status]');
                if (geoStatus) geoStatus.textContent = '';
                return;
            }
            next.querySelectorAll('aside details').forEach(details => details.removeAttribute('open'));
            // Keep the current content visible until the replacement is ready.
            await fadeFilterParts(region, 1, 0, 120, signal);
            if (revision !== current || signal.aborted) return;
            const sidebarScroll = region.querySelector('aside')?.scrollTop ?? 0;
            document.dispatchEvent(new Event('event-browser:before-update'));
            region.replaceWith(next);
            const nextSidebar = next.querySelector('aside');
            if (next.hasAttribute('data-filter-fade') && nextSidebar) nextSidebar.scrollTop = sidebarScroll;
            next.setAttribute('aria-busy', 'true');
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
            await fadeFilterParts(next, 0, 1, 200, signal);
        } catch (error) {
            if (error.name !== 'AbortError' && current === revision) {
                region.querySelectorAll('aside form').forEach(form => form.reset());
                const panel = region.querySelector('[data-filter-panel]');
                const status = panel?.querySelector('[data-filter-status]');
                if (status) { status.hidden = false; status.textContent = panel.dataset.filterError; }
            }
        } finally {
            if (current === revision) {
                document.querySelector('[data-event-browser]')?.removeAttribute('aria-busy');
                document.querySelector('[data-event-browser] aside')?.removeAttribute('inert');
            }
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
        if (form?.method.toLowerCase() !== 'get' || !input.matches('select, input[type="checkbox"], input[type="date"]')) return;
        if (input.name === 'from' || input.name === 'to') form.querySelector('[name="date"]').value = '';
        if (input.name === 'date') {
            form.querySelector('[name="from"]').value = '';
            form.querySelector('[name="to"]').value = '';
        }
        if (input.name === 'municipality') {
            const zone = form.querySelector('[name="zone"]');
            if (zone) zone.value = '';
        }
        form.requestSubmit();
    });
    window.addEventListener('popstate', () => void navigate(location.href, false));
    document.addEventListener('event-browser:navigate', event => void navigate(event.detail));
}

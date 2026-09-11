import { patchFilters, searchableFilters, searchFilterOptions, navigateFilterOptions } from './filter-sidebar.js';

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
    searchableFilters();
    document.addEventListener('keydown', navigateFilterOptions);
    document.addEventListener('input', event => {
        if (event.target.matches('[data-option-search]')) searchFilterOptions(event.target.closest('[data-searchable-filter]'));
    });
    let pending;
    let revision = 0;
    /* L'indirizzo senza frammento, per riconoscere i `popstate` che non
       cambiano pagina. Vedi il gestore in fondo alla funzione. */
    let percorsoCorrente = location.href.split('#')[0];
    const navigate = async (url, push = true) => {
        pending?.abort();
        pending = new AbortController();
        const signal = pending.signal;
        const current = ++revision;
        const region = document.querySelector('[data-event-browser]');
        region.setAttribute('aria-busy', 'true');

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

            // Keep the current content visible until the replacement is ready.
            await fadeFilterParts(region, 1, 0, 120, signal);
            if (revision !== current || signal.aborted) return;
            let activeRegion = next;
            if (region.hasAttribute('data-map-browser')) {
                const sidebar = region.querySelector('aside');
                const scroll = sidebar.scrollTop;
                patchFilters(sidebar, next.querySelector('aside'));
                searchableFilters(sidebar);
                sidebar.scrollTop = scroll;
                const shell = region.querySelector('[data-map-shell]');
                const config = next.querySelector('[data-map-config]');
                if (shell && config) {
                    shell.querySelector('[data-map-config]').textContent = config.textContent;
                    shell.dispatchEvent(new CustomEvent('map:filters', { detail: JSON.parse(config.textContent) }));
                }
                region.querySelector('[data-map-results]').replaceWith(next.querySelector('[data-map-results]'));
                region.dataset.resultCount = next.dataset.resultCount;
                activeRegion = region;
            } else {
                const details = [...region.querySelectorAll('aside details')].map(item => item.open);
                next.querySelectorAll('aside details').forEach((item, index) => { item.open = details[index] ?? item.open; });
                document.dispatchEvent(new Event('event-browser:before-update'));
                region.replaceWith(next);
                searchableFilters(next);
            }
            activeRegion.setAttribute('aria-busy', 'true');
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
            percorsoCorrente = location.href.split('#')[0];
            document.dispatchEvent(new Event('event-browser:updated'));
            // Focus remains on the filter the user is operating.
            await fadeFilterParts(activeRegion, 0, 1, 200, signal);
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
            const venue = form.querySelector('[name="venue"]');
            if (venue) venue.value = '';
        }
        if (input.name === 'zone') {
            const venue = form.querySelector('[name="venue"]');
            if (venue) venue.value = '';
        }
        form.requestSubmit();
    });
    /*
     * Indietro e avanti del browser ricaricano l'elenco. Un cambio di **solo
     * frammento** no.
     *
     * Chrome emette `popstate` anche quando si apre un collegamento a un'ancora
     * della stessa pagina (`#filtri`), e senza questo controllo il gestore
     * rifaceva la richiesta e sostituiva l'intera regione: il bersaglio
     * dell'ancora spariva a metà dello scorrimento, l'elenco infinito
     * ripartiva da capo e si atterrava in mezzo ad altri risultati. Costava
     * anche una richiesta al server per ogni ancora premuta.
     */
    window.addEventListener('popstate', () => {
        const percorso = location.href.split('#')[0];
        if (percorso === percorsoCorrente) return;
        percorsoCorrente = percorso;
        void navigate(location.href, false);
    });
    document.addEventListener('event-browser:navigate', event => void navigate(event.detail));
}

export function liveSearch() {
    for (const form of document.querySelectorAll('[data-live-search]')) {
        const input = form.querySelector('input[name="q"]');
        const panel = form.querySelector('[data-search-suggestions]');
        let timer;
        let controller;
        let generation = 0;
        const message = (text) => {
            const paragraph = document.createElement('p');
            paragraph.className = 'p-4 text-sm text-ink-muted';
            paragraph.textContent = text;
            panel.replaceChildren(paragraph);
            panel.hidden = false;
        };
        const close = () => {
            clearTimeout(timer);
            controller?.abort();
            generation++;
            panel.hidden = true;
            panel.removeAttribute('aria-busy');
        };
        input.addEventListener('input', (event) => {
            close();
            if (event.isComposing) return;
            const term = input.value.trim();
            if ([...term].length < 3 || [...term].length > 120 || input.matches(':disabled')) return;
            const current = generation;
            timer = setTimeout(async () => {
                controller = new AbortController();
                const url = new URL(form.dataset.liveSearch, location.origin);
                url.searchParams.set('q', term);
                message(form.dataset.searchLoading);
                panel.setAttribute('aria-busy', 'true');
                try {
                    const response = await fetch(url, { signal: controller.signal, headers: { Accept: 'text/html' } });
                    if (!response.ok) throw new Error('Search unavailable');
                    const html = await response.text();
                    if (current !== generation) return;
                    // Only the escaped Blade fragment from our own endpoint is inserted.
                    panel.innerHTML = html;
                    panel.hidden = false;
                } catch (error) {
                    if (current === generation && error.name !== 'AbortError') message(form.dataset.searchError);
                } finally {
                    if (current === generation) panel.removeAttribute('aria-busy');
                }
            }, 250);
        });
        form.addEventListener('keydown', (event) => {
            if (event.key === 'Escape') {
                close();
                input.focus();
            }
            if (panel.hidden || !['ArrowDown', 'ArrowUp'].includes(event.key)) return;
            const links = [...panel.querySelectorAll('a')];
            if (!links.length) return;
            event.preventDefault();
            const index = links.indexOf(document.activeElement);
            const next = index === -1
                ? (event.key === 'ArrowDown' ? 0 : links.length - 1)
                : (index + (event.key === 'ArrowDown' ? 1 : -1) + links.length) % links.length;
            links[next].focus();
        });
        form.addEventListener('focusout', () => {
            setTimeout(() => { if (!form.contains(document.activeElement)) close(); }, 0);
        });
        document.addEventListener('pointerdown', (event) => { if (!form.contains(event.target)) close(); });
        form.addEventListener('submit', close);
    }
}

export function continuousTicker() {
    for (const ticker of document.querySelectorAll('[data-ticker]')) {
        const track = ticker.querySelector('[data-ticker-track]');
        const source = track.firstElementChild;
        const fit = () => {
            const width = source.getBoundingClientRect().width;
            if (!width) return;
            const copies = Math.ceil(ticker.clientWidth / width) + 1;
            while (track.children.length > copies) track.lastElementChild.remove();
            while (track.children.length < copies) {
                const copy = source.cloneNode(true);
                copy.setAttribute('aria-hidden', 'true');
                track.append(copy);
            }
            track.style.setProperty('--ticker-distance', `${width}px`);
            track.style.animationName = 'marquee';
            track.style.animationDuration = 'var(--tk-dur)';
            track.style.animationTimingFunction = 'linear';
            track.style.animationIterationCount = 'infinite';
        };
        fit();
        if ('ResizeObserver' in window) {
            const observer = new ResizeObserver(fit);
            observer.observe(ticker);
            observer.observe(source);
        } else window.addEventListener('resize', fit);
        document.fonts?.ready.then(fit);
    }
}

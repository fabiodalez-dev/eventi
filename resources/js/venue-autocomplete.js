export function matchingVenues(choices, query) {
    const normalize = (value) => value.normalize('NFD').replace(/\p{M}/gu, '').toLocaleLowerCase('it').trim();
    const term = normalize(query);
    if ([...term].length < 2) return [];
    return choices.filter((choice) => normalize(choice.name).includes(term)).slice(0, 8);
}

export function venueAutocomplete() {
    for (const root of document.querySelectorAll('[data-venue-autocomplete]')) {
        const select = root.querySelector('[data-venue-select]');
        const input = root.querySelector('[data-venue-input]');
        const list = root.querySelector('[data-venue-options]');
        const status = root.querySelector('[data-venue-status]');
        const clear = root.querySelector('[data-venue-clear]');
        const choices = [...select.options].filter((option) => option.value).map((option) => ({ slug: option.value, name: option.textContent }));
        let matches = [];
        let active = -1;
        const close = () => {
            list.hidden = true;
            input.setAttribute('aria-expanded', 'false');
            input.removeAttribute('aria-activedescendant');
            active = -1;
        };
        const choose = (choice) => {
            select.value = choice?.slug ?? '';
            input.value = choice?.name ?? '';
            input.setCustomValidity('');
            clear.hidden = !choice;
            status.textContent = choice?.name ?? select.options[0].textContent;
            close();
        };
        const show = () => {
            matches = matchingVenues(choices, input.value);
            list.replaceChildren();
            active = -1;
            input.removeAttribute('aria-activedescendant');
            for (const [index, choice] of matches.entries()) {
                const option = document.createElement('li');
                option.id = `${list.id}-${index}`;
                option.setAttribute('role', 'option');
                option.setAttribute('aria-selected', 'false');
                option.className = 'cursor-pointer px-3 py-3 text-sm hover:bg-surface aria-selected:bg-accent aria-selected:text-on-accent';
                option.textContent = choice.name;
                option.addEventListener('pointerdown', (event) => event.preventDefault());
                option.addEventListener('click', () => { choose(choice); input.focus(); });
                list.append(option);
            }
            list.hidden = !matches.length;
            input.setAttribute('aria-expanded', String(matches.length > 0));
            status.textContent = input.value.trim().length >= 2 && !matches.length ? root.dataset.empty : '';
        };
        choose(choices.find((choice) => choice.slug === select.value));
        input.addEventListener('input', () => {
            select.value = '';
            clear.hidden = !input.value;
            input.setCustomValidity(input.value.trim() ? root.dataset.invalid : '');
            show();
        });
        input.addEventListener('focus', () => { if (!select.value) show(); });
        input.addEventListener('keydown', (event) => {
            if (event.key === 'Escape') { close(); return; }
            if (event.key === 'Enter' && !list.hidden) {
                event.preventDefault();
                if (active >= 0) choose(matches[active]);
                return;
            }
            if (!['ArrowDown', 'ArrowUp'].includes(event.key)) return;
            event.preventDefault();
            if (list.hidden) show();
            if (!matches.length) return;
            active = (active + (event.key === 'ArrowDown' ? 1 : -1) + matches.length) % matches.length;
            [...list.children].forEach((option, index) => option.setAttribute('aria-selected', String(index === active)));
            input.setAttribute('aria-activedescendant', list.children[active].id);
            list.children[active].scrollIntoView({ block: 'nearest' });
        });
        clear.addEventListener('click', () => { choose(null); input.focus(); });
        root.addEventListener('focusout', () => setTimeout(() => { if (!root.contains(document.activeElement)) close(); }, 0));
        document.addEventListener('pointerdown', (event) => { if (!root.contains(event.target)) close(); });
        root.querySelector('[data-venue-fallback]').hidden = true;
        root.querySelector('[data-venue-enhanced]').hidden = false;
    }
}

export function tonightCounts() {
    document.querySelectorAll('[data-tonight-counts]').forEach(form => {
        let request;
        let timer;
        let revision = 0;
        const output = form.querySelector('[data-count-total]');
        form.addEventListener('change', () => {
            clearTimeout(timer);
            request?.abort();
            const current = ++revision;
            output.textContent = `(${form.dataset.countLoading})`;
            form.querySelectorAll('[data-place-option] input').forEach(input => input.disabled = false);
            timer = setTimeout(async () => {
                request = new AbortController();
                const params = new URLSearchParams(new FormData(form));
                params.set('preview', '1');
                params.set('question', 'when');
                if (params.get('municipality') !== 'Padova') params.delete('zone');
                try {
                    const response = await fetch(`${form.action}?${params}`, { signal: request.signal, headers: { Accept: 'application/json' }, cache: 'no-store' });
                    if (!response.ok) throw new Error('Count unavailable');
                    const { data } = await response.json();
                    if (revision !== current) return;
                    output.textContent = `(${form.dataset.countTemplate.replace(':count', data.total)})`;
                    form.querySelectorAll('[data-option-count]').forEach(label => {
                        const count = data[label.dataset.countKind]?.[label.dataset.optionCount] ?? 0;
                        label.textContent = count;
                        const input = label.closest('label').querySelector('input');
                        if (input.type === 'checkbox') {
                            input.disabled = count === 0;
                            if (count === 0) input.checked = false;
                        } else input.disabled = count === 0 && !input.checked;
                    });
                    form.querySelectorAll('[data-place-count]').forEach(label => {
                        const key = label.dataset.placeCount;
                        label.textContent = label.dataset.placeKind === 'municipality'
                            ? (key === '' ? data.everywhere : data.municipalities[key] ?? 0)
                            : (key === '' ? data.municipalities.Padova ?? 0 : data.zones[key] ?? 0);
                        const input = label.closest('[data-place-option]').querySelector('input');
                        input.disabled = Number(label.textContent) === 0 && !input.checked;
                    });
                } catch (error) {
                    if (error.name !== 'AbortError' && revision === current) output.textContent = `(${form.dataset.countError})`;
                }
            }, 180);
        });
    });
}

/*
 * La procedura «Stasera» mentre si sceglie.
 *
 * A ogni cambio di risposta chiedo al controller la stessa domanda con
 * `preview=1`: risponde con i conteggi (il numero accanto a ogni opzione, le
 * opzioni vuote disattivate, i comuni con eventi in cima) e con il frammento
 * `tonight-live` già disegnato — il riepilogo delle scelte e i primi eventi con
 * la locandina. Così il risultato di una scelta si vede prima di andare avanti.
 *
 * Senza JavaScript il modulo funziona lo stesso: è un GET, e ogni domanda
 * arriva già con i conteggi e l'anteprima della risposta precedente.
 */
/* `data-zero`, `data-one`, `data-many` (o con un prefisso: `data-status-zero`…)
   sono le tre forme della stessa frase, preparate dal server con `trans_choice`. */
const plural = (element, count, prefix = '') => {
    const form = count === 0 ? 'zero' : count === 1 ? 'one' : 'many';
    const key = prefix ? prefix + form[0].toUpperCase() + form.slice(1) : form;

    return (element.dataset[key] ?? '').replace(':count', String(count));
};

const revealPosters = root => {
    root.querySelectorAll('[data-poster-reveal]').forEach(image => {
        const reveal = () => requestAnimationFrame(() => requestAnimationFrame(() => image.classList.add('is-color')));
        if (image.complete) reveal();
        else image.addEventListener('load', reveal, { once: true });
    });
};

/*
 * Comuni e quartieri sono più di cento: l'elenco mostra le prime otto righe
 * (prima quelle con eventi, e sempre quella scelta) e un pulsante per vederle
 * tutte. Cercando si vede tutto ciò che corrisponde. Senza JavaScript l'elenco
 * resta intero: la classe che lo accorcia la mette questo script.
 */
const collapsiblePlaces = form => {
    const list = form.querySelector('[data-tonight-places]');
    const toggle = form.querySelector('[data-places-toggle]');
    const search = form.querySelector('[data-place-search]');
    if (!list || !toggle) return;
    list.classList.add('is-collapsed');
    toggle.hidden = false;
    toggle.addEventListener('click', () => {
        const open = list.classList.toggle('is-collapsed') === false;
        toggle.setAttribute('aria-expanded', String(open));
        toggle.textContent = open ? toggle.dataset.less : toggle.dataset.more;
    });
    search?.addEventListener('input', () => {
        const searching = search.value.trim() !== '';
        list.classList.toggle('is-searching', searching);
        toggle.hidden = searching;
    });
};

export function tonightCounts() {
    document.querySelectorAll('[data-tonight-counts]').forEach(form => {
        collapsiblePlaces(form);
        let request;
        let timer;
        let revision = 0;
        const live = form.querySelector('[data-tonight-live]');
        const status = form.querySelector('[data-count-status]');
        const labels = form.querySelectorAll('[data-count-text]');

        form.addEventListener('change', event => {
            /* La casella di ricerca dei luoghi non è una risposta. */
            if (event.target instanceof HTMLInputElement && event.target.matches('[data-place-search]')) return;
            clearTimeout(timer);
            request?.abort();
            const current = ++revision;
            live?.setAttribute('aria-busy', 'true');
            labels.forEach(label => { label.textContent = form.dataset.countLoading; });
            form.querySelectorAll('[data-place-option] input').forEach(input => { input.disabled = false; });
            timer = setTimeout(async () => {
                request = new AbortController();
                const params = new URLSearchParams(new FormData(form));
                params.set('preview', '1');
                params.set('question', form.dataset.question);
                if (params.get('municipality') !== 'Padova') params.delete('zone');
                try {
                    const response = await fetch(`${form.action}?${params}`, { signal: request.signal, headers: { Accept: 'application/json' }, cache: 'no-store' });
                    if (!response.ok) throw new Error('Count unavailable');
                    const { data, html } = await response.json();
                    if (revision !== current) return;
                    labels.forEach(label => { label.textContent = plural(label, data.total); });
                    if (status) status.textContent = plural(form, data.total, 'status');
                    if (live && typeof html === 'string') {
                        live.innerHTML = html;
                        revealPosters(live);
                    }
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
                    const places = [...form.querySelectorAll('[data-place-option]')];
                    places.sort((a, b) => {
                        const rank = option => option.querySelector('input').value === '' ? -1
                            : Number(option.querySelector('[data-place-count]').textContent) > 0 ? 0 : 1;
                        return rank(a) - rank(b);
                    }).forEach(option => option.parentElement.append(option));
                } catch (error) {
                    if (error.name !== 'AbortError' && revision === current) {
                        /* Il pulsante resta un pulsante: senza numero dice solo «Vedi i risultati». */
                        labels.forEach(label => { label.textContent = label.dataset.zero; });
                        if (status) status.textContent = form.dataset.countError;
                    }
                } finally {
                    if (revision === current) live?.removeAttribute('aria-busy');
                }
            }, 180);
        });
    });
}

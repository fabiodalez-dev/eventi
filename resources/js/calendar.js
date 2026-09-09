/*
 * Il calendario mensile (§11.8).
 *
 * Alpine sta in un pacchetto suo, caricato dalla sola pagina `/calendario`.
 * Livewire porta con sé la propria copia di Alpine e due copie sulla stessa
 * pagina si contendono lo stesso oggetto globale: qui non c'è alcun componente
 * Livewire, e il controllo qui sotto evita comunque di sovrascrivere una copia
 * già presente se un giorno ce ne fosse uno.
 *
 * Quello che aggiunge è tutto facoltativo: i titoli sul telefono e le frecce
 * della tastiera per cambiare mese. Senza JavaScript restano i conteggi, i
 * collegamenti ai giorni e quelli ai mesi vicini.
 */
import Alpine from 'alpinejs';

/**
 * Le frecce non devono rubare il tasto a chi sta scrivendo in un campo o
 * scegliendo una voce da un menu.
 */
function isTyping(event) {
    const target = event.target;

    if (document.querySelector('dialog[open]') || event.metaKey || event.ctrlKey || event.altKey) {
        return true;
    }

    return target instanceof HTMLElement
        && (target.isContentEditable || ['INPUT', 'SELECT', 'TEXTAREA'].includes(target.tagName));
}

let dayRequest;
document.addEventListener('click', async event => {
    const day = event.target.closest('[data-calendar-day]');
    const pageLink = event.target.closest('#calendar-day-preview [data-pagination] a');
    if ((!day && !pageLink) || event.ctrlKey || event.metaKey || event.shiftKey || event.altKey || event.button !== 0) return;
    const dialog = document.getElementById('calendar-day-preview');
    if (!dialog?.showModal) return;
    event.preventDefault();
    dayRequest?.abort();
    const request = new AbortController();
    dayRequest = request;
    const content = dialog.querySelector('[data-day-results]');
    const title = document.getElementById('calendar-day-title');
    if (day) title.textContent = day.dataset.dayLabel;
    const url = day?.dataset.calendarDay ?? pageLink.href;
    content.textContent = dialog.dataset.loading;
    if (!dialog.open) dialog.showModal();
    dialog.addEventListener('close', () => request.abort(), { once: true });
    try {
        const response = await fetch(url, { signal: request.signal });
        if (!response.ok) throw new Error('calendar');
        const page = new DOMParser().parseFromString(await response.text(), 'text/html');
        const results = page.querySelector('[data-results]');
        if (!results) throw new Error('calendar');
        content.replaceChildren(results);
        const pagination = page.querySelector('[data-pagination]');
        if (pagination) content.append(pagination);
    } catch (error) {
        if (error.name === 'AbortError') return;
        content.textContent = dialog.dataset.error + ' ';
        const fallback = document.createElement('a');
        fallback.href = url;
        fallback.textContent = title.textContent;
        fallback.className = 'underline';
        content.append(fallback);
    }
});

function calendario(config = {}) {
    return {
        /* Sul telefono le sette colonne non reggono anche i titoli: si
           mostrano a richiesta. Da tablet in su ci sono sempre, via CSS. */
        titoli: false,

        vaiAlPrecedente(event) {
            this.vai(config.previous, event);
        },

        vaiAlSuccessivo(event) {
            this.vai(config.next, event);
        },

        vai(url, event) {
            if (!url || isTyping(event)) {
                return;
            }

            window.location.assign(url);
        },
    };
}

if (window.Alpine === undefined) {
    Alpine.data('calendario', calendario);

    window.Alpine = Alpine;
    Alpine.start();
}

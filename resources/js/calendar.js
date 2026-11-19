/*
 * Il calendario mensile (§11.8), senza Alpine.
 *
 * Prima il comportamento stava in un `x-data="calendario(...)"`: Alpine
 * valuta quelle espressioni con `new Function`, che la CSP del sito vieta
 * (niente `unsafe-eval`). Il pulsante dei titoli non faceva niente, le frecce
 * nemmeno, e in console restava l'errore. Qui tutto passa da attributi `data-*`
 * scritti da `<x-month-calendar>`:
 *
 * - `[data-calendar-titles]`: sul telefono mostra e nasconde i titoli dei
 *   giorni (`aria-expanded`, un solo testo che cambia);
 * - `[data-calendar-keyboard]` con `data-previous`/`data-next`: le frecce
 *   sinistra e destra cambiano mese;
 * - `[data-calendar-day]`: il giorno apre l'anteprima nella finestra
 *   `#calendar-day-preview` invece di cambiare pagina.
 *
 * Tutto è facoltativo: senza JavaScript restano i conteggi, i collegamenti ai
 * giorni e quelli ai mesi vicini.
 */

/**
 * Le frecce non devono rubare il tasto a chi sta scrivendo in un campo o
 * scegliendo una voce da un menu, né a una finestra aperta.
 */
function isTyping(event) {
    const target = event.target;

    if (document.querySelector('dialog[open]') || event.metaKey || event.ctrlKey || event.altKey || event.shiftKey) {
        return true;
    }

    return target instanceof HTMLElement
        && (target.isContentEditable || ['INPUT', 'SELECT', 'TEXTAREA'].includes(target.tagName));
}

function titlesToggle(calendar) {
    const button = calendar.querySelector('[data-calendar-titles]');

    if (!(button instanceof HTMLButtonElement)) {
        return;
    }

    button.hidden = false;
    button.addEventListener('click', () => {
        const open = button.getAttribute('aria-expanded') !== 'true';

        button.setAttribute('aria-expanded', open ? 'true' : 'false');
        button.textContent = open ? button.dataset.labelLess : button.dataset.labelMore;

        if (open) {
            calendar.dataset.titles = 'open';
        } else {
            delete calendar.dataset.titles;
        }
    });
}

function keyboard(calendar) {
    if (!calendar.hasAttribute('data-calendar-keyboard')) {
        return;
    }

    window.addEventListener('keydown', event => {
        if (event.defaultPrevented || isTyping(event)) {
            return;
        }

        const url = event.key === 'ArrowLeft'
            ? calendar.dataset.previous
            : (event.key === 'ArrowRight' ? calendar.dataset.next : undefined);

        if (url) {
            event.preventDefault();
            window.location.assign(url);
        }
    });
}

let dayRequest;

async function preview(event) {
    const day = event.target.closest('[data-calendar-day]');
    const pageLink = event.target.closest('#calendar-day-preview [data-pagination] a');

    if ((!day && !pageLink) || event.ctrlKey || event.metaKey || event.shiftKey || event.altKey || event.button !== 0) {
        return;
    }

    const dialog = document.getElementById('calendar-day-preview');

    if (!dialog?.showModal) {
        return;
    }

    event.preventDefault();
    dayRequest?.abort();

    const request = new AbortController();
    dayRequest = request;

    const content = dialog.querySelector('[data-day-results]');
    const title = document.getElementById('calendar-day-title');

    if (day) {
        title.textContent = day.dataset.dayLabel;
    }

    const url = day?.dataset.calendarDay ?? pageLink.href;
    content.textContent = dialog.dataset.loading;

    if (!dialog.open) {
        dialog.showModal();
    }

    dialog.addEventListener('close', () => request.abort(), { once: true });

    try {
        const response = await fetch(url, { signal: request.signal });

        if (!response.ok) {
            throw new Error('calendar');
        }

        const page = new DOMParser().parseFromString(await response.text(), 'text/html');
        const results = page.querySelector('[data-results]');

        if (!results) {
            throw new Error('calendar');
        }

        content.replaceChildren(results);

        const pagination = page.querySelector('[data-pagination]');

        if (pagination) {
            content.append(pagination);
        }

        document.dispatchEvent(new Event('calendar-day:updated'));
    } catch (error) {
        if (error.name === 'AbortError') {
            return;
        }

        content.textContent = `${dialog.dataset.error} `;

        const fallback = document.createElement('a');
        fallback.href = url;
        fallback.textContent = title.textContent;
        fallback.className = 'underline';
        content.append(fallback);
    }
}

document.addEventListener('click', preview);

for (const calendar of document.querySelectorAll('[data-month-calendar]')) {
    titlesToggle(calendar);
    keyboard(calendar);
}

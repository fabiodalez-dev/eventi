import { CheckinQueue } from './checkin-queue';

document.querySelectorAll('[data-reservation-form]').forEach((form) => {
    const list = form.querySelector('[data-attendees]');
    const add = form.querySelector('[data-add-attendee]');
    const template = list.firstElementChild.cloneNode(true);
    const update = () => {
        [...list.children].forEach((row, index) => row.querySelectorAll('[data-name-part]').forEach((input) => {
            input.name = `attendees[${index}][${input.dataset.namePart}]`;
        }));
        add.hidden = list.children.length >= Number(form.dataset.limit);
        list.querySelectorAll('[data-remove-attendee]').forEach((button) => { button.hidden = list.children.length < 2; });
    };
    add.addEventListener('click', () => {
        if (list.children.length >= Number(form.dataset.limit)) return;
        const row = template.cloneNode(true);
        row.querySelectorAll('input').forEach((input) => { input.value = ''; });
        list.append(row);
        update();
        row.querySelector('input').focus();
    });
    list.addEventListener('click', (event) => {
        const button = event.target.closest('[data-remove-attendee]');
        if (!button || list.children.length < 2) return;
        button.closest('[data-attendee]').remove();
        update();
        add.focus();
    });
    form.addEventListener('submit', () => { form.querySelector('[type="submit"]').disabled = true; });
    window.addEventListener('pageshow', () => { form.querySelector('[type="submit"]').disabled = false; });
    update();
});

// Delegation also covers participant rows refreshed by live search.
document.addEventListener('submit', (event) => {
    const form = event.target.closest('form[data-confirm]');
    if (form && !window.confirm(form.dataset.confirm)) event.preventDefault();
});

document.querySelectorAll('[data-ticket-search]').forEach((form) => {
    let timer;
    let pending;
    let revision = 0;
    const status = document.querySelector('[data-search-status]');
    const search = async () => {
        const current = ++revision;
        pending?.abort();
        pending = new AbortController();
        const url = new URL(form.action || location.href);
        url.search = new URLSearchParams(new FormData(form)).toString();
        status.textContent = 'Ricerca in corso…';
        try {
            const response = await fetch(url, { signal: pending.signal, headers: { Accept: 'text/html' } });
            if (!response.ok || response.redirected) throw new Error('Search failed');
            const doc = new DOMParser().parseFromString(await response.text(), 'text/html');
            const results = doc.querySelector('[data-ticket-results]');
            if (!results) throw new Error('Missing results');
            if (current !== revision) return;
            document.querySelector('[data-ticket-results]').replaceWith(results);
            history.replaceState(null, '', url);
            status.textContent = 'Risultati aggiornati.';
        } catch (error) {
            if (error.name !== 'AbortError' && current === revision) status.textContent = 'Ricerca non disponibile. Premi Cerca per riprovare.';
        }
    };
    form.addEventListener('input', () => {
        clearTimeout(timer);
        revision++;
        pending?.abort();
        timer = setTimeout(search, 300);
    });
    form.addEventListener('change', (event) => {
        if (event.target.matches('select')) { clearTimeout(timer); search(); }
    });
    // A visitor can type before the module finishes loading after navigation.
    const initialQuery = new FormData(form).get('q');
    if (initialQuery && initialQuery !== new URL(location.href).searchParams.get('q')) search();
    // Native submission stays available for recovery and without JavaScript.
});

document.querySelectorAll('[data-ticket-scanner]').forEach((form) => {
    const video = form.querySelector('video');
    const start = form.querySelector('[data-scan-start]');
    const stopButton = form.querySelector('[data-scan-stop]');
    const message = form.querySelector('[data-scan-message]');
    let controls;
    let generation = 0;
    function stop() {
        generation++;
        controls?.stop();
        controls = null;
        video.srcObject?.getTracks().forEach((track) => track.stop());
        video.srcObject = null;
        video.hidden = true;
        stopButton.hidden = true;
        start.disabled = false;
    }
    start.addEventListener('click', async () => {
        const current = ++generation;
        start.disabled = true;
        stopButton.hidden = false;
        try {
            if (!window.isSecureContext || !navigator.mediaDevices?.getUserMedia) throw new Error('Camera unavailable');
            const { createTicketQrReader } = await import('./ticket-qr-reader');
            if (current !== generation) return;
            video.hidden = false;
            message.textContent = form.dataset.ready;
            const reader = createTicketQrReader();
            const next = await reader.decodeFromConstraints({ video: { facingMode: 'environment' }, audio: false }, video, (result, error, readerControls) => {
                if (current !== generation) { readerControls.stop(); return; }
                if (!result || !/^[a-zA-Z0-9]{64}$/.test(result.getText())) return;
                form.querySelector('[name="code"]').value = result.getText();
                readerControls.stop();
                stop();
                form.querySelector('[type="submit"]').focus();
            });
            if (current !== generation) next.stop();
            else controls = next;
        } catch (error) {
            if (current === generation) {
                stop();
                message.textContent = error.name === 'NotAllowedError'
                    ? 'Accesso alla fotocamera negato. Consenti la fotocamera nelle impostazioni del browser e riprova.'
                    : form.dataset.fallback;
            }
        }
    });
    stopButton.addEventListener('click', stop);
    window.addEventListener('pagehide', stop);
    document.addEventListener('visibilitychange', () => { if (document.hidden) stop(); });
});


document.querySelectorAll('[data-checkin-queue]').forEach((form) => {
    const results = form.querySelector('[data-checkin-results]');
    const retry = form.querySelector('[data-checkin-retry]');
    const queue = new CheckinQueue(async (entry) => {
        const response = await fetch(form.action, {
            method: 'POST', credentials: 'same-origin', signal: AbortSignal.timeout(15000),
            headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': form.querySelector('[name="_token"]').value },
            body: JSON.stringify({ code: entry.code, request_key: entry.requestKey }),
        });
        if (response.status >= 500) throw new Error('Temporary failure');
        const payload = await response.json();
        if (response.ok) document.querySelector('[data-ticket-search]')?.dispatchEvent(new Event('input'));
        return {
            accepted: response.ok,
            message: response.ok ? form.dataset.success.replace(':name', payload.data.attendee_name)
                : (Object.values(payload.errors || {}).flat()[0] || payload.message || form.dataset.error),
        };
    });
    const render = () => {
        results.replaceChildren(...queue.entries.map((entry, index) => {
            const li = document.createElement('li');
            li.dataset.state = entry.state;
            li.textContent = `${index + 1}. ${entry.state === 'pending' ? form.dataset.pending : entry.message}`;
            return li;
        }));
        retry.hidden = !queue.entries.some((entry) => entry.state === 'pending');
    };
    const flush = () => queue.flush(render);
    form.addEventListener('submit', (event) => {
        event.preventDefault();
        const input = form.querySelector('[name="code"]');
        const code = input.value.trim();
        if (!/^[a-zA-Z0-9]{64}$/.test(code)) return;
        queue.add(code);
        input.value = '';
        render();
        flush();
        input.focus();
    });
    retry.addEventListener('click', flush);
    window.addEventListener('online', flush);
    window.addEventListener('beforeunload', (event) => {
        if (queue.entries.some((entry) => entry.state === 'pending')) {
            event.preventDefault();
            event.returnValue = '';
        }
    });
});

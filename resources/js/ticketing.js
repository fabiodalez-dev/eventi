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

document.querySelectorAll('form[data-confirm]').forEach((form) => {
    form.addEventListener('submit', (event) => {
        if (!window.confirm(form.dataset.confirm)) event.preventDefault();
    });
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
            const { BrowserQRCodeReader } = await import('@zxing/browser');
            if (current !== generation) return;
            video.hidden = false;
            message.textContent = form.dataset.ready;
            const reader = new BrowserQRCodeReader();
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
        } catch {
            if (current === generation) {
                stop();
                message.textContent = form.dataset.fallback;
            }
        }
    });
    stopButton.addEventListener('click', stop);
    window.addEventListener('pagehide', stop);
    document.addEventListener('visibilitychange', () => { if (document.hidden) stop(); });
});

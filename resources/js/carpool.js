const csrf = () => document.querySelector('meta[name="csrf-token"]')?.content || '';
async function request(url, body = null) {
    const controller = new AbortController();
    const timeout = setTimeout(() => controller.abort(), 10000);
    try {
        const response = await fetch(url, { method: body ? 'POST' : 'GET', credentials: 'same-origin', cache: 'no-store',
            headers: { Accept: 'application/json', 'X-CSRF-TOKEN': csrf(), ...(body ? { 'Content-Type': 'application/json' } : {}) },
            body: body ? JSON.stringify(body) : undefined, signal: controller.signal });
        const payload = await response.json();
        if (!response.ok) { const error = new Error(payload.error?.message || Object.values(payload.errors || {})[0]?.[0] || payload.message || String(response.status)); error.status = response.status; throw error; }
        return payload;
    } finally { clearTimeout(timeout); }
}

function badges() {
    const source = document.querySelector('[data-community-summary-url]');
    if (!source) return;
    let timer, busy = false, generation = 0;
    const refresh = async () => {
        clearTimeout(timer);
        if (busy || document.hidden) return;
        busy = true;
        const ownGeneration = ++generation;
        try {
            const { data } = await request(source.dataset.communitySummaryUrl);
            if (ownGeneration !== generation) return;
            document.querySelectorAll('[data-community-count]').forEach(node => {
                const value = Number(data[node.dataset.communityCount] || 0);
                node.textContent = value > 99 ? '99+' : String(value);
                node.hidden = value === 0;
                node.setAttribute('aria-label', String(value));
            });
            const link = source.closest('a, summary');
            if (link) link.setAttribute('aria-label', `${source.dataset.label}: ${data.total}`);
        } catch { /* The existing links and counters remain usable during temporary network loss. */ }
        finally { busy = false; if (!document.hidden) timer = setTimeout(refresh, 30000); }
    };
    document.addEventListener('visibilitychange', () => { if (document.hidden) clearTimeout(timer); else refresh(); });
    document.addEventListener('community-changed', refresh);
    window.addEventListener('pagehide', () => clearTimeout(timer), { once: true });
    refresh();
}

function chat() {
    const root = document.querySelector('[data-ride-chat]');
    if (!root) return;
    const log = root.querySelector('[data-chat-messages]');
    const form = root.querySelector('[data-chat-send]');
    const error = root.querySelector('[data-chat-error]');
    const ids = () => [...log.querySelectorAll('[data-message-id]')].map(n => Number(n.dataset.messageId));
    let timer, busy = false, delay = 5000, read = 0, reading = false, writable = Boolean(form);
    const markRead = async () => {
        const bounds = log.getBoundingClientRect();
        if (document.hidden || reading || bounds.top >= innerHeight || bounds.bottom <= 0 || log.scrollHeight - log.scrollTop - log.clientHeight > 35) return;
        const latest = Math.max(0, ...ids());
        if (latest <= read) return;
        reading = true;
        try { await request(root.dataset.preferences, { read_through_id: latest }); read = latest; document.dispatchEvent(new Event('community-changed')); }
        catch { /* A subsequent visible refresh retries the watermark. */ }
        finally { reading = false; }
    };
    const insert = (rows, prepend = false) => {
        const existing = new Set(ids());
        const fragment = document.createDocumentFragment();
        for (const row of rows) {
            if (existing.has(row.id)) continue;
            const article = document.createElement('article');
            article.dataset.messageId = String(row.id);
            // Le stesse classi dei messaggi disegnati dal server (data-bubble-*), così un messaggio
            // arrivato adesso non si distingue da quelli caricati con la pagina.
            article.className = `${row.mine ? root.dataset.bubbleMine : root.dataset.bubbleTheirs} px-4 py-3`;
            const text = document.createElement('p'); text.className = 'text-sm whitespace-pre-wrap break-words'; text.textContent = row.body;
            const time = document.createElement('time'); time.className = 'mt-1 block text-xs opacity-75';
            time.dateTime = row.created_at;
            // «gg/mm hh:mm» nell'ora di Roma, come i messaggi stampati dal server.
            const parts = Object.fromEntries(new Intl.DateTimeFormat('it-IT', { timeZone: 'Europe/Rome', day: '2-digit', month: '2-digit', hour: '2-digit', minute: '2-digit', hourCycle: 'h23' })
                .formatToParts(new Date(row.created_at)).map(part => [part.type, part.value]));
            time.textContent = `${parts.day}/${parts.month} ${parts.hour}:${parts.minute}`;
            article.append(text, time); fragment.append(article);
        }
        const atBottom = log.scrollHeight - log.scrollTop - log.clientHeight < 50;
        const oldHeight = log.scrollHeight;
        if (prepend) { log.prepend(fragment); log.scrollTop += log.scrollHeight - oldHeight; }
        else { log.append(fragment); if (atBottom) log.scrollTop = log.scrollHeight; }
    };
    const refresh = async () => {
        clearTimeout(timer);
        if (busy || document.hidden) return;
        busy = true;
        try {
            const { data } = await request(`${root.dataset.url}?after=${Math.max(0, ...ids())}`);
            insert(data.messages);
            writable = data.writable;
            if (form && !writable) {
                form.querySelectorAll('textarea,button').forEach(control => control.disabled = true);
                error.textContent = root.dataset.readonly;
            }
            delay = 5000;
            await markRead();
        } catch (e) { error.textContent = e.message || root.dataset.error; delay = Math.min(60000, delay * 2); if ([401,403,404].includes(e.status)) { writable = false; form?.querySelectorAll('textarea,button').forEach(control => control.disabled = true); } }
        finally { busy = false; if (!document.hidden) timer = setTimeout(refresh, delay); }
    };
    form?.addEventListener('submit', async event => {
        event.preventDefault();
        const button = form.querySelector('button[type="submit"]');
        if (button.disabled) return;
        const field = form.elements.body, key = form.elements.request_key;
        button.disabled = true; error.textContent = '';
        try {
            await request(root.dataset.send, { body: field.value, request_key: key.value });
            field.value = ''; key.value = crypto.randomUUID();
            await refresh(); log.scrollTop = log.scrollHeight; await markRead(); field.focus();
        } catch (e) { error.textContent = e.message || root.dataset.error; }
        finally { button.disabled = !writable; }
    });
    root.querySelector('[data-chat-older]')?.addEventListener('click', async event => {
        const button = event.currentTarget; button.disabled = true;
        try {
            const first = Math.min(...ids());
            if (!Number.isFinite(first)) { button.hidden = true; return; }
            const { data } = await request(`${root.dataset.url}?before=${first}`);
            insert(data.messages, true); button.hidden = data.messages.length < 50;
        } catch (e) { error.textContent = e.message || root.dataset.error; }
        finally { button.disabled = false; }
    });
    log.addEventListener('scroll', markRead, { passive: true });
    window.addEventListener('scroll', markRead, { passive: true });
    document.addEventListener('visibilitychange', () => { if (document.hidden) clearTimeout(timer); else refresh(); });
    window.addEventListener('pagehide', () => clearTimeout(timer), { once: true });
    log.scrollTop = log.scrollHeight;
    refresh();
}

/*
 * La tendina della campanella. È un `<details>`: apertura, chiusura e tastiera
 * le fa il browser. Qui si aggiunge solo ciò che il tag non sa fare: caricare
 * gli ultimi avvisi all'apertura, chiudersi con Esc o con un clic fuori.
 */
function inboxMenu() {
    const menu = document.querySelector('[data-inbox-menu]');
    const body = menu?.querySelector('[data-inbox-menu-body]');
    if (!menu || !body) return;
    const through = menu.querySelector('[data-inbox-through]');
    let generation = 0;
    const load = async () => {
        const own = ++generation;
        try {
            const response = await fetch(menu.dataset.inboxUrl, { credentials: 'same-origin', cache: 'no-store', headers: { 'X-Requested-With': 'fetch' } });
            if (!response.ok) throw new Error(String(response.status));
            const html = await response.text();
            if (own !== generation) return;
            if (through) through.value = response.headers.get('X-Inbox-Watermark') || '';
            body.replaceChildren(...new DOMParser().parseFromString(html, 'text/html').body.childNodes);
        } catch {
            if (own !== generation) return;
            const notice = document.createElement('p');
            notice.className = 'px-4 py-6 text-sm text-ink-muted';
            notice.setAttribute('role', 'alert');
            notice.textContent = menu.dataset.inboxError || '';
            body.replaceChildren(notice);
        }
    };
    menu.addEventListener('toggle', () => { if (menu.open) load(); });
    document.addEventListener('click', event => { if (menu.open && !menu.contains(event.target)) menu.open = false; });
    document.addEventListener('keydown', event => {
        if (event.key === 'Escape' && menu.open) { menu.open = false; menu.querySelector('summary')?.focus(); }
    });
}

/*
 * Il modulo «Offro passaggio»: la partenza consentita dipende dalla tratta. Cambiando
 * andata/ritorno si aggiornano `min`/`max` del campo e la riga d'aiuto, così il
 * selettore del browser propone solo orari che il servizio accetta.
 */
function departureWindows() {
    document.querySelectorAll('form[data-ride-windows]').forEach(form => {
        let windows;
        try { windows = JSON.parse(form.dataset.rideWindows || '{}'); } catch { return; }
        const inputs = [...form.querySelectorAll('input[type="datetime-local"]')];
        if (!inputs.length) return;
        const apply = () => {
            const leg = form.querySelector('input[name="leg"]:checked')?.value ?? form.querySelector('select[name="leg"]')?.value;
            const range = windows[leg];
            if (!range) return;
            inputs.forEach(input => {
                input.min = range.min; input.max = range.max;
                const hint = document.getElementById(`${input.id}-aiuto`);
                if (hint && form.dataset.windowText) hint.textContent = form.dataset.windowText.replace(':from', range.from).replace(':to', range.to);
            });
        };
        form.querySelectorAll('[name="leg"]').forEach(control => control.addEventListener('change', apply));
        apply();
    });
}

export function carpool() {
    badges(); chat(); inboxMenu();
    document.querySelectorAll('[data-ride-template]').forEach(select => select.addEventListener('change', () => {
        if (!select.value) return;
        const fields = JSON.parse(select.value), form = select.closest('form');
        for (const [name, value] of Object.entries(fields)) {
            if (name === 'stops') { form.querySelectorAll('[name="stops[]"]').forEach((input, index) => input.value = value[index] || ''); }
            else if (name === 'leg') { form.querySelectorAll('[name="leg"]').forEach(input => input.checked = input.value === value); }
            else if (form.elements[name]) form.elements[name].value = value ?? '';
        }
    }));
    departureWindows();
    // `form[data-confirm]` (annulla, rinuncia, rimuovi recensione) lo gestisce già ticketing.js,
    // caricato da app.js per qualunque pagina con quel modulo: un secondo ascoltatore qui
    // faceva comparire la conferma due volte.
    document.querySelectorAll('[data-carpool-gate]').forEach(control => control.addEventListener('click', event => {
        const dialog = document.getElementById(control.dataset.carpoolGate);
        if (!dialog?.showModal) return;
        event.preventDefault();
        const destination = new URL(control.dataset.carpoolDestination || control.href, location.origin);
        if (destination.origin === location.origin && /^\/passaggi\/date\/[1-9][0-9]*(\/offri)?$/.test(destination.pathname)) {
            dialog.querySelectorAll('[data-carpool-intent]').forEach(anchor => { const url = new URL(anchor.href); url.searchParams.set('intended', destination.href); anchor.href = url.href; });
            const field = dialog.querySelector('[name=return_to]'); if (field) field.value = destination.pathname;
        }
        dialog.showModal();
        dialog.addEventListener('close', () => control.focus(), { once: true });
    }));
}
carpool();

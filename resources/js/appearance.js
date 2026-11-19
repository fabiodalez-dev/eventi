/*
 * Il tema del sito: scuro, chiaro oppure come il sistema.
 *
 * Due cose distinte: la *scelta* (`dark`, `light`, `system`) e il *tema
 * applicato* (`dark` o `light`). Con «come il sistema» il tema segue il
 * dispositivo e cambia con lui, e nel browser non resta nessuna preferenza:
 * niente cookie `incitta_appearance`, niente memoria locale. È proprio il
 * cookie che al primo accesso viene copiato nel profilo, quindi se restasse
 * la scelta «sistema» si trasformerebbe di nascosto in una scelta fissa.
 *
 * Chi è collegato salva la scelta nel profilo; l'ospite nel browser. Il tema
 * iniziale lo ha già messo lo script nel layout, prima del disegno.
 */
const root = document.documentElement;
const guest = root.dataset.themeUser === 'guest';
const key = `incitta:appearance:${root.dataset.themeUser ?? 'guest'}`;
const guestKey = 'incitta:appearance:guest';
const forms = [...document.querySelectorAll('[data-appearance-form]')];
const choices = [...document.querySelectorAll('[data-appearance-choice], [data-appearance-toggle]')];
const pickers = choices.filter((choice) => choice.hasAttribute('data-appearance-choice'));
const scheme = window.matchMedia?.('(prefers-color-scheme: light)');
const isTheme = (value) => value === 'light' || value === 'dark';
const systemTheme = () => (scheme?.matches ? 'light' : 'dark');
const secure = location.protocol === 'https:' ? '; Secure' : '';

function cookieTheme() {
    const value = document.cookie.split('; ').find((entry) => entry.startsWith('incitta_appearance='))?.split('=')[1];
    return isTheme(value) ? value : null;
}

function storedTheme(name) {
    try { const value = localStorage.getItem(name); return isTheme(value) ? value : null; } catch { return null; }
}

/* La scelta di partenza. Per chi è collegato la dice il server: senza
   `data-theme-auto` il profilo ha un tema, con l'attributo non ne ha. Per
   l'ospite conta quello che il browser ricorda. */
let mode = guest
    ? (cookieTheme() ?? storedTheme(guestKey) ?? 'system')
    : ('themeAuto' in root.dataset ? 'system' : (isTheme(root.dataset.theme) ? root.dataset.theme : 'dark'));

function message(form, name, fallback) {
    return form?.dataset[name] || fallback;
}

function apply(theme) {
    root.dataset.theme = theme;
    document.querySelector('meta[name="color-scheme"]')?.setAttribute('content', theme);
    document.querySelector('meta[name="theme-color"]')?.setAttribute('content', theme === 'light' ? '#faf9f6' : '#0b0b0b');
    for (const choice of choices) {
        if (!choice.hasAttribute('data-appearance-toggle')) continue;
        choice.value = theme === 'light' ? 'dark' : 'light';
        const label = theme === 'light' ? 'Passa al tema scuro' : 'Passa al tema chiaro';
        choice.setAttribute('aria-label', label);
        choice.title = label;
    }
    for (const choice of pickers) choice.setAttribute('aria-pressed', String(choice.value === mode));
    window.dispatchEvent(new CustomEvent('appearance:change', { detail: { theme, mode } }));
}

/* Una scelta fissa resta nel browser (l'ospite la ritrova alla prossima
   visita); «sistema» toglie ogni traccia. */
function remember(choice) {
    if (!isTheme(choice)) {
        document.cookie = `incitta_appearance=; Path=/; Max-Age=0; SameSite=Lax${secure}`;
        try { localStorage.removeItem(guestKey); localStorage.removeItem(key); } catch {}
        return true;
    }
    let stored = false;
    if (guest) {
        document.cookie = `incitta_appearance=${choice}; Path=/; Max-Age=31536000; SameSite=Lax${secure}`;
        stored = cookieTheme() === choice;
    }
    try { localStorage.setItem(key, choice); return true; } catch { return stored; }
}

const themeFor = (choice) => (isTheme(choice) ? choice : systemTheme());

apply(isTheme(root.dataset.theme) ? root.dataset.theme : themeFor(mode));

async function choose(choice) {
    const form = choice.closest('[data-appearance-form]');
    const status = form?.querySelector('[data-appearance-status]');
    const feedback = document.querySelector('[data-appearance-error]');
    if (feedback) feedback.hidden = true;
    const previous = { mode, theme: root.dataset.theme };
    mode = ['light', 'dark', 'system'].includes(choice.value) ? choice.value : 'system';
    apply(themeFor(mode));
    const say = (text) => { if (status) status.textContent = text; };

    if (guest) {
        const kept = remember(mode);
        say(mode === 'system'
            ? message(form, 'msgSystemBrowser', 'Il sito segue il tema del dispositivo.')
            : (kept ? message(form, 'msgSavedBrowser', 'Aspetto salvato in questo browser.') : message(form, 'msgNotStored', 'Tema applicato. Il browser non consente di salvare la scelta.')));
        return;
    }

    for (const button of choices) button.disabled = true;
    say(message(form, 'msgSaving', 'Salvataggio…'));
    try {
        const response = await fetch(form.action, {
            method: 'PATCH', credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content },
            body: JSON.stringify({ appearance: mode }),
        });
        if (!response.ok) throw new Error('save failed');
        if (mode === 'system') remember('system');
        say(message(form, 'msgSaved', 'Aspetto salvato nel tuo profilo.'));
    } catch {
        mode = previous.mode;
        apply(previous.theme);
        const text = message(form, 'msgFailed', 'Salvataggio non riuscito. Riprova; se la sessione è scaduta, accedi di nuovo.');
        say(text);
        if (feedback) { feedback.textContent = text; feedback.hidden = false; }
    } finally {
        for (const button of choices) button.disabled = false;
    }
}

for (const form of forms) form.addEventListener('submit', (event) => {
    event.preventDefault();
    if (event.submitter?.matches('[data-appearance-choice], [data-appearance-toggle]')) void choose(event.submitter);
});

if (guest) {
    for (const choice of choices) choice.addEventListener('click', () => void choose(choice));
    window.addEventListener('storage', (event) => {
        if (event.key !== guestKey) return;
        mode = isTheme(event.newValue) ? event.newValue : 'system';
        apply(themeFor(mode));
    });
}

// «Come il sistema» segue il dispositivo anche mentre la pagina è aperta.
scheme?.addEventListener?.('change', () => { if (mode === 'system') apply(systemTheme()); });

// Restore the profile theme when returning through the browser's back/forward cache.
window.addEventListener('pageshow', (event) => {
    if (!event.persisted) return;
    if (!guest) { window.location.reload(); return; }
    mode = cookieTheme() ?? storedTheme(guestKey) ?? 'system';
    apply(themeFor(mode));
});

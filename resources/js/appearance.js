const root = document.documentElement;
const key = `incitta:appearance:${root.dataset.themeUser ?? 'guest'}`;
const forms = [...document.querySelectorAll('[data-appearance-form]')];
const choices = [...document.querySelectorAll('[data-appearance-choice]')];


function apply(theme) {
    root.dataset.theme = theme;
    document.querySelector('meta[name="color-scheme"]')?.setAttribute('content', theme);
    for (const choice of choices) choice.setAttribute('aria-pressed', String(choice.value === theme));
    window.dispatchEvent(new CustomEvent('appearance:change', { detail: { theme } }));
}
function remember(theme) {
    let stored = false;
    if (root.dataset.themeUser === 'guest') {
        document.cookie = `incitta_appearance=${theme}; Path=/; Max-Age=31536000; SameSite=Lax${location.protocol === 'https:' ? '; Secure' : ''}`;
        stored = document.cookie.split('; ').includes(`incitta_appearance=${theme}`);
    }
    try { localStorage.setItem(key, theme); return true; } catch { return stored; }
}
apply(root.dataset.theme === 'light' ? 'light' : 'dark');

async function choose(choice) {
    const form = choice.closest("[data-appearance-form]");
    const status = form.querySelector("[data-appearance-status]");
    const previous = root.dataset.theme;
    const theme = choice.value;
    apply(theme);
    if (root.dataset.themeUser === 'guest') {
        status.textContent = remember(theme) ? 'Aspetto salvato in questo browser.' : 'Tema applicato. Il browser non consente di salvare la scelta.';
        return;
    }
    for (const button of choices) button.disabled = true;
    status.textContent = 'Salvataggio…';
    try {
        const response = await fetch(form.action, {
            method: 'PATCH', credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content },
            body: JSON.stringify({ appearance: theme }),
        });
        if (!response.ok) throw new Error('save failed');
        status.textContent = 'Aspetto salvato nel tuo profilo.';
    } catch {
        apply(previous);
        status.textContent = 'Salvataggio non riuscito. Riprova; se la sessione è scaduta, accedi di nuovo.';
    } finally {
        for (const button of choices) button.disabled = false;
    }
}
for (const form of forms) form.addEventListener('submit', (event) => {
    event.preventDefault();
    if (event.submitter?.matches('[data-appearance-choice]')) void choose(event.submitter);
});
if (root.dataset.themeUser === 'guest') {
    for (const choice of choices) choice.addEventListener('click', () => void choose(choice));
    window.addEventListener('storage', (event) => {
        if (event.key === key && ['light', 'dark'].includes(event.newValue)) apply(event.newValue);
    });
}
// Restore the profile theme when returning through the browser's back/forward cache.
window.addEventListener('pageshow', (event) => {
    if (event.persisted && root.dataset.themeUser !== 'guest') window.location.reload();
    else if (event.persisted) {
        try { const saved = localStorage.getItem(key); if (['light', 'dark'].includes(saved)) apply(saved); } catch {}
    }
});

for (const menu of document.querySelectorAll('.appearance-menu')) {
    document.addEventListener('click', (event) => { if (!menu.contains(event.target)) menu.open = false; });
    menu.addEventListener('keydown', (event) => {
        if (event.key === 'Escape') { menu.open = false; menu.querySelector('summary').focus(); }
    });
}

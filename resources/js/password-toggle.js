// L'occhio dei campi password (`x-field type="password"`): mostra e nasconde
// quello che si sta scrivendo. Il pulsante esiste già nell'HTML, nascosto:
// qui si rende visibile e si collega, così senza JavaScript non resta un
// pulsante che non fa niente.
export function passwordToggles(root = document) {
    root.querySelectorAll('[data-password-toggle]').forEach((button) => {
        if (button.dataset.passwordReady === '1') return;
        const input = document.getElementById(button.getAttribute('aria-controls'));
        if (!input) return;
        button.dataset.passwordReady = '1';
        button.hidden = false;
        button.addEventListener('click', () => {
            const show = input.type === 'password';
            input.type = show ? 'text' : 'password';
            button.setAttribute('aria-pressed', show ? 'true' : 'false');
            button.setAttribute('aria-label', show ? button.dataset.labelHide : button.dataset.labelShow);
            button.querySelector('[data-password-icon="show"]')?.classList.toggle('hidden', show);
            button.querySelector('[data-password-icon="hide"]')?.classList.toggle('hidden', !show);
            input.focus({ preventScroll: true });
        });
    });
    // All'invio si torna a password, così il browser non la salva come testo visibile.
    root.querySelectorAll('form:has([data-password-toggle])').forEach((form) => {
        if (form.dataset.passwordReset === '1') return;
        form.dataset.passwordReset = '1';
        form.addEventListener('submit', () => {
            form.querySelectorAll('[data-password-field] input').forEach((input) => { input.type = 'password'; });
        });
    });
}

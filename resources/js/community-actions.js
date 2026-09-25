const restore = () => document.querySelectorAll('form[data-community-form][aria-busy]').forEach(form => {
    form.removeAttribute('aria-busy');
    form.querySelectorAll('[data-community-pending]').forEach(button => { button.disabled = false; delete button.dataset.communityPending; });
});
document.addEventListener('submit', event => {
    const form = event.target.closest('form[data-community-form]');
    if (!form || event.defaultPrevented) return;
    if (form.getAttribute('aria-busy') === 'true') { event.preventDefault(); return; }
    form.setAttribute('aria-busy', 'true');
    queueMicrotask(() => {
        if (event.defaultPrevented) { restore(); return; }
        form.querySelectorAll('button[type="submit"]').forEach(button => { button.dataset.communityPending = ''; button.disabled = true; });
    });
});
window.addEventListener('pageshow', restore);

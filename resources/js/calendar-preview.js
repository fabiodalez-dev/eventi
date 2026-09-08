// Native dialogs retain focus, close on Escape, and leave the calendar in place.
document.addEventListener('click', (event) => {
    const trigger = event.target.closest('[data-calendar-preview]');
    if (!trigger) return;
    const dialog = document.getElementById(trigger.dataset.calendarPreview);
    if (!(dialog instanceof HTMLDialogElement)) return;
    event.preventDefault();
    dialog.showModal();
});

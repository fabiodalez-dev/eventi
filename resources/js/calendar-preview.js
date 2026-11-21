// Native dialogs retain focus, close on Escape, and leave the calendar in place.
document.addEventListener('click', (event) => {
    const trigger = event.target.closest('[data-calendar-preview]');
    if (!trigger) return;
    const dialog = document.getElementById(trigger.dataset.calendarPreview);
    if (!(dialog instanceof HTMLDialogElement)) return;
    event.preventDefault();
    dialog.showModal();
});

/*
 * Un clic sul fondo scuro chiude le finestre che lo dichiarano con
 * `data-light-dismiss` (il giorno del calendario, i giorni dei salvati).
 *
 * Fondo e riquadro sono lo stesso elemento `<dialog>`: anche un clic sul
 * margine interno del riquadro ha il dialogo come bersaglio. Per questo conta
 * dove cade il clic, non chi lo riceve. E deve cominciare fuori: chi seleziona
 * un titolo trascinando oltre il bordo non perde la finestra.
 */
const outside = (dialog, event) => {
    if (!(dialog instanceof HTMLDialogElement) || !dialog.open || !dialog.hasAttribute('data-light-dismiss')) {
        return false;
    }

    const box = dialog.getBoundingClientRect();

    return event.clientX < box.left || event.clientX > box.right || event.clientY < box.top || event.clientY > box.bottom;
};

let pressedOutside = null;

document.addEventListener('pointerdown', (event) => {
    pressedOutside = outside(event.target, event) ? event.target : null;
});

document.addEventListener('click', (event) => {
    if (pressedOutside !== null && pressedOutside === event.target && outside(event.target, event)) {
        event.target.close();
    }

    pressedOutside = null;
});

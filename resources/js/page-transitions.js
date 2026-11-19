// Native navigation remains in charge of requests, history and fallback.
const publicPage = value => {
    if (!value) return false;
    const url = new URL(value, location.href);
    return url.origin === location.origin && /^\/(?:$|eventi(?:\/|$)|locali(?:\/|$)|mappa(?:\/|$)|cerca(?:\/|$)|categorie(?:\/|$))/.test(url.pathname);
};
const reduced = () => matchMedia('(prefers-reduced-motion: reduce)').matches;
// Saltare una transizione rifiuta le sue promesse: senza un `catch` ogni
// navigazione verso o da una pagina non pubblica lascia in console
// «Transition was skipped».
const skip = transition => {
    if (!transition) return;
    for (const promise of [transition.ready, transition.finished, transition.updateCallbackDone]) promise?.catch(() => {});
    transition.skipTransition();
};
window.addEventListener('pageswap', event => {
    if (!event.viewTransition || reduced()) { skip(event.viewTransition); return; }
    const destination = event.activation?.entry?.url;
    if (!publicPage(location.href) || !publicPage(destination)) { skip(event.viewTransition); return; }
    const link = [...document.querySelectorAll('.event-card a, .home-poster-feature')]
        .find(anchor => anchor.href === destination && anchor.getBoundingClientRect().bottom > 0 && anchor.getBoundingClientRect().top < innerHeight);
    const photo = link?.closest('.event-card, .home-poster-feature')?.querySelector('.event-poster-frame');
    if (!photo) return;
    photo.style.viewTransitionName = 'event-photo';
    event.viewTransition.finished.finally(() => photo.style.removeProperty('view-transition-name')).catch(() => {});
});
window.addEventListener('pagereveal', event => {
    if (!event.viewTransition || reduced()) { skip(event.viewTransition); return; }
    if (!publicPage(location.href) || !publicPage(window.navigation?.activation?.from?.url)) { skip(event.viewTransition); return; }
    const photo = document.querySelector('.event-detail-hero .event-poster-frame');
    if (!photo) return;
    photo.style.viewTransitionName = 'event-photo';
    event.viewTransition.finished.finally(() => photo.style.removeProperty('view-transition-name')).catch(() => {});
});

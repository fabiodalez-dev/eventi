/*
 * Il service worker delle notifiche push (§15.4, §15.6, D54).
 *
 * Sta in `public/` e non fra i pacchetti di Vite per una ragione sola, ma
 * vincolante: un service worker governa gli indirizzi **sotto** il proprio, e
 * Vite pubblica in `/build/assets/` con un nome che cambia a ogni rilascio.
 * Da lì vedrebbe `/build/` e nient'altro, e cambierebbe identita' ogni volta.
 * Qui vive alla radice, che e' l'unico posto da cui puo' ricevere le notifiche
 * dell'intero sito, e ha un indirizzo stabile.
 *
 * Non fa nient'altro: nessuna cache, nessuna intercettazione delle richieste.
 * Il sito e' gia' rapido perche' le sue pagine sono in cache lato server, e un
 * worker che serve pagine vecchie e' il difetto piu' difficile da diagnosticare
 * che si possa aggiungere a un sito.
 */

self.addEventListener("install", () => self.skipWaiting());

self.addEventListener("activate", (event) => event.waitUntil(self.clients.claim()));

self.addEventListener("push", (event) => {
    if (!event.data) {
        return;
    }

    let payload;

    try {
        payload = event.data.json();
    } catch (error) {
        return;
    }

    const data = payload.data || {};

    event.waitUntil(
        self.registration.showNotification(payload.title || "", {
            body: payload.body || "",
            // Due riepiloghi della stessa specie si sostituiscono invece di
            // impilarsi: su un telefono la seconda copia della stessa cosa e'
            // rumore.
            tag: payload.tag || undefined,
            // Un PNG vero, e non piu' `/favicon.ico`: quel file e' stato per
            // mesi da zero byte, quindi ogni notifica arrivava con l'icona di
            // ripiego del sistema. Il distintivo e' la sagoma monocromatica
            // che Android mette nella barra di stato: del file usa solo la
            // trasparenza, il colore lo decide lui.
            icon: "/icon-192.png",
            badge: "/notification-badge.png",
            data: data,
        }),
    );
});

/*
 * Il tocco apre il collegamento profondo che §15.4 pretende su ogni notifica,
 * mai la home.
 *
 * Se una scheda del sito e' gia' aperta la si riusa: aprirne una seconda
 * identica e' il comportamento che fa accumulare schede a chi riceve piu' di
 * un promemoria.
 */
self.addEventListener("notificationclick", (event) => {
    event.notification.close();

    let url = self.location.origin + "/";
    try {
        const target = new URL(event.notification.data?.url || "/", self.location.origin);
        if (target.origin === self.location.origin && !target.username && !target.password) url = target.href;
    } catch { /* Invalid destinations open the homepage. */ }

    event.waitUntil(
        self.clients
            .matchAll({ type: "window", includeUncontrolled: true })
            .then((clientList) => {
                for (const client of clientList) {
                    if (client.url === url && "focus" in client) {
                        return client.focus();
                    }
                }

                return self.clients.openWindow(url);
            }),
    );
});

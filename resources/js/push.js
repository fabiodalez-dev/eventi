/*
 * L'interruttore delle notifiche push nella pagina delle preferenze
 * (§15.6, D54).
 *
 * Regola d'ingaggio identica a quella di `app.js`: **nulla qui e' necessario**.
 * Se il browser non sa cosa sia un service worker, o se il permesso non viene
 * concesso, la pagina resta esattamente quella di prima e le notifiche
 * continuano ad arrivare per email — che e' il ripiego previsto da §15.6, non
 * un guasto.
 *
 * Due cose che sembrano dettagli e non lo sono:
 *
 * - **il permesso si chiede dopo un gesto**, mai al caricamento. Un browser
 *   che riceve la richiesta senza che nessuno abbia toccato niente la blocca
 *   da solo, e in alcuni casi la blocca *per sempre* su quel dominio: chiedere
 *   troppo presto significa non poter piu' chiedere;
 * - **l'iscrizione si rimanda al server a ogni visita** di chi ha gia' dato il
 *   permesso. Non e' una ripetizione inutile: e' cio' che aggiorna
 *   `last_seen_at`, e senza quel rinnovo la finestra di trenta giorni di §15.6
 *   misurerebbe la data della prima iscrizione invece dell'ultimo accesso.
 */

const PERCORSO_WORKER = "/sw.js";

/**
 * La chiave VAPID viaggia in base64url perche' e' cio' che sta comodo dentro
 * un attributo HTML; l'API del browser vuole invece i byte.
 */
function chiaveInByte(base64url) {
    const riempimento = "=".repeat((4 - (base64url.length % 4)) % 4);
    const base64 = (base64url + riempimento).replace(/-/g, "+").replace(/_/g, "/");
    const grezzo = window.atob(base64);
    const byte = new Uint8Array(grezzo.length);

    for (let i = 0; i < grezzo.length; i += 1) {
        byte[i] = grezzo.charCodeAt(i);
    }

    return byte;
}

/**
 * `options.applicationServerKey` torna come ArrayBuffer: si confronta byte a
 * byte, perche' non c'e' modo di rileggerlo come stringa.
 */
function stessaChiave(buffer, byte) {
    if (!buffer) {
        return false;
    }

    const attuale = new Uint8Array(buffer);

    return attuale.length === byte.length && attuale.every((valore, i) => valore === byte[i]);
}

function tokenCsrf() {
    const meta = document.querySelector('meta[name="csrf-token"]');

    return meta instanceof HTMLMetaElement ? meta.content : "";
}

async function chiamaServer(metodo, url, corpo) {
    const risposta = await fetch(url, {
        method: metodo,
        headers: {
            "Content-Type": "application/json",
            Accept: "application/json",
            "X-CSRF-TOKEN": tokenCsrf(),
            "X-Requested-With": "XMLHttpRequest",
        },
        credentials: "same-origin",
        body: JSON.stringify(corpo),
    });

    if (!risposta.ok) {
        throw new Error(`Il server ha risposto ${risposta.status}`);
    }

    return risposta;
}

/**
 * Il service worker si registra solo quando serve davvero, cioe' quando il
 * permesso c'e' gia' o e' appena stato concesso: registrarlo a ogni visita
 * installerebbe un worker anche a chi le push non le ha mai volute.
 */
async function iscrivi(chiave) {
    const registrazione = await navigator.serviceWorker.register(PERCORSO_WORKER);

    await navigator.serviceWorker.ready;

    const byte = chiaveInByte(chiave);
    let esistente = await registrazione.pushManager.getSubscription();

    /*
     * Un'iscrizione nata da un'altra chiave VAPID e' inservibile: il servizio
     * push rifiuterebbe le consegne, e `subscribe()` con una chiave diversa da
     * quella in corso solleva `InvalidStateError` invece di sostituirla. Va
     * disdetta prima. E' il caso di chi era iscritto quando le chiavi sono
     * state rigenerate — raro, ma silenzioso: senza questo controllo si
     * rimanderebbe al server un endpoint che non ricevera' mai niente.
     */
    if (esistente && !stessaChiave(esistente.options.applicationServerKey, byte)) {
        await esistente.unsubscribe();
        esistente = null;
    }

    const iscrizione =
        esistente ??
        (await registrazione.pushManager.subscribe({
            userVisibleOnly: true,
            applicationServerKey: byte,
        }));

    const json = iscrizione.toJSON();

    await chiamaServer("POST", "/notifiche/push", {
        endpoint: json.endpoint,
        keys: json.keys,
    });

    return iscrizione;
}

async function disdici() {
    let endpoint = "";

    if ("serviceWorker" in navigator) {
        const registrazione = await navigator.serviceWorker.getRegistration(PERCORSO_WORKER);
        const iscrizione = registrazione ? await registrazione.pushManager.getSubscription() : null;

        if (iscrizione) {
            endpoint = iscrizione.endpoint;
            await iscrizione.unsubscribe();
        }
    }

    /*
     * Si avvisa il server anche senza endpoint: e' il caso di chi ha revocato
     * il permesso dalle impostazioni del browser, dove l'iscrizione da citare
     * non esiste piu' ma la riga in `devices` si'.
     */
    await chiamaServer("DELETE", "/notifiche/push", { endpoint });
}

function push() {
    const sezione = document.querySelector("[data-push]");

    if (!sezione) {
        return;
    }

    const interruttore = sezione.querySelector("[data-push-toggle]");
    const stato = sezione.querySelector("[data-push-status]");
    const chiave = sezione.dataset.push;

    const supportato =
        "serviceWorker" in navigator && "PushManager" in window && "Notification" in window;

    const dillo = (testo) => {
        if (stato) {
            stato.textContent = testo;
        }
    };

    if (!supportato || !interruttore) {
        dillo(sezione.dataset.pushUnsupported || "");

        if (interruttore instanceof HTMLInputElement) {
            interruttore.disabled = true;
            interruttore.checked = false;
        }

        return;
    }

    if (Notification.permission === "denied") {
        dillo(sezione.dataset.pushDenied || "");
        interruttore.disabled = true;
        interruttore.checked = false;

        return;
    }

    /*
     * Il permesso c'e' gia' e l'interruttore era acceso: si rinnova
     * l'iscrizione in silenzio, che e' l'unico modo perche' `last_seen_at`
     * resti la data dell'ultimo accesso.
     */
    if (Notification.permission === "granted" && interruttore.checked) {
        iscrivi(chiave).catch(() => dillo(sezione.dataset.pushFailed || ""));
    }

    interruttore.addEventListener("change", async () => {
        interruttore.disabled = true;

        try {
            if (!interruttore.checked) {
                await disdici();
                dillo(sezione.dataset.pushOff || "");

                return;
            }

            const permesso = await Notification.requestPermission();

            if (permesso !== "granted") {
                interruttore.checked = false;
                dillo(sezione.dataset.pushDenied || "");

                return;
            }

            await iscrivi(chiave);
            dillo(sezione.dataset.pushOn || "");
        } catch (errore) {
            interruttore.checked = false;
            dillo(sezione.dataset.pushFailed || "");
        } finally {
            interruttore.disabled = false;
        }
    });
}

push();

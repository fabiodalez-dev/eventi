/*
 * I commenti, lato browser.
 *
 * Tre cose soltanto, e tutte e tre sono **miglioramenti**: la pagina funziona
 * identica senza questo file, perché ogni gesto qui intercettato è un form
 * normale che il server sa già gestire.
 *
 * 1. Il modale che chiede l'iscrizione.
 * 2. Il form di risposta, che nasce chiuso.
 * 3. Le reazioni, che aggiornano il conteggio senza ricaricare la pagina.
 */

function apriIscrizione() {
    const modale = document.getElementById("iscriviti-per-partecipare");

    /*
     * `showModal` non esiste su browser molto vecchi. Lì il pulsante non deve
     * restare muto: si va alla pagina di registrazione, che è ciò che il
     * modale avrebbe proposto.
     */
    if (modale && typeof modale.showModal === "function") {
        modale.showModal();
        return;
    }

    const registrati = modale?.querySelector("a[href]");
    if (registrati) window.location.href = registrati.href;
}

/*
 * Il form di risposta parte `hidden` solo quando c'è JavaScript ad aprirlo.
 * Senza, resta visibile: un pulsante che non fa niente è peggio di un form
 * sempre aperto.
 */
function preparaRisposte(radice) {
    radice.querySelectorAll("[data-apri-risposta]").forEach((pulsante) => {
        if (pulsante.dataset.legato === "1") return;
        pulsante.dataset.legato = "1";

        pulsante.addEventListener("click", () => {
            const id = pulsante.getAttribute("data-apri-risposta");
            const form = radice.querySelector(`[data-risposta="${CSS.escape(id)}"]`);
            if (!form) return;

            form.hidden = !form.hidden;
            if (!form.hidden) form.querySelector("textarea")?.focus();
        });
    });
}

/*
 * Le reazioni.
 *
 * L'invio del form viene intercettato e rifatto in `fetch`; la risposta porta
 * il nuovo conteggio e quale reazione è rimasta attiva. Se qualcosa va storto
 * — rete assente, sessione scaduta, 419 — **si lascia partire il form**:
 * l'utente finisce sulla pagina ricaricata, che è il comportamento senza
 * JavaScript, invece di restare davanti a un pulsante che non reagisce.
 */
function preparaReazioni(radice) {
    radice.querySelectorAll("form[data-reazione]").forEach((form) => {
        if (form.dataset.legato === "1") return;
        form.dataset.legato = "1";

        form.addEventListener("submit", async (evento) => {
            evento.preventDefault();

            const commento = form.closest("[data-commento]");
            const pulsante = form.querySelector("button[type=submit]");
            if (!commento || !pulsante) {
                form.submit();
                return;
            }

            pulsante.disabled = true;

            try {
                const risposta = await fetch(form.action, {
                    method: "POST",
                    body: new FormData(form),
                    headers: { Accept: "application/json", "X-Requested-With": "XMLHttpRequest" },
                    credentials: "same-origin",
                });

                if (!risposta.ok) throw new Error(String(risposta.status));

                const esito = await risposta.json();
                aggiornaConteggio(commento, esito);
            } catch {
                form.dataset.legato = "0";
                form.submit();
                return;
            } finally {
                pulsante.disabled = false;
            }
        });
    });
}

/*
 * Aggiorna il numero e lo stato dei pulsanti dopo una reazione.
 *
 * Le reazioni sono alternative fra loro, quindi `aria-pressed` va rimesso a
 * `false` su **tutte** prima di accenderne una: è il lato visibile della
 * regola che nel database garantisce l'indice unico.
 */
function aggiornaConteggio(commento, esito) {
    const contatore = commento.querySelector("[data-conteggio-reazioni]");
    if (contatore) contatore.textContent = esito.conteggio > 0 ? String(esito.conteggio) : "";

    commento.querySelectorAll("form[data-reazione]").forEach((altro) => {
        const tipo = altro.querySelector("input[name=type]")?.value;
        const pulsante = altro.querySelector("button[type=submit]");
        if (!pulsante) return;

        const attiva = esito.tipo !== null && tipo === esito.tipo;
        pulsante.setAttribute("aria-pressed", attiva ? "true" : "false");
        pulsante.classList.toggle("border-accent", attiva);
        pulsante.classList.toggle("text-accent", attiva);
        pulsante.classList.toggle("border-line", !attiva);
        pulsante.classList.toggle("text-ink-muted", !attiva);
    });
}

function avvia() {
    const sezione = document.getElementById("commenti");

    document.querySelectorAll("[data-apri-iscrizione]").forEach((pulsante) => {
        if (pulsante.dataset.legato === "1") return;
        pulsante.dataset.legato = "1";
        pulsante.addEventListener("click", apriIscrizione);
    });

    if (!sezione) return;

    sezione.querySelectorAll("[data-risposta]").forEach((form) => {
        form.hidden = true;
    });

    preparaRisposte(sezione);
    preparaReazioni(sezione);
}

document.addEventListener("DOMContentLoaded", avvia);

/*
 * Tornando indietro col tasto del browser la pagina può arrivare dalla cache
 * a ritroso, senza `DOMContentLoaded`: senza questa riga i pulsanti sarebbero
 * morti proprio dopo un «indietro».
 */
window.addEventListener("pageshow", avvia);

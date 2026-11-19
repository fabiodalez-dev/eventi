/*
 * I commenti, lato browser.
 *
 * Tre cose soltanto, e tutte e tre sono **miglioramenti**: la pagina funziona
 * identica senza questo file, perché ogni gesto qui intercettato è un form
 * normale che il server sa già gestire.
 *
 * 1. Il modale che chiede l'iscrizione.
 * 2. Il form di risposta, che nasce chiuso, e i campi che crescono col testo.
 * 3. Le reazioni, che aggiornano il conteggio senza ricaricare la pagina.
 */

function apriIscrizione(evento) {
    const modale = document.getElementById("iscriviti-per-partecipare");

    /*
     * `showModal` non esiste su browser molto vecchi. Lì il pulsante non deve
     * restare muto: si va alla pagina di registrazione, che è ciò che il
     * modale avrebbe proposto.
     */
    if (modale && typeof modale.showModal === "function") {
        evento.preventDefault();
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
function mostraRisposta(radice, id, aperta) {
    const form = radice.querySelector(`[data-risposta="${CSS.escape(id)}"]`);
    const pulsante = radice.querySelector(`[data-apri-risposta="${CSS.escape(id)}"]`);
    if (!form) return;

    form.hidden = !aperta;
    pulsante?.setAttribute("aria-expanded", aperta ? "true" : "false");

    if (aperta) {
        form.querySelector("textarea")?.focus();
    } else {
        pulsante?.focus();
    }
}

function preparaRisposte(radice) {
    radice.querySelectorAll("[data-apri-risposta]").forEach((pulsante) => {
        if (pulsante.dataset.legato === "1") return;
        pulsante.dataset.legato = "1";

        pulsante.addEventListener("click", () => {
            const id = pulsante.getAttribute("data-apri-risposta");
            const form = radice.querySelector(`[data-risposta="${CSS.escape(id)}"]`);
            if (form) mostraRisposta(radice, id, form.hidden);
        });
    });

    /*
     * «Annulla» esiste solo quando c'è JavaScript a richiudere il form:
     * senza, il form è sempre aperto e il pulsante non servirebbe a niente.
     */
    radice.querySelectorAll("[data-chiudi-risposta]").forEach((pulsante) => {
        pulsante.hidden = false;
        if (pulsante.dataset.legato === "1") return;
        pulsante.dataset.legato = "1";

        pulsante.addEventListener("click", () => {
            mostraRisposta(radice, pulsante.getAttribute("data-chiudi-risposta"), false);
        });
    });
}

/*
 * I campi di testo partono da due righe e crescono mentre si scrive, fino al
 * massimo fissato dal CSS: sul telefono un riquadro alto quattro righe vuoto
 * occupa mezzo schermo prima ancora di cominciare.
 */
function adatta(campo) {
    campo.style.height = "auto";
    campo.style.height = `${campo.scrollHeight + campo.offsetHeight - campo.clientHeight}px`;
}

function preparaCampi(radice) {
    radice.querySelectorAll("textarea[data-cresce]").forEach((campo) => {
        if (campo.dataset.legato === "1") return;
        campo.dataset.legato = "1";
        campo.addEventListener("input", () => adatta(campo));
        if (campo.value !== "") adatta(campo);
    });
}

/*
 * Le reazioni.
 *
 * L'invio del form viene intercettato e rifatto in `fetch`; la risposta porta
 * il nuovo conteggio e quale reazione è rimasta attiva. Se qualcosa va storto
 * si rilegge la pagina senza ripetere il POST: una risposta persa potrebbe
 * nascondere una scrittura riuscita, e ripetere il toggle la annullerebbe.
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

            if (commento.dataset.reagendo === "1") return;
            commento.dataset.reagendo = "1";
            pulsante.disabled = true;

            try {
                const risposta = await fetch(form.action, {
                    method: "POST",
                    body: new URLSearchParams(new FormData(form)),
                    headers: { Accept: "application/json", "X-Requested-With": "XMLHttpRequest" },
                    credentials: "same-origin",
                });

                if (!risposta.ok) throw new Error(String(risposta.status));

                const esito = await risposta.json();
                aggiornaConteggio(commento, esito);
            } catch {
                window.location.reload();
                return;
            } finally {
                pulsante.disabled = false;
                delete commento.dataset.reagendo;
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
        if (altro.closest("[data-commento]") !== commento) return;
        const tipo = altro.querySelector("input[name=type]")?.value;
        const pulsante = altro.querySelector("button[type=submit]");
        if (!pulsante) return;

        const attiva = esito.tipo !== null && tipo === esito.tipo;
        pulsante.setAttribute("aria-pressed", attiva ? "true" : "false");
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
        if (!form.dataset.preparata) {
            form.hidden = form.dataset.rispostaError !== "1";
            form.dataset.preparata = "1";
            const id = form.getAttribute("data-risposta");
            sezione.querySelector(`[data-apri-risposta="${CSS.escape(id)}"]`)?.setAttribute("aria-expanded", form.hidden ? "false" : "true");
        }
    });

    sezione.querySelectorAll("[data-conferma-eliminazione]").forEach((form) => {
        if (form.dataset.legato === "1") return;
        form.dataset.legato = "1";
        form.addEventListener("submit", (event) => {
            if (!window.confirm(form.dataset.confermaEliminazione)) event.preventDefault();
        });
    });
    preparaRisposte(sezione);
    preparaCampi(sezione);
    preparaReazioni(sezione);
}

document.addEventListener("DOMContentLoaded", avvia);

/*
 * Tornando indietro col tasto del browser la pagina può arrivare dalla cache
 * a ritroso, senza `DOMContentLoaded`: senza questa riga i pulsanti sarebbero
 * morti proprio dopo un «indietro».
 */
window.addEventListener("pageshow", avvia);

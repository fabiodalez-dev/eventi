/*
 * Il calendario mensile (§11.8).
 *
 * Alpine sta in un pacchetto suo, caricato dalla sola pagina `/calendario`.
 * Livewire porta con sé la propria copia di Alpine e due copie sulla stessa
 * pagina si contendono lo stesso oggetto globale: qui non c'è alcun componente
 * Livewire, e il controllo qui sotto evita comunque di sovrascrivere una copia
 * già presente se un giorno ce ne fosse uno.
 *
 * Quello che aggiunge è tutto facoltativo: i titoli sul telefono e le frecce
 * della tastiera per cambiare mese. Senza JavaScript restano i conteggi, i
 * collegamenti ai giorni e quelli ai mesi vicini.
 */
import Alpine from 'alpinejs';

/**
 * Le frecce non devono rubare il tasto a chi sta scrivendo in un campo o
 * scegliendo una voce da un menu.
 */
function isTyping(event) {
    const target = event.target;

    if (event.metaKey || event.ctrlKey || event.altKey) {
        return true;
    }

    return target instanceof HTMLElement
        && (target.isContentEditable || ['INPUT', 'SELECT', 'TEXTAREA'].includes(target.tagName));
}

function calendario(config = {}) {
    return {
        /* Sul telefono le sette colonne non reggono anche i titoli: si
           mostrano a richiesta. Da tablet in su ci sono sempre, via CSS. */
        titoli: false,

        vaiAlPrecedente(event) {
            this.vai(config.previous, event);
        },

        vaiAlSuccessivo(event) {
            this.vai(config.next, event);
        },

        vai(url, event) {
            if (!url || isTyping(event)) {
                return;
            }

            window.location.assign(url);
        },
    };
}

if (window.Alpine === undefined) {
    Alpine.data('calendario', calendario);

    window.Alpine = Alpine;
    Alpine.start();
}

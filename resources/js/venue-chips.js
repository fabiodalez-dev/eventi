import { matchingVenues } from './venue-autocomplete';

/**
 * I locali consigliati: ricerca e chip invece di un elenco di caselle.
 *
 * **Le caselle restano la fonte di verità.** Non vengono sostituite da campi
 * nascosti: l'elenco si nasconde e resta attivo, le chip lo spuntano e lo
 * despuntano. Così non esiste un istante in cui due rappresentazioni dello
 * stesso dato possano dire cose diverse, e senza JavaScript si vede l'elenco
 * di prima, che funziona da solo.
 *
 * Attenzione al motivo per cui la casella va **nascosta e non disabilitata**:
 * `hidden` non impedisce l'invio, quindi la spunta continua a contare. Un
 * `disabled` qui perderebbe silenziosamente le scelte.
 */
export function venueChips() {
    for (const root of document.querySelectorAll('[data-locali]')) {
        const arricchito = root.querySelector('[data-locali-arricchito]');
        const caselle = root.querySelector('[data-locali-caselle]');
        const input = root.querySelector('[data-locali-input]');
        const lista = root.querySelector('[data-locali-opzioni]');
        const stato = root.querySelector('[data-locali-stato]');
        const scelti = root.querySelector('[data-locali-scelti]');
        if (!arricchito || !caselle || !input || !lista || !scelti) continue;

        const limite = Number(root.dataset.limite || 20);
        const voci = [...caselle.querySelectorAll('input[type="checkbox"]')]
            .map((casella) => ({ casella, id: casella.value, name: casella.closest('label').textContent.trim() }));
        if (voci.length === 0) continue;

        let matches = [];
        let attivo = -1;

        const chiudi = () => {
            lista.hidden = true;
            input.setAttribute('aria-expanded', 'false');
            input.removeAttribute('aria-activedescendant');
            attivo = -1;
        };

        const disegnaChip = () => {
            scelti.replaceChildren();
            for (const voce of voci.filter((v) => v.casella.checked)) {
                const chip = document.createElement('li');
                const bottone = document.createElement('button');
                bottone.type = 'button';
                bottone.className = 'ui-action inline-flex items-center gap-1.5 border-2 border-accent bg-accent px-2.5 py-1.5 font-display text-[0.6875rem] leading-[1.25] font-extrabold tracking-[0.12em] text-on-accent uppercase transition-colors';
                bottone.append(document.createTextNode(voce.name));
                const croce = document.createElement('span');
                croce.setAttribute('aria-hidden', 'true');
                croce.className = 'shrink-0 text-sm leading-none';
                croce.textContent = '×';
                bottone.append(croce);
                /* Il nome nell'etichetta accessibile, perché fuori contesto
                   «Togli» da solo non dice cosa si sta togliendo. */
                bottone.setAttribute('aria-label', (root.dataset.togli || ':name').replace(':name', voce.name));
                bottone.addEventListener('click', () => {
                    voce.casella.checked = false;
                    disegnaChip();
                    input.focus();
                });
                chip.append(bottone);
                scelti.append(chip);
            }
        };

        const scegli = (voce) => {
            if (voci.filter((v) => v.casella.checked).length >= limite) {
                stato.textContent = root.dataset.limiteTesto ?? '';
                chiudi();
                return;
            }
            voce.casella.checked = true;
            input.value = '';
            stato.textContent = '';
            disegnaChip();
            chiudi();
        };

        const mostra = () => {
            /* Chi è già scelto non ricompare fra i risultati: sceglierlo due
               volte non farebbe niente, e una riga che non fa niente in un
               elenco di risultati è un invito sbagliato. */
            matches = matchingVenues(voci.filter((v) => !v.casella.checked), input.value);
            lista.replaceChildren();
            attivo = -1;
            input.removeAttribute('aria-activedescendant');
            for (const [indice, voce] of matches.entries()) {
                const opzione = document.createElement('li');
                opzione.id = `${lista.id}-${indice}`;
                opzione.setAttribute('role', 'option');
                opzione.setAttribute('aria-selected', 'false');
                opzione.className = 'cursor-pointer px-3 py-3 text-sm hover:bg-surface aria-selected:bg-accent aria-selected:text-on-accent';
                opzione.textContent = voce.name;
                opzione.addEventListener('pointerdown', (evento) => evento.preventDefault());
                opzione.addEventListener('click', () => scegli(voce));
                lista.append(opzione);
            }
            lista.hidden = matches.length === 0;
            input.setAttribute('aria-expanded', String(matches.length > 0));
            stato.textContent = input.value.trim().length >= 2 && matches.length === 0 ? (root.dataset.nessuno ?? '') : '';
        };

        input.addEventListener('input', mostra);
        input.addEventListener('focus', mostra);
        input.addEventListener('keydown', (evento) => {
            if (evento.key === 'Escape') { chiudi(); return; }
            if (evento.key === 'Enter' && !lista.hidden) {
                evento.preventDefault();
                if (attivo >= 0) scegli(matches[attivo]);
                return;
            }
            if (!['ArrowDown', 'ArrowUp'].includes(evento.key)) return;
            evento.preventDefault();
            if (lista.hidden) mostra();
            if (matches.length === 0) return;
            attivo = (attivo + (evento.key === 'ArrowDown' ? 1 : -1) + matches.length) % matches.length;
            [...lista.children].forEach((opzione, indice) => opzione.setAttribute('aria-selected', String(indice === attivo)));
            input.setAttribute('aria-activedescendant', lista.children[attivo].id);
            lista.children[attivo].scrollIntoView({ block: 'nearest' });
        });
        root.addEventListener('focusout', () => setTimeout(() => { if (!root.contains(document.activeElement)) chiudi(); }, 0));
        document.addEventListener('pointerdown', (evento) => { if (!root.contains(evento.target)) chiudi(); });

        disegnaChip();
        caselle.hidden = true;
        arricchito.hidden = false;
    }
}

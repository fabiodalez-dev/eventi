/**
 * Il nome utente, controllato mentre si scrive.
 *
 * Prima l'unico modo di sapere che un nome era preso era inviare il modulo e
 * leggere l'errore di convalida: dopo aver compilato tutto il resto, e con la
 * pagina ricaricata.
 *
 * Le regole non sono riscritte qui. Il cliente fa solo la verifica **minima**
 * che permette di non chiamare il server per niente — campo vuoto o troppo
 * corto — e per tutto il resto chiede a `/profilo-social/nome-utente`, che
 * risponde con le stesse regole del salvataggio. Una copia delle regole nel
 * JavaScript prima o poi direbbe «disponibile» su un nome che il server
 * rifiuta, ed è il peggior errore possibile: arriva dopo la fiducia.
 */
export function profileHandle() {
    for (const root of document.querySelectorAll('[data-nome-utente]')) {
        const input = root.querySelector('input[name="handle"]');
        const segno = root.querySelector('[data-nome-utente-segno]');
        const stato = root.querySelector('[data-nome-utente-stato]');
        if (!input || !segno || !stato) continue;

        const iniziale = input.value.trim().toLowerCase();
        let attesa;
        let richiesta;

        const mostra = (simbolo, testo, esito) => {
            segno.hidden = simbolo === '';
            segno.textContent = simbolo;
            /* Il colore lo porta solo il «no». Il tema chiaro non ridefinisce
               `--accent`, quindi un segno verde-lime su fondo avorio sarebbe
               illeggibile: il sì si affida al glifo e alla frase. */
            segno.className = `absolute inset-y-0 right-3 flex items-center text-lg leading-none font-bold ${esito === 'no' ? 'text-alert' : 'text-ink'}`;
            stato.textContent = testo;
            stato.className = `mt-2 text-sm empty:hidden ${esito === 'no' ? 'text-alert' : 'text-ink-muted'}`;
        };

        const controlla = async () => {
            const valore = input.value.trim().toLowerCase();
            richiesta?.abort();

            if (valore === '' || valore === iniziale) { mostra('', '', null); return; }
            if ([...valore].length < 3) { mostra('', root.dataset.corto, null); return; }

            mostra('', root.dataset.attesa, null);
            richiesta = new AbortController();

            try {
                const risposta = await fetch(`${root.dataset.url}?handle=${encodeURIComponent(valore)}`, {
                    headers: { Accept: 'application/json' }, signal: richiesta.signal,
                });
                if (!risposta.ok) { mostra('', '', null); return; }
                const { data } = await risposta.json();
                /* Una risposta che arriva dopo che il campo è già cambiato non
                   deve sovrascrivere lo stato: senza questo controllo, digitando
                   in fretta si legge il verdetto di due lettere prima. */
                if (data.handle !== input.value.trim().toLowerCase()) return;
                mostra(data.available ? '✓' : '✗', data.available ? root.dataset.libero : root.dataset.occupato, data.available ? 'si' : 'no');
            } catch (errore) {
                if (errore.name !== 'AbortError') mostra('', '', null);
            }
        };

        input.addEventListener('input', () => {
            clearTimeout(attesa);
            segno.hidden = true;
            attesa = setTimeout(controlla, 350);
        });
        input.addEventListener('blur', () => { clearTimeout(attesa); void controlla(); });
    }
}

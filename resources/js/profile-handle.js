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
    avatarPicker();
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
                /* Un no che dipende dall'attesa fra due cambi non è «nome
                   occupato»: lì il server dice da quando si potrà, e si mostra
                   la sua frase. */
                const testo = data.available ? root.dataset.libero : (data.changeable_from ? data.reason : root.dataset.occupato);
                mostra(data.available ? '✓' : '✗', testo, data.available ? 'si' : 'no');
            } catch (errore) {
                if (errore.name !== 'AbortError') mostra('', '', null);
            }
        };

        input.addEventListener('input', () => {
            /* Minuscolo mentre si scrive: la maiuscola che il telefono mette
               da sola all'inizio diventa minuscola sotto le dita, invece di
               un errore dopo l'invio. Il cursore resta dov'era. */
            const minuscolo = input.value.toLowerCase();
            if (minuscolo !== input.value) {
                const [inizio, fine] = [input.selectionStart, input.selectionEnd];
                input.value = minuscolo;
                input.setSelectionRange(inizio, fine);
            }
            clearTimeout(attesa);
            segno.hidden = true;
            attesa = setTimeout(controlla, 350);
        });
        input.addEventListener('blur', () => { clearTimeout(attesa); void controlla(); });
    }
}

/**
 * La foto del profilo, preparata nel browser prima dell'invio.
 *
 * Una foto del telefono pesa 3-12 MB: oltre il limite del server, e il
 * risultato era una pagina d'errore invece di un messaggio. Qui la foto si
 * apre in un canvas, si riduce a 1024 px sul lato lungo e si riscrive in JPEG:
 * parte qualche centinaio di KB. Il server la ricodifica comunque (512 px,
 * senza metadati): questa è solo la strada per arrivarci.
 *
 * Se il browser non sa aprire il file (una HEIC su Chrome, un file rovinato)
 * il file parte così com'è e la risposta del server dice cosa non va — a meno
 * che non pesi troppo per partire: allora lo si dice subito e il campo si
 * svuota, così il modulo non finisce sul limite del server.
 */
const LATO = 1024;

async function riduci(file) {
    const bitmap = await createImageBitmap(file, { imageOrientation: 'from-image' });
    const scala = Math.min(1, LATO / Math.max(bitmap.width, bitmap.height));
    const canvas = document.createElement('canvas');
    canvas.width = Math.max(1, Math.round(bitmap.width * scala));
    canvas.height = Math.max(1, Math.round(bitmap.height * scala));
    const ctx = canvas.getContext('2d');
    // Fondo bianco: un PNG trasparente in JPEG verrebbe nero.
    ctx.fillStyle = '#fff';
    ctx.fillRect(0, 0, canvas.width, canvas.height);
    ctx.drawImage(bitmap, 0, 0, canvas.width, canvas.height);
    bitmap.close?.();
    const blob = await new Promise((resolve) => canvas.toBlob(resolve, 'image/jpeg', 0.88));
    if (!blob) throw new Error('toBlob');
    const nome = (file.name.replace(/\.[^.]+$/, '') || 'foto') + '.jpg';

    return new File([blob], nome, { type: 'image/jpeg', lastModified: Date.now() });
}

function anteprima(root, file) {
    const box = root.querySelector('[data-avatar-preview]');
    if (!box) return;
    const img = document.createElement('img');
    img.alt = '';
    img.width = 72;
    img.height = 72;
    img.src = URL.createObjectURL(file);
    img.addEventListener('load', () => URL.revokeObjectURL(img.src), { once: true });
    box.replaceChildren(img);
}

export function avatarPicker() {
    for (const root of document.querySelectorAll('[data-avatar-picker]')) {
        const input = root.querySelector('[data-avatar-input]');
        const stato = root.querySelector('[data-avatar-stato]');
        const togli = root.querySelector('[data-avatar-remove]');
        const form = input?.form;
        if (!input || !stato || !form) continue;
        const massimo = Number(root.dataset.maxBytes) || 2 * 1024 * 1024;
        let lavoro = null;

        const scrivi = (testo, errore = false) => {
            stato.textContent = testo;
            stato.classList.toggle('text-alert', errore);
            stato.classList.toggle('font-semibold', errore);
            stato.classList.toggle('text-ink-muted', !errore);
        };

        input.addEventListener('change', () => {
            const file = input.files?.[0];
            if (!file) { scrivi(''); return; }
            if (togli) togli.checked = false;
            scrivi(root.dataset.riduco ?? '');
            lavoro = (async () => {
                try {
                    const ridotta = await riduci(file);
                    const scelta = ridotta.size < file.size || file.type !== 'image/jpeg' ? ridotta : file;
                    const elenco = new DataTransfer();
                    elenco.items.add(scelta);
                    input.files = elenco.files;
                    anteprima(root, scelta);
                    scrivi(root.dataset.pronta ?? '');
                } catch {
                    if (file.size > massimo) {
                        input.value = '';
                        scrivi(root.dataset.illeggibile ?? '', true);
                    } else {
                        scrivi('');
                    }
                } finally {
                    lavoro = null;
                }
            })();
        });

        /* Un invio partito mentre la foto si sta ancora riducendo aspetta la
           fine del lavoro, poi riparte da solo con la foto pronta. */
        form.addEventListener('submit', (evento) => {
            if (!lavoro) return;
            evento.preventDefault();
            evento.stopImmediatePropagation();
            const pulsante = evento.submitter;
            lavoro.finally(() => form.requestSubmit(pulsante ?? undefined));
        }, { capture: true });
    }
}


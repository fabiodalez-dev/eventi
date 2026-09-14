# Proposta UI/UX con GSAP

Data: 14 settembre 2026. Branch: `fix/ui-ux-gsap`, da `main` dopo il deploy `e953c8e`. Questa fase produce un piano: nessuna modifica applicativa o pubblicazione.

## Direzione

Un calendario urbano editoriale, immediato da consultare. Conservare nero/lime e bianco/grafite/terracotta, foto naturali, segnalibri e gerarchia comune ai due temi. Rendere prima più chiari spazio, tipografia e azioni; usare il movimento per spiegare cambiamenti e confermare gesti. GSAP non riduce i tempi del server: caricamento reale e fluidità percepita si misurano separatamente.

## Stato osservato nel codice

- Card condivisa e righe allineate mediante subgrid; foto 3:4, badge salvati interattivo, prezzo e azioni nel footer.
- Filtri asincroni con AbortController, protezione dalle risposte obsolete, URL e cronologia aggiornati; transizioni Web Animations già presenti.
- MapLibre mantiene la mappa durante i filtri e dispone già di cluster e animazioni della camera.
- Hover CSS su card, titoli e frecce: prima di introdurre GSAP occorre eliminare le animazioni concorrenti sulle stesse proprietà.
- GSAP non è ancora una dipendenza npm. Installate le otto skill ufficiali da https://github.com/greensock/gsap-skills in ~/.codex/skills.

## 1. Card — prototipo da validare per primo

- Composizione unica: foto, categoria discreta, titolo con spazio per due/tre righe, luogo, data ben riconoscibile, footer stabile. Il titolo completo resta accessibile; verificare titoli lunghi e zoom del testo.
- Conservare 3:4 nelle griglie editoriali; prevedere una variante orizzontale compatta per lista e mappa, senza modificare le locandine originali.
- Badge salvati sulla foto con segnalibro e conteggio; aggiornamento immediato per ospiti, confermato dal server per autenticati. Nessuna animazione deve simulare un salvataggio fallito.
- Hover: foto scala 1 → 1,025, freccia avanza 3 px, bordo usa l'accento. Contenitore e pulsanti restano fermi: si evita un bersaglio che si sposta sotto il puntatore.
- Segnalibro: breve impulso dell'icona e dissolvenza del nuovo conteggio, senza cambiare dimensioni del pulsante o annunciare valori intermedi agli screen reader.
- GSAP Core: timeline reversibile 140–220 ms, attiva solo con puntatore preciso. Focus visibile equivalente, touch con feedback di pressione.

## 2. Home — scelta rapida prima dello scorrimento

- Ridurre l'altezza iniziale su mobile: titolo breve, azioni Oggi / Stasera / Weekend / Vicino a me e un evento principale, poi subito il catalogo.
- Un solo evento in evidenza, con data, luogo e prezzo immediati; nessun carosello automatico. Sezioni con titoli e link «Vedi tutti» nella stessa posizione.
- Ticker più discreto e arrestabile; evitare che competano ticker, hover e ingressi contemporaneamente.
- Timeline iniziale breve per i soli elementi secondari; titolo e foto principale subito visibili. Ingresso delle nuove card in piccoli gruppi, massimo 250 ms complessivi.
- Immagine principale prioritaria, dimensioni riservate; immagini fuori schermo lazy. Verificare srcset e peso delle varianti prima di aggiungere effetti.
- ScrollTrigger opzionale solo sotto la prima schermata, una volta per elemento; niente scroll artificiale o sezioni bloccate.

## 3. Pagina eventi — filtrare senza perdere il filo

- Barra compatta con data, categoria, zona, gratuiti e ordinamento; filtri attivi rimovibili e numero risultati vicini alla lista.
- Desktop: pannello filtri leggibile e stabile. Mobile: pannello con filtri modificabili e pulsante «Mostra risultati»; applicazione in un'unica richiesta, con distinzione tra selezioni provvisorie e applicate.
- Conservare risultati durante la richiesta; indicatore discreto dopo circa 150 ms, errore con «Riprova» e filtri invariati. Nessuna attesa artificiale per terminare una timeline.
- Per zero risultati spiegare quali filtri sono attivi e proporre di rimuoverli singolarmente; non ignorare silenziosamente una selezione dell'utente.
- Preservare focus, posizione utile e cronologia Indietro/Avanti. Debounce solo sui campi testuali, richieste annullate e risposte obsolete scartate.
- Flip per riordinamenti con identità stabile dell'occorrenza: conservare/riconciliare i nodi comuni. Per risultati del tutto nuovi basta una dissolvenza; non applicare Flip indiscriminatamente a centinaia di card.
- Non animare la card che contiene focus o un gesto in corso. Annunciare il totale aggiornato una sola volta tramite aria-live.

## 4. Mappa — lista e luoghi come un unico percorso

- Desktop: lista e mappa affiancate; selezione della card evidenzia il punto e viceversa, senza spostare la camera al semplice hover.
- Mobile: mappa e pannello risultati con stati chiuso / anteprima / elenco. Comandi espliciti accessibili anche senza trascinamento.
- «Cerca in quest'area» dopo uno spostamento manuale; mantenere zoom e centro durante filtri non geografici. Distinguere luoghi raggruppati e singole date.
- GSAP per apertura e chiusura del pannello; camera gestita da MapLibre con easeTo/fitBounds. Non animare il canvas o la stessa trasformazione con due motori.
- Conservare cluster e istanza della mappa; importare il codice pesante solo dove serve. Se la mappa fallisce, lista e filtri devono restare utilizzabili.

## Caricamento, accessibilità e architettura

### Transizioni e microinterazioni — integrazione richiesta

- **Ingresso delle card:** piccoli gruppi con opacity 0 → 1 e y 12 → 0, durata 220–280 ms, stagger 35–45 ms e durata totale massima 400 ms. Solo card appena inserite o viste per la prima volta; niente ripetizione quando si risale. Prima schermata subito disponibile, senza aspettare GSAP. Movimento ridotto: comparsa immediata.
- **Hover dei pulsanti:** riempimento con il colore del tema e freccia spostata di 3 px, 160–200 ms; uscita reversibile dalla posizione corrente. Testo e area cliccabile fermi. Focus da tastiera con contorno netto, touch con pressione breve; nessuna dipendenza dall'hover per capire l'azione.
- **Pressione e conferma:** scale 0,98 solo su uno strato visivo interno, 80–120 ms; il segnalibro cambia stato una sola volta e l'icona conferma con un impulso contenuto. Nessun blocco dei clic per aspettare la fine dell'animazione.
- **Aggiornamento risultati:** mantenere il vecchio contenuto durante la richiesta, poi transizione locale di 180–240 ms. GSAP Flip solo sui nodi comuni spostati; fade per entrate e uscite. Totale e filtri si aggiornano insieme, senza animare numeri intermedi.
- **Pannelli filtri e mappa:** apertura/chiusura 240–300 ms con traslazione breve e sfondo progressivo, focus e scroll gestiti secondo lo stato reale del pannello. Interruzione e inversione ammesse anche a metà transizione.
- **Navigazione tra pagine:** seconda fase sperimentale per un passaggio breve lista → dettaglio con continuità della foto. Valutare View Transitions come contenitore della navigazione e GSAP per il contenuto interno, mantenendo navigazione standard come fallback. Evitare un'uscita animata che ritardi l'avvio della richiesta o richieda una riscrittura SPA.
- **Cambio tema:** eventuale transizione cromatica breve, 120–160 ms, senza flash e senza animare contemporaneamente ogni elemento. Palette gestita esclusivamente dai token del tema.
- **Verifiche specifiche:** hover rapido avanti/indietro, doppio clic, clic durante l'ingresso, filtro cambiato prima della fine del precedente, ritorno dalla cronologia. Nessun contenuto deve restare invisibile dopo interruzione o errore JS.

- Aggiungere GSAP da npm con versione nel lock; Core iniziale, Flip a richiesta sul catalogo. Non caricare l'intero catalogo di plugin.
- Un modulo motion condiviso con durata breve 160 ms, normale 220 ms, pannelli 280 ms come valori iniziali da misurare.
- gsap.context() per ogni regione e cleanup prima della sostituzione del DOM; gsap.matchMedia() per breakpoint e prefers-reduced-motion. Annullare tween e listener su navigazione asincrona.
- Preferire transform e opacity; CSS per colori e focus. Nessun doppio controllo CSS/GSAP della stessa proprietà. Azzerare gli stili inline quando cambia il tema.
- Con movimento ridotto: niente traslazioni, zoom o sequenze; contenuti e feedback restano disponibili. Mai nascondere contenuti essenziali in attesa del download di GSAP.
- Skeleton solo per caricamenti iniziali senza contenuto, con geometria corretta; niente lampeggi o shimmer continuo. Durante il filtraggio conservare i risultati esistenti.
- Gestire immagini mancanti, rete lenta, offline, richieste fallite, badge zero, titoli lunghi e riapertura dalla cronologia.

## Sequenza di lavoro

1. Misurare baseline mobile/desktop, screenshot nei due temi e inventario delle animazioni esistenti; definire layout e token comuni.
2. Prototipare card in griglia e lista, inclusi hover, focus e salvataggio. Confronto locale prima di estendere il sistema.
3. Ridisegnare home e caricamento immagini; misurare nuovamente la prima schermata.
4. Migliorare filtri, stati di caricamento e transizioni del catalogo; poi coordinare lista e mappa.
5. Verifiche accessibilità, prestazioni, test funzionali e browser; revisione locale delle quattro aree. Deploy in una fase successiva alla proposta.

## Criteri di accettazione

- Verifiche a 375, 666, 804 e 1440 px, nei due temi; navigazione da tastiera e movimento ridotto.
- Nessun salto dovuto alle immagini o alle animazioni; obiettivo CLS ≤ 0,1. Obiettivi LCP ≤ 2,5 s e INP ≤ 200 ms da distinguere tra misure di laboratorio e dati reali disponibili, senza prometterli prima del benchmark.
- Confrontare JS/CSS compressi prima/dopo, lavoro del main thread e fluidità su dispositivo mobile; definire il budget incrementale dopo la baseline.
- Clic singolo sui segnalibri verificato senza retry automatico del gesto; conteggio coerente dopo refresh e tra più istanze.
- Filtri rapidi, risposte fuori ordine, errore e cronologia non fanno perdere selezioni né mostrano risultati obsoleti.
- Nessun listener duplicato, tween rimasto su DOM rimosso o mappa ricreata inutilmente dopo ripetuti cambi filtro.

## Ambito

Questa proposta riguarda il web pubblico Blade/JavaScript. L'app Android è nativa: potrà riprendere gerarchia e comportamenti con animazioni Compose, non eseguendo GSAP. Nessun nuovo APK richiesto da questa fase di piano.

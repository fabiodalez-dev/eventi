# Product

Aggiornato all’11 settembre 2026. Stato del rilascio e verifiche in [RELEASE-1.9.1](docs/RELEASE-1.9.1.md); priorità future in [ROADMAP-PRODOTTO](docs/ROADMAP-PRODOTTO.md).

## Register

product

## Users

Persone che vivono o visitano una città e, spesso mentre sono già fuori casa, vogliono decidere rapidamente cosa fare adesso, stasera o nel fine settimana. Gli utenti registrati salvano singole date, seguono locali e categorie, ricevono promemoria e ritrovano un feed personale. Redazione e gestori alimentano il catalogo dal web, non dall'app consumer.

## Product Purpose

inCittà è il calendario pubblico degli eventi locali. Riduce una ricerca oggi frammentata tra social, siti dei locali e locandine a un percorso breve: apro, capisco cosa sta succedendo, filtro, scelgo, salvo e raggiungo il luogo. Il successo non è il tempo passato nell'app, ma la probabilità che una persona trovi davvero qualcosa da fare.

## Brand Personality

Urbana, diretta, editoriale. Due espressioni della stessa identità: scuro nero/lime e chiaro minimale bianco/grafite con accento terracotta discreto. Energico senza essere rumoroso, netto senza essere ostile, locale senza essere folkloristico. Il chiaro evita fondi crema e grandi superfici arancioni; Bricolage Grotesque 700–800 dà carattere ai titoli, Manrope rende leggibili testi e controlli.

## Anti-references

Non deve sembrare una directory commerciale, un marketplace di biglietti, un social feed infinito o una generica app Material composta da card arrotondate. Niente gradienti, vetro, ombre decorative, palette multicolore, fotografie colorate o interfacce che nascondono l'informazione dietro animazioni. Le sponsorizzazioni non devono confondersi con il catalogo editoriale.

## Design Principles

1. La serata viene prima dell'interfaccia: il contenuto utile deve apparire subito.
2. Tempo e luogo sono fatti, non decorazione: devono essere leggibili a colpo d'occhio e derivare dalle stesse regole del sito.
3. Una data è l'unità d'azione: si salva l'occorrenza precisa, non un evento astratto.
4. Il personale resta personale: wishlist, follow, notifiche e sessioni dipendono esclusivamente dall'identità autenticata.
5. Pubblicità riconoscibile, controlli familiari, errori recuperabili: la fiducia vale più di un'interazione in più.

## Accessibility & Inclusion

Obiettivo WCAG 2.2 AA e piena navigazione con TalkBack. Contrasto elevato, aree di tocco di almeno 48 dp, ordine di lettura coerente, etichette testuali per ogni icona, supporto al ridimensionamento dei caratteri e nessuna informazione affidata al solo colore. Le animazioni rispettano la preferenza di movimento ridotto; caricamento, vuoto, offline ed errore sono stati espliciti e comprensibili.

## Comportamenti consolidati

- **Aspetto:** primo accesso secondo il tema di sistema; scelta esplicita chiaro/scuro persistente. Web: cookie essenziale `incitta_appearance` di un anno, documentato nella cookie policy, e preferenza nel profilo. Android: memoria locale per gli ospiti e sincronizzazione del profilo tramite API. Il comando web è una sola icona che alterna i temi, ultima a destra su desktop; su mobile resta compatto. La scelta è disponibile anche nel profilo. I pannelli backend sono sempre chiari.
- **Identità delle date:** `/eventi/{slug}/{numero}` identifica una singola replica. Il numero parte da 1 per evento, resta stabile e non è l’ID globale. La scheda della serie raccoglie le date; filtri, mappe, salvataggi e condivisioni distinguono ogni appuntamento. Non sono previsti 301 dai vecchi percorsi `/date/{id}` per questa migrazione.
- **Luogo e organizzatore:** possono essere diversi. La sede della data determina mappa e indicazioni; se manca un organizzatore esplicito, vale il locale originale dell’evento. Spostare una replica non cambia implicitamente l’organizzatore.
- **Pubblicità:** riconoscibile e pertinente. Negli archivi si preferisce una sponsorizzazione compatibile con tag, categoria e data; se manca si conserva la selezione ordinaria e personalizzata. Nel dettaglio non si promuove mai lo stesso evento, incluse le sue altre repliche. La regola vale anche su Android.
- **Mappe e filtri:** collegamenti alle date corrette, risultati con fondo opaco e transizioni sobrie rispettose del movimento ridotto. Nella scelta rapida città e quartieri con eventi precedono quelli senza risultati.
- **Redazione:** verifica del locale separata da approvazione e piano PRO; le proposte pubbliche possono essere esaminate e convertite in eventi pubblicati dal backend.
- **Social:** Instagram, Facebook e Telegram configurabili dal backend; preparazione, programmazione e annullamento dei post, con storico degli esiti. Credenziali valide e attivazione dei canali restano necessarie: la presenza del codice non prova una pubblicazione reale. Sistema → Cron e programmazioni documenta scheduler e worker centrali.

## Confini e priorità

Il prodotto comprende sito pubblico, pannelli web, API e app Android nativa; le modifiche ai flussi condivisi devono essere considerate su entrambe le interfacce consumer. Non richiedere una seconda autorizzazione per build o deploy già richiesti nella sessione.

La qualità comprende test funzionali, browser e dispositivo, analisi statica e verifiche architetturali. Non equivale a dichiarare ogni controllo verde: livello massimo Larastan, prestazioni Lighthouse e verifiche dei servizi esterni hanno limiti espliciti nel referto del rilascio. Le funzioni future della roadmap non sono presentate come già distribuite.

# SEO eventi: URL, dati e verifiche

## Identità delle pagine

La pagina `/eventi/{slug}/{numero}` è la URL canonica stabile di un appuntamento.
Il suo JSON-LD usa la stessa URL e `#event` come identificativo. Le card e le
condivisioni della data puntano a questa pagina. Una scheda con un unico
appuntamento resta raggiungibile all’URL breve ma dichiara la stessa canonical;
la sitemap contiene soltanto la pagina della data. Una serie con più date
storiche o una ricorrenza mantiene la scheda riepilogativa CollectionPage,
anche quando rimane una sola data futura. Le date conservano i propri Event.

Il numero pubblico parte da 1 per ciascun evento ed è persistito in `url_number`:
non è l'ID globale, non deriva dalla posizione corrente in una lista e non viene
riutilizzato dopo una cancellazione. Le date esistenti sono numerate inizialmente
in ordine cronologico; le nuove ricevono il successivo numero libero. Modificare
il giorno o l'orario non cambia URL. La modifica del titolo non rigenera lo slug.
Un contatore per evento, prenotato sotto lock e un vincolo univoco nel database,
impediscono di assegnare lo stesso numero a import concorrenti.

Esempio: `/eventi/jam-session/1` e `/eventi/jam-session/2` sono due pagine Event;
`/eventi/jam-session` è il riepilogo della serie. I filtri lavorano su starts_at,
business_date e sugli altri dati temporali, mai sul numero nell'URL. La mappa
raggruppa per locale, il pannello mostra le singole date filtrate (fino al limite
previsto dal pannello) e ogni card collega la propria replica.

Su richiesta esplicita non vengono introdotti redirect 301: i vecchi percorsi
`/date/{id}` vengono rimossi. La numerazione si applica anche a calendario e PDF;
ID di API, prenotazioni e identificatori ICS restano invariati.

### Fonti e scelta degli URL

[Search Engine Journal, Matt G. Southern](https://www.searchenginejournal.com/ranking-factors/urls/)
considera le keyword negli URL un segnale di ranking marginale, non una ragione
per aggiungere parole o promettere miglioramenti di posizione.
[Ahrefs](https://ahrefs.com/blog/seo-friendly-urls/) raccomanda URL descrittivi
e concisi. La scelta titolo/numero privilegia semplicità e identità stabile.
Il locale resta in titolo SEO/contenuto quando pertinente e soprattutto nel
campo location dei dati strutturati: non è obbligatorio nel percorso.
[Google Event](https://developers.google.com/search/docs/appearance/structured-data/event)
richiede una pagina distinta per ciascun evento; non prescrive una data nel suo URL.

Questa modifica è applicata in locale; i test di regressione sono preparati ma
eseguiti: URL progressivi, filtri delle repliche e collegamenti API verificati.

## Organizzatore e luogo

Regola richiesta dal prodotto, in ordine di precedenza:

1. Organizzatore attivo selezionato tramite `organizer_id`.
2. Locale scelto esplicitamente come organizzatore (`organizer_venue_id`).
3. Nome e URL dell’organizzatore compilati manualmente.
4. In assenza dei precedenti, locale originale dell’evento.

La sede effettiva della singola data alimenta `location`. Spostare una data
in un altro locale non cambia il suo organizzatore: il fallback continua a
indicare il locale dell’evento. Frontend e schema leggono lo stesso dato;
non viene stampato un secondo organizzatore dal blocco informativo.
Se mancano sia organizzatore sia locale, il campo non viene inventato.

## Offerte e contenuti

Le date terminate mantengono la scheda ma non espongono offerte acquistabili.
Per biglietti non ancora in vendita non si dichiara una disponibilità di
prevendita. Se esiste l’apertura delle prenotazioni interne, viene emesso
`validFrom`. Prezzi e valute derivano dal listino e dagli override della data.

Il controllo SEO del backend segnala testi che indicano gratuità in presenza
di un prezzo positivo; è un avviso da verificare, non una correzione automatica.
Il seeder marca gli esempi come demo. La migrazione del 10 settembre corregge
solo il concerto acustico che corrisponde integralmente al testo e al prezzo
originali del seeder: aggiorna il testo a 8 euro e imposta noindex tramite
is_demo. Non modifica eventi con descrizioni già riscritte.

## Immagini e sitemap

Tre conversioni WebP dei poster: `schema-square` 1200×1200,
`schema-landscape` 1600×1200, `schema-wide` 1920×1080. La locandina viene
contenuta interamente su fondo neutro, senza tagliare informazioni. Lo schema
include le varianti soltanto quando generate; resta il fallback alla
locandina disponibile. La generazione avviene nella coda media esistente.

Per rigenerare le sole varianti mancanti dei poster già caricati:

```sh
php artisan media-library:regenerate event --only=schema-square --only=schema-landscape --only=schema-wide --only-missing --queue-all --force
```

Usare in produzione il percorso PHP indicato in Sistema → Cron e
programmazioni. Il cron del worker già installato consuma anche queste
conversioni: non occorre aggiungerne uno.
Il comando filtra il valore salvato in `media.model_type`: qui è `event`,
l’alias della morph map, non il nome completo della classe PHP.

La sitemap calcola lastmod dai dati dell’evento, data, sede, organizzatore,
media, lineup e listino. Eliminare una fascia o una voce di lineup aggiorna
il genitore. L’indice delle sitemap omette lastmod anziché simulare una
modifica a ogni richiesta. Le landing filtrate vuote non vengono inserite.

## Indicizzazione e controlli esterni

Si indicizzano elenchi generali e landing con risultati e un solo filtro
significativo: categoria, tag, comune, quartiere, giorno, oggi/domani/weekend
o gratuiti. Le combinazioni e i budget arbitrari restano noindex. La pagina
2 conserva una canonical propria e ha un titolo distinto. `/cerca` espone
noindex ed è leggibile dal crawler, quindi robots.txt non blocca quel percorso.

Verificare il rilascio con Rich Results Test sulle pagine delle date e con
Ispezione URL nella Search Console del dominio. Schema valido non garantisce
rich result: le esperienze esclusivamente virtuali e gli eventi riservati
agli iscritti non sono idonei alla specifica esperienza Google Event.
Core Web Vitals va valutato sui dati reali; Lighthouse è una misura di laboratorio.

## Spazio del server e archivi dei deploy

Il deploy conserva al massimo dieci copie riconosciute di codice e asset. Prima
di crearne una nuova elimina le copie più vecchie, lasciando nove rollback.
La pulizia riguarda soltanto le cartelle datate contenenti `code.tar` e
`build.tar.gz`; backup del database, media, file aggiuntivi e collegamenti
simbolici restano esclusi. La quota del singolo account hosting può esaurirsi
anche quando `df` mostra spazio libero sul disco del server.

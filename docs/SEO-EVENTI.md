# SEO eventi: URL, dati e verifiche

## Identità delle pagine

La pagina `/eventi/{slug}/date/{id}` è la URL canonica stabile di un appuntamento.
Il suo JSON-LD usa la stessa URL e `#event` come identificativo. Le card e le
condivisioni della data puntano a questa pagina. Una scheda con un unico
appuntamento resta raggiungibile all’URL breve ma dichiara la stessa canonical;
la sitemap contiene soltanto la pagina della data. Una serie con più date
storiche o una ricorrenza mantiene la scheda riepilogativa CollectionPage,
anche quando rimane una sola data futura. Le date conservano i propri Event.

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

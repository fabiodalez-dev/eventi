# SEO automatica e piano dei campi — 6 settembre 2026

## Scelta tecnica

Mantenere `PageMeta`, `StructuredData`, `EventListingMeta` e `spatie/laravel-sitemap`.
Non installare `artesaos/seotools`: gli helper per title, Open Graph, Twitter e
JSON-LD duplicano servizi già presenti e testati. Il composer.json del ramo master
dichiara compatibilità Laravel 13; il motivo della scelta non è incompatibilità.
La logica di date, pubblicazione, prezzi, prenotazioni e canonical resterebbe comunque nostra.

SEO automatica significa derivare dichiarazioni dai dati verificati, non generare
informazioni, recensioni o FAQ inventate. Nessun pacchetto garantisce posizionamento,
indicizzazione, caroselli o risultati avanzati.

## Confronto Eventbrite

La pagina indicata separa organizzatore e luogo, riassunto e descrizione,
informazioni pratiche, ingresso e inizio, programma a fasce orarie e condizioni
di rimborso. Include regole per minori, parcheggio, itinerari e contatto organizzatore.
Il vantaggio da replicare è la chiarezza delle informazioni, non la quantità di tag SEO.
La pagina è stata consultata tramite la versione indicizzata dello stesso evento:
il caricamento diretto ha risposto 429. Non è stato verificato il suo JSON-LD live.

## Interventi implementati in questo rilascio

- Home: titolo descrittivo con città e canonical privo dei parametri di marketing.
- Eventi: titolo con città; archivio pubblico indicizzabile, coerente con sitemap,
  senza modificare date, stato storico o privacy delle bozze.
- Schema Event: `about` al posto dell'inesistente `eventType`; apertura porte e lingua
  quando presenti; date senza orari inventati per eventi tutto il giorno; fine solo
  quando esplicitamente conosciuta, mai derivata dalla durata predefinita di categoria.
- Descrizioni strutturate senza troncamento a 500 caratteri; priorità al sommario
  editoriale quando disponibile. Nessun cambiamento al testo originale visibile.
- Offerte annullate non dichiarate disponibili; sui rinvii non dichiarare disponibilità
  non confermata. Link di organizzatori, artisti, biglietti e social filtrati per schema sicuro.
- Categorie, tag e altre liste eventi: CollectionPage + ItemList dei risultati della
  pagina effettivamente servita, con BreadcrumbList già esistente.
- Canonical delle liste eventi conserva la pagina successiva; ordinamenti alternativi
  non indicizzabili. Le URL promozionali non diventano canonical.
- Locali: immagini nel Place; elenco con CollectionPage/ItemList, canonical per
  pagina e filtri, noindex sulla ricerca e varianti filtrate.
- Open Graph italiano `it_IT`; autorizzazione alle anteprime grandi delle immagini.

Questa è una correzione della base tecnica, non la conclusione di ogni attività SEO.
I campi e i flussi descritti sotto sono il piano successivo, non funzionalità già aggiunte.

## Piano dei campi: prima riutilizzare, poi aggiungere

| Ambito | Campi / struttura | Regola e automatismi | Priorità |
|---|---|---|---|
| Evento, identità | title, subtitle, short_description, description, categoria e tag già presenti | Un titolo reale; sommario autonomo; testo leggibile in HTML. Title e description SEO derivati, non richiesti al locale | P1 |
| Organizzazione | organizer_name, organizer_url già presenti; futuro organizer_id e tipo Person/Organization | Distinguere chi organizza da chi ospita. Non attribuire automaticamente alla piattaforma eventi di terzi senza conferma | P1 |
| Singola data | starts_at, ends_at, doors_at, is_all_day già presenti | Fine facoltativa; fuso della città; apertura porte distinta dall'inizio. Convalida ordine temporale nel motore centrale | P1 |
| Rinvii | previous_start_at / storico strutturato delle date e motivo pubblico | Valorizzare EventRescheduled e previousStartDate dopo un effettivo cambio data; non confondere cambio luogo e rinvio | P1 |
| Luogo | locale collegato oppure custom_location già presenti | Nome, indirizzo, comune, CAP, coordinate; evidenziare dati mancanti senza inventarli | P1 |
| Prezzo e prenotazione | prezzo, valuta, ticket_url e ticketing esistenti | Un unico resolver pubblico per prezzo effettivo, fasce tariffarie, posti, chiusura vendite e annullamento; stesso risultato in HTML/API/schema | P1 |
| Immagini | poster e gallery già presenti; aggiungere alt/caption/credit | Originale accessibile e pertinente; alternative 1:1, 4:3, 16:9 solo se non tagliano informazioni. Non alterare locandina per inseguire un formato | P1 |
| Contenuto editoriale tassonomie | category/tag description e introduzione per città | Testo utile e specifico, solo sulla landing principale; niente blocchi ripetuti in ogni combinazione di filtri | P1 |
| SEO opzionale | title, description, image, indexing_mode per evento/locale/tassonomia/pagina/città | Default automatico. Override avanzato admin; locale modifica solo il proprio contenuto. Nessun campo JSON-LD libero o canonical esterno arbitrario | P2 |
| Programma | event_agenda_items: titolo, testo, inizio/fine, posizione, relatori | Blocco ripetibile; gestisce mezzanotte e più giorni; riferimento alla data quando il programma varia | P2 |
| Pubblico | age_restriction esistente; regola minori strutturata e testo accompagnamento | Età minima, accompagnatore, eventuali eccezioni; non dedurre dai tag | P2 |
| Accessibilità | caratteristiche e note esistenti, da uniformare tra evento e locale | Tre stati: sì/no/non specificato. Il locale fornisce il default, l'evento può correggerlo | P2 |
| Arrivo | parking_type, parking_notes, transit_notes, entrance_notes | Default del locale con override evento. Link itinerari derivati dalle coordinate | P2 |
| Condizioni | cancellation_policy, refund_policy e contatto pubblico | Regole coerenti con ticketing gratuito. Non generare politica economica automatica per biglietti esterni | P2 |
| Modalità | attendance_mode; eventuale URL pubblico informativo online | Fisico/online/ibrido solo dopo supporto reale dei relativi flussi. Mai esporre nello schema link di accesso riservati ai prenotati | P3 |
| FAQ | domanda, risposta, ordine; relazione con evento/locale/tassonomia | Domande reali, risposte visibili nella pagina, compilazione facoltativa, nessuna risposta inferita su rimborsi/accessibilità | P3 |

La capienza resta facoltativa. Il limite di biglietti online non equivale
necessariamente alla capienza fisica: non va dichiarato come maximumAttendeeCapacity.
I dati delle persone prenotate e i loro QR non entrano mai nella SEO.

## Interventi architetturali successivi, in ordine

1. **URL per le singole date.** Oggi ogni occorrenza ha un @id distinto ma condivide
   la scheda dell'evento. Per appuntamenti realmente separati introdurre una pagina
   focalizzata sulla data, con URL stabile, canonical autonomo, informazioni visibili,
   prenotazione corretta e sitemap dedicata. La scheda generale resta indice del ciclo.
   Non basta cambiare un frammento `#data` nello schema. Evitare pagine artificiali
   per ciascun giorno di un unico evento continuativo.
2. **Resolver offerte unico.** Connettere tutti i casi del ticketing ai dati strutturati:
   disponibilità effettiva, prenotazione interna, vendite non iniziate/terminate,
   fasce tariffarie e override. Non dedurre prezzo gratuito da prezzo sconosciuto.
3. **Landing curate.** Approvazione editoriale dei tag destinati all'indice, presenza
   effettiva nella città, descrizione originale, gestione delle pagine senza risultati.
   Condividere la stessa decisione tra robots, sitemap e link interni; non imporre
   noindex appena finisce una singola serata se la landing resta utile.
4. **Editor semplice.** Sezione facoltativa “Anteprima su Google” con automatico come
   default, suggerimenti sui dati mancanti e anteprima modifiche salvate. Nessun
   punteggio 100/100 che incentivi ripetizioni o keyword stuffing.
5. **Qualità del catalogo prima della crescita.** I dati attuali sono dimostrativi:
   sostituire/verificare gli eventi prima di promuoverli come appuntamenti reali.
   Predisporre un flag demo o un ambiente demo non indicizzabile. Non inventare
   manifestazioni, disponibilità o recensioni per ottenere traffico.
6. **Monitoraggio reale.** Search Console, sitemap inviata, Rich Results Test su URL
   pubblici rappresentativi, Schema.org Validator e Core Web Vitals misurati.
   La suite locale verifica output e regressioni, non certifica approvazione Google.
   Accesso a Search Console e credenziali non sono stati configurati in questo lavoro.

## Matrice di indicizzazione desiderata

| Pagina | Indicizzazione | Schema |
|---|---|---|
| Home città | Sì, contenuto originale e catalogo reale | WebSite, Organization |
| Evento pubblico / storico | Sì se utile; stato e date autentici | Event e BreadcrumbList |
| Bozza / anteprima | No; autenticazione e policy prima dei meta | Nessun Event pubblico |
| Categoria / tag curato | Sì, canonical stabile | CollectionPage, ItemList, BreadcrumbList |
| Ricerca libera / geolocalizzata / ordinamenti | No | Nessuna promessa di rich result |
| Locale approvato | Sì | Place; sottotipo solo se verificato |
| Pagine successive | Canonical proprio, link HTML percorribili | Lista limitata ai risultati visibili |
| Profilo / biglietti / checkout / pannelli | No; protezione applicativa | Nessun dato personale |

## FAQ: aspettativa corretta

Le FAQ aiutano comprensione e prenotazione. Google ha ritirato i risultati avanzati
FAQ da maggio 2026: non sono una scorciatoia per aumentare lo spazio in SERP.
Schema.org può restare utile semanticamente, ma solo per contenuto realmente visibile.

## Fonti consultate

- [Evento Eventbrite](https://www.eventbrite.com/e/registrazione-cena-medievale-con-spettacolo-di-fuoco-il-tempo-di-berta-1995189959879?aff=ebdssbcitybrowse)
- [SEO Tools](https://github.com/artesaos/seotools) e [compatibilità dichiarata](https://raw.githubusercontent.com/artesaos/seotools/master/composer.json)
- [Google: eventi](https://developers.google.com/search/docs/appearance/structured-data/event)
- [Schema.org Event](https://schema.org/Event)
- [Google: canonical](https://developers.google.com/search/docs/crawling-indexing/consolidate-duplicate-urls)
- [Google: paginazione](https://developers.google.com/search/docs/specialty/ecommerce/pagination-and-incremental-page-loading)
- [Google: direttive robots](https://developers.google.com/search/docs/crawling-indexing/robots-meta-tag)
- [Google: ritiro FAQ, aggiornamenti maggio/giugno 2026](https://developers.google.com/search/updates)

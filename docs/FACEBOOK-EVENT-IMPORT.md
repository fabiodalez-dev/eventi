# Importazione da link Facebook

## Flusso

Nel primo passo di **Gestione → Eventi → Nuovo evento**, il locale incolla il link e preme **Carica dal link Facebook**. Titolo e descrizione completa sono necessari; date, foto e gli altri campi possono mancare. Una data mancante non cancella quella già inserita nel form. Il modulo viene precompilato, senza creare un evento o pubblicarlo. Titolo, descrizione integrale, inizio/fine disponibili, prezzo d’ingresso esplicito, link e copertina restano modificabili. I passi successivi e la moderazione sono quelli del wizard esistente.

L’indirizzo, gli organizzatori, le coordinate, i conteggi e gli altri metadati pubblici estratti restano in `events.source_metadata.facebook`, insieme alla descrizione originale e alla data di lettura. Il riepilogo della fonte mostra luogo, indirizzo, orario e tutti gli organizzatori. Gli organizzatori di Facebook sono conservati con nome, ID e link, senza concedere loro permessi di gestione. Il locale proprietario del record resta quello autenticato, non viene sostituito da un nome trovato su Facebook. Le risposte/interessati di Facebook non incrementano i salvataggi di inCittà.

La foto viene scaricata temporaneamente nel caricatore immagini esistente, dove può essere rimossa o sostituita. Diventa una locandina persistente quando il locale salva. Se il download fallisce, i dati testuali vengono comunque caricati e viene mostrato un avviso.

## Verifica reale del 13 settembre 2026

URL: <https://www.facebook.com/events/1078756118449684/>.

- Titolo: `16.10 / SICK TAMBURO @ CSO Pedro - Padova`.
- Descrizione: 2.868 caratteri, completa, compresi prezzo e programma.
- Inizio strutturato: `2026-10-16T19:00:00Z`, ossia 21:00 a Padova. Fine assente, mantenuta vuota.
- Luogo: CSO Pedro, Via Ticino 5, 35135 Padova, con coordinate.
- Copertina: JPEG 1.200 × 628, 87.712 byte, scaricata e validata attraverso lo stesso servizio PHP usato dal form.
- Organizzatore e link Instagram/Facebook disponibili.
- La descrizione distingue apertura alle 21:00 e concerto alle 23:00; il modulo conserva l’inizio strutturato e tutto il testo, lasciando la scelta al locale.

Confronto dei repository richiesti:

| Repository | Risultato |
| --- | --- |
| [mrkkr/fb-events-scraper](https://github.com/mrkkr/fb-events-scraper) | Testato il parser browser sul link: restituisce tre eventi suggeriti, nessuno con l’ID richiesto. È orientato a elenchi di pagine, non ai dettagli di un evento. |
| [ChocoData-com/facebook-event-scraper](https://github.com/ChocoData-com/facebook-event-scraper) | Lo script gratuito funziona ma restituisce solo OpenGraph: titolo, anteprima descrittiva e copertina. Non restituisce la descrizione integrale o l’orario. |
| [artine-pton/facebook-events-lite-ppr](https://github.com/artine-pton/facebook-events-lite-ppr) | Il repository contiene README, senza il codice dello scraper descritto. |
| [LuizinTheHeroSalyer/facebook-events-scraper](https://github.com/LuizinTheHeroSalyer/facebook-events-scraper) | Contiene archivi con eseguibili Windows/Lua; ispezionati gli elenchi, non eseguiti i binari. Non è un parser sorgente integrabile. |

L’implementazione del progetto riprende l’approccio browser e lo rende specifico per l’ID richiesto: legge i frammenti JSON pubblici caricati dalla pagina, unisce soltanto quelli dell’evento selezionato e ignora i suggerimenti. Nessuna dipendenza dai quattro repository viene installata nel sito.

## Secondo evento verificato

URL: <https://www.facebook.com/events/1536044601638258/>.

- Titolo: `TEATRO BRESCI | Malabrenta`; descrizione completa di 2.258 caratteri.
- Inizio `2026-09-13T19:15:00Z` (21:15 a Padova); fine assente.
- Palazzo Zuckermann, Corso Giuseppe Garibaldi, 33, 35121 Padova PD, Italia.
- Coordinate `45.41148, 11.87822`.
- Copertina JPEG 2.048 × 1.072, 74.476 byte, scaricata e validata dal servizio PHP.
- Link Eventbrite, sito del festival, organizzatore Castello Festival Padova e categoria Facebook Teatro disponibili. La categoria Facebook resta nel riepilogo/metadati; la categoria inCittà viene scelta nel catalogo del modulo.

## Indirizzo alternativo, anche senza Facebook

Il campo «Indirizzo dell’evento» è presente sia in creazione sia in modifica. Se compilato, sostituisce il luogo fisico dell’evento nelle schede, API e calendari, senza cambiare la sede o i permessi del locale proprietario. Se svuotato, l’evento usa nuovamente la sede del locale. Coordinate e nome residui non mantengono attiva la sostituzione.

Facebook precompila indirizzo, nome del luogo e coordinate disponibili. Un avviso persistente prima del salvataggio informa che verrà usato quell’indirizzo per l’evento e spiega come tornare alla sede. Cambiando l’indirizzo a mano si cancellano le coordinate del vecchio luogo, che possono essere reinserite. La scheda pubblica mostra la mappa e le indicazioni del luogo alternativo; senza coordinate usa l’indirizzo nelle indicazioni.

## Limiti e sicurezza

- Solo HTTPS e host d’ingresso `facebook.com`, `www.facebook.com`, `m.facebook.com`, `web.facebook.com`. Vietati credenziali nell’URL, porte esplicite, pagine non evento e domini somiglianti.
- Dal link viene ricavato solo l’ID numerico: la navigazione usa un URL canonico costruito dal server. Query string e parametri di tracciamento non vengono inoltrati.
- Browser temporaneo senza account, cookie personali o sessioni riutilizzate. Richieste e redirect limitati ai domini Meta necessari; immagini, font e video non vengono caricati dal browser.
- Tre tentativi al minuto e trenta in 24 ore per account. Una sola estrazione browser contemporanea. Timeout browser 50 secondi e processo 55 secondi; lock rilasciato anche in caso di errore.
- JSON di uscita massimo 1 MiB, dati JSON della pagina massimo 12 MiB; corrispondenza esatta fra ID richiesto e restituito, validazione tipi, date, lunghezze, coordinate e conteggi.
- Foto solo HTTPS su `*.fbcdn.net`; nessun redirect, DNS pubblico verificato e indirizzo fissato alla connessione cURL. Limite di 12 MiB secondo la configurazione media e massimo 40 milioni di pixel, MIME verificato sui byte. Passaggio anche attraverso `RealImage` e la pipeline immagini esistente.
- Nessuna shell costruita con input del locale: processo invocato con argomenti separati.
- Permesso di creazione ricontrollato sull’utente e sul locale correnti. Metadati di provenienza bloccati lato Livewire. Nessuna assegnazione massiva del JSON remoto al model.
- Descrizioni salvate attraverso il sanitizzatore del rich editor; gli attributi del record, le relazioni, lo stato di pubblicazione e i vincoli restano quelli del wizard.
- Fallimenti di lettura non producono record parziali. Dati mancanti non vengono inventati; una fine assente rimane assente e un prezzo non dichiarato non diventa “gratis”.

## Requisiti per il server

Applicare la nuova migrazione `2026_09_13_220000_add_event_source_metadata` prima di usare il salvataggio di eventi importati.

Il processo PHP deve poter avviare Node.js e Playwright, già presente nelle dipendenze npm del progetto. Sul server mantenere disponibili il pacchetto `playwright` e il suo browser Chromium, anche dopo la compilazione degli asset:

```sh
npx playwright install --with-deps chromium
```

L’installazione deve usare la stessa utenza/cache accessibile dal worker PHP. `FACEBOOK_IMPORT_NODE_BINARY` permette di indicare il percorso assoluto di Node. In sviluppo, con Google Chrome installato, è possibile impostare `FACEBOOK_IMPORT_BROWSER_CHANNEL=chrome`. Il valore predefinito usa Chromium di Playwright. `FACEBOOK_IMPORT_LIBRARY_PATH` permette di indicare una directory di librerie native installate per l’utente del sito, senza modificare le librerie di sistema.

Il test reale conferma il funzionamento per l’evento indicato al momento della prova. Eventi privati, restrizioni di Facebook o cambiamenti del markup possono impedire la lettura: il modulo manuale resta disponibile e l’importazione segnala l’errore.

## Test

```sh
node --test tests/Unit/Facebook/event-parser.test.mjs
php vendor/bin/pest tests/Feature/Venue/FacebookImportTest.php tests/Feature/Venue/EventWizardTest.php tests/Feature/Venue/EventLocationOverrideTest.php tests/Feature/Import
php vendor/bin/pest --configuration=phpunit.browser.xml tests/Browser/FacebookImportTest.php
php vendor/bin/phpstan analyse --memory-limit=1G --no-progress
```

Le fixture automatiche sono sintetiche: non copiano la descrizione o la foto dell’evento reale. Copertura: identità dell’evento, suggerimenti estranei, URL, dati mancanti, fusi/DST, foto, errori HTTP, DNS privati, redirect, limiti, permessi, metadati bloccati, sanitizzazione, nessun record prima del salvataggio, correzioni manuali e anteprima browser desktop/mobile.

# Filtri contestuali e contatori — 11 settembre 2026

## Regole condivise

- `ContextualFacets` usa `EventFinder` e il motore temporale canonico. Ogni
  conteggio parte dall’intero insieme filtrato, non solo dai risultati paginati.
  Le alternative geografiche ignorano la propria selezione e quelle a valle: comune → quartiere → locale. Gli altri vincoli (tempo, prezzo, categoria, tessera, posizione) restano attivi, anche nell’API.
- Le opzioni senza risultati spariscono. I filtri selezionati restano rimovibili
  anche quando non producono risultati. Togliendoli, le alternative ricompaiono.
- Sono rispettati preferenze esplicite, luogo effettivo della data, ricerca,
  posizione, fascia oraria e condizioni di accessibilità.
- Il wizard mostra invece anche categorie a zero, disabilitate e non spuntate.
  I conteggi delle categorie ignorano solo la selezione di categorie corrente:
  si possono scegliere più categorie in OR. I budget sostituiscono solo il budget
  corrente. Gli altri vincoli restano applicati.
- L'API `GET /api/v1/events/facets` accetta gli stessi parametri degli eventi e
  restituisce mappe di conteggi (oggetti JSON anche quando vuoti).

## Interazione

Lista e mappa web aggiornano filtri e risultati senza ricaricare il documento,
annullano le richieste superate e conservano URL condivisibili e cronologia.
I selettori avanzati applicano la modifica immediatamente e rimangono aperti
durante l'aggiornamento. Senza JavaScript i link e il modulo GET funzionano ancora.
Android usa gli stessi conteggi nella ricerca e nei filtri espandibili della mappa.

Su `/mappa` sidebar, form e istanza MapLibre restano montati: si riconciliano solo i nodi dei filtri cambiati e si aggiorna il source dei marcatori. Accordion, focus, scorrimento e ricerca locale vengono conservati. Nessuna ricreazione della mappa a ogni selezione; risposte obsolete di marcatori e schede vengono ignorate.

Comune, quartiere, locale e tag hanno ricerca immediata sulle opzioni disponibili. Nel web frecce su/giù esplorano i risultati mantenendo il focus nel campo, Invio conferma, Escape chiude l’esplorazione. Il testo cercato non diventa un filtro sull’evento e non entra nell’URL. Cambiare comune azzera quartiere e locale; cambiare quartiere azzera il locale.

La mappa scorre fuori dalla pagina prima del footer; un foglio aperto si chiude quando la mappa non è più visibile. Con la ricerca dalla propria posizione compare un pin blu con persona, distinto dai marcatori evento, etichettato “La tua posizione”. Usa le coordinate richieste esplicitamente dall’utente, resta nei successivi aggiornamenti e sparisce togliendo la posizione. Anche Android disegna il pin fuori dai raggruppamenti degli eventi.

## Tessera e accessibilità

`content_details.membership` dell’**evento** accetta `required`, `not_required` o null/non dichiarato. È modificabile nei campi editoriali condivisi di admin, locale e organizzatore; le note restano in `membership_notes`. Nessuna migrazione: usa il JSON editoriale esistente. Per compatibilità un evento con `price_type=membership` vale come tessera richiesta se non c’è una dichiarazione esplicita; non si eredita `venues.requires_membership`.

Il parametro `membership=required|not_required` vale su web, marcatori e API eventi. “Non richiesta” include solo dichiarazioni esplicite, non gli sconosciuti. Prezzo e requisito tessera sono due informazioni distinte, leggibili nel dettaglio e in “Prima di andare”, anche su Android. La scelta rapida non assume più che tutti gli eventi di un locale richiedano la stessa tessera.

L’accessibilità del luogo mantiene sei voci strutturate a tre stati: ingresso senza scalini, servizi igienici accessibili, posti riservati, percorso tattile, assistenza su richiesta e cane guida ammesso. I campi condivisi sono presenti in admin e nel profilo locale; le note editoriali permettono di dichiarare informazioni specifiche dell’evento. Non si trasforma l’assenza di dati in “accessibile”.

Il calendario pubblico apre un dialogo nativo con le schede del giorno caricate
su richiesta. Chiusura esplicita/Escape, collegamenti agli eventi e paginazione
interna; in caso di errore resta disponibile il collegamento alla giornata.

## Verifiche

`tests/Feature/Web/ContextualFacetsTest.php`: 31 casi, incluse combinazioni
incompatibili, rimozione di filtri senza risultati, JSON Android, permessi delle
preferenze, prezzo/locale della data e indipendenza dalla paginazione.

Regressioni: TonightWizard, TonightGeography, SelectedFilterChips, CalendarPage,
DiscoverySearch e VenueAndDiscovery. Contratti Android in TonightContractTest.
Verifica Chrome: aggiornamento categoria senza perdere lo stato della ricerca
nell'header; popup desktop con schede reali.

Nessuna migrazione né modifica ai dati o alle credenziali. La compilazione e i
test dei sorgenti Android non equivalgono alla distribuzione di un nuovo APK:
il packaging resta soggetto alla conferma del proprietario.

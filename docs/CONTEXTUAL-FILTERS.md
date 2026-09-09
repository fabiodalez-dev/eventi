# Filtri contestuali e contatori — 9 settembre 2026

## Regole condivise

- `ContextualFacets` usa `EventFinder` e il motore temporale canonico. Ogni
  conteggio interseca tutti i filtri correnti, non solo i risultati paginati.
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

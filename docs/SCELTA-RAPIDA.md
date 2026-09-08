# Scelta rapida: “La tua prossima serata”

Il wizard è accessibile dalla home Laravel a `/stasera` e dalla home Android.
Una domanda per schermata: comune (Padova preselezionata, oppure Ovunque),
quartiere/rione solo per Padova, orario, budget, categorie dinamiche, proposte.
Il pulsante «Trova la tua serata» è nell'hero web e nell'apertura Android.
Elenchi di comuni e quartieri ricercabili, senza servizi esterni.
Non richiede registrazione; se l'utente è autenticato rispetta le categorie escluse
dal profilo. Le scelte del wizard non modificano implicitamente le preferenze salvate.

## Criteri condivisi

`TonightDiscovery` usa esclusivamente `EventOccurrenceQuery` per le finestre
“Stasera” e “Tra poco”. Le proposte sono date future o non terminate secondo il
motore temporale, pubbliche, in stato programmato/spostato, ordinate per inizio.
Non vengono inserite date annullate, rinviate o esaurite, né riempitivi fuori filtro.
Una sola data per evento, massimo cinque eventi. Non ci sono boost sponsorizzati
o inferenze pubblicitarie nell'ordinamento del wizard.

Il budget riguarda l'ingresso, non il costo complessivo della serata. Il filtro
considera il prezzo effettivo della data (`price_override`), non solo quello
dell'evento. Un prezzo sconosciuto non viene interpretato come gratuito. Per un
prezzo variabile si considera la tariffa minima dichiarata, non la disponibilità
garantita di quella tariffa. Offerta libera inclusa nei budget positivi, ma non
nel filtro “solo ingresso gratuito”.

## Risultati e informazioni pratiche

Ogni proposta mostra le corrispondenze reali con orario, zona, budget e categorie
scelte. Il link apre la data precisa. “Prima di andare” distingue dati mancanti
da risposte negative, mostrando costi aggiuntivi, tessera, ingresso, trasporti,
parcheggio, maltempo, accessibilità ed età minima. Eredita informazioni dal locale
effettivo e conserva le indicazioni esplicite dell'evento.

“Cambia le scelte” mantiene i criteri; “Mostra tutti gli eventi” apre il catalogo
generale, senza annullare le esclusioni del profilo. Il sito collega direttamente
la pagina di modifica degli interessi; nell'app è accessibile dalla tab Profilo.

## API e Android

`GET /api/v1/tonight` usa lo stesso controller/servizio e non usa la cache delle
risposte pubbliche. Parametri: `step` (1–3), `when` (`tonight`, `starting_soon`),
`municipality`, `zone`, `budget` (vuoto, 0, 10, 20, 30, 50), `categories[]` (ID attivi).
Il contratto step=3 per i risultati resta compatibile con le app precedenti.
Il sito usa anche `question` (municipality, district, when, budget, categories,
results). Comune vuoto significa Ovunque; fuori Padova il quartiere residuo è
ignorato. I filtri riguardano sempre il locale della data effettiva.

La risposta contiene `categories`, `zones`, `municipalities`,
`neighborhood_municipality`, `results`. Ogni risultato comprende
`occurrence`, `reasons`, `practical` e `content_details`. Il contratto è additivo.
Android usa Compose nativo, navigazione indietro tra i passaggi e ripristino delle
scelte dopo la visita a una proposta. Errori di connessione mostrano un retry,
senza riciclare risultati incompatibili come se fossero nuovi.

## Verifiche e distribuzione

Test dedicati: parità web/API, esclusioni utente e isolamento account, limite
cinque eventi, date non disponibili, prezzi sconosciuti e sovrascritti, input
invalidi, informazioni mancanti e vincoli ereditati dal luogo.
Test di contratto Android su opzioni dinamiche, risultati vuoti e informazioni pratiche.
Controlli visuali in Chrome, PHPStan, lint Android, build web e test JavaScript.

Le modifiche sono locali nei sorgenti. Un nuovo APK richiede autorizzazione;
non confondere compilazione dei test con generazione o collaudo di un APK sul telefono.

Esiti del lotto: 27 test PHP passati (wizard, preferenze e organizzatori), 39 test
Android passati con controllo opt-in sulle API locali incluso, 6 test JavaScript
passati, PHPStan e lint Android senza errori. Build web riuscita. Nessun deploy o
nuovo APK; nessuna riesecuzione integrale della suite PHP in questo lotto.

## Comuni e quartieri (8 settembre 2026)

Catalogo locale versionato in `config/discovery-geography.php`, separato per
città. I quartieri sono i nomi d'uso dei rioni (Brusegana, Guizza, Arcella…),
non le sei circoscrizioni amministrative. Il campo esistente `venues.zone`
viene riutilizzato: nessuna migrazione e nessun quartiere inventato per i locali
già presenti. Locali senza quartiere restano visibili scegliendo tutta Padova.
Nei moduli admin e referente, Padova richiede un quartiere; gli altri comuni
non lo richiedono. Cambiare comune cancella la selezione precedente.

Fonti per manutenzione del catalogo:
- [Provincia: 101 comuni](https://www.provincia.padova.it/comuni-del-territorio).
- [Comune: rioni nelle Consulte](https://www.comune.padova.it/le-consulte-di-quartiere).
- [Unità urbane](https://www.comune.padova.it/sites/default/files/2025-04/Capitolo%202.pdf).

Inclusi Borgo Veneto e Santa Caterina d'Este al posto dei comuni accorpati.
I vecchi dati non sono riscritti: eventuali indirizzi in comuni accorpati vanno
revisionati dal referente, non indovinati durante una ricerca.
20 casi aggiuntivi in `TonightGeographyTest`: catalogo, percorso condizionale,
input invalidi, luogo della data, Ovunque, quartiere mancante e salvataggio locale.

Verifica di questo aggiornamento: 31 test PHP passati (20 nuovi più regressioni
wizard/profilo locale), PHPStan senza errori, 7 test JavaScript passati,
test unitari Android e contratto API locale passati; lint Android riuscito.
Chrome a 390 px: ricerca comuni/rioni e salto quartiere verificati, nessun
overflow orizzontale. Nuova build web riuscita; restano gli avvisi preesistenti
sulla dimensione dei chunk. Nessun deploy o APK generato in questo aggiornamento.

# Prenotazioni gratuite e biglietti

## Piano e confini

Modulo unico Laravel, consumato da sito e Android con Sanctum. Filament mantiene
creazione/moderazione degli eventi; Spatie gestisce i ruoli già esistenti.
Simple QrCode genera QR e DomPDF il biglietto stampabile. Nessun pagamento,
commissione o gateway. Posti non numerati: la capienza fisica del locale non è
un inventario e non viene usata come limite implicito.

1. Abilitazione amministrativa per locale, disattivata per impostazione iniziale.
2. Configurazione per data: attivazione, inventario facoltativo (`null` = senza
   tetto), finestra prenotazioni, limite per account, annullamento e lista d'attesa.
3. Prenotazione nominativa di gruppo con QR individuali. Account obbligatorio.
   Richieste ripetute con la stessa chiave non emettono altri biglietti.
4. Profilo sito/app: storico, QR, PDF web, cancellazione singola o di gruppo.
5. Gestore: partecipanti, CSV, controllo ingresso, revoca, impostazioni.
   Proprietari vedono solo i propri locali; gli editor non ricevono elenchi personali.
6. Notifiche transazionali in coda, annullamenti e promozione lista d'attesa.
7. Seeder dimostrativo locale, test isolamento, disponibilità e ciclo dei ticket.

## Invarianti

- Una sola prenotazione attiva per account e data, anche in lista d'attesa.
  Il controllo avviene sotto lock e vale per sito e API con chiavi diverse.
  L'annullamento parziale non consente un secondo ordine; dopo annullamento
  completo si può riprenotare se la finestra è aperta. Le altre date restano indipendenti.
  Il sito mostra «Gestisci la tua prenotazione» e il vecchio URL del modulo
  reindirizza alla prenotazione personale, protetta da policy.
- Ogni modifica di inventario acquisisce il lock della data prima dei biglietti.
- Un biglietto controllato non può essere riutilizzato o annullato dall'utente.
- QR opachi, senza nome/email. Nessun ingresso viene registrato con GET.
- Lista d'attesa esplicita e FIFO per prenotazione intera (nessuno scavalcamento).
- Disabilitare il modulo blocca nuove prenotazioni, non nasconde quelle emesse.
- Annullare una data o evento revoca anche i ticket già emessi; riaprire la data
  non riattiva i vecchi QR. I cambi d'orario avvisano i prenotati.
- Annullamento personale fino alla scadenza configurata (default inizio evento).
- Prenotazioni e QR sono risposte private, mai cache pubblica.

## Esclusioni esplicite

Non sono inclusi pagamenti/rimborsi monetari, piantine con sedili numerati,
rivendita/trasferimento dei biglietti o validazione offline (che non può garantire
assenza di doppi ingressi fra dispositivi). Questi richiedono flussi separati.

## Utilizzo

### Form configurabile e PDF via email (aggiornamento)

Il gestore sceglie nelle impostazioni di ogni data se telefono, indirizzo,
comune, CAP e paese del prenotante sono nascosti, facoltativi o obbligatori.
Nome e cognome sono separati sia per il prenotante sia per ciascun partecipante.
L'email resta quella dell'account autenticato. I campi nascosti vengono scartati
dal server; i dati aggiuntivi del prenotante sono cifrati nel database e visibili
solo al titolare della prenotazione e al gestore autorizzato (anche nel CSV).

Il limite per account conta i biglietti attivi della stessa data, inclusa la
lista d'attesa e gli eventuali ordini storici precedenti al vincolo unico. Non limita la capienza
fisica del locale. Il checkbox privacy non è preselezionato e non è consenso
marketing; vengono registrate data e versione della presa visione.

Le email di conferma, promozione dalla lista d'attesa, aggiornamento e reinvio
allegano un PDF per ogni biglietto ancora valido al momento dell'invio. I biglietti
annullati, scaduti, utilizzati o ancora in attesa non sono allegati come validi.

Le API accettano `attendees: [{first_name, last_name}]` e
`booker: {first_name, last_name, phone?, address?, city?, postal_code?, country?}`.
`accept_terms: true` resta la presa visione obbligatoria; la disponibilità live
fornisce `booker_fields` e `privacy_url`. Il vecchio array di nomi completi resta
compatibile solo quando la configurazione non richiede dati aggiuntivi.
I nomi storici non vengono suddivisi automaticamente per non inventare cognomi.

Il codice Android è aggiornato, compilato e sottoposto ai test unitari, senza
generare nuovi APK: il proprietario richiede conferma prima del prossimo APK.
Il deploy pubblico è ora autorizzato dopo backup e verifiche. Verifica completa
di questo aggiornamento: 1.668 test passati, 6.125 asserzioni, PHPStan e Pint verdi.

1. Admin: modifica il locale in Filament e attiva «Abilita biglietteria gratuita».
2. Proprietario: crea/modifica normalmente un evento nel pannello del locale.
   Nella tabella delle date apri «Gestione biglietti», oppure usa
   `/gestione-biglietti` dal profilo. Gli editor non possono esportare nominativi.
3. Apri le impostazioni della data, abilita le prenotazioni e scegli l'eventuale
   limite. Il massimo per account è separato dal limite totale. Se lasci vuoti
   apertura e chiusura, si prenota subito fino all'inizio dell'evento.
4. Il visitatore apre «Prenota il tuo posto» nella data, accede/si registra,
   inserisce i nominativi e conferma. I salvataggi in agenda non prenotano posti.
5. `/biglietti` sul sito e «Profilo → I miei biglietti» su Android mostrano QR,
   stato e annullamento. Il sito offre anche PDF; entrambi possono richiedere
   un riepilogo via email, massimo tre richieste/ora.
6. Il gestore cerca un nome o email, scarica il CSV oppure scansiona il QR.
   Una scansione prepara il codice: serve confermare «Registra ingresso».
   È sempre disponibile il controllo manuale dalla lista. Il controllo apre
   alle porte dichiarate (altrimenti tre ore prima) e chiude alla fine evento.
7. Annullare la data/evento da Filament revoca i ticket. Sono possibili revoche
   singole e di gruppo dal pannello partecipanti. I cambi di orario avvisano
   gli iscritti. Tutto passa dagli observer, anche per modifiche di serie.

La prenotazione è gratuita; non è una ricevuta di pagamento e non elimina
eventuali prezzi d'ingresso, tessere o requisiti indicati dall'organizzatore.

## API aggiuntive

Tutte le risposte di prenotazione sono `private, no-store`; disponibilità live
`no-store`, senza ETag. Errori nello stesso envelope delle API esistenti.

| Metodo | Percorso `/api/v1` | Accesso |
|---|---|---|
| GET | `/occurrences/{id}/booking` | Pubblico, evento visibile |
| POST | `/occurrences/{id}/bookings` | Sanctum |
| GET | `/me/bookings?page=1` | Sanctum, solo proprie prenotazioni |
| POST | `/me/bookings/{id}/cancel` | Sanctum, solo proprie prenotazioni |
| POST | `/me/bookings/{id}/email` | Sanctum, solo proprie prenotazioni |
| POST | `/ticketing/{id}/check-in` | Admin/super admin o proprietario del locale |

Prenotazione: `attendees` (array di nomi, 1–20), `request_key` (UUID stabile per
retry), `accept_terms: true`, `waitlist` (booleano esplicito). Non cambiare chiave
quando si ritenta una richiesta dopo un errore di rete. Lo stesso UUID con un
payload diverso è rifiutato. Annullamento: `ticket_id` facoltativo, assente per
annullare tutto il gruppo. Check-in: `code` opaco di 64 caratteri.

Il vecchio vincolo generale «API di sola lettura» è stato rimosso su richiesta.
La garanzia ora verificata è l'autenticazione delle scritture, salvo i flussi
pubblici dichiarati (accesso, proposte e segnalazioni), più le policy di dominio.
Non sono stati aggiunti endpoint CRUD pubblico degli eventi.

## Operatività e rilascio

- Eseguire la nuova migrazione prima di servire la nuova versione.
- `npm ci && npm run build` genera anche lo scanner ZXing, caricato soltanto
  quando il gestore apre la fotocamera. Non usa CDN esterni per la libreria.
- Code Laravel attive e trasporto email configurato sono necessari per gli
  avvisi. Emissione, annullamento e check-in non dipendono dalla consegna email.
- Lo scheduler esegue `ticketing:promote` ogni minuto per gestire riaperture;
  gli annullamenti promuovono immediatamente, dentro la transazione.
- Usare HTTPS per fotocamera, autenticazione e API; `localhost` è ammesso nei
  test browser. Validazione ingressi online obbligatoria.
- I QR non contengono dati personali. Le esportazioni non includono i QR e
  neutralizzano formule CSV. I download non sono pubblici.
- Cancellare l'account elimina prenotazioni e nominativi, libera posti e
  impedisce invii in coda a quell'account. L'export personale include i ticket.
- Non cambiare lo stato dei modelli con update SQL massivi che saltino gli
  observer. Il modulo e i pannelli usano i servizi e gli eventi Eloquent.

## Demo locale

`php artisan db:seed --class=TicketingDemoSeeder` è idempotente e opera soltanto
in ambiente `local`/`testing`. Usa il Circolo Arci La Fornace già presente nel
catalogo padovano, oppure lo «Spazio delle Erbe» dove il catalogo non è caricato.
I tre esempi verosimili sono «Jazz in acustico: chitarra e contrabbasso» (12 posti),
«Padova raccontata: storie tra piazze e portici» (senza limite) e «Taccuini urbani:
atelier di illustrazione» (2 posti con lista d'attesa). La descrizione chiarisce
che sono contenuti dimostrativi, senza prefissi «Demo» nei titoli.

Account demo, password comune `DemoTicket-2026!`:

- `gestore-ticket@example.test`: Marta Berti, proprietaria del locale.
- `biglietti@example.test`: Giulia Rossi, due nominativi già prenotati per ciascun esempio.
- `attesa-ticket@example.test`: Luca Moretti, prenotazione in attesa sul laboratorio pieno.

Il proprietario ha autorizzato questi account anche sul pubblico per una
presentazione. Il comando esplicito `php artisan ticketing:demo --force` crea
soltanto il locale, gli eventi e gli account demo, senza faker né reset di dati
esistenti. Il normale deploy non esegue questo comando. Gli indirizzi `.test`
non ricevono email reali; per verificare la consegna usare un proprio account
con indirizzo raggiungibile. Il seeder non invia email.

## Verifica ripetibile

```sh
php artisan test --compact tests/Feature/Ticketing
php artisan test --compact tests/Concurrency/TicketInventoryTest.php
cd android
./gradlew :app:testDebugUnitTest :app:lintDebug
./gradlew :app:connectedDebugAndroidTest -PapiBaseUrl=http://10.0.2.2:8170/api/v1/
```

La prova di concorrenza usa quattro processi PHP e quattro connessioni reali
MariaDB per un singolo posto. Non eseguirla in parallelo alla suite PHP sullo
stesso database. Il test Android di integrazione scrive solo negli esempi del
server locale, annulla la propria prenotazione e chiude le sessioni.

### Esito del 5 settembre 2026

- Suite Laravel completa: 1.663 test superati, 6.080 asserzioni.
- Verifica mirata successiva: 33 test superati; contratto OpenAPI ulteriormente
  verificato dopo l'aggiunta degli schemi ticketing espliciti.
- PHPStan sul modulo e contratto API: zero errori; Pint superato.
- Android: unit test debug/release, lint e 7 test su emulatore Android 15 superati.
- Build frontend e release APK 1.4.0 completate; OpenAPI esportato senza avvisi.
- Controlli visivi: prenotazione, profilo QR, pannello gestore desktop/mobile,
  biglietto PDF e QR nativo Android. Flusso browser prenota/annulla verificato.
- Log Android: nessun crash applicativo nel flusso; un timeout diagnostico
  `FrameTracker` dell'animazione tastiera nell'emulatore, senza errore funzionale.

APK release sul Desktop: `inCitta-Android-1.4.0.apk`, collegato al dominio pubblico.
Il backend pubblico NON è stato distribuito da questo intervento. Il ticketing
dell'APK release richiede prima migrazione e codice aggiornato sul server.
La variante `inCitta-Android-1.4.0-demo-locale.apk` usa `10.0.2.2:8170` ed è
destinata esclusivamente all'emulatore con il server locale acceso.
La firma è quella di sviluppo già usata dal progetto, non una chiave Play Store.

## Gestione da mobile, 11 settembre 2026

La gestione mostra prima gli eventi prossimi e in corso, in ordine cronologico;
il selettore Passati usa l’ordine inverso. La distinzione usa la fine effettiva
della data. Ricerca evento/locale e ricerca partecipanti si aggiornano dopo
300 ms, con invio del modulo disponibile anche senza JavaScript. Filtri e
ricerca si conservano nella paginazione e rispettano i permessi del gestore.

I quattro contatori sono disposti 2×2 da mobile e su quattro colonne da desktop.
Il sito abilita `camera=(self)` solo nella pagina autorizzata di gestione della
data, mantenendo il divieto nelle altre pagine. Lo scanner richiede HTTPS,
legge il QR con ZXing e interrompe il video dopo la lettura o quando si lascia
la pagina. La lettura prepara il codice; l’ingresso richiede conferma esplicita.
Un permesso negato mostra come riattivarlo e resta disponibile la ricerca del
partecipante.

Salvataggio impostazioni, ingresso, annullamento, reinvio e relativi errori
ritornano esplicitamente alla prenotazione o alla data. Le richieste di stato
in background non possono diventare la pagina di ritorno.

I test browser usano una sorgente video sintetica contenente un QR reale:
verificano decodifica, stop del video, conferma ingresso, permesso negato,
ricerca e disposizione mobile/desktop. Non equivalgono a una prova su camera
fisica iPhone o Android.

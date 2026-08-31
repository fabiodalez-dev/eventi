# Decisioni tecniche (ADR leggeri)

Ogni decisione: cosa, perché, quando ricontrollare.

---

## 2026-08-23 — D1. PHP 8.4 invece di 8.3

**Decisione:** l'applicazione richiede PHP ^8.4.
**Perché:** `spatie/laravel-activitylog` 5.1, `spatie/laravel-sitemap` 8.2 e `pestphp/pest` 5
dichiarano `php: ^8.4`. Il server (`ea-php84` = 8.4.24) e la macchina di sviluppo (8.4.21)
lo hanno entrambi.
**Ricontrollo:** non necessario.

## 2026-08-23 — D2. Filament 5 + Livewire 4 confermati (task bloccante §4 chiuso)

**Decisione:** si adotta Filament 5.7.6 con Livewire 4.4.1. Nessun ripiego su Filament 4.
**Perché:** verifica su Packagist del 23/08/2026: entrambi installabili con Laravel 13.26.1,
e Filament è alla 5.7 (ecosistema assestato, non una .0 appena uscita).
**Ricontrollo:** non necessario.

## 2026-08-23 — D3. MariaDB invece di PostgreSQL + PostGIS

**Decisione:** MariaDB su entrambi gli ambienti. Colonna `POINT` con `SPATIAL INDEX`,
query geospaziali dietro `GeoQueryInterface`.
**Perché:** fabiodalez.it è shared hosting cPanel senza root: non è installabile alcun
demone PostgreSQL. Il client `pdo_pgsql` è presente ma non serve senza server.
**Conseguenze:** `->near()` usa `MBRContains` (per sfruttare l'indice) più
`ST_Distance_Sphere` (per raffinare da quadrato a cerchio).
**Costo del ritorno a PostGIS:** una implementazione di `GeoQueryInterface` e una migration.
**Ricontrollo:** al passaggio a VPS.

## 2026-08-23 — D4. Sviluppo su MariaDB, non su MySQL

**Decisione:** l'ambiente locale usa **MariaDB 12.3 sulla porta 3307**, non MySQL 9.6 sulla 3306.
**Perché:** il server di produzione è MariaDB 10.11. MySQL e MariaDB divergono sulle
funzioni spaziali — in particolare MySQL 8+ con SRID 4326 **inverte l'ordine degli assi**
(latitudine prima), mentre MariaDB no. Sviluppare sull'uno e pubblicare sull'altro
significa scoprire la differenza in produzione.
**Regola derivata:** le coordinate si scrivono **sempre** come `POINT(lng, lat)` con SRID 0.
È il solo formato che si comporta in modo identico su MySQL e MariaDB.
**Verificato:** `ST_Distance_Sphere`, `MBRContains` e `FOR UPDATE SKIP LOCKED` funzionano
su MariaDB 12.3 (locale) e sono supportati da MariaDB 10.6+ (produzione).

## 2026-08-23 — D5. Niente Redis: cache su file, code su database

**Decisione:** `CACHE_STORE=file`, `QUEUE_CONNECTION=database`, `SCOUT_DRIVER=database`.
Nessun Horizon (richiede Redis): worker `queue:work` invocato da cron.
**Perché:** nessun demone Redis né Meilisearch sulla shared hosting.
**Conseguenza sul motore notifiche:** senza lock distribuito, il worker preleva le righe
`pending` con `SELECT ... FOR UPDATE SKIP LOCKED` in transazione, e il vincolo
`dedupe_key UNIQUE` a livello di database resta l'unica garanzia contro il doppio invio —
che è già quanto prescrive §7.10 del piano.
**Costo del ritorno:** tre righe di `.env`.

## 2026-08-23 — D6. Laravel Pulse escluso

**Decisione:** non installato.
**Perché:** `laravel/pulse` (fino alla 1.8) richiede `guzzlehttp/promises ^1|^2`, mentre
Laravel 13 porta la 3.0.1. Pulse è marcato opzionale nello stack tecnologico.
**Ricontrollo:** quando Pulse dichiarerà il supporto a promises 3.

## 2026-08-23 — D7. Login social (Google/Apple) rimandato

**Decisione:** `laravel/socialite` non installato. Registrazione via email + password e
magic link. Lo schema `users` non contiene nulla che impedisca di aggiungerlo dopo.
**Perché:** Socialite fino alla 5.30 richiede `guzzlehttp/guzzle ^6|^7`, incompatibile con
la 8.0.2 di Laravel 13. Inoltre le versioni recenti dipendono da `firebase/php-jwt ^6.4`,
attualmente bloccato da un advisory di sicurezza.
**Coerenza col piano:** §13.4 e §15.2 lo davano come "predisposto, non necessariamente
attivo al primo rilascio".
**Ricontrollo:** alla prossima major di Socialite.

## 2026-08-23 — D8. Web Push escluso: notifiche via email

**Decisione:** canali attivi = **email** e **archivio in-app** (tabella `notifications`).
Nessun Web Push al lancio.
**Perché:** `minishlink/web-push` 11 dipende da `web-token/jwt-library`, che richiede
`brick/math ^0.12...^0.17`, mentre Laravel 13 porta la 0.18. La versione 10 di web-push
richiede guzzle ^7. Nessuna combinazione è installabile su Laravel 13.
**Coerenza col piano:** §20.6 lasciava esplicitamente aperta la scelta fra Web Push ed
email al lancio; §15.6 impone comunque l'email come fallback. La decisione che il piano
rimandava viene quindi presa, con motivazione tecnica e non di prodotto.
**Conseguenza:** il motore `scheduled_notifications` resta implementato per intero —
dedupe_key, riprogrammazione, quiet hours, frequency cap. Cambia solo il canale scelto in
fondo alla catena. Aggiungere push (Web Push o FCM in fase mobile) sarà un nuovo canale di
notifica, non una riscrittura del motore.
**Ricontrollo:** quando `web-token` supporterà brick/math 0.18.

## 2026-08-23 — D9. Monetizzazione: gratuito per no-profit, a pagamento per commerciali

**Decisione:** nessun pagamento implementato in v1, ma lo schema nasce capace:
`venues.is_nonprofit`, `venues.plan (free|premium)`, tabella `promotions` vuota.
**Perché:** §20.4 impone di decidere ora perché tocca schema e Termini.
**Ricontrollo:** prima di scrivere i Termini definitivi.

## 2026-08-23 — D10. Nome del prodotto come segnaposto

**Decisione:** "inCittà" come segnaposto, esposto solo tramite `config('app.name')` e una
stringa in `lang/it`. Nessuna occorrenza hardcoded in Blade, manifest o email.
**Perché:** il committente sceglierà il nome definitivo; il cambio deve costare una riga.

## 2026-08-23 — D11. Scope geografico: provincia di Padova

**Decisione:** la città pilota copre la **provincia** di Padova, non il solo comune.
**Perché:** decisione esplicita del committente (§20.2).
**Conseguenza:** i locali del seed includono comuni della provincia; `cities.radius_km`
è dimensionato di conseguenza e il centro mappa resta Padova.

## 2026-08-23 — D12. Lo slug dell'evento è unico per città, non globalmente

**Decisione:** `events.slug` perde l'indice unico globale e prende `UNIQUE (city_id, slug)`.
La generazione dello slug (`spatie/laravel-sluggable`) usa `extraScope()` sulla stessa coppia.
**Perché:** §11.1 predispone `/{city}/eventi` e D11 apre alla provincia; con l'unicità globale
la seconda città che pubblica un "Concerto di Natale" otterrebbe `concerto-di-natale-1`, uno
slug peggiore e dipendente dall'ordine di inserimento. Il locale, invece, resta unico in
tutto il sistema: `/locali/{slug}` è una rotta senza città.
**Verificato:** stesso titolo, stessa città → `-1`; stesso titolo, città diversa → slug pulito.
**Ricontrollo:** non necessario.

## 2026-08-23 — D13. Migration dei pacchetti pubblicate insieme ai model

**Decisione:** pubblicate `media`, le tabelle di `spatie/laravel-permission` e `activity_log`
(`2026_08_23_1100xx`), lasciate **verbatim** come le genera il pacchetto — quindi con
`timestamps()` e non `datetimes()`.
**Perché:** i trait dichiarati sui model le usano davvero. `LogsActivity` scrive su
`activity_log` a ogni cambio di stato: senza la tabella, salvare un locale fallisce.
Restano fuori dalla regola "tutte le date sono `DATETIME`" (deviazione 11 di `SCHEMA.md`)
per lo stesso motivo di `failed_jobs`: sono migration di terze parti, non del dominio, e
riscriverle complica ogni aggiornamento del pacchetto.
**Nota di versione:** `spatie/laravel-activitylog` 5 ha spostato il trait in
`Spatie\Activitylog\Models\Concerns\LogsActivity`, `LogOptions` in `…\Support\LogOptions`
e ha rinominato `dontSubmitEmptyLogs()` in `dontLogEmptyChanges()`. La documentazione in
giro per la rete è quasi tutta ferma alla 4.
**Ricontrollo:** non necessario.

## 2026-08-23 — D14. `phpstan.neon` con Larastan

**Decisione:** aggiunto `phpstan.neon` in radice: include `vendor/larastan/larastan/extension.neon`,
livello 6, percorsi `app` e `database`.
**Perché:** la pipeline invocava `./vendor/bin/phpstan analyse` senza argomenti, e senza file
di configurazione il comando non ha né percorsi né livello: falliva. Larastan era già fra le
dipendenze di sviluppo ma non veniva caricato, quindi PHPStan non conosceva le proprietà
magiche dei model Eloquent e segnalava come errore ogni `$event->city_id`.
**Verificato:** `./vendor/bin/phpstan analyse --memory-limit=1G` → 0 errori.

## 2026-08-23 — D15. `parseModelCastsMethod: true` in `phpstan.neon`

**Decisione:** aggiunto il parametro Larastan `parseModelCastsMethod: true`.
**Perché:** i model dichiarano i cast nel metodo `casts()`, non nella proprietà `$casts`.
Senza il parametro Larastan legge solo il tipo **dichiarato** dal docblock del metodo
(`array<string, string>`), non lo riconosce come array costante e quindi ignora i cast:
tipa `starts_at` come `string` (dalla migration) e segnala come "sempre falso" ogni
`instanceof DateTimeInterface`, che a runtime è vero. Con il parametro attivo Larastan
legge il corpo del metodo dall'AST e tipa `starts_at` come `Carbon`.
**Conseguenza:** i valori scritti su una colonna con cast `datetime` vanno passati come
`Carbon` (mutabile), non `CarbonImmutable`: il tipo della proprietà è quello di lettura.
**Verificato:** `./vendor/bin/phpstan analyse` → 0 errori con e senza il codice del motore.

## 2026-08-23 — D16. Fasce orarie: enum `TimeOfDay`, sera alle 17:00

**Decisione:** `time_of_day` diventa un enum PHP con tre casi e confini fissi —
giorno `[06:00, 17:00)`, sera `[17:00, 22:00)`, notte `[22:00, 06:00)`.
**Perché:** §13.2 fissa i valori `day|evening|night` ma non i confini, e §11.3 nomina le
fasce senza orari. La sera comincia alle 17:00 perché è la stessa soglia di "stasera"
in §8.4: due definizioni diverse darebbero due risposte diverse sulla stessa pagina.
**Ricontrollo:** quando la redazione vorrà una fascia "pomeriggio" distinta dal mattino.

## 2026-08-23 — D17. Ora locale in SQL: `CASE` dai cambi d'ora, non `CONVERT_TZ`

**Decisione:** `EventOccurrenceQuery` converte `starts_at` (UTC) in ora locale con
un'espressione `CASE` costruita dai cambi d'ora reali del fuso, letti dal database dei
fusi di PHP.
**Perché:** `CONVERT_TZ` con nome di fuso richiede le tabelle dei fusi caricate nel
server MariaDB, che sulla shared hosting non sono garantite; uno scostamento fisso
(`+02:00`) sbaglierebbe di un'ora per metà anno. Serve a "stasera" e a `timeOfDay()`,
gli unici punti che confrontano l'**ora del giorno**: le finestre di giornata leggono
`business_date` e quelle istantanee confrontano istanti UTC.
**Verificato:** `timeOfDay('night')` seleziona sia le 22:00 sia l'1:00 con l'ora legale
e con l'ora solare.
**Ricontrollo:** se un giorno il server garantisse le tabelle dei fusi.

## 2026-08-23 — D18. Perimetro e ordinamenti di `EventOccurrenceQuery`

**Decisione:** tre scelte che §8 non fissa.
1. La query di base è già ristretta agli **eventi pubblicati e non cestinati della
   città**: una bozza non deve poter comparire in nessuna finestra pubblica.
2. `between($from, $to)` e `onDate($date)` lavorano su `business_date`, come `today()`,
   `tomorrow()` e `weekend()`: gli estremi sono giornate evento, non istanti.
3. La **distanza ordina solo le sezioni dal vivo**, dove §8.5 la prescrive come terzo
   criterio. Nelle liste cronologiche `distance_m` resta un dato esposto ma non ordina:
   chi chiede "oggi" si aspetta l'ordine del tempo.
**Conseguenza:** l'ordinamento `sort=distance` di §13.2 non ha ancora un metodo dedicato.
**Ricontrollo:** quando si scriverà `GET /v1/events`.

## 2026-08-23 — D19. Formato di `venues.opening_hours`

**Decisione:** `{"mon": [{"open": "10:00", "close": "18:00"}], ...}`, chiavi in inglese
abbreviato, più fasce per giorno ammesse; una fascia che chiude prima di aprire
attraversa la mezzanotte.
**Perché:** §8.3 fa dipendere `effective_ends_at` degli eventi di intera giornata dalla
"fine dell'orario di apertura del giorno", ma nessun documento definiva la struttura del
campo. Senza orari dichiarati vale la fine della giornata locale.
**Ricontrollo:** al form del pannello locale, che è ciò che scriverà davvero il campo.

## 2026-08-23 — D20. Le occorrenze generate ereditano dal modello della serie

**Decisione:** `GenerateOccurrencesAction` copia sulle date generate la durata
(`ends_at`), l'orario di apertura porte e il flag `is_all_day` della **prima occorrenza**
della ricorrenza.
**Perché:** senza, ogni data generata perderebbe l'orario di fine inserito dal gestore e
`effective_ends_at` ricadrebbe sulla durata di categoria — un concerto di due ore e mezza
diventerebbe di cinque.
**Conseguenza:** l'azione **inserisce soltanto**, non aggiorna e non cancella. È così che
resta idempotente e che le occorrenze con `is_exception = true` restano intatte; una data
spostata o annullata si toglie dalla serie con `exdates`.
**Verificato:** 53 venerdì generati, seconda esecuzione 0 creazioni, `exdates` rispettate
sia come data sia come istante, occorrenza-eccezione invariata dopo la rigenerazione.

## 2026-08-23 — D21. `is_exception` lo imposta l'observer, non chi scrive

**Decisione:** `EventOccurrenceObserver::updating()` marca `is_exception = true` quando
un'occorrenza **appartenente a una ricorrenza** (`recurrence_id` non nullo) cambia uno
degli attributi che eredita dalla serie: `starts_at`, `ends_at`, `doors_at`, `is_all_day`,
`status`. Il flag non torna mai indietro da solo.
**Perché:** il criterio di accettazione F1 chiede che "cancellare una occorrenza non tocchi
le altre e imposti `is_exception`", ma nessuna parte del codice scriveva quel campo: restava
al `false` della factory anche su una data annullata a mano. Metterlo nell'observer è l'unico
punto che lo garantisce a prescindere da chi salva — pannello admin, pannello locale, import
o comando.
**Conseguenza:** una data della serie annullata o spostata è riconoscibile come tale da
`EventOccurrence::exceptions()`, e resta coerente con D20 (l'azione di generazione non la
tocca perché non aggiorna nulla).
**Verificato:** annullando la terza di cinque date, quella risulta eccezione e le altre
quattro restano `scheduled` con `is_exception = false` e `starts_at` invariato.

## 2026-08-23 — D22. Il `DTSTART` implicito si prende dalla prima occorrenza dell'evento

**Decisione:** `GenerateOccurrencesAction::template()` cerca l'occorrenza più antica
**dell'evento** escludendo quelle di altre ricorrenze, invece della più antica *della
ricorrenza*.
**Perché:** era un bug di idempotenza vero. Alla prima esecuzione la ricorrenza non ha
ancora occorrenze proprie, quindi il `DTSTART` veniva dedotto dall'occorrenza inserita a
mano dal gestore; alla seconda esecuzione la ricorrenza aveva occorrenze proprie e il
`DTSTART` diventava la **seconda** data. Con `COUNT=10` la finestra scivolava avanti di una
settimana a ogni esecuzione: ogni rilancio del comando mensile aggiungeva una data in coda,
per sempre.
**Verificato:** `FREQ=WEEKLY;BYDAY=TH;COUNT=10` → 10 occorrenze; seconda e terza esecuzione
0 create, stessi `starts_at`.

## 2026-08-23 — D23. Fondamenta visive: token CSS, font self-hosted, contesto della card

**Decisione:** quattro scelte prese scrivendo il livello di presentazione (§11).

1. **Palette in `:root`, non in `@theme`.** I colori sono variabili CSS con un
   secondo blocco sotto `@media (prefers-color-scheme: dark)`; `@theme inline`
   mappa i token di Tailwind su quelle variabili, così `bg-surface` emette
   `var(--surface)` invece di copiarne il valore. È ciò che permette al tema
   scuro di cambiare valore senza generare una seconda serie di utility.
   Definire i colori dentro `@theme` avrebbe congelato il valore chiaro nel CSS.
2. **Font self-hosted con `@fontsource-variable`** (Bricolage Grotesque per i
   titoli, Inter per il testo), importati dal CSS e serviti dal nostro dominio.
   Rimosso il plugin `bunny()` da `vite.config.js`: era un CDN esterno, quindi
   un trasferimento di dati verso terzi a ogni visita. I file sono divisi per
   `unicode-range`, il browser scarica il solo sottoinsieme che gli serve.
3. **La card non deduce la finestra temporale: la riceve.** `<x-event-card>`
   prende un attributo `context` (`ongoing`, `starting_soon`, `tonight`, …) che
   dichiara da quale metodo di `EventOccurrenceQuery` arriva l'occorrenza. La
   vista non ricalcola "in corso" né "stasera" (§3 delle convenzioni): sarebbe
   la seconda definizione, e divergerebbe.
4. **`App\Support\DateFormatter` formatta soltanto.** Non conosce finestre. Fa
   un solo confronto di calendario — oggi, domani, ieri — per scegliere la
   parola. Tratta le **giornate** (`business_date`, cast `date`, mezzanotte UTC)
   senza conversione di fuso e gli **istanti** (`starts_at`) convertendoli
   nell'ora della città: convertire una giornata la farebbe scivolare al giorno
   prima alle 22:00, ed è un errore che si vede solo in produzione.

**Conseguenze:** `App\Support\CurrentCity` è registrato come `scoped` e
restituisce la prima città attiva; `DateFormatter` è risolto dal container con
il fuso di quella città. Quando `/{city}/eventi` diventerà una rotta, sarà un
middleware a chiamare `CurrentCity::set()` e nient'altro cambierà.

**Verificato:** `npm run build` senza errori, `/` risponde 200 sul database
seedato, nessuna sezione disegnata quando la finestra è vuota (§8.6, test
dedicato), nessun trabocco orizzontale a 390px, tema scuro con
`prefers-color-scheme` (`body` a `oklch(0.17 0.02 288)`).


## 2026-08-23 — D24. Pannello di redazione: sette scelte prese scrivendo `/admin`

**Decisione.** Sette punti che §9 non fissa.

1. **Chi entra lo dice il model, cosa può fare lo dicono le Policy.**
   `User::canAccessPanel()` apre `/admin` ai soli ruoli `admin`, `super_admin`
   e `moderator`, e `/gestione` a chi ha una riga in `venue_user`. Dentro al
   pannello nessun permesso è ricodificato: Filament interroga da sé le Policy
   di `app/Policies`, e ogni azione personalizzata dichiara `->authorize()`
   sulla stessa abilità (`moderate`, `publish`, `manageCollaborators`,
   `impersonate`).
2. **`EventPolicy::create()` e `EventOccurrencePolicy::create()` accettano un
   secondo argomento nullo.** Filament chiede il permesso di creare prima di
   sapere per quale locale, e Laravel invoca la Policy con la sola classe: con
   la firma obbligatoria il pannello moriva di `ArgumentCountError`. Senza
   locale la domanda diventa "può creare in assoluto?" e la risposta resta
   allo staff globale — chi gestisce un locale crea sempre *per* quel locale.
   Il comportamento delle chiamate esistenti non cambia.
3. **`venues.location` diventa una colonna calcolata.** `VenueObserver` la
   deriva da `lat`/`lng` a ogni salvataggio. Prima la scrivevano solo factory e
   seeder: un locale creato dal pannello sarebbe stato impossibile
   (`POINT NOT NULL`), e uno corretto a mano sarebbe rimasto geograficamente
   fermo dov'era, con la mappa e il "vicino a me" a rispondere sul vecchio
   punto senza alcun segnale.
4. **La mappa del locale è coppia di coordinate più anteprima, non un marcatore
   trascinabile.** §9.2 lo suggeriva; una mappa vera richiede una libreria
   servita da un CDN e un server di tessere esterno, cioè un trasferimento di
   dati verso terzi a ogni apertura della scheda — l'opposto di ciò che D23 ha
   deciso per i font. Il modulo offre latitudine e longitudine con limiti di
   validità, un'anteprima testuale, un collegamento a OpenStreetMap che parte
   solo se lo si clicca e un pulsante che centra sul capoluogo. §9.2 ammette
   esplicitamente questa alternativa. **Da ricontrollare** quando si sceglierà
   il fornitore di tessere per la mappa pubblica (§11.6): allora la stessa
   libreria varrà anche qui.
5. **Le date stanno nel ripetitore alla creazione e nella sezione dedicata alla
   modifica.** Il ripetitore serve al gesto per cui esiste — inserire l'evento
   con le sue tre serate in un salvataggio solo — ma non può porre la domanda
   di §9.2, *questa data o tutta la serie?*. Tenerli entrambi attivi
   significherebbe due moduli che scrivono le stesse righe nella stessa
   pagina: chi salva quello principale sovrascriverebbe con lo stato caricato
   in apertura le decisioni appena prese nell'altro.
6. **`OccurrenceScope` è un enum, non una stringa.** Serve al pannello di
   redazione, servirà a quello dei locali e all'API: tre stringhe magiche
   divergono sempre. `UpdateOccurrencesAction` lo applica salvando riga per
   riga e non con un `UPDATE` di massa, perché `business_date` ed
   `effective_ends_at` le ricalcola l'observer e una query di massa lo
   salterebbe, lasciando mentire due colonne persistite.
7. **Due formati JSON che il piano non definiva.** `cities.settings` è una
   mappa di interruttori `{"nome": true}`; `venues.opening_hours` resta quello
   di D19. La conversione fra formato salvato e righe del modulo vive in
   `StructuredFields` ed è chiamata dai `mutateFormData…` delle pagine, **mai**
   da `afterStateHydrated()` di un ripetitore: lì lo stato è già la mappa
   interna `{identificatore: riga}` del componente, e la prima versione la
   scambiava per i dati salvati producendo righe fantasma con
   l'identificatore al posto del giorno.

**Conseguenze.** Nuovi permessi `users.manage` e `users.impersonate` (assegnati
ad amministratore e amministratore di sistema, mai al moderatore) e nuova
`UserPolicy` che vieta di cancellare se stessi e di agire su chi sta più in
alto. L'impersonificazione è una rotta propria e non un'azione Livewire, perché
cambiare l'utente autenticato a metà di una richiesta Livewire lascerebbe la
pagina aperta con i dati di prima; chi non è autenticato viene mandato
all'accesso del pannello e non a una rotta `login` del sito pubblico, che
nascerà con §15.2.

**Verificato.** 55 test dedicati verdi, fra cui il criterio di accettazione
(città, locale, evento con tre date, pubblicazione) percorso due volte: una con
i componenti Livewire reali e una **via HTTP su `php artisan serve`**, con
sessione autenticata, token CSRF e chiamate al canale Livewire. Nel database
risultante l'evento è `published`, ha tre occorrenze e `business_date` ed
`effective_ends_at` scritte dall'observer. Tutte le pagine del pannello
rispondono 200 e nessuna stampa una chiave di traduzione grezza (verifica
automatica su elenchi, moduli di creazione e schede di modifica). `pint`
passato, `phpstan` livello 6 senza errori sui file di questa fase.

## 2026-08-23 — D25. Sito pubblico: cosa entra nel motore temporale e cosa resta fuori

**Decisione:** scrivendo §11 sono state prese sei scelte che il piano non fissa.

1. **Nove metodi nuovi in `EventOccurrenceQuery`, non nei controller.**
   `upcoming()`, `past()`, `nextDays()`, `featured()`, `forEvent()`,
   `excludingEvent()`, `inMunicipality()`, `outdoor()`, `accessible()`,
   `atVenueSlug()`, più gli ordinamenti `orderByDistance()` (che D18 lasciava
   aperto) e `orderByNewestFirst()` per l'archivio di §11.9. Il criterio è
   sempre lo stesso: se una pagina ha bisogno di sapere che cosa è "futuro" o
   "passato", quella definizione appartiene al motore (§8.1). Un controller che
   scrivesse `where('business_date', '>=', today())` sarebbe la seconda verità.
2. **Tre conteggi aggregati** — `countsByBusinessDate()`, `countsByCategory()`,
   `countsByVenue()` — perché lo scroller dei prossimi giorni, la griglia per
   categoria e l'elenco dei locali devono sapere **quanto** c'è senza caricare
   i modelli, e perché §8.6 impone di non disegnare la casella di una categoria
   che porterebbe a una lista vuota.
3. **La query string è l'unico stato della lista.** `App\DTOs\EventFilters` è
   immutabile e sa rigenerare il proprio indirizzo; ogni pillola di filtro è un
   link a un altro `EventFilters`, quindi funziona senza JavaScript ed è
   copiabile. `EventFinder` è il solo punto che traduce quei filtri in chiamate
   al motore.
4. **Un solo canonico per insieme di filtri.** Le rotte parlanti
   (`/eventi/oggi`, `/eventi/gratis`, `/eventi/categoria/{slug}`) vincono solo
   quando esprimono da sole tutta la richiesta; appena si aggiunge un secondo
   filtro il canonico torna alla forma `/eventi?…`. Le combinazioni con più di
   due filtri, la ricerca libera e la posizione escono dall'indice
   (`noindex, follow`): sono infinite, e nessuna merita una riga in un indice.
5. **"Adatto alle famiglie" è tassonomia, non uno schema nuovo.** Il filtro
   seleziona le categorie elencate in `config/eventi.php`. Una seconda chiamata
   a `inCategories()` aggiunge una condizione in AND, quindi "musica" più
   "famiglie" dà l'intersezione — che è ciò che si aspetta chi accende due
   filtri.
6. **`/{city}/eventi` esiste come secondo gruppo di rotte**, non come rotta
   diversa: `routes/public.php` è incluso due volte da `routes/web.php`, la
   seconda con prefisso `{city}`, nomi preceduti da `city.` e il middleware
   `ResolveCity`, che risolve la città e poi **dimentica** il segmento, così
   nessun controller ha un parametro in più. Una città sconosciuta o non ancora
   accesa è un 404: `/verona/eventi` non deve mostrare gli eventi di Padova.

**Conseguenza sulla homepage.** "In corso adesso" e "Inizia tra poco" sono un
componente Livewire `#[Lazy]` (`App\Livewire\LiveNow`) caricato dopo il primo
disegno. È la premessa di §12.3: lo scheletro della pagina potrà andare in
cache cinque minuti solo se le due finestre che cambiano ogni minuto stanno
fuori. Il segnaposto è una riga sola, non uno scheletro di card che potrebbero
non esserci, e porta un `<noscript>` verso `/eventi/oggi`. La conseguenza
accettata è che senza JavaScript quelle due sezioni non compaiono: tutto il
resto del sito, liste e paginazione comprese, funziona senza.

**Verificato.** 296 test verdi (47 nuovi), `pint` passato, `phpstan` livello 6
a zero errori, `npm run build` verde. Sul database seedato, con `php artisan
serve` e `curl`: il criterio di accettazione — dalla homepage a "Musica +
Stasera + Gratis" in tre link — percorso davvero, con l'indirizzo finale
`/eventi?date=tonight&category=musica-dal-vivo&price=free` che rende la stessa
pagina; `/eventi/oggi?category=X` e `/eventi?date=today&category=X` restituiscono
lo stesso insieme di eventi. Con Playwright a 390 px: nessun trabocco
orizzontale (la pagina non scorre lateralmente), il frammento dal vivo si
risolve e inserisce le due sezioni in cima nell'ordine di §11.2, lo scorrimento
infinito porta le card da 24 a 48 a 72 aggiornando l'indirizzo, e la stessa
lista resta sfogliabile con `?page=` senza JavaScript.

## 2026-08-23 — D26. Mappa, calendario, ricerca e feed: nove scelte

**Decisione:** scrivendo §11.6, §11.7, §11.8 e §11.10 sono state prese nove
scelte che il piano non fissa.

1. **Un marcatore per locale, non per data.** Due concerti nello stesso circolo
   hanno le stesse identiche coordinate: disegnati come due punti restano
   sovrapposti a qualunque ingrandimento e il raggruppamento non li scioglie
   mai. `EventOccurrenceQuery::venueMarkers()` restituisce quindi un punto per
   locale con il numero di date che vi cadono dentro, in **una sola** query con
   due funzioni di finestra (`COUNT() OVER`, `ROW_NUMBER() OVER`): il conteggio
   e la categoria della data più vicina, che è quella da cui viene il colore.
2. **Il carico della mappa è posizionale, la card arriva dal server.** I
   marcatori viaggiano come liste `[locale, lng, lat, categoria, quante]` e le
   categorie stanno in una tabella citata per indice: ripetere i nomi dei campi
   cinquecento volte costa più dei dati. Quando si tocca un marcatore il foglio
   inferiore chiede a `/mappa/locale/{id}` la `<x-event-card>` **già disegnata**:
   una seconda card scritta in JavaScript divergerebbe dalla prima al primo
   cambio di badge.
3. **MapLibre e Alpine hanno un pacchetto ciascuno.** `resources/js/map.js` e
   `resources/js/calendar.js` sono due ingressi Vite in più, caricati dalle sole
   pagine che li usano: MapLibre pesa quasi un megabyte e non deve gravare sulla
   pagina iniziale. Alpine arriva dal proprio pacchetto e non da Livewire perché
   Livewire porta con sé la propria copia, e due copie sulla stessa pagina si
   contendono lo stesso oggetto globale; il controllo `window.Alpine === undefined`
   lo evita comunque.
4. **Il worker di MapLibre va dichiarato a Vite.** MapLibre cerca il proprio web
   worker accanto al proprio file, deducendone l'indirizzo da `import.meta.url`:
   dopo il raggruppamento quell'indirizzo è quello del nostro pacchetto e il
   worker dà 404. Il sintomo è una mappa **grigia senza alcun errore** —
   controlli, attribuzione e tela ci sono tutti. Si importa quindi
   `maplibre-gl/dist/maplibre-gl-worker.mjs?worker&url` e lo si passa a
   `setWorkerUrl()`. Per la stessa ragione `map.on('error')` ora scrive in
   console: un guasto silenzioso è il più difficile da riconoscere.
5. **`cities.bounds` ha finalmente un formato:**
   `{"min_lng":…, "min_lat":…, "max_lng":…, "max_lat":…}`, gli stessi nomi del
   parametro `bbox=minLng,minLat,maxLng,maxLat` di §13.3. Se manca, la mappa
   parte da centro e zoom della città.
6. **Il calendario ha una sola query e una versione di cache.** §11.8 impone una
   query aggregata per mese: `dailyDigest()` restituisce conteggio **e** primi
   titoli in una lettura sola, leggendo righe grezze invece di idratare
   trecento modelli. La cache di §12.3 (mezz'ora) si invalida con un numero di
   versione per città che gli observer di `Event` ed `EventOccurrence`
   incrementano a ogni salvataggio: inseguire quali mesi tocchi un evento con
   una ricorrenza annuale significherebbe inseguirli tutti.
7. **Scout dice quali eventi, il motore temporale quali date.** La ricerca prende
   da Scout gli identificativi degli eventi che somigliano al testo e li passa a
   `EventOccurrenceQuery::forEvents()->upcoming()`: chiedere le occorrenze
   direttamente al motore di ricerca significherebbe riscrivere lì la definizione
   di "futuro" (§8.1). I candidati chiesti sono più dei risultati mostrati,
   perché fra le corrispondenze testuali ce ne sono di concluse.
   Le colonne brevi vanno per `LIKE` — così "concer" trova "concerto" — e le
   **descrizioni** per `MATCH … AGAINST`, con due indici full-text aggiunti da
   una migration: su un `LONGTEXT` un `LIKE '%…%'` leggerebbe l'intera tabella a
   ogni ricerca, e senza indice MariaDB rifiuta la query invece di restituire
   zero righe. **Nota per i test:** InnoDB aggiorna l'indice full-text alla
   commit, quindi dentro la transazione di un test una riga appena inserita non
   è trovabile; si verifica che il `MATCH` venga emesso e trovi il proprio
   indice, e la ricerca vera si prova sul database seedato.
8. **Il widget è un `iframe`, e senza sessione.** Uno `<script>` incorporato
   girerebbe nel dominio di chi lo ospita e vedrebbe la sua pagina; un `iframe`
   è murato nel proprio contesto. La rotta sta fuori dai gruppi del sito
   pubblico (lo slug del locale è unico ovunque, D12) e toglie di mezzo
   `EncryptCookies`, `AddQueuedCookiesToResponse`, `StartSession`,
   `ShareErrorsFromSession` e `PreventRequestForgery`: chi incorpora il riquadro
   non deve ritrovarsi cookie di terze parti sulla propria pagina. Dichiara
   `frame-ancestors *`, perché esiste per essere incorniciato.
   Il codice da copiare lo produce `App\Support\WidgetEmbed` ed è mostrato come
   campo di sola lettura nella scheda del locale in `/admin`; la stessa riga
   vale per il pannello del locale.
9. **"Vicino a me" ha un posto solo.** Il pulsante nudo dentro il pannello dei
   filtri è stato sostituito dal componente `<x-near-me>`, che dice **prima**
   perché la posizione viene chiesta, offre i quattro raggi di §11.7 e dichiara
   che non viene salvata. Il pulsante nasce nascosto e lo mostra il JavaScript
   solo dove la geolocalizzazione esiste; il raggio si legge al momento del clic,
   così chi lo cambia e poi concede la posizione ottiene il raggio nuovo. La
   posizione vive nella query string della ricerca in corso e da nessun'altra
   parte.

**Conseguenze.** Nuovi file di traduzione `lang/it/map.php`, `calendar.php`,
`search.php`, `feeds.php`; nuove configurazioni `config/map.php`,
`config/scout.php`, `config/feeds.php`; `EventOccurrenceQuery` guadagna
`withinBounds()`, `forEvents()`, `forOccurrence()`, `venueMarkers()` e
`dailyDigest()`. Le chiavi `filters.distance.permission_*` e
`filters.distance.near_me` spariscono: quelle frasi vivono ora in `map.near.*`.

**Verificato.** 52 test nuovi (mappa, calendario, ricerca, feed, widget e i
metodi nuovi del motore), suite intera verde, `pint` passato, `phpstan` livello
6 a zero errori, `npm run build` verde. Il file `.ics` letto da
`sabre/vobject`: zero problemi sul profilo generico, e quello della singola data
zero anche sul profilo CalDAV. L'RSS riletto da `simplexml`. Con Playwright a
390 px: nessun trabocco orizzontale su mappa e calendario, MapLibre disegna
tessere e marcatori colorati per categoria con il raggruppamento che somma le
date, lo spostamento della mappa fa comparire "Cerca in quest'area" e il clic
rilegge i marcatori del nuovo rettangolo (`?bbox=…`) aggiornando la legenda, il
tocco su un marcatore apre il foglio inferiore con sei card vere, e
l'interruttore dei titoli del calendario accende e spegne le anteprime.

## 2026-08-23 — D27. Pannello dei locali: otto scelte prese scrivendo `/gestione`

**Decisione.** Otto punti che §10 non fissa.

1. **Tre serrature per lo stesso ingresso, non una.** La tenancy di Filament
   restringe le query al locale dell'indirizzo, ma non è la difesa:
   `User::canAccessTenant()` risponde 404 prima ancora che la pagina si apra,
   e le Policy di `app/Policies` verificano il `venue_id` riga per riga. §18
   scenario F chiede che regga «anche manipolando URL o ID direttamente», e
   una difesa sola non lo garantisce — se domani un rilascio di Filament
   cambiasse il modo in cui applica gli ambiti, le altre due reggerebbero.
   Verificato per ogni pagina del pannello e per la scheda di un evento
   altrui aperta dal proprio locale.
2. **`getCreateAuthorizationResponse()` riscritto, non `canCreate()`.**
   `EventPolicy::create()` accetta il locale come secondo argomento e senza di
   esso risponde "solo staff globale" (D24, punto 2), mentre Filament chiede
   l'abilità con la sola classe: il pulsante "Nuovo evento" spariva a chiunque
   gestisse un locale. La prima correzione — riscrivere `canCreate()` — non
   bastava: `CreateAction` interroga la *risposta di autorizzazione*, non quel
   metodo, e il risultato era il difetto peggiore dei due, pulsante invisibile
   e pagina raggiungibile. **Il bug non è emerso dai test** (il componente
   Livewire della creazione rispondeva) ma dal browser: è la ragione per cui
   il criterio va percorso davvero.
3. **La bozza si salva a ogni passo, e ha bisogno di una categoria in
   prestito.** `events.category_id` è obbligatoria nello schema, ma la
   categoria si chiede al terzo passo: senza un ripiego il lavoro dei primi
   due passi — locandina e titolo, cioè il più faticoso da rifare — non
   sarebbe salvabile. La bozza nasce con la categoria abituale del locale (o
   la prima del catalogo) e il terzo passo, dove il campo è obbligatorio, la
   sovrascrive. L'identificatore della bozza è `#[Locked]`: senza, viaggerebbe
   nello stato del componente e chiunque potrebbe farsi riscrivere l'evento di
   un altro locale dal proprio wizard.
4. **Le abitudini del locale si imparano, non si compilano.**
   `venues.default_event_settings` non aveva forma definita: gliela dà
   `App\Support\VenueEventDefaults` (categoria, tipo di prezzo, importo, ora
   abituale, all'aperto). Nessun modulo la chiede — sarebbe una schermata in
   più per un dato deducibile: viene riscritta a ogni evento creato, così il
   secondo evento costa meno del primo. È la metà dei 90 secondi di §2.4 che
   non si vede.
5. **Le scorciatoie propongono un valore, non definiscono una finestra.**
   `ScheduleShortcut` produce l'istante da mettere nel campo data — *stasera*
   all'ora abituale, *venerdì* (oggi stesso se oggi è venerdì), *ogni giovedì*
   che accende anche la ripetizione. Non risponde alla domanda «che cosa c'è
   stasera», che resta di `EventOccurrenceQuery` e di nessun altro.
6. **`EventOccurrenceQuery` espone `now()` e `currentBusinessDate()`.** I
   pannelli di gestione lavorano anche sulle bozze, che il motore non mostra
   perché la sua base è ristretta al pubblicato; senza un modo di *chiedere*
   che giorno è, ogni elenco di gestione si sarebbe riscritto la propria idea
   di "oggi". Ora la definizione si prende in prestito e la query resta in
   `app/Queries` (`VenueDashboardQuery::applyNextOccurrence()`).
7. **Si invitano collaboratori, non referenti.** `InviteVenueMemberAction`
   crea l'account se manca, assegna il ruolo globale (senza mai declassare chi
   era già altro), scrive la riga in `venue_user` e manda il messaggio con il
   collegamento per scegliere la password. È lo stesso gesto con cui la
   redazione accredita il referente di un locale approvato, ed è la stessa
   azione: due strade separate, prima o poi, dimenticano un passaggio. Dal
   pannello del locale si aggiunge però solo chi *pubblica*, non chi *risponde*
   del locale — un referente in più è una decisione della redazione.
8. **`APP_FALLBACK_LOCALE` passa da `it` a `en`.** Con entrambi a `it`, ogni
   chiave che i pacchetti Filament non traducono ancora finiva **stampata
   grezza in pagina** (`filament-panels::layout.skip_to_content.label` era una
   di queste, sull'etichetta del salto al contenuto). Le 44 chiavi mancanti
   sono state tradotte in `lang/vendor/*/it/`, in file **parziali**: Laravel li
   fonde con quelli del pacchetto, e ricopiarli per intero congelerebbe a oggi
   anche le traduzioni già presenti.

**Conseguenze.** Nuovi enum `RecurrenceFrequency`, `Weekday`, `StatsPeriod`,
`ScheduleShortcut`; `App\Support\RecurrenceRule` è l'unico punto che scrive e
rilegge la sintassi RFC 5545 (§10.4: il gestore non la vede mai);
`EventStatusPresentation` si sposta in `App\Filament\Support` perché ora la
usano due pannelli; `DuplicateEventAction` copia anche la locandina (§10.3),
mentre la lineup resta fuori — appartiene alle singole date, e la copia non ne
ha ancora nessuna.

**Verificato.** 75 test dedicati verdi. Il criterio di accettazione percorso
davvero in un browser a 390 px, su `php artisan serve` e database seedato:
accesso → wizard in cinque passi con locandina caricata → **pubblicato**, con
l'evento in elenco e nel database `status = published`, `source = venue`,
`starts_at` alle 19:00 UTC per le 21:00 di Padova, `business_date` ed
`effective_ends_at` scritte dall'observer, la locandina in `media`. Nessun
trabocco orizzontale su nessuno dei cinque passi né sulle altre pagine, nessun
errore in console. Un collaboratore non vede la voce "Collaboratori" (403 se
ne scrive l'indirizzo) e riceve 404 sugli eventi di un altro locale.

## 2026-08-23 — D28. API v1: quattordici scelte prese scrivendo `/api/v1`

**Decisione.** Quattordici punti che §13 non fissa, o che fissa senza dire come.

1. **`starting_soon` e `ongoing` sono casi di `DatePreset`, non di un enum
   dell'API.** §13.2 impone che quelle due finestre esistano in API «se la
   futura app le ricostruisse, in sei mesi ci sarebbero due definizioni
   diverse». La stessa frase vale un livello più sotto: due enum, uno per il
   sito e uno per l'API, sarebbero due elenchi di finestre da tenere allineati.
   `DatePreset` ne ha ora sette e continua a non calcolare nulla — dice solo
   quale metodo di `EventOccurrenceQuery` chiamare. Conseguenza voluta: anche
   il sito accetta `/eventi?date=ongoing`, che è una pagina legittima.
2. **L'ordinamento invece ha due enum**, `EventSort` (sito: `time`) e
   `ApiEventSort` (API: `start|distance|popular|relevance`), perché i due
   vocabolari sono due contratti pubblici diversi e un alias fra i due sarebbe
   una stringa magica in mezzo. Quello che non si duplica è la logica: ogni
   caso chiama un metodo del motore. `sort=popular` ha richiesto
   `orderByPopularity()` (salvataggi, visualizzazioni, poi cronologia).
3. **Il prezzo dell'API è un oggetto, non un enum.** §13.2 ammette `max:20`,
   cioè un tetto parametrico che `PriceFilter` (quattro voci chiuse) non sa
   esprimere. `App\DTOs\PriceConstraint` unisce `PriceMode` e un importo;
   `paid` ha richiesto `pricePaid()` nel motore (biglietto o tessera, escluso
   ciò che nessuno ha dichiarato).
4. **`is_saved` assente contro `is_saved: false`** (§15.8) vive in
   `ApiContext`: `savedOccurrenceIds` nullo significa «nessuno ha chiesto»,
   array vuoto significa «nessuno salvato». I salvataggi di una pagina si
   leggono in **una** interrogazione, non una per elemento.
5. **L'API valida in modo severo, il sito no.** `EventFilterRequest` scarta i
   parametri che non riconosce, perché un link storpiato aperto da una persona
   deve rendere una pagina sensata; `ApiRequest` risponde 422, perché una
   richiesta storpiata fatta da un programma è un difetto del programma e va
   corretto lì. `limit=51` è un errore, non un 50 silenzioso.
6. **Un cursore illeggibile è 400 `INVALID_CURSOR`.** Laravel, da solo, lo
   tratterebbe come «prima pagina»: il client scorrerebbe per sempre lo stesso
   inizio di lista senza alcun segnale.
7. **`Cache-Control` è `public` solo per chi non è autenticato.** Una risposta
   che porta `is_saved` parla di una persona sola e non può finire in una cache
   condivisa. Il middleware è nostro e non `cache.headers` di Laravel perché
   quello non sa emettere `stale-while-revalidate`, che è metà del beneficio:
   senza, alla scadenza del minuto qualcuno aspetta la risposta nuova.
8. **Nessun messaggio del framework esce dall'API.** Sono in inglese ("Too
   Many Attempts.") e violerebbero la regola sulle stringhe fuori da `lang/it`;
   quello di un 404 di rotta racconta i nomi delle classi del progetto. I
   messaggi nostri viaggiano con `ApiException`, che porta anche il codice.
9. **`AlwaysJson` in testa al gruppo `api`.** Senza intestazione `Accept`,
   Laravel considera la richiesta «da browser» e il middleware di
   autenticazione rimanda alla rotta `login`, che non esiste: il risultato è un
   **500 «Route [login] not defined»** al posto del 401 di §13.6. Dichiararlo
   qui significa che `curl` senza intestazioni si comporta come l'app.
10. **`poster` ha sempre le sei chiavi**, anche quando valgono `null`. Le
    conversioni della libreria media appartengono a §12.1, che è di un'altra
    fase: finché non esistono, `thumb`, `card` e `full` puntano all'originale e
    `blurhash`, `width`, `height` restano nulli. Un client che a volte trova la
    chiave e a volte no scrive due strade per leggere la stessa cosa, e il
    contratto della v1 non cambia più.
11. **La mappa dell'API consegna date, quella del sito locali.** §13.3 descrive
    un marcatore per occorrenza; D26 aveva scelto un marcatore per locale
    perché due concerti nello stesso circolo restano sovrapposti a qualunque
    ingrandimento. Sono due risposte a due domande diverse: il raggruppamento
    su un telefono lo decide il client, e `occurrencePoints()` gli dà i punti
    grezzi. La proiezione è ridotta a sette campi e ordinata cronologicamente
    a prescindere da `sort`, perché un ordinamento per rilevanza porterebbe
    nella `SELECT` alias che quella proiezione non contiene.
12. **La stessa segnalazione ripetuta è 409.** Due righe identiche in coda di
    moderazione fanno perdere tempo a chi le legge, e un client che ritenta
    dopo un timeout non deve produrne una. Il riconoscimento è per utente se
    c'è un token, altrimenti per indirizzo IP.
13. **La reimpostazione password ha una notifica propria.** Quella di Laravel
    prende i testi dalle traduzioni del framework (inglese) e punta alla rotta
    `password.reset` del sito, che non esiste: chi reimposta dall'app deve
    tornare nell'app. `API_PASSWORD_RESET_URL` è il modello dell'indirizzo,
    `{token}` e `{email}` i segnaposti. L'uscita revoca **solo** il token con
    cui è stata chiesta; la reimpostazione della password li revoca **tutti**,
    perché chi la cambia teme un accesso altrui.
14. **`created_at` e `updated_at` si leggono con `ApiDate::attribute()`.** Le
    colonne di questo schema nascono da `$table->datetimes()` (deviazione 11 di
    `SCHEMA.md`) e l'analisi statica non le riconosce come proprietà dei model:
    leggerle dall'attributo, verificando che siano davvero una data, è anche il
    solo modo di non farle diventare stringhe vuote.

**Conseguenze.** Nuova tabella `personal_access_tokens` (migration di Sanctum
pubblicata verbatim, D13); `User` usa `HasApiTokens`; `config/api.php` e
`lang/it/api.php` nuovi; `EventOccurrenceQuery` guadagna `pricePaid()`,
`updatedSince()`, `orderByPopularity()` e `occurrencePoints()`; `config/scramble.php`
pubblicato con `api_path = api/v1` e la sicurezza dedotta dai middleware, mentre
titolo e descrizione arrivano da `lang/it` attraverso un trasformatore. Il login
social resta escluso (D7) e le rotte utente di §13.5 — salvataggi, follow, feed,
device — restano alla fase successiva: `GET /v1/config` lo dichiara spegnendo
`saved_events` e `follows`, così un'app non mostra un pulsante che non funziona.

**Verificato.** 501 test verdi (76 nuovi in `tests/Feature/Api/`), `pint`
passato, `phpstan` livello 6 a zero errori. Con `php artisan serve` e database
seedato, **ogni** endpoint di §13.1 percorso con `curl`: i venti indirizzi
rispondono 200, `/docs/api` e `/docs/api.json` rendono le venti operazioni,
`ETag` più `If-None-Match` danno 304 con corpo vuoto, il cursore percorre tre
pagine senza ripetere né saltare, `X-RateLimit-Limit` vale 60 da anonimo e 120
con token, la sessantunesima richiesta è 429 con `Retry-After`, e
`preset=ongoing` e `preset=starting_soon` restituiscono **gli stessi
identificativi** che `EventOccurrenceQuery` restituisce nello stesso istante
(scenario G di §18, verificato sia sui dati seedati sia in tre test dedicati che
confrontano sito, API e motore).

## 2026-08-23 — D29. Account, salvataggi e follow: sedici scelte prese scrivendo §15

**Decisione.** Sedici punti che §15 non fissa, o che fissa senza dire come.

1. **Seguire un evento è una riga di `follows`, non una tabella nuova.**
   §15.3 chiede «Segui questo evento: ogni nuova occorrenza generata viene
   salvata automaticamente» e nello stesso paragrafo avverte che il «segui» di
   un locale «è un'altra cosa». Sono due semantiche, ma un magazzino solo:
   `FollowableType` guadagna il caso `event`, la colonna è già una stringa e la
   morph map conteneva già l'alias. Il vincolo unico `(user_id, followable_type,
   followable_id)` regala l'idempotenza, la cascata sull'utente esisteva.
   Perché non si mescolino in lettura, `FollowableType::feedSources()` elenca i
   soli tre tipi che alimentano il feed: un evento seguito entra nel feed
   attraverso i salvataggi che produce, non come sorgente.
2. **L'aggancio dell'auto-salvataggio è la creazione dell'occorrenza, non la
   generazione della serie.** Una data aggiunta a mano dal gestore a una
   rassegna seguita è, per chi la segue, la stessa identica notizia di una data
   nata dalla regola. Sta quindi in `EventOccurrenceObserver::created()` e non
   dentro `GenerateOccurrencesAction`.
3. **Il salvataggio da anonimo il server non lo conosce.** Vive nel
   `localStorage` e basta: nessun cookie, nessuna riga, nessun identificativo
   assegnato a chi non ha chiesto niente. È ciò che rende il primo click
   immediato (§15.1) ed è anche la scelta più sobria in termini di dati.
4. **La migrazione la chiede il browser dopo *ogni* accesso, non solo dopo la
   registrazione.** §15.1 la descrive al momento della registrazione, ma il
   caso «ho già un account e ho salvato da sloggato» è identico e sarebbe
   rimasto scoperto. `POST /salvataggi/unisci` è idempotente e la risposta dice
   quante date sono entrate: **è quel numero che autorizza a svuotare il
   `localStorage`**, mai il fatto di averlo inviato.
5. **Il cuore è un modulo, non un pulsante.** Senza JavaScript chi è collegato
   salva con un invio e un ricaricamento; lo script gli toglie il
   ricaricamento e, per chi non è collegato, lo dirotta sul `localStorage`.
   Stessa regola di §11.11: nulla di ciò che sta in `app.js` è necessario.
6. **`savedBy()` e `followedBy()` sono filtri di `EventOccurrenceQuery`.**
   Erano l'occasione più ghiotta per scrivere «da oggi in poi» una seconda
   volta: sono filtri, e la finestra resta `upcoming()`. Il feed è quindi
   `upcoming()->followedBy($user)` e i salvataggi `savedBy($user)->upcoming()`,
   con la stessa definizione di adesso del resto del prodotto (§8.1).
7. **Un salvataggio impossibile è un caso solo.** Data passata, evento non
   pubblicato, occorrenza inesistente: per chi scrive il server sono tre cose,
   per chi chiama una — quella data non si può mettere in agenda. Il
   riconoscimento è una sola interrogazione al motore, che è già ristretto agli
   eventi pubblicati della città: **chiedere al motore *è* il controllo.**
8. **La rotta di accesso del sito si chiama `login`.** È il nome che Laravel
   cerca quando il middleware `auth` deve rimandare qualcuno da qualche parte;
   chiamarla `account.login` avrebbe richiesto di dichiarare quel
   reindirizzamento a mano, lontano da dove sta la rotta.
9. **Il collegamento senza password apre una sessione del sito, non consegna
   un token dell'API.** Un token che viaggiasse dentro un indirizzo web
   finirebbe nella cronologia del browser, nell'intestazione `Referer` e nei
   log di qualunque proxy: tre posti in cui una credenziale non deve stare.
   `POST /v1/auth/magic-link` manda quindi lo stesso collegamento del sito.
   Difese: firma, quindici minuti, e un'impronta della password in vigore —
   cambiarla invalida i collegamenti già spediti, che è esattamente ciò che si
   vuole quando la si cambia perché si teme un accesso altrui.
10. **`POST /v1/auth/verify-email` accetta l'indirizzo intero.** Non la coppia
    `id`+`hash`: quel `hash` è `sha1(email)`, cioè un valore che chiunque
    conosca l'indirizzo può calcolare. È la firma a fare la prova, e la firma
    copre l'indirizzo completo. `SignedEmailVerification` è il solo punto in
    cui da una stringa si fabbrica una richiesta per verificarla.
11. **La conferma dell'indirizzo non pretende una sessione.** Chi apre il
    messaggio dal telefono può non essere collegato lì: mandarlo alla pagina di
    accesso partendo da un collegamento che era già una prova di identità
    sarebbe un ostacolo senza guadagno.
12. **`users.name` diventa nullable.** §15.2 dice «nome (facoltativo)», e una
    stringa vuota non è un nome mancante: è un nome vuoto. La migrazione è
    stata cambiata sul posto (il progetto è nuovo, nessun dato da migrare) e
    `User::getFilamentName()` ripiega sull'email, perché il pannello si aspetta
    una stringa.
13. **La tabella `notifications` nasce adesso**, benché il motore di invio
    appartenga alla fase successiva. D8 ha promosso l'archivio in-app da
    comodità a canale di destinazione: `GET /v1/me/notifications` è un
    indirizzo di §15.8 e un archivio vuoto è una risposta legittima, mentre un
    endpoint che non esiste costringe l'app a due strade.
14. **`CurrentSaves` e `CurrentFollows` leggono una volta per richiesta.** Il
    cuore compare su ogni card: chiederlo card per card sarebbe una lettura per
    ciascuna, passarlo come proprietà da ogni controller significherebbe
    dimenticarsene in metà delle pagine — e un cuore spento su una data salvata
    non è un dettaglio estetico, è una risposta sbagliata. I due servizi sono
    `scoped` e confrontano l'oggetto richiesta: `scoped` da solo, senza un
    runtime persistente, non scade mai davvero.
15. **La cancellazione anonimizza e cestina, non rimuove.** Le pratiche dei
    locali e le segnalazioni hanno chiavi `SET NULL` e sopravvivono da sole, ma
    la riga resta perché la cronologia editoriale conservi un riferimento che
    non è più una persona. L'indirizzo diventa un `.invalid` (RFC 2606):
    nessun messaggio potrà mai partire. Nella stessa transazione spariscono
    salvataggi, follow, dispositivi, archivio e **token**, e gli invii in
    attesa passano a `cancelled` — «effetto immediato» (§15.2) significa questo
    e si verifica con una prova, non con una promessa.
16. **Le preferenze di notifica stanno in un DTO con i propri valori
    predefiniti**, e gli **annullamenti non hanno un interruttore**: §15.4 li
    dichiara «attivi, non disattivabili», e un interruttore che il server
    ignora è peggio di nessun interruttore. La risposta li dichiara comunque
    (`cancellations: true`) perché l'app non disegni qualcosa che non esiste.
    Il consenso alla newsletter resta fuori: è giuridicamente distinto e vive
    in `marketing_opt_in_at`, che **conserva la propria data** — riconfermarlo
    non la riscrive, toglierlo la cancella.

**Conseguenze.** Nuova tabella `notifications`; `users.name` nullable;
`FollowableType` con quattro casi; `EventOccurrenceQuery` guadagna `savedBy()`,
`followedBy()` e `forOccurrences()`; nuovi `config/account.php` e
`lang/it/account.php`; `routes/account.php` incluso da `routes/web.php` fuori
dai gruppi del sito pubblico (l'area personale non appartiene a una città);
diciassette rotte sotto `/api/v1/me` e `/api/v1/auth/{magic-link,verify-email}`;
`api.features.saved_events` e `follows` accesi in `config/api.php`. La
registrazione di sito e API passa dalla stessa azione `RegisterUser`.

**Verificato.** 563 test verdi (44 nuovi in `tests/Feature/Account/` e
`tests/Feature/Api/MeEndpointsTest.php`), `pint` passato, `phpstan` livello 6 a
zero errori sul codice di questa fase. Con `php artisan serve` e database
seedato: **scenario H** percorso davvero con `curl` e cookie — tre date salvate
da anonimo, registrazione, `POST /salvataggi/unisci` che risponde
`{"merged":3,"ignored":0}`, pagina dei salvataggi con tre cuori accesi, uno
tolto e due rimasti; giro completo dell'API (`/me`, `/me/saved`, `/me/follows`,
`/me/feed` con e senza follow, `/me/devices`, `/me/export`, `DELETE /me` seguito
da 401); **collegamento senza password e collegamento di verifica aperti
davvero** dai messaggi resi dal mailer, con sessione creata nel primo caso e
`canReceiveNotifications()` che passa a vero nel secondo.

## 2026-08-23 — D30. Media, SEO, performance e cache: undici scelte prese scrivendo §12

**Decisione.** Undici punti che §12 non fissa, o che fissa senza dire come.

1. **Il tipo reale di un'immagine si legge dai byte, non da `finfo`.**
   `App\Enums\ImageType` riconosce JPEG, PNG, WebP, HEIC e AVIF dai primi
   trentadue byte (per HEIC e AVIF dalla marca del riquadro `ftyp`). `finfo`
   dipende dalla versione di libmagic installata sul server e sui HEIC le
   versioni vecchie rispondono `application/octet-stream`: un controllo che
   cambia risposta cambiando macchina non è un controllo. `App\Rules\RealImage`
   confronta poi il tipo reale con l'estensione dichiarata e rifiuta le
   discordanze, che sono la forma esatta di un caricamento ostile.
2. **La pipeline è un solo lavoro di coda, non due in parallelo.**
   `App\Jobs\Media\ProcessImageMedia` **sostituisce**
   `media-library.jobs.perform_conversions` invece di affiancarsi ad esso: è
   l'unico modo di garantire che lo strip dell'EXIF avvenga **prima** delle
   conversioni. Imagick riscrive i pixel ma conserva i riquadri dei metadati:
   con due lavori concorrenti la posizione GPS di chi ha fotografato la
   locandina potrebbe finire pubblicata dentro la miniatura.
3. **Prima si applica l'orientamento, poi lo si toglie.** `ImageSanitizer`
   ruota davvero i pixel secondo il riquadro EXIF `Orientation` e solo dopo
   chiama `stripImage()`; il profilo colore ICC viene estratto e rimesso,
   perché `stripImage()` porta via anche quello e senza profilo un'immagine in
   Display P3 si spegne. `Imagick::autoOrientImage()` non esiste in tutte le
   build (manca in quella di questa macchina): c'è una via manuale di scorta.
4. **Sei conversioni, non tre**: `thumb`, `card`, `full` in WebP **e** in AVIF
   (`-avif`). L'AVIF pesa dal 20 al 40 per cento meno a parità di resa ma non
   lo aprono tutti: `<picture>` li offre in ordine e il browser prende il primo
   che sa leggere. Nessuna variante ingrandisce (`DoNotUpsize`).
5. **L'anteprima Open Graph appartiene all'evento, non alla locandina.** Vive
   in `og/eventi/{id}.jpg` e si compone anche per un evento senza locandina.
   Si disegna **su richiesta**: la prima visita alla scheda accoda il lavoro e
   intanto `og:image` ricade sulla locandina; comporla dentro la richiesta
   costerebbe un secondo a chi apre la pagina per un'immagine che vedrà
   qualcun altro. Il font è **Inter e Bricolage Grotesque convertiti in TTF**
   (`resources/fonts/`, licenza OFL a fianco): l'ImageMagick di questa macchina
   non ha un font predefinito e senza percorso `annotateImage()` fallisce, e i
   `woff2` di `@fontsource` freetype non li apre.
6. **`spatie/image` va usato dal driver, non dalla facciata.**
   `Image::useImageDriver(...)->new(...)` restituisce un'istanza nuova che la
   facciata non tiene: la chiamata successiva muore su `$image` non
   inizializzato. Si costruisce quindi `new ImagickDriver` e si compone su
   quello. Resta `spatie/image`, e non un browser senza schermo: Chromium sono
   trecento megabyte e un processo da sorvegliare, e su spazio condiviso senza
   root non si installerebbe nemmeno (D3).
7. **`robots.txt` è una rotta, e il file statico è stato tolto da `public/`.**
   La riga `Sitemap:` vuole un indirizzo assoluto: un file scritto a mano lo
   congelerebbe al dominio di quando è stato scritto, e sviluppo, collaudo e
   produzione ne hanno tre diversi. Finché il file esisteva, il server lo
   avrebbe servito prima di arrivare a PHP.
8. **La mappa del sito si compone a ogni richiesta e sta in cache**, invece di
   essere scritta su disco da un comando periodico: un file scritto stanotte
   non conosce l'evento pubblicato stamattina, e su spazio condiviso un `cron`
   in meno è un guasto in meno.
9. **`App\Support\ContentVersion` è il solo segnale di invalidazione.** Un
   numero per città, dentro la chiave di ogni cache: pagine, conteggi del
   calendario, mappa del sito e tassonomie di città. Cambiare quel numero rende
   irraggiungibile tutto in una scrittura sola, senza inseguire quali chiavi un
   evento tocchi — che sono, in generale, tutte. `MonthCalendar::bump()`
   sparisce a suo favore. La cache resta `file` senza `tags()` (D5).
10. **In cache vanno array, mai oggetti.** `cache.serializable_classes` è
    `false`: nessuna classe PHP viene ricostruita da ciò che sta in cache.
    Salvare `Url` di `spatie/laravel-sitemap` o collezioni Eloquent **passa nei
    test** — il driver `array` non serializza nulla — e **rompe in produzione**
    con `__PHP_Incomplete_Class`. È successo davvero, ed è stato visto solo
    percorrendo le pagine con `curl`. Ora si salvano righe e si ricostruisce
    con `hydrate()`; due test girano sul driver `file` proprio per questo.
11. **La full-page cache conserva il corpo, non le intestazioni, e rimette il
    token CSRF.** Salvare le intestazioni riemetterebbe a un visitatore il
    cookie di sessione di un altro. Il token, che vale per una sessione sola,
    viene sostituito da un segnaposto nella copia e rimesso al volo su ogni
    risposta: verificato con `curl`, due sessioni ottengono due token diversi
    dalla stessa copia in cache e un invio con quel token passa (302) mentre
    uno con un token qualsiasi è 419. Non entrano in cache le pagine di chi è
    autenticato, quelle con un messaggio o errori in sessione, la ricerca
    libera e gli indirizzi con `near=` — che cambiano a ogni metro percorso.

**Conseguenze.** Nuovi `config/media.php`, `config/seo.php`,
`config/page_cache.php` e `config/media-library.php` pubblicato (disco
`public`, 12 MB, `imagick`, lavoro e generatore di indirizzi nostri);
`App\Support\Media\{ImageSet, Variants, CdnUrlGenerator}`, il componente
`<x-media-image>` e il trait `HasImageVariants`; `Poster::url()` ora restituisce
la variante `full` invece dell'originale, perché un HEIC in `og:image` non lo
apre nessuno; `PageMeta` guadagna `imageWidth`/`imageHeight`;
`App\Services\Cache\LiveWindows` tiene le due finestre dal vivo con la chiave
arrotondata al quarto d'ora; `LiveNow` gliele chiede invece di interrogare il
motore due volte per pagina. Il limite di memoria della suite passa a 512 MB in
`phpunit.xml`: con la pipeline media che apre file veri, i 128 MB predefiniti
della CLI non bastano più.

**Verificato.** 597 test verdi (48 nuovi in `tests/Feature/Media/`,
`tests/Feature/Seo/` e `tests/Feature/Cache/`), `pint` passato, `phpstan`
livello 6 a zero errori. La pipeline è provata su **file veri**: un JPEG con un
riquadro EXIF scritto byte per byte (ImageMagick non scrive le proprietà
`exif:*`, quindi un file senza EXIF non dimostrerebbe niente) esce senza
`Orientation` e con i lati scambiati, le sei conversioni sono davvero WebP e
AVIF (riconosciute dai byte), blurhash e segnaposto finiscono nelle proprietà
del media, un file con estensione `.jpg` e contenuto PHP viene rifiutato e il
media cancellato. L'arrotondamento al quarto d'ora ha il test che §12.3 chiede:
stessa chiave a tre secondi, chiave diversa a venti minuti, e la finestra non
viene ricalcolata dentro lo stesso quarto d'ora. Con `php artisan serve` e
database seedato, **con `curl`**: `robots.txt` e `sitemap.xml` a indice con le
cinque sezioni tutte 200 (11, 132, 23, 52, 31 indirizzi) e riletti dalla cache
su disco, 404 oltre la fine; `X-Page-Cache` miss poi hit su `/` e `/eventi`,
assente su `/cerca`; 162 media rigenerati con le sei varianti (`file` conferma
"ISO Media, AVIF Image"), 136 anteprime social da 1200×630 composte dalla coda,
e la scheda evento che dichiara canonical, Open Graph con misure, X/Twitter,
`hreflang`, `<picture>` con le due serie, segnaposto sfocato e preload AVIF del
LCP. Il JSON-LD riletto con `json_decode`: un nodo `Event` per occorrenza con
tutti i campi obbligatori di schema.org più `location: Place` con indirizzo e
coordinate, `offers`, `eventStatus`, `eventAttendanceMode`, `organizer`,
`image` e `performer`.

## 2026-08-24 — D31. Motore di invio delle notifiche: dodici scelte prese scrivendo §15.4-§15.6 e §15.9

**Decisione.** Dodici punti che §15 non fissa, o che fissa senza dire come.

1. **Il motivo di uno `skipped` si scrive in `last_error`.** §15.5 pretende
   «skipped (con motivo)», ma `scheduled_notifications` ha una sola colonna di
   testo libero. Aggiungerne una seconda avrebbe significato una migration per
   distinguere due parole — «saltata perché» e «fallita perché» — che lo stato
   della riga già distingue: `skipped` e `failed` non si confondono. Il valore
   è quello di `NotificationSkipReason`, e il pannello lo rende in italiano.
   Nessuna modifica allo schema di `SCHEMA.md`: questa fase non ha migration.
2. **Una sola classe di notifica per tutte le tipologie.**
   `ScheduledMessage` riceve un `NotificationMessage` già composto da
   `MessageFactory`. Dieci classi avrebbero significato dieci copie della
   stessa impalcatura — canali, piè di pagina, disiscrizione, intestazioni
   `List-Unsubscribe` — con dieci occasioni di dimenticarne un pezzo proprio
   dove §15.9 non ammette dimenticanze. La tipologia resta leggibile:
   `databaseType()` scrive in `notifications.type` il valore di dominio
   (`event_reminder`) e non il nome della classe PHP, che a un'app non
   direbbe nulla.
3. **Il prelievo in lock avviene dentro la transazione, e l'invio pure.**
   `ScheduledMessage` è una notifica di coda: «inviare» significa scrivere una
   riga nella tabella `jobs` dello **stesso** database. Stato della riga e
   messaggio nascono e muoiono insieme, e la connessione SMTP avviene altrove,
   fuori da qualunque blocco. Per la stessa ragione **non** si usa
   `afterCommit`: il lavoro accodato diventa visibile agli altri processi solo
   al commit, quindi la corsa che `afterCommit` protegge qui non esiste, mentre
   l'atomicità che si guadagna è reale.
4. **Le regole di volume si applicano all'invio, non alla programmazione.**
   Fra il salvataggio di una data e il promemoria passano giorni: in mezzo la
   persona può aver spento una tipologia, dichiarato le proprie ore di
   silenzio o verificato l'indirizzo. Decidere alla programmazione
   significherebbe decidere con i dati di ieri. La riga si crea comunque, ed è
   ciò che la rende ispezionabile in anticipo (§15.5).
5. **L'ordine dei controlli è account → verifica → preferenze → silenzio →
   tetto → contenuto.** Il tetto giornaliero si valuta **dopo** lo spostamento
   fuori dalle ore di silenzio, altrimenti verrebbe contato nel giorno
   sbagliato. Il contenuto si valuta per ultimo, ed è ciò che realizza la
   frase di §15.4: «se nel frattempo è diventato inutile viene marcato
   skipped» — un promemoria spostato alle otto del mattino per una serata
   finita alle tre non parte, e la riga dice perché.
6. **Le ore di silenzio non hanno un valore predefinito.** §18 le chiama «le
   proprie». Un silenzio imposto d'ufficio sposterebbe invii che nessuno ha
   chiesto di spostare, e lo farebbe senza che l'interessato possa capire
   perché. Chi non le dichiara non ne ha.
7. **Il tetto giornaliero salta, non rimanda.** Una notifica intrusiva
   consegnata il giorno dopo è una notifica vecchia; e rimandarla
   consumerebbe il tetto dell'indomani, spingendo il ritardo in avanti per
   sempre. I tipi che consumano il tetto sono i quattro non richiesti —
   riepiloghi, newsletter, sold out; i promemoria di ciò che è stato messo in
   agenda a mano no, come prescrive §15.4.
8. **La giornata del tetto è quella locale di chi riceve.** Due notifiche alle
   23:00 e una alle 00:30 sono due giorni per chi legge e uno solo per un
   server in UTC.
9. **Un secondo comando, `notifications:plan`, ogni ora.** Il worker risponde
   a «cosa devo mandare adesso», il pianificatore a «cosa sarà da mandare»:
   sono due domande, e i riepiloghi devono esistere come righe **prima**
   dell'ora di invio perché §15.5 vuole vederli in anticipo. La stessa
   esecuzione purga `notification_log` oltre i dodici mesi (§15.9), che è
   l'unica cosa che ha senso far passare una volta l'ora e non ogni cinque
   minuti.
10. **Il riepilogo settimanale copre tutto ciò che si segue**, non i soli
    locali: §15.7 unifica già locali, generi ed etichette nel feed, e un
    riepilogo che ignorasse le categorie lascerebbe senza nulla chi segue solo
    quelle. Resta **un** messaggio aggregato, che è la regola vincolante di
    §15.4 — mai una notifica per singolo evento nuovo. Il riepilogo si
    programma solo per chi segue qualcosa: per gli altri sarebbe una riga
    creata ogni settimana per essere saltata, cioè rumore nel pannello che
    §15.5 vuole leggibile.
11. **La disiscrizione a un click disiscrive davvero, con un `GET`.** Una
    promessa di «un click» che ne richiede due è il punto in cui un messaggio
    viene segnato come spam. Lo stesso indirizzo esiste in `POST` con le
    intestazioni `List-Unsubscribe` e `List-Unsubscribe-Post` (RFC 8058), che
    è il click che l'utente non deve nemmeno fare. Le tipologie obbligatorie
    non mostrano alcun collegamento: offrirne uno che non spegne nulla sarebbe
    peggio che non averlo.
12. **La pagina delle preferenze senza accesso cambia solo ciò che si riceve.**
    Nome, lingua, fuso ed email restano fuori: sono modifiche di identità e
    vogliono una sessione. La firma prova che quell'indirizzo è di chi lo
    apre — esattamente la prova che serve per far smettere le email, né una di
    più. L'indirizzo vale trenta giorni, che è la vita utile di un messaggio
    nella casella di chi lo riceve.

**Conseguenze.** Nuovi `config/notifications.php`, `lang/it/notifications.php`;
enum `NotificationType` (con dentro le regole di volume) e
`NotificationSkipReason`; DTO `QuietHours`, `NotificationMessage`,
`NotificationDecision`; `App\Services\Notifications\{NotificationScheduler,
NotificationGate, MessageFactory, DigestPlanner, NotificationDispatcher}`;
`App\Notifications\Scheduled\ScheduledMessage` con il modello Blade a tabelle
`resources/views/mail/notification.blade.php`; due comandi
(`notifications:send` ogni cinque minuti, `notifications:plan` ogni ora); due
permessi (`notifications.view`, `notifications.manage`),
`ScheduledNotificationPolicy` e la pagina `/admin/scheduled-notifications`;
quattro rotte firmate sotto `/notifiche`. Agganci: `SaveOccurrences` e
`SaveOccurrenceForFollowers` creano i promemoria, `EventOccurrenceObserver`
riprogramma e annulla, `EventObserver` avvisa chi gestisce il locale.
**Nessuna migration**: lo schema di §7.10 bastava.

**Verificato.** 662 test verdi (61 nuovi in `tests/Feature/Notifications/`),
`pint` passato, `phpstan` livello 6 a zero errori. Gli scenari I, J e K di §18
sono test veri: il promemoria spostato di due ore resta **la stessa riga** con
lo stesso identificativo e la stessa chiave; spostato a ieri diventa `skipped`
con motivo `occurrence_past`; quaranta annullamenti partono entro i cinque
minuti del worker verso persone che avevano spento tutto; otto locali seguiti
producono **un** riepilogo con otto voci, il tetto ferma la terza notifica
intrusiva della giornata, e un promemoria che cade a mezzanotte e mezza viene
spostato alle otto — mentre un annullamento all'una di notte parte subito. Sul
database seedato, con `php artisan serve` e il mailer su file: le righe
programmate hanno le chiavi di §7.10 (`reminder_24h:user_6:occ_42`),
l'annullamento di una data ha annullato i due promemoria collegati e accodato
l'avviso, il messaggio reso porta oggetto «Domani: Sagra dei vini del
territorio», il collegamento alla scheda, le intestazioni `List-Unsubscribe` e
`List-Unsubscribe-Post`, e con `curl` la pagina delle preferenze firmata
risponde 200, quella non firmata 403, e il collegamento di disiscrizione ha
davvero spento la tipologia.

## 2026-08-31 — D32. Import ICS: pubblicazione diretta e dodici scelte del motore

**Decisione.** La più importante non è tecnica ed è del committente: **gli
eventi importati nascono `published`**, non in coda di moderazione come
prescriveva §14.2 (`fetch → parse → map → deduplicate → preview → moderazione →
publish`). Il flusso effettivo è `fetch → parse → map → filtro → deduplica →
scrittura`, con la moderazione tolta di mezzo.

**Perché.** È una richiesta esplicita del committente: un calendario ICS lo
dichiara il locale stesso, e far passare da un redattore ogni serata di ogni
locale collegato annullerebbe il vantaggio dell'import — che è togliere lavoro
alla redazione, non aggiungerne. Con dieci locali collegati, l'import
diventerebbe una coda che nessuno svuota, e una coda che nessuno svuota è
peggio di nessun import: gli eventi non escono e nessuno sa perché.

**I due contrappesi**, che sono la ragione per cui la decisione regge:

1. **L'anteprima è obbligatoria prima di accendere la sorgente.**
   `ImportRunner::preview()` percorre `fetch` e `map` interi, applica il filtro
   di esclusione e **non scrive niente**: restituisce le prime venti date che
   entrerebbero, in ordine di inizio. È esposta come azione «Anteprima» nella
   tabella di `/admin`, accanto a «Esegui ora». Chi accende una sorgente ha
   guardato che cosa entra; l'ordine cronologico serve proprio a questo,
   perché un fuso letto male si riconosce a colpo d'occhio solo lì.
2. **Il filtro di esclusione.** `mapping.exclude_keywords`, con i valori
   predefiniti *chiuso, ferie, riunione, privato, manutenzione, chiusura* in
   `config/import.php`: un titolo che contiene una di quelle parole non entra,
   e il fatto è contato nel resoconto. È ciò che tiene fuori dal sito
   «riunione staff» e «chiuso per ferie», che è quello che un calendario
   Google di un locale contiene per metà. Il confronto è su **parole intere**,
   senza accenti e senza maiuscole: una sottostringa ucciderebbe un evento
   vero al primo elenco un po' più lungo — «arte» ucciderebbe «Cartellone».

§14.1 resta rispettato per intero e diventa la difesa che sostituisce la coda:
`source = import_ics` e `verification_status = unverified`, così che il sito e
l'API possano distinguere un evento confermato dal locale da uno importato e
non verificato. Un import che pubblica **senza** dichiararsi non verificato
sarebbe inaccettabile; questo no.

**Le altre undici scelte, che §14.2 non fissa.**

1. **`events.source_ref` porta davanti l'identificativo della sorgente**
   (`{id}:{UID}`, più `#{RECURRENCE-ID}` per le eccezioni di una serie).
   §14.2 dice «idempotente su `source_ref`» e il valore naturale sarebbe il
   solo `UID`, ma senza il prefisso non esiste alcun modo di chiedere al
   database *quali eventi vengono da questo calendario* — e senza quella
   domanda non si riconosce ciò che dal feed è **sparito**. Serve anche a non
   far collidere due calendari della stessa città: l'RFC vuole l'`UID` unico
   al mondo, ma è una stringa scritta da chi esporta, e «1» si incontra
   davvero. Un `UID` più lungo di quanto la colonna consenta viene sostituito
   dalla propria impronta, che resta **stabile** fra un'esecuzione e l'altra —
   ed è la stabilità, non la leggibilità, che l'idempotenza richiede.
   Tutto questo vive in `App\Support\Import\SourceRef` e in nessun altro punto.
2. **`UID` e `RECURRENCE-ID` sono due chiavi diverse.** In un ICS la data
   spostata di una serie porta lo **stesso** `UID` della serie: trattarle come
   una cosa sola farebbe sovrascrivere la serie con la propria eccezione a
   ogni esecuzione, e la serie sparirebbe.
3. **Le tre forme di data hanno tre test ciascuna, e convergono.** Fluttuante
   (nel fuso della città, o in quello di `mapping.timezone`), UTC, e con
   `TZID` — compreso un `TZID` risolto attraverso il `VTIMEZONE` incorporato,
   che è come Outlook e i CalDAV aziendali dichiarano fusi con nomi che il
   database dei fusi non conosce. `DTSTART:20260905T213000`,
   `DTSTART:20260905T193000Z` e `DTSTART;TZID=Europe/Rome:20260905T213000`
   producono lo stesso identico istante, e c'è un test che lo afferma. Il
   cambio d'ora è dentro le prove: il 4 settembre e il 1 novembre non hanno lo
   stesso scarto, e uno scarto fisso ne sbaglierebbe uno.
4. **`DTEND` di un evento di intera giornata è esclusivo.** Un giorno solo si
   scrive «dal 5 al 6»: preso alla lettera diventa un evento di due giorni.
   Per la giornata singola si scrive `ends_at = null` e la fine la decide §8.3
   (orario di apertura del locale); per più giorni si sottrae un secondo.
   `DURATION` sostituisce `DTEND` quando manca; quando mancano entrambi la
   durata resta alla categoria, che è ciò che §8.3 prescrive.
5. **Le `EXDATE` si riscrivono in ora locale.**
   `GenerateOccurrencesAction` le rilegge con il fuso della città: scriverle in
   UTC le farebbe scivolare di due ore e la data esclusa non verrebbe esclusa.
6. **Le `RRULE` non si espandono nel motore di import.** Si scrive la regola in
   `event_recurrences` e si chiama `GenerateOccurrencesAction`, che è già
   idempotente (D20, D22). Quando la regola cambia a monte, le date che non
   produce più vengono annullate confrontandole con **`expectedStarts()`**,
   metodo nuovo della stessa azione: due espansori RFC 5545 nello stesso
   progetto divergerebbero, ed è il genere di divergenza che nessuno nota
   finché non manca una serata. `__invoke()` è stato rifattorizzato per usare
   lo stesso `plan()` privato, così l'elenco che l'import confronta è —
   letteralmente — quello che l'azione materializza.
7. **Le sparizioni marcano, non cancellano, e non partono su un dubbio.** Una
   voce che il feed non porta più diventa `cancelled` sulle occorrenze
   **future** (il passato non si tocca: una serata avvenuta non diventa
   annullata perché il calendario non la elenca più). La spazzata **non parte
   affatto** se anche una sola voce del feed non si è lasciata interpretare:
   un'esecuzione che ha capito il feed a metà non sa che cosa sia davvero
   sparito. Un feed irraggiungibile non arriva nemmeno a quel punto — è
   un'eccezione, e le eccezioni si riprovano.
8. **L'import riscrive ciò che il calendario dichiara e nient'altro.** Titolo,
   descrizione, luogo, date. Non rimette `status` a `published` e non riabbassa
   `verification_status`: se un redattore ha ritirato o verificato un evento
   importato, l'esecuzione oraria non deve disfare quella decisione ogni
   sessanta minuti. Per la stessa ragione un evento cestinato resta cestinato,
   e una data con `is_exception = true` (D21) non viene annullata: la decisione
   di una persona vale più della regola del feed.
9. **Una sorgente, un lavoro di coda.** §14.2 chiede l'esecuzione oraria e che
   un guasto non fermi il resto: se l'import fosse un ciclo dentro un comando,
   tre calendari che impiegano trenta secondi a non rispondere manderebbero
   l'esecuzione oltre l'ora successiva. `import:run` **accoda** un
   `ImportSourceJob` per sorgente (tre tentativi, attesa 60 e 300 secondi,
   tempo massimo 120), e `ShouldBeUnique` sull'identificativo impedisce che
   l'esecuzione delle 15:00 ancora in corso si scontri con quella delle 16:00.
   `--sync` esegue nel processo e stampa il resoconto: è il modo di provare una
   sorgente appena configurata senza leggere i log di un worker.
10. **Il guasto si scrive sulla sorgente *prima* di rilanciare.**
    `ImportRunner` registra `last_run_at`, `last_status` e `last_error` e poi
    rilancia, così la dashboard di §14.5 vede il problema anche quando il
    lavoro finisce fra i falliti. Un'esecuzione riuscita **azzera**
    `last_error`: quella query guarda la colonna e non lo stato (lo dice
    `EditorialDashboardQuery`), e senza la ripulitura una sorgente resterebbe
    segnalata in guasto per sempre.
11. **`last_status` resta una stringa libera in colonna** ma ha ora un enum,
    `ImportRunStatus` (`success|partial|failed`). Tre e non due perché il caso
    più frequente è un calendario di duecento voci in cui tre non si lasciano
    interpretare: non è un'esecuzione fallita, e non è nemmeno pulita. La
    tabella di `/admin` mostra l'etichetta dell'enum quando la riconosce e il
    valore grezzo quando no, perché il contratto della colonna non cambia.

**Difetto preesistente corretto.** `lang/it/admin.php` dichiarava **due volte**
la chiave `'notifications'`: PHP teneva la seconda e buttava via la prima, e
tutti i messaggi di esito del pannello — «Evento pubblicato.», «Approvato.»,
«Copia creata come bozza.», ventuno in tutto — uscivano come chiave grezza. I
due blocchi sono stati fusi; un controllo sulle chiavi duplicate di primo
livello di tutti i file di `lang/it` non trova più nulla.

**Conseguenze.** Nuovi `config/import.php` e `lang/it/import.php`; enum
`ImportRunStatus` e `ImportSourceType::eventSource()`; DTO `ImportedEventDto`,
`ImportMapping`, `ImportReport`; `App\Exceptions\ImportException`;
`App\Support\Import\SourceRef`; `App\Services\Import\{ImportSourceDriver,
IcsImportDriver, ImportDriverFactory, ImportRunner}`;
`App\Jobs\Import\ImportSourceJob`; comando `import:run {--source=} {--sync}`
schedulato ogni ora in `routes/console.php`; due azioni («Anteprima», «Esegui
ora») e la colonna dell'esito in `ImportSourceResource`.
`GenerateOccurrencesAction` guadagna `expectedStarts()`. **Nessuna migration**:
lo schema di §7.11 e §3.20 bastava.

**Verificato.** 71 test nuovi in `tests/Feature/Import/`, suite intera verde,
`pint` passato, `phpstan` livello 6 a zero errori. I calendari di prova sono
**file veri** in `tests/Fixtures/ics/` — un ICS scritto dentro un'asserzione
somiglia a ciò che il codice si aspetta, uno su disco somiglia a quello che
manda Google Calendar: le tre forme di data, `VALUE=DATE` singolo e su più
giorni, `DURATION`, nessuna fine, `RRULE` con `EXDATE`, un feed in stile Google
Calendar con `VTIMEZONE` incorporato ed eccezione con `RECURRENCE-ID`, un feed
di voci interne da escludere, un calendario vuoto, una pagina HTML servita al
posto del calendario, e un file malformato — che fallisce con un messaggio in
italiano e non con un'eccezione della libreria. Tre esecuzioni consecutive
dello stesso feed: quattro eventi creati la prima volta, zero creati e zero
aggiornati la seconda e la terza, stessi identificativi e stesse date. Un
titolo, una descrizione, un luogo e una data cambiati a monte aggiornano
**la stessa riga**, e la colonna calcolata `business_date` segue la data nuova.

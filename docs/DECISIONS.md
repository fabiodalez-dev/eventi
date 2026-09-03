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

## 2026-08-31 — D33. Operatività: dieci scelte prese configurando §16

**Decisione.** I quattro pacchetti installati e non configurati —
`spatie/laravel-backup`, `spatie/laravel-health`,
`spatie/laravel-schedule-monitor`, `laravel/pennant` — più
`sentry/sentry-laravel`, che mancava. Dieci punti che §16 e §12 dello stack
non fissano.

1. **La retention di 30 giorni è retention, non decadimento.** La strategia
   predefinita del pacchetto conserverebbe una copia settimanale per due mesi,
   una mensile per quattro e una annuale per due anni; §16 dice «30 giorni», e
   i quattro periodi successivi sono a zero. Sulla shared hosting il disco è
   condiviso, quindi c'è anche un tetto di spazio (2 GB) che vince sui giorni:
   un disco pieno ferma anche il sito.
2. **Nel backup entra `storage/app`, non l'intero progetto.** Il codice è in
   git, ed è l'unica cosa già al sicuro altrove: includerlo raddoppierebbe
   ogni copia notturna per conservare ciò che si recupera con un `git clone`.
   Il disco `s3` resta predisposto e commentato in tre punti (`BACKUP_DISKS`,
   `destination.disks`, `monitor_backups`), perché una copia che vive solo
   sulla macchina che protegge non protegge da un guasto di quella macchina.
3. **Conseguenza sul rilascio, e non è secondaria:** il `rsync --delete` del
   deploy sincronizzava anche `storage/app/private`, dove finiscono i backup.
   Senza `--exclude 'storage/app/private/*'` ogni pubblicazione avrebbe
   cancellato ogni copia — un backup distrutto proprio dal gesto dopo il quale
   serve di più. La riga è stata aggiunta insieme a questa fase.
4. **Avvisa il fallimento, tace il successo.** Le tre notifiche di riuscita
   restano dichiarate con un canale vuoto e non cancellate (il pacchetto
   morirebbe di chiave mancante): un messaggio ogni notte per dire che è
   andato tutto bene si smette di leggere in una settimana, e da quel momento
   non si legge più nemmeno quello che dice il contrario. `backup:monitor`
   copre il caso peggiore — un comando che **non parte** non fallisce, quindi
   non avviserebbe nessuno: quello che si sorveglia è l'età dell'ultima copia.
5. **Un indirizzo solo per tutti gli allarmi.** `OPS_ALERT_EMAIL` vale per il
   backup e per i controlli di stato; `App\Support\OpsAlerts` lo interpreta in
   un posto solo. È una **lista**, anche con un destinatario: `laravel-backup`
   valida ogni voce come indirizzo e rifiuterebbe la stringa vuota con
   un'eccezione all'avvio, mentre una lista vuota è una destinazione assente e
   Laravel salta il canale. Così «nessun indirizzo configurato» significa
   «nessun invio» e non «l'applicazione non parte» — che è ciò che tiene
   silenziosi sviluppo, test e integrazione continua senza interruttori.
6. **`/stato` è chiuso di default, non aperto di default.** Il middleware è
   nostro e non il `RequiresSecretToken` del pacchetto, che senza chiave
   configurata lascia passare **chiunque**: un endpoint che si apre da solo
   quando qualcuno dimentica una riga di `.env` è un endpoint aperto, e questo
   elenca uno per uno i pezzi del sistema che stanno cedendo. Senza chiave
   risponde 404 — non 403, che confermerebbe l'esistenza dell'indirizzo — e il
   confronto è a tempo costante. La chiave si porta in `X-Secret-Token` o in
   query string, perché diversi servizi di uptime sanno interrogare solo un
   indirizzo.
7. **`/stato` va registrato prima del gruppo `/{city}`, e questa è la
   trappola.** Il secondo gruppo di `routes/public.php` monta la pagina
   iniziale su `/{city}`: una rotta di **un solo segmento**. Registrata dopo,
   `/stato` viene letta come la città «stato», e `ResolveCity` risponde 404 —
   un 404 che assomiglia in tutto a una rotta mancante, mentre `route:list` la
   mostra al posto giusto. Vale per ogni indirizzo di primo livello che
   nascerà da qui in avanti.
8. **Lo stato si rilegge, non si ricalcola.** `health:check` gira ogni cinque
   minuti dallo scheduler e salva; l'endpoint restituisce l'ultimo esito
   (`always_send_fresh_results` a `false`, che nonostante il nome è la chiave
   letta anche dai controller). Con un monitor che interroga ogni minuto la
   differenza è sette controlli al minuto per sempre, e un endpoint di sola
   lettura non deve poter diventare un carico. Il codice di risposta di un
   guasto è **503** e non il 200 predefinito del pacchetto: un monitor deve
   capirlo senza leggere il corpo.
9. **`schedule-monitor` registra, `health` avvisa.** Il pacchetto, da solo,
   scrive quando ogni comando è partito e finito e aspetta che qualcuno guardi;
   la notifica la porterebbe Oh Dear, che è un servizio esterno a pagamento
   fuori da questo stack. `App\Support\Health\ScheduledTasksCheck` è il ponte:
   legge il registro e lo traduce in uno stato che `health:check` sa mandare
   per email. «Smesso di girare» non è un tempo assoluto — per un comando
   mensile sarebbero trenta giorni e per uno ogni cinque minuti cinque — ma la
   prossima esecuzione prevista dalla propria espressione cron più la
   tolleranza. Il controllo fallisce **anche** quando un comando dello
   scheduler non è nel registro: è il caso peggiore, tutto verde perché nessuno
   sta guardando, e succede quando `schedule-monitor:sync` non gira dopo un
   rilascio che ha aggiunto comandi. Per questo il `sync` sta sia nel deploy
   sia nello scheduler. I due battiti di `health` (`queue-check` e
   `schedule-check`) sono gli unici comandi esclusi dalla sorveglianza:
   girano ogni minuto, scriverebbero da soli due terzi dello storico e dicono
   già, meglio, ciò che il registro direbbe di loro.
   Il backup **non** gira `runInBackground()`: in background lo scheduler non
   conosce il codice di uscita, e il monitor registrerebbe come riuscita ogni
   esecuzione, fallimenti compresi.
10. **Pennant ha due interruttori veri, e la verità sta nella riga.**
    `city-import` (ambito: la città) spegne l'import di §14.2 per una città
    sola — è il motivo per cui lo stack ha scelto il pacchetto, «accendere
    funzioni per singola città»; `newsletter` (ambito costante, non l'utente
    collegato, altrimenti ogni visitatore si porterebbe una riga in `features`
    per una risposta uguale per tutti) toglie il consenso dai tre moduli che lo
    chiedono e svuota la programmazione del giovedì.
    Il valore risolto da Pennant **resta memorizzato**: per questo la città
    accesa non si legge da `cities.settings`, che si potrebbe cambiare senza
    che nessuno se ne accorga. La verità è la riga in `features`, i valori di
    `config/pennant.php` sono solo ciò che vale per un ambito su cui nessuno ha
    ancora deciso, ed entrambi nascono accesi perché un interruttore introdotto
    spento cambia il prodotto di nascosto. `feature:set` esiste perché senza di
    esso spegnere una città richiederebbe un'espressione PHP scritta a mano su
    una macchina viva: rifiuta un nome inesistente, pretende la città dove
    serve e **rifiuta** `--city` dove non serve, invece di ignorarlo — chi lo
    scrive crede di spegnere una città sola e spegnerebbe il sistema intero.
    Spegnere la newsletter **non revoca** i consensi già dati: la casella
    sparisce dai moduli, e i controller smettono di leggerla invece di
    interpretarne l'assenza come un «no». Un consenso è un atto della persona,
    e cancellarlo al primo salvataggio di un altro campo sarebbe una revoca che
    nessuno ha chiesto.

**Conseguenze.** Nuovo `App\Providers\OperationsServiceProvider` (i controlli
di stato e la definizione degli interruttori stanno fuori da
`AppServiceProvider`: non dichiarano che cosa il prodotto fa, ma come ci si
accorge che ha smesso di farlo); nuovi `App\Support\Features`,
`App\Support\OpsAlerts`, `App\Support\Health\{ImportSourcesCheck,
ScheduledTasksCheck}`, `App\Http\Middleware\RequiresOpsToken`,
`App\Console\Commands\SetFeatureCommand`; nuove configurazioni `backup`,
`health`, `schedule-monitor`, `pennant`, `sentry`; nuovi `lang/it/health.php` e
`lang/it/operations.php`; tre migration di terze parti pubblicate verbatim
(D13), con l'aggiunta del solo `down()` dove lo stub non ce l'aveva; otto voci
nuove in `routes/console.php` e la direttiva Blade `@newsletter`.
Sentry è **spento senza DSN** e non è un accorgimento nostro: il provider del
pacchetto non registra nulla e il trasporto risponde «saltato» invece di aprire
una connessione. `send_default_pii` resta `false` e i parametri delle
interrogazioni SQL non escono (§16: «raccogliere il minimo»).

**Verificato.** 56 test nuovi in `tests/Feature/Operations/`, suite intera
verde (952), `pint` passato, `phpstan` livello 6 a zero errori. Il backup è
stato **eseguito davvero**, non solo testato: `backup:run` ha prodotto un
archivio da 126 MB con dump del database e `storage/app`, `backup:clean` lo ha
mantenuto e `backup:monitor` lo ha dichiarato sano. `health:check` percorre i
sette controlli e li salva; con `php artisan serve` e chiave in `.env`,
`/stato` risponde **404** senza chiave, 404 con chiave sbagliata e 503 con
chiave giusta e stato degradato, in JSON. `schedule-monitor:sync` registra
undici comandi e `schedule-monitor:list` ne mostra la prossima esecuzione.

## 2026-08-31 — D34. Pagine legali, consenso e analitica: undici scelte prese scrivendo §16

**Decisione.** Undici punti che §16 impone ma non descrive.

1. **Le pagine legali stanno nel database, non in Blade.** Tabella `pages`
   (`slug` unico, `title`, `excerpt`, `body`, `is_published`, campi SEO,
   `sort_order`) e una risorsa in `/admin`. La ragione non è la comodità: un
   errore in un'informativa privacy va corretto **oggi**, non al prossimo
   rilascio, e chi lo corregge non deve saper usare git. Sono anche gli unici
   testi del sito che una persona non tecnica deve poter riscrivere per intero.
2. **Il corpo è Markdown, e la conversione scarta la marcatura grezza.**
   `Str::markdown(..., ['html_input' => 'strip', 'allow_unsafe_links' => false])`
   sostituisce l'intero sanificatore che §16 chiede («whitelist, no `<script>`,
   no iframe arbitrari»): non c'è una lista di tag ammessi da tenere allineata
   nel tempo, perché **nessun** tag scritto nel campo sopravvive alla
   conversione, e `javascript:` in un collegamento perde l'indirizzo invece di
   restare cliccabile. Un editor ricco avrebbe salvato HTML, cioè avrebbe
   spostato il problema a valle su ogni lettura.
3. **Lo slug non insegue il titolo.** `doNotGenerateSlugsOnUpdate()`:
   l'indirizzo di un'informativa privacy finisce nei registri dei trattamenti e
   nelle email già spedite. Cambiare il titolo non deve trasformare quei
   collegamenti in 404. Resta modificabile a mano, ma è un gesto dichiarato.
4. **`/pagine/{slug}` sta fuori dai gruppi di città.** Stessa ragione della
   mappa del sito (D25, punto 6): un'informativa privacy per provincia non
   esiste, e `/padova/pagine/privacy` sarebbe lo stesso testo a un secondo
   indirizzo, cioè un doppione offerto all'indice.
5. **Una pagina non pubblicata è 404, non 403.** Una bozza di informativa non
   deve essere leggibile da chi ne indovina l'indirizzo, e non deve nemmeno
   rivelare di esistere.
6. **Il banner è un modulo vero, non un pannello disegnato dallo script.** §16
   chiede che «il rifiuto sia semplice quanto l'accettazione»: un banner che si
   chiude solo via JavaScript lascia a chi non lo esegue una striscia in fondo
   allo schermo per sempre, che è il modo più efficace di far premere «accetta».
   Senza script il modulo invia e la pagina si ricarica; con lo script la
   richiesta parte in sottofondo e il banner sparisce. **La parità è
   verificabile**: i due pulsanti prendono la classe da una variabile sola, e un
   test confronta le due stringhe invece di affidarsi alla rilettura di un diff.
7. **Le finalità sono due, e sono quelle vere.** `ConsentCategory` ha
   `necessary` e `statistics`. Non esiste una categoria «marketing» perché non
   esistono pubblicità né pixel; le date messe in agenda da chi non ha un
   account restano fra i **necessari** perché sono l'archetipo dell'eccezione
   dell'articolo 5(3) ePrivacy — storage richiesto esplicitamente dall'utente,
   come un carrello — e renderle rifiutabili avrebbe rotto §15.1 («il cuore deve
   funzionare al primo click») fingendo una scelta che non esiste.
8. **Conta il pulsante premuto, non lo stato del modulo.** Chi preme «Rifiuta»
   con una casella rimasta spuntata ha rifiutato. Leggere le caselle invece
   dell'azione è il modo esatto in cui un banner finisce per registrare il
   contrario di ciò che gli è stato detto; c'è un test dedicato.
9. **Il registro del consenso non contiene l'indirizzo IP.** `consent_logs`
   porta un identificativo casuale generato qui e conservato nel cookie
   (`consent_id`), la scelta per categoria, la versione dell'informativa e
   l'istante. Raccogliere l'IP per dimostrare il rispetto della privacy sarebbe
   l'unico dato personale introdotto da questa funzione. Le righe non si
   aggiornano mai: cambiare idea ne scrive una nuova **con lo stesso
   identificativo**, così il registro racconta una storia e non due scelte
   scollegate. `CONSENT_VERSION` che cambia riporta ogni scelta a «non ancora
   espressa» e fa ricomparire il banner: è il solo modo di far valere davvero un
   cambiamento delle finalità.
10. **La scelta entra nella chiave della full-page cache.** Il banner e lo
    script delle statistiche stanno **dentro** il documento: senza questa parte
    della chiave, il primo visitatore che accetta riempirebbe la cache di pagine
    con il contatore acceso e il banner assente, e quelle pagine verrebbero
    servite a chi non ha ancora scelto niente — un consenso preventivo che
    diventa il consenso di qualcun altro. Le varianti restano tre («non ha
    scelto», «ha accettato», «ha rifiutato») perché l'identificativo del browser
    resta fuori: dentro la chiave darebbe a ogni visitatore una copia sua, cioè
    nessuna cache. Per la stessa ragione `App\Support\Consent` lega la scelta
    ricordata **all'oggetto richiesta** che l'ha prodotta e non alla vita
    dell'istanza `scoped`: nei test HTTP e su un futuro server persistente il
    processo non muore fra una richiesta e l'altra, e la scelta di chi accetta
    resterebbe addosso al visitatore successivo.
11. **`ANALYTICS_*` vuote non significano contatore spento: significano
    nessuna riga.** Nessuno `<script>`, nessun `preconnect`, nessun commento —
    un test verifica che nell'HTML non compaia **alcun** indirizzo esterno,
    non solo che manchi il nome del fornitore. Configurata, parte comunque solo
    con il consenso, e chi non ha ancora scelto vale come chi ha rifiutato
    (preventivo). `AnalyticsProvider` sa che lo stesso valore si chiama
    `data-domain` per Plausible e `data-website-id` per Umami, così le variabili
    d'ambiente restano tre come chiesto.

**Conseguenze.** Nuove tabelle `pages` e `consent_logs`; nuovi enum
`ConsentCategory`, `ConsentAction`, `AnalyticsProvider`; permesso
`pages.manage` (amministratore e amministratore di sistema, **non** il
moderatore: §3 gli dà la moderazione dei contenuti altrui, non i testi con cui
il sito risponde davanti a un'autorità) con la `PagePolicy` corrispondente;
`config/consent.php`, `config/analytics.php`, `lang/it/consent.php`,
`lang/it/pages.php`; `App\Support\Consent`, `App\DTOs\ConsentState`,
`App\Actions\RecordConsent`, `App\Services\Analytics\AnalyticsScript`;
componenti `<x-cookie-banner>`, `<x-consent-preferences>`, `<x-analytics>`;
limitatore `consent` a 30 invii l'ora (i cinque dei moduli pubblici avrebbero
reso difficile la revoca, che è un diritto); `DateFormatter::instantDate()`.
Le cinque voci legali del piè di pagina non sono più stringhe in `lang/it/ui.php`
ma **le pagine pubblicate**, nel loro ordine: un collegamento a un'informativa
che nessuno ha ancora scritto sarebbe un 404 nel punto in cui un'autorità
guarda per primo.

**Due trappole trovate provando davvero.**

- **`form.action` non è l'indirizzo del modulo.** Un modulo che contiene un
  controllo chiamato `action` — e questo ne ha tre, i pulsanti di scelta —
  espone quel controllo al posto della propria proprietà: `form.action`
  restituisce una `RadioNodeList` che, concatenata, diventa
  `[object RadioNodeList]`. La richiesta partiva verso un indirizzo inesistente
  e l'unico segnale era un 404 in console; il ripiego sull'invio normale
  scattava, ma **senza il pulsante premuto** il campo `action` non viaggiava e
  il server rimandava indietro un errore di validazione. Il sintomo visibile era
  «premo Rifiuta e il banner resta lì». Si legge l'attributo, e il ripiego
  allega la scelta a mano.
- **Un permesso nuovo non è assegnato finché il seeder non rigira.** Con
  l'amministratore autenticato, `/admin/pages` rispondeva 403 in sviluppo mentre
  i test erano verdi — perché ogni test semina i ruoli da capo. È il caso già
  descritto in `RUNBOOK.md`, incontrato per la prima volta: **al rilascio va
  eseguito `db:seed --class=RolesAndPermissionsSeeder`**.

**Verificato.** 89 test nuovi (61 in `tests/Feature/Legal/`, più il pannello in
`PanelRenderingTest` e i casi editoriali via Livewire), suite intera verde,
`pint` passato, `phpstan` livello 6 a zero errori sui file di questa fase,
`npm run build` verde. Con `php artisan serve` e database seedato: le cinque
pagine rispondono 200 e uno slug inesistente 404; nell'HTML della pagina
iniziale e di `/eventi` gli unici indirizzi esterni sono le due attribuzioni
cartografiche, che sono collegamenti e non risorse caricate. In un browser a
390 px: banner in fondo che non copre la pagina (326 px su 844, nessun velo,
nessun blocco dello scorrimento, nessun trabocco orizzontale), i due pulsanti
con **la stessa classe, lo stesso sfondo e la stessa misura** (171×40),
«Rifiuta» raggiunto con **un solo tabulatore** da «Accetta» e attivato con
Invio: banner rimosso, pagina **non ricaricata**, riga `reject_all` nel
registro. Il dettaglio granulare (una casella sola) scrive `custom` con
`statistics: true`. Alla visita successiva il banner non torna; «Cancella la mia
scelta» dalla Cookie Policy lo fa tornare e il pannello dichiara di nuovo
«non hai ancora espresso una scelta». Zero errori in console.

---

## 2026-08-31 — D35. `/docs/api` in produzione: staff editoriale autenticato

**Decisione:** il cancello `viewApiDocs` (`AppServiceProvider`) apre `/docs/api` a
chi ha uno dei ruoli di redazione — `admin`, `super_admin`, `moderator`, cioè
esattamente chi entra in `/admin` — e nega a tutti gli altri, ospiti compresi.
In ambiente `local` il pacchetto lascia passare chiunque e questo resta.

**Perché così:** prima del cancello, Scramble negava a tutti fuori da `local`.
La documentazione era quindi al sicuro e inutile: nessuno poteva consultarla
sull'unico ambiente dove l'API vive davvero.

**Perché non una chiave in `.env`:** una chiave condivisa è un segreto che non
scade, che finisce in una chat e che non dice mai *chi* l'ha usata. Un ruolo si
toglie a una persona sola e ha effetto al caricamento successivo. La
documentazione descrive anche gli endpoint di scrittura e la forma degli errori:
non è un segreto, ma non è nemmeno un invito a inventariare la superficie.

**Conseguenza operativa:** dare a qualcuno la documentazione significa dargli un
ruolo di redazione. Se un giorno servisse aprirla a un collaboratore esterno
senza dargli `/admin`, la strada è un ruolo nuovo con i soli permessi di
lettura, non una chiave.

**Ricontrollo:** se nascerà un programma per sviluppatori terzi (§13 non lo
prevede in fase 1), servirà una pagina pubblica di documentazione — diversa da
questa, che è uno strumento interno.

---

## 2026-08-31 — D36. Ore di silenzio: 23:00-08:00 a chi non ha scelto

**Decisione:** `notifications.quiet_hours.default` vale `23:00`-`08:00` e si
applica a chi non ha mai espresso una preferenza. La colonna
`users.quiet_hours` distingue ora **tre** stati e non due:

| valore | significato | effetto |
|---|---|---|
| `null` | non ho ancora scelto | finestra predefinita |
| `{from, to}` | le mie ore | quelle |
| `[]` (array vuoto) | ho scelto di non averne | nessun silenzio |

**Perché si cambia idea rispetto a D31:** lì si era scritto che «un silenzio
imposto d'ufficio sposterebbe promemoria che nessuno ha chiesto di spostare».
È vero e resta vero, ma nasconde l'altra metà: **l'assenza di un valore
predefinito non è neutralità**. Chi non apre mai le preferenze — cioè quasi
tutti — riceveva la scelta peggiore per omissione, e la scopriva con una email
alle tre di notte. Fra le due asimmetrie, spostare un promemoria di qualche ora
è recuperabile in un clic; svegliare qualcuno no.

**Il terzo stato non è pedanteria:** senza di esso il valore predefinito
sarebbe **impossibile da spegnere** — svuotare i due campi riporterebbe a
`null`, cioè di nuovo al predefinito. Nel modulo lo esprime una casella
(«Nessuna ora di silenzio»); nell'API, `quiet_hours: {}`.

**API:** `GET /v1/me/notification-preferences` porta ora due campi.
`quiet_hours` è la **scelta** (può essere `null`), `quiet_hours_effective` è
ciò che il motore applica davvero. È un'aggiunta, non un cambio di contratto:
senza il secondo, un'applicazione direbbe «nessuna ora di silenzio» a chi le ha
eccome, e l'unico modo di accorgersene sarebbe un promemoria che non arriva.

**Cosa non cambia:** gli annullamenti e gli spostamenti ignorano il silenzio
(§15.4) — quella regola è del tipo di notifica, non della finestra. E mettere
`default` a `null` in configurazione riporta esattamente il comportamento
precedente.

**Ricontrollo:** se i dati d'uso mostreranno che la finestra predefinita fa
slittare troppi promemoria serali oltre la loro utilità, restringerla (per
esempio 00:00-07:00) invece di toglierla.

---

## 2026-08-31 — D37. Lighthouse in CI: soglie di §11.11 dichiarate, non ammorbidite

**Decisione:** il lavoro `lighthouse` di `.github/workflows/ci.yml` monta il
sito completo (MariaDB, seeder, coda delle immagini, nginx davanti a `php -S`)
e vi lancia `@lhci/cli` con le soglie di §11.11 così come sono scritte nel
piano: Performance / Accessibility / SEO `>= 0.90` e LCP `< 2000 ms`, tutte a
livello **`error`**, su tre indirizzi — pagina iniziale, una lista, una scheda
evento.

**Le soglie non sono state adattate a ciò che passa.** Ecco cosa misurano
oggi, e le due colonne non coincidono:

| pagina | banco di prova (nginx + `php -S`) | produzione (`eventi.fabiodalez.it`) |
|---|---|---|
| `/` | perf 0.86 · a11y 0.96 · SEO 1.00 · **LCP 4.1 s** | perf 0.95 · a11y 0.96 · SEO 1.00 · **LCP 2.8 s** |
| `/eventi` | perf 0.87 · a11y 0.96 · SEO 1.00 · **LCP 3.9 s** | perf 0.99 · a11y 0.96 · SEO 1.00 · **LCP 2.0 s** |
| scheda evento | perf 0.96 · a11y 0.97 · SEO 1.00 · **LCP 2.6 s** | perf 0.94 · a11y 0.97 · SEO 1.00 · **LCP 2.1 s** |

Accessibilità e SEO passano ovunque. Le altre due no, per due motivi distinti:

1. **Il banco di prova resta più lento della produzione** di circa un secondo e
   mezzo di LCP, e la causa è una sola: parla **HTTP/1.1**. Senza certificati
   Chrome non negozia HTTP/2 in chiaro, quindi le sei connessioni fanno la coda
   che in produzione non esiste. È il motivo per cui la produzione prende 0.95
   dove il banco prende 0.86. Non è aggirabile senza mettere TLS nel runner.
2. **LCP < 2 s non è raggiungibile oggi in nessuno dei due**, e il referto di
   produzione dice perché: il solo TTFB vale fra 0,6 e 3,0 secondi. Su hosting
   condiviso cPanel, senza Redis (D5) e con il worker rilanciato al minuto dal
   cron (RUNBOOK), buona parte del budget di due secondi se ne va prima che
   arrivi un byte. La seconda voce è la locandina: sulla pagina iniziale il
   referto mostra l'elemento LCP con `loading="lazy"` e 1,4 s di *load delay* —
   la prima riga di card è marcata `eager` ma sul viewport mobile l'elemento
   più grande è un'altra, in una sezione più in basso.

**Perché non si abbassano comunque:** una soglia allineata a ciò che già passa
non misura niente. §11.11 è un obiettivo di prodotto e resta scritto dov'è, con
il referto allegato a ogni esecuzione a dire quanto manca.

**Conseguenza, dichiarata:** il lavoro `lighthouse` è **rosso** finché LCP resta
sopra i due secondi. Per questo `deploy` continua a dipendere da `quality` e
`tests` e **non** da `lighthouse`: un criterio che nessun ambiente soddisfa
ancora non deve tenere in ostaggio ogni rilascio. Il giorno in cui LCP scende
sotto i 2 s, aggiungere `lighthouse` a `needs:` è una riga.

**Le due cose da fare, in ordine di resa:** correggere quale locandina riceve
`eager`/`fetchpriority=high` sul viewport mobile (vale circa 1,4 s sulla pagina
iniziale) e ridurre il peso delle varianti AVIF, che escono da Imagick a 90-240
KB per una locandina da 800 px — `uses-responsive-images` segnala 113-118 KiB
sprecati su ogni pagina.

**Dettagli del banco di prova che sembrano trascurabili e non lo sono:**

- nginx davanti serve compressione e `Cache-Control` sui file statici: senza,
  l'HTML viaggia a 159 KB invece di 35 e la misura boccia il server di
  sviluppo, non il sito;
- le varianti WebP e AVIF nascono da lavori in coda (§12.1): senza
  `queue:work --stop-when-empty` dopo il seeder, il sito serve le locandine
  originali e la Performance scende da 0.86 a 0.75;
- l'applicazione si avvia con `php -S` e non con `php artisan serve`:
  quest'ultimo legge lo standard output del figlio attraverso una pipe che si
  rompe quando finisce il passo del workflow, e da lì in poi ogni risposta
  esce con un `Notice: Broken pipe` prima del `<!doctype>`. Costava diciassette
  punti di SEO e sembrava un difetto del sito;
- lo slug dell'evento da misurare si pesca dal feed RSS a server acceso: nasce
  dal seeder e cambia a ogni esecuzione.

**Ricontrollo:** alla prima delle due correzioni di cui sopra, e comunque al
passaggio a un VPS (D3), che sposta il TTFB.

---

## 2026-08-31 — D38. Archiviare significa uscire dalle liste, non dal sito

**Decisione:** il comando `events:archive`, ogni notte alle 04:10, porta a
`archived` gli eventi **pubblicati** che non hanno più nemmeno una data dal
giorno di taglio in poi — novanta giorni fa, configurabili con
`EVENTS_ARCHIVE_AFTER_DAYS` o con `--days`.

**La regola è scritta al contrario di come suona.** Non «l'ultima occorrenza è
vecchia» ma «non esiste alcuna occorrenza recente o futura»: formulata così, una
rassegna cominciata due anni fa e ancora in cartellone non viene toccata, e
nemmeno una ricorrenza settimanale con una sola data residua. La prima
formulazione le avrebbe archiviate entrambe.

Il taglio si conta **nel fuso della città** e sulla `business_date`, non
sull'ora di inizio: un concerto che finisce alle 3:00 appartiene alla sera
prima, e archiviarlo un giorno in anticipo si vedrebbe. Gli eventi **senza
alcuna data** restano dove sono: non sono scaduti, sono incompleti, ed è
un'altra riga della dashboard qualità (§14.5).

**La metà che conta di più:** un evento archiviato **non diventa un 404**. §11.9
fonda sull'archivio degli eventi passati una parte della ricerca organica, e una
pagina che ha ricevuto visite per un anno non si butta via. Quindi:

| dove | prima | dopo l'archiviazione |
|---|---|---|
| liste, mappa, calendario, feed, finestre di §8 | c'è | **non c'è** |
| scheda `/eventi/{slug}` | 200 | **200** |
| archivio della scheda locale (§11.9) | c'è | **c'è** |
| `sitemap.xml` | c'è | **c'è** |
| `GET /v1/events/{slug}` | 200 | **200** |
| `GET /v1/events` (lista) | c'è | **non c'è** |
| modulo di segnalazione della scheda | aperto | **aperto** |

Tecnicamente sono due cose: lo scope `Event::readable()` (pubblicati **e**
archiviati), che sostituisce `published()` dove si legge *una* scheda, e
`EventOccurrenceQuery::archiveFor()`, gemello di `for()` che allarga la base
agli archiviati. Le finestre pubbliche continuano a passare da `for()`, che
resta ristretto ai soli pubblicati: è lì che l'archiviazione ha effetto.

**Aggiornamento di massa e non un salvataggio per modello:** l'observer
rimetterebbe in coda un'anteprima social per ciascuno — il file non cambia,
cambia lo stato — e su un archivio arretrato sarebbero centinaia di lavori
inutili. L'unica cosa dell'observer che qui serve è l'invalidazione della cache
della città, ed è una riga.

**Ricontrollo:** se novanta giorni si riveleranno pochi per il traffico
organico, la leva è una variabile d'ambiente.

## 2026-09-01 — D39. Il backup di §16 è dichiarato valido: restore reale eseguito

**Decisione:** il backup di `spatie/laravel-backup` si considera valido, perché
il criterio di §16 — «un backup non è valido finché non è stato testato un
restore reale» — è stato soddisfatto con una prova completa, non con la sola
riga «Backup verified» del comando (che verifica lo zip, non il ripristino).
**Come:** `backup:run --only-db` su database seedato → dentro lo zip
(`storage/app/private/eventi/…zip`, 57 kB) il dump
`db-dumps/mariadb-<db>.sql.gz` → `gunzip -c | mysql` su un database di prova
nuovo → confronto: 47 tabelle su 47 e conteggi identici (locali 25, eventi 138,
occorrenze 145, utenti 5, pagine 5) → database di prova eliminato. Procedura
scritta nel RUNBOOK («Restore del database»).
**Limite dichiarato:** la prova è avvenuta in locale (MariaDB 12.3). In
produzione (MariaDB 10.11, `mysqldump` di cPanel) va ripetuta dopo il primo
`backup:run` notturno: stessa procedura, database `fabiodal_evtest`.
**Numeri della stessa verifica (2026-09-01):** 1064 test / 3570 asserzioni
verdi (781 → +283 con F10), Pint pulito, PHPStan livello 6 a 0 errori,
Lighthouse sul banco locale `/` 0.91/0.96/1.00 LCP 3,4 s · `/eventi`
0.80/0.96/1.00 LCP 5,2 s · scheda 0.96/0.97/1.00 LCP 2,7 s — soglie di D37
non toccate, lavoro `lighthouse` rosso come dichiarato.
**Ricontrollo:** dopo il primo backup notturno in produzione, e a ogni modifica
di `config/backup.php` o della pipeline di deploy che tocchi
`storage/app/private/`.

## 2026-09-01 — D40. La sezione viva si disegna dal server, cache di pagina a un minuto

**Decisione:** `<livewire:live-now />` senza `lazy`, e `page_cache.ttl_minutes`
sceso da 5 a 1.

**Perché:** §12.3 la voleva differita per non mettere in cache una sezione che
cambia ogni minuto, e argomentava che l'alternativa avrebbe fatto crollare il
TTFB. La misura dice altro: **generare la homepage per intero costa fra i 200 e
i 263 ms** su questo server. Con la finestra di cache a un minuto la sezione è
sempre fresca e il TTFB resta dove era.

Differirla costava molto più di quanto facesse risparmiare. La locandina di
"In corso adesso" è la prima immagine grande della pagina: aspettare il giro di
Livewire spostava il momento in cui compare, e l'LCP ne pagava il conto.

**Misurato su https://eventi.fabiodalez.it, mediana di tre giri con cache calda:**

| | Prima | Dopo |
|---|---|---|
| Performance | 0.88 | **0.92** |
| Accessibility | 0.96 | 0.96 |
| SEO | 1.00 | 1.00 |
| CLS | 0.032 | 0.031 |
| LCP | 3695 ms | 3320 ms |

**Ricontrollo:** se la generazione della pagina dovesse superare i 500 ms —
più contenuti, più città, un server più carico — la scelta va rifatta, perché è
il numero su cui poggia.

## 2026-09-01 — D41. L'LCP resta sopra la soglia di §11.11

**Stato:** LCP 3320 ms contro i 2000 ms richiesti da §11.11. Le altre tre
soglie sono soddisfatte (Performance 0.92, Accessibility 0.96, SEO 1.00).

**Cosa è già stato fatto:** preload dell'immagine giusta (era quella sbagliata:
si annunciava la locandina di "Stasera" mentre la prima visibile è quella di
"In corso adesso"), locandina servita in variante piccola su telefono, sezione
viva disegnata dal server. Insieme hanno portato Load Delay e Load Time a
zero — l'immagine, quando serve, è già lì.

**Cosa resta:** 2774 ms di *render delay*, cioè il tempo fra "il pixel è
pronto" e "il browser lo dipinge". Non è più un problema di rete: è il costo
di eseguire CSS e JavaScript su una CPU rallentata quattro volte, che è come
Lighthouse simula un telefono di fascia media.

**Perché non si abbassa la soglia:** una soglia truccata dà la stessa
sensazione di sicurezza senza il contenuto. Il numero resta rosso e visibile.

**Le strade praticabili, in ordine di resa:**
1. Ridurre il JavaScript iniziale: Livewire e Alpine si caricano su ogni
   pagina, anche dove non c'è niente di interattivo.
2. CSS critico in linea e il resto differito (il foglio pesa 67 kB).
3. Un server che non sia shared hosting: il TTFB simulato di 631 ms parte da
   qui.

Nessuna delle tre è un ritocco: sono lavori a sé, da valutare con il committente.

## 2026-09-01 — D42. Installer web: architettura decisa, implementazione a seguire

**Decisione.** Un wizard di installazione web a passi, modellato su un installer
esistente giudicato ottimo dal committente, adattato a Laravel. Sei domande
chiuse qui; chi implementa segue queste scelte e l'elenco dei file in coda.

1. **Dentro il framework, senza eccezioni — e un cartello per chi arriva senza
   `vendor`.** L'installer è rotte e controller Laravel normali: CSRF,
   validazione via Form Request, traduzioni in `lang/it/installer.php`,
   sessioni e PRG arrivano gratis, e ogni riga di logica sta in
   `app/Services/Installer` come impongono le convenzioni. L'alternativa — un
   installer autonomo che gira senza `vendor` — duplicherebbe scrittura del
   `.env`, validazione e messaggi in un file fuori da ogni regola del repo, per
   coprire un caso che qui non esiste: `public/index.php` richiede
   `vendor/autoload.php` alla riga 14, quindi senza `vendor` non parte
   *niente*, installer autonomo o no, e il deploy ufficiale (CI) porta sempre
   `vendor` già costruito con `--no-dev`. Chi installa a mano riceve però una
   risposta e non un fatal error bianco: in testa a `public/index.php` va un
   guard di poche righe in PHP puro — se `vendor/autoload.php` manca, pagina
   statica con il comando `composer install --no-dev --optimize-autoloader`
   copiabile, e basta. Non è un installer parallelo: è un cartello.
   Due conseguenze tecniche da non dimenticare:
   - **Le rotte dell'installer forzano `session.driver = file`** sul proprio
     gruppo: `SESSION_DRIVER=database` è il default del progetto, ma durante
     l'installazione il database non esiste ancora. Cache è già `file` (D5).
   - **`APP_KEY` si genera prima che il wizard parta**, non alla fine: senza
     chiave niente sessioni cifrate né CSRF. Il punto d'ingresso, se `.env`
     manca lo copia da `.env.example`, e se `APP_KEY` è vuota la genera e la
     scrive (atomicamente, vedi punto 3) prima del primo redirect.

2. **Il lock è `storage/app/private/install.lock` più il database, e chi era
   già installato si auto-marca.** Il file marker (JSON: data, versione app,
   versione schema) vive in `storage/app/private/` per tre ragioni verificate:
   è fuori dalla document root (`public/`), è già nel `.gitignore` di
   `storage/`, e il deploy lo esclude esplicitamente dal `rsync --delete`
   (riga `--exclude 'storage/app/private/*'` di `ci.yml`, la stessa che
   protegge i backup) — un rilascio non lo cancella né lo sovrascrive mai.
   Ma il file da solo non basta, in nessuna direzione:
   - **Marker presente, database rotto:** l'installer non risponde "già
     installato" e basta — ricarica la configurazione, riprova la connessione
     e mostra una **diagnosi**: quale pezzo manca (connessione rifiutata,
     database sparito, tabelle attese assenti) con la soluzione accanto. Il
     dettaglio PDO va nel log, mai in pagina (vedi punto sui messaggi).
   - **Marker assente, database vivo:** è il caso di eventi.fabiodalez.it, che
     è in produzione da *prima* che l'installer esistesse. Il middleware che
     protegge le rotte, se trova la connessione configurata e la tabella
     `migrations` popolata, **scrive il marker da sé** e risponde 404: il
     primo deploy che porta l'installer non deve mostrare un wizard di
     installazione a un sito installato. Nessun percorso di reinstallazione:
     reinstallare un sistema vivo è un gesto da RUNBOOK e da SSH, non da
     endpoint web non autenticato.
   - Le rotte (`/installazione/...`) vanno registrate **prima** del gruppo
     `/{city}` in `routes/web.php`, o `ResolveCity` le mangia come città
     inesistente — è la trappola documentata in D33, punto 7.

3. **Sette passi, tredici domande: si chiede solo ciò che nessun default può
   sapere.** Delle 91 variabili di `.env.example` se ne chiedono ~13, se ne
   generano 2, e le altre ~76 si scrivono da sole con il valore di
   `.env.example` — che in questo progetto è già la configurazione di
   produzione corretta, perché ogni integrazione esterna nasce spenta con
   "vuoto = spento" (Turnstile, Sentry, analitica, S3, CDN: D33, D34, RUNBOOK).
   I passi, in sessione con `completed_steps` e anti-salto (chi chiede il passo
   N senza aver completato N-1 torna al primo incompleto), POST-redirect-GET
   ovunque:
   1. *Benvenuto e requisiti* — la tabella bloccanti/avvisi del punto 4.
   2. *Database* — host, porta, nome, utente, password. La connessione si
      prova **senza** il nome del database prima, e col nome poi: "credenziali
      sbagliate" e "database inesistente" sono due errori diversi con due
      messaggi diversi, e per il secondo si tenta `CREATE DATABASE` con le
      stesse credenziali prima di chiedere di crearlo dal pannello hosting.
      Endpoint con throttle (`throttle:10,1`): è un tester di connessioni
      verso host arbitrari, non autenticato — il difetto peggiore del modello
      analizzato era lasciarlo libero.
   3. *Applicazione* — `APP_NAME`, `APP_URL` (precompilato dall'host della
      richiesta), email: `MAIL_MAILER` con `log` come ripiego dichiarato,
      host/porta/credenziali/`MAIL_FROM_*` se SMTP. `APP_ENV=production` e
      `APP_DEBUG=false` si scrivono da soli, non si chiedono.
   4. *Città* — punto 6.
   5. *Amministratore* — nome, email, password. `OPS_ALERT_EMAIL` viene
      precompilata con questa email: vuota nessun allarme parte (RUNBOOK), e
      un sistema appena installato che tace i propri guasti è il default
      sbagliato. Resta modificabile.
   6. *Esecuzione* — non un passo monolitico che va in timeout sui database
      lenti: una checklist dove **ogni operazione è un POST proprio**,
      idempotente, che avanza da solo (e senza JavaScript col pulsante
      "continua"): scrittura `.env` → `migrate --force` → verifica tabelle →
      seed (punto 5) → città e amministratore → `storage:link` →
      `config:cache`. Le migrazioni e i comandi girano **in-process con
      `Artisan::call()`**, mai con un processo shell: la password del database
      non deve comparire in `ps`, e sulla shared hosting `proc_open` può
      essere spento — due ragioni indipendenti, stessa scelta.
   7. *Fine* — riepilogo e, in evidenza, **le due righe di cron da installare
      a mano** (`schedule:run` e `queue:work`, quelle del RUNBOOK): un
      installer web su shared hosting non può scriverle, e senza di esse
      scheduler, code, notifiche, import e backup non girano. Mostrate
      copiabili, con l'avviso esplicito. Più l'elenco delle integrazioni
      rimaste spente (Turnstile, Sentry, analitica, S3) con il rimando al
      RUNBOOK.
   Il `.env` si scrive **una volta, atomicamente**: contenuto completo
   costruito in memoria dal template `.env.example`, `file_put_contents` su
   file temporaneo nella stessa directory, `rename()`, `chmod 600`. I valori
   passano dalla funzione di quoting del modello analizzato (spazi, `#`, `=`,
   apici, backslash, `$`, backtick — una password con un cancelletto scritta
   nuda tronca il valore e il sintomo arriva giorni dopo); le sostituzioni
   successive di singole chiavi usano `preg_replace_callback`, mai
   `preg_replace` (una password contenente `$1` corromperebbe la
   sostituzione). Generate, mai chieste: `APP_KEY` e `OPS_HEALTH_TOKEN`
   (base64 di 32 byte casuali, come `key:generate --show`).
   Dopo `migrate`, le tabelle attese si **verificano davvero**: l'elenco si
   deriva dai file di `database/migrations` (i nomi passati a
   `Schema::create`) e si confronta con `Schema::getTableListing()` — oggi
   sono 37 migration; un elenco scritto a mano mentirebbe alla prima
   migration nuova.

4. **Blocca solo ciò che impedisce di funzionare; tutto il resto è un avviso
   con la soluzione accanto.**
   *Bloccanti:* PHP < 8.4; le estensioni senza le quali il core non parte o
   il database non risponde — `pdo` + `pdo_mysql`, `mbstring`, `openssl`,
   `curl`, `dom`, `fileinfo`, `intl`, `bcmath`, `json` — **né `gd` né
   `imagick`** disponibili (senza alcun motore immagini l'intera pipeline
   media di §12.1 muore, e le locandine sono il prodotto); `storage/` o
   `bootstrap/cache/` non scrivibili dopo il tentativo di riparazione; `.env`
   non scrivibile (l'installer esiste per scriverlo). La riparazione dei
   permessi si tenta da sola con `chmod` **0775/0664, mai 0777**; se fallisce,
   i comandi da dare a mano compaiono copiabili.
   *Avvisi (l'installazione procede, la funzione interessata è nominata):*
   `imagick` assente con `gd` presente → si scrive `IMAGE_DRIVER=gd` da soli
   e si dichiara cosa si perde (HEIC e AVIF, come documenta `.env.example`);
   `exif` assente → orientamento delle foto; `zip` assente → i backup di §16
   non si creeranno (`ZipArchive`), con l'istruzione per abilitarla;
   `symlink()` non disponibile o fallito → le immagini non si servono finché
   non si crea `public/storage` a mano, comando mostrato;
   `upload_max_filesize`/`post_max_size` sotto i 12 MB delle locandine
   (§12.1) → il caricamento dei manifesti grandi fallirà, con il valore
   attuale, quello richiesto e dove cambiarlo (MultiPHP INI su cPanel).
   Un installer che blocca su un avviso non fa installare nessuno; uno che
   avvisa su un errore fatale fa installare tutti male. La riga di confine è:
   *il sito, dopo, risponde?*
   *Messaggi:* al client sempre il messaggio utile con la soluzione e il
   pulsante per riprovare; il dettaglio tecnico (l'eccezione PDO, il codice
   errore) va in `storage/logs/laravel.log`. Un errore PDO in pagina dice a
   un estraneo quali host e porte rispondono: l'endpoint è pubblico per
   costruzione, non deve raccontare la topologia.

5. **Il seed di installazione è un seeder nuovo, fatto dei soli quattro che
   vivono senza faker.** `fakerphp/faker` è in `require-dev` e in produzione
   non esiste (`composer install --no-dev`): è già costato un guasto.
   Verificato seeder per seeder su un database pulito:
   - **senza faker:** `RolesAndPermissionsSeeder` (6 ruoli, 27 permessi),
     `CategorySeeder` (14), `TagSeeder` (38), `PageSeeder` (5 pagine legali)
     — nessuno usa `fake()` né factory, tutti eseguiti con successo;
   - **richiedono faker:** `EventSeeder` (chiama `fake()` direttamente) e
     `CitySeeder`, `UserSeeder`, `VenueSeeder`+`MediaSeeder` nel loro insieme
     demo — i primi due passano da factory le cui `definition()` chiamano
     `fake()` anche quando ogni attributo è esplicito.
   Nasce `Database\Seeders\ProductionSeeder` che chiama i quattro sicuri,
   nell'ordine di `DatabaseSeeder`; l'installer invoca quello, e resta
   utilizzabile a mano (`db:seed --class=ProductionSeeder --force`).
   **Nessun dato dimostrativo dall'installer**, nemmeno come opzione: la demo
   è `migrate:fresh --seed` in sviluppo, dove faker c'è. Un'opzione "dati di
   esempio" che funziona in locale e crasha in produzione è esattamente il
   guasto già pagato. Città e amministratore non sono seed: sono i dati del
   wizard (punto 6), scritti con `City::create()` e `User::create()` +
   `assignRole()` diretti, senza factory — verificato che funzionano così.

6. **La città si chiede al wizard, le coordinate si incollano: nessun servizio
   di geocodifica.** L'applicazione non esiste senza una città attiva (§7.1).
   Il passo 4 chiede: nome, provincia (sigla e nome), regione, fuso orario
   (select da `DateTimeZone::listIdentifiers`, preselezionato `Europe/Rome`),
   coordinate del centro (due campi decimali con limiti di validità lat/lng),
   raggio in km (default 30, il default dello schema). Slug derivato dal nome
   e modificabile. Tutto il resto prende i default di schema: zoom 12,
   `night_cutoff_time` 06:00, `starting_soon_minutes` 180, `locale` it,
   `is_active` true, `launched_at = now()`. Per trovare le coordinate, lo
   stesso pattern già deciso in D24 punto 4 per il pannello: un collegamento a
   OpenStreetMap che **parte solo se cliccato** (nessun trasferimento di dati
   verso terzi a ogni apertura), con l'istruzione "cerca il tuo comune,
   copia latitudine e longitudine dall'indirizzo". Un campo di ricerca che
   interroga Nominatim sarebbe più comodo e introdurrebbe una dipendenza
   esterna nel momento in cui il sistema è più fragile — durante
   l'installazione — oltre che un trasferimento non necessario. `bounds`
   resta nullo (la mappa parte da centro e zoom, D26 punto 5) e
   `CITY_DEFAULT_SLUG` nel `.env` prende lo slug della città creata.

**Conseguenze — i file che chi implementa creerà.**
- `routes/installer.php`, incluso da `routes/web.php` **prima** del gruppo `/{city}`;
- `app/Http/Middleware/InstallerGate.php` — marker, auto-marcatura del già
  installato, diagnosi col database rotto;
- `app/Http/Controllers/Installer/InstallerController.php` — orchestrazione
  sottile dei passi, PRG;
- `app/Http/Requests/Installer/{DatabaseStepRequest,ApplicationStepRequest,CityStepRequest,AdminStepRequest}.php`;
- `app/Enums/{InstallerStep,InstallerTask}.php` — i passi e le operazioni
  della checklist di esecuzione, mai stringhe magiche;
- `app/Services/Installer/InstallerState.php` — stato in sessione,
  `completed_steps`, anti-salto;
- `app/Services/Installer/RequirementsChecker.php` — la tabella
  bloccanti/avvisi del punto 4, con i tentativi di riparazione;
- `app/Services/Installer/DatabaseInspector.php` — doppio test di
  connessione, `CREATE DATABASE`, verifica tabelle derivata dalle migration;
- `app/Services/Installer/EnvWriter.php` — quoting, scrittura atomica,
  `chmod 600`, `preg_replace_callback`;
- `app/Services/Installer/InstallLock.php` — lettura/scrittura del marker;
- `app/Actions/Installer/RunInstallationTask.php` — esegue una singola
  operazione della checklist via `Artisan::call()`, idempotente;
- `database/seeders/ProductionSeeder.php` — i quattro seeder senza faker;
- `resources/views/installer/` — layout e viste dei passi, senza dipendenze
  dagli asset compilati del sito (l'installer deve disegnarsi anche se
  `npm run build` non è mai girato su quella macchina: CSS minimo inline);
- `lang/it/installer.php` — ogni testo del wizard;
- il guard in testa a `public/index.php` (modifica, non file nuovo);
- `tests/Feature/Installer/` — anti-salto, PRG, lock nelle due direzioni,
  auto-marcatura, quoting del `.env` (password con `#`, `$1`, apici),
  distinzione dei due errori di connessione, bloccanti contro avvisi,
  seed senza faker.

**Verificato (per questa decisione, il codice non esiste ancora).** Su un
database pulito (`eventi_test_d42`, poi eliminato): le 37 migration passano;
`RolesAndPermissionsSeeder`, `CategorySeeder`, `TagSeeder`, `PageSeeder`
eseguiti con successo e righe contate (6/27/14/38/5); `City::create()` e
`User::create()` + `assignRole()` funzionano senza factory. Contate 91
variabili in `.env.example`. Verificate le esclusioni del `rsync --delete` in
`ci.yml`: `storage/app/private/*` non viene mai toccata dal deploy, quindi il
marker sopravvive ai rilasci. Verificato in `routes/web.php` che il gruppo
`/{city}` è registrato dopo le rotte di primo livello (`/stato`): l'installer
seguirà lo stesso ordine.

## 2026-09-01 — D43. Le password restano su bcrypt

**Decisione:** l'hashing resta quello predefinito di Laravel (bcrypt). Argon2id,
nominato da §16, non viene adottato ora.

**Perché:** decisione esplicita del committente. Non è una svista: cambiare
algoritmo su un'applicazione che ha già utenti non è una riga di configurazione.
Gli hash esistenti restano bcrypt e non sono convertibili senza la password in
chiaro, che nessuno ha; l'unica migrazione possibile è il **rehash al primo
accesso riuscito**, che va scritto, testato e presidiato — e lascia comunque
gli account dormienti sul vecchio algoritmo finché non tornano.

**Cosa NON cambia:** bcrypt con il costo di default resta un algoritmo adeguato
per le password. Non è una scorciatoia insicura: è una scelta diversa da quella
che il piano suggeriva, presa sapendo cosa comporta.

**Quando riprenderla:** prima di aprire le registrazioni al pubblico su scala,
quando il costo del rehash progressivo si paga una volta sola e su pochi utenti.
Serve: `config/hashing.php` su argon2id, e un rehash al login dentro
`Illuminate\Auth\Events\Login` che riconosce gli hash vecchi con
`Hash::needsRehash()`.

## 2026-09-01 — D44. Installer web: attuazione di D42, e le cinque cose che si sono scoperte scrivendolo

**L'installer di D42 esiste ed è stato provato installando davvero.** Il
disegno è rimasto quello; qui stanno le sole cose che il disegno non poteva
sapere, ciascuna scoperta eseguendo e non leggendo.

1. **Il nome del cookie di sessione dipende da `APP_NAME`, e il wizard scrive
   `APP_NAME` a metà installazione.** `config/session.php` lo calcola come
   `Str::slug(APP_NAME).'-session'`. Alla prima richiesta dopo la scrittura del
   `.env` il cookie cambia nome, il browser continua a mandare quello vecchio,
   Laravel apre una sessione nuova e vuota: **419 CSRF token mismatch**, tutte
   le risposte perdute, installazione da rifare. Non è un'ipotesi: è successo
   al primo giro sul banco di prova, esattamente sulla prima operazione della
   checklist. `InstallerSession` fissa ora anche `session.cookie` a
   `installazione-session`, che non dipende dal nome che si sta scegliendo.
   Il test di regressione è in `InstallerWizardTest`.
2. **L'anti-salto deve stare in un middleware, non nel controller.** Una Form
   Request valida *prima* che il metodo del controller cominci: un controllo
   scritto lì arriva dopo, e un POST al passo della città senza database
   risponde con sette errori di validazione invece del rimando al passo che
   manca davvero. Nasce `app/Http/Middleware/InstallerStepOrder.php`, che legge
   il passo dal secondo segmento dell'indirizzo — dal nome della rotta non si
   può, i POST non ne hanno uno.
3. **`CategorySeeder` e `TagSeeder` non erano idempotenti.** Usavano `create()`:
   una seconda esecuzione produceva 28 categorie e 76 tag. D42 pretende che
   *ogni* operazione della checklist sia ripetibile senza danno — un secondo
   clic, un ricaricamento, un ritentativo dopo un timeout — e la stessa cosa
   vale per il `db:seed --class=ProductionSeeder --force` documentato per la
   mano. Entrambi passano ora da `firstOrCreate` sul nome, come già facevano
   `RolesAndPermissionsSeeder` e `PageSeeder`.
4. **Lo slug della città scelto a mano veniva sovrascritto.** Il trait `HasSlug`
   lo rigenera dal nome sia in creazione sia in aggiornamento, quindi passarlo
   fra gli attributi non serve e ripassarlo con `save()` viene sovrascritto una
   seconda volta: si scrive con `City::query()->whereKey(...)->update()`, che
   non passa dagli eventi del model. Il campo è anche diventato **facoltativo**
   e si ricava dal nome lato server: derivarlo con un po' di JavaScript avrebbe
   significato scoprire, durante un'installazione, che un campo obbligatorio si
   riempiva da solo e non l'ha fatto.
5. **`config:cache` in-process scrive la configurazione giusta.** Il dubbio era
   fondato — `Env::getRepository()` è *immutable* — ma la protezione di
   phpdotenv vale solo per le variabili definite dall'ambiente esterno: quelle
   caricate da dotenv stesso vengono sovrascritte da un secondo caricamento.
   Verificato due volte: con una prova isolata sul repository di `Env`, e sul
   banco, dove `bootstrap/cache/config.php` è uscito con `app.env=production`,
   `app.debug=false` e il database appena dichiarato. Nessuna deviazione da D42.
   L'unica conseguenza è sui **test**: l'operazione `cache` non viene eseguita
   dalla suite, perché compilerebbe la configurazione *della suite* — ambiente
   `testing`, database dei test — dentro il `bootstrap/cache/config.php` della
   macchina che sta eseguendo i test. Nel caso di prova che arriva in fondo è
   dichiarata già fatta, ed è verificata a mano sul banco.

**Il banco di prova.** Copia dell'applicazione in una directory temporanea
(`rsync` senza `.git`, `node_modules`, `vendor`, `.env` e i contenuti di
`storage/`), `vendor` collegato con un symlink, `php -S` sulla 8099, wizard
percorso con `curl` estraendo il token CSRF da ogni pagina. È l'unico modo di
provare un installer: la suite non può cancellare il `.env` della macchina su
cui gira.

**MISURATO sul banco, a installazione conclusa** (database `eventi_sandbox`,
poi eliminato): 47 tabelle create; 14 categorie, 38 tag, 5 pagine, 27 permessi,
6 ruoli; una città `padova` attiva con fuso `Europe/Rome`, raggio 30 km e
coordinate 45.4064/11.8768; un utente con `super_admin` ed email già
verificata; `.env` a `-rw-------` con `APP_ENV=production`, `APP_DEBUG=false`,
`IMAGE_DRIVER=imagick`, `CITY_DEFAULT_SLUG=padova`, `OPS_ALERT_EMAIL`
precompilata e `OPS_HEALTH_TOKEN` generata, mentre `TURNSTILE_SITE_KEY` è
rimasta vuota; marcatore scritto con la migrazione più recente;
`public/storage` collegato. Poi: `/` 200, `/pagine/privacy` 200,
`/admin/login` 200, `/robots.txt` 200, e `/installazione/*` **404** da una
sessione nuova.

**MISURATE anche le tre strade storte**, sempre sul banco: (a) prima
esecuzione con il `.env.example` che punta a un database esistente e popolato →
il cancello si è **auto-marcato** e ha risposto 404, che è il caso di
eventi.fabiodalez.it; (b) database eliminato con il marcatore presente →
**503** con la diagnosi «la connessione non si apre» e i comandi da dare, non
«già installato»; database ricreato vuoto → **503** con «il database è vuoto,
non c'è nemmeno la tabella delle migrazioni»; (c) `vendor/` rinominata →
**503** con `composer install --no-dev --optimize-autoloader` copiabile, dal
guard in PHP puro di `public/index.php`, che nella stessa occasione ha creato
il `.env` da `.env.example` con `APP_KEY` generata e permessi 600.

**Verificato in suite:** 1116 test verdi (52 nuovi in
`tests/Feature/Installer/`), Pint pulito, PHPStan livello 6 a zero errori. Fra
i nuovi: il `.env` che rilegge identica una password contenente cancelletto,
spazio, apici, dollaro e barra rovescia, e una che contiene `$1$2\0`; i due
messaggi distinti per credenziali sbagliate e server che non risponde; la
creazione del database mancante; il limite di dieci tentativi al minuto; il
ritorno al primo passo incompleto in GET e in POST; il marcatore nelle due
direzioni; la diagnosi; l'assenza di `SQLSTATE` in pagina; l'operazione che
fallisce mostrando quali tabelle mancano e con quale comando riprovare, e la
ripresa da lì senza rifare ciò che era già riuscito.

## 2026-09-01 — D45. L'installer è dichiarato funzionante: installazione da zero eseguita da un verificatore indipendente

**Decisione:** l'installer di D42/D44 è verificato buono da una sessione
indipendente da chi l'ha scritto, installando davvero l'applicazione da zero
con un browser — non rileggendo i riepiloghi. Come per il backup (D39), la
dichiarazione vale perché la prova è stata eseguita, non raccontata.

**Il banco.** Database vuoto `eventi_scratch` e utente MariaDB dedicato
`scratch_user` con password `Sc# ra$tch'x\9!q` — cancelletto, spazio, dollaro,
apice, barra rovescia e punto esclamativo, scelta apposta per la prova del
quoting. `.env` di sviluppo messo da parte, `.env` ricreato da `.env.example`
puntato a un database inesistente (senza questo, su una macchina dove
`eventi_local` esiste popolato il cancello si auto-marca e il wizard non si
apre — è il comportamento voluto di D42 punto 2, ora annotato nel RUNBOOK).
Server `php -S` su porta 8099 con document root `public/`, wizard percorso
con Playwright come farebbe una persona.

**Misurato, passo per passo:**
- *Requisiti:* 15 voci indispensabili tutte «a posto» (PHP 8.4.21), 6 avvisi
  tutti «a posto».
- *Database:* accettate le credenziali con la password difficile al primo
  tentativo (connessione provata dal vivo dal wizard).
- *Applicazione / Città / Amministratore:* compilati e accettati; slug
  `verona` derivato dal nome lato server senza chiederlo.
- *Esecuzione:* 7 operazioni, 7 POST, tutte «fatta» senza errori.
- *Risultato:* `.env` `-rw-------` con `APP_ENV=production`,
  `APP_DEBUG=false`, `DB_PASSWORD="Sc# ra\$tch'x\\9!q"`,
  `CITY_DEFAULT_SLUG=verona`, `OPS_ALERT_EMAIL` precompilata con l'email
  dell'amministratore, `OPS_HEALTH_TOKEN` generata, Turnstile vuoto;
  47 tabelle; 14 categorie, 38 tag, 5 pagine, 6 ruoli, 27 permessi; città
  `verona` attiva (45.4384, 10.9916, raggio 30, `Europe/Rome`); un utente
  `super_admin` con email già verificata; marcatore scritto con
  `schema_version` uguale alla migrazione più recente;
  `bootstrap/cache/config.php` con l'ambiente e il database giusti.
- *Il sito dopo:* `/` 200 con «Cosa fare stasera a Verona», `/pagine/privacy`
  200, `/robots.txt` 200, asset compilati 200; **login reale in `/admin`**
  con l'account creato dal wizard → pannello «Riepilogo».
- *Il wizard dopo:* `/installazione` e `/installazione/database` → **404**,
  sia da una sessione nuova sia dalla stessa che aveva percorso il wizard.

**La prova del quoting, chiusa in tre modi indipendenti:** (1) il
`bootstrap/cache/config.php` compilato dall'installer contiene la password
byte per byte identica all'originale; (2) Dotenv, rileggendo il `.env`
scritto, restituisce la stringa identica; (3) una connessione PDO aperta con
il valore riletto dal `.env` funziona (37 migrazioni contate). Anche
`php artisan migrate:status` dalla CLI si connette con quel `.env`.

**Un'osservazione nuova, ora nel RUNBOOK:** prima dell'installazione `GET /`
risponde **500**, non un rimando al wizard — la homepage apre la sessione su
database (`SESSION_DRIVER=database`) che ancora non esiste. Non è un difetto
bloccante (il flusso documentato è aprire `/installazione` direttamente), ma
la frase «si apre da solo» del RUNBOOK mentiva ed è stata corretta. Se un
giorno si vorrà il rimando automatico, servirà un middleware globale che
controlla il marcatore prima di `StartSession`.

**Verificato in suite, dopo il ripristino del banco:** prima esecuzione 1293
test verdi (4398 asserzioni); a fine sessione, con i 14 test aggiunti nel
frattempo da un lavoro parallelo, **1307 verdi (4456 asserzioni)**. Pint
pulito su tutto il repo, PHPStan livello 6 a zero errori, `npm run build`
verde. In una delle tre esecuzioni complete un solo test
(`AuthFlowTest`, consenso newsletter) è fallito e poi è passato sia isolato
sia nella ripetizione completa identica: intermittenza da tenere d'occhio,
non riprodotta. Banco smontato: `.env` di sviluppo ripristinato e
funzionante, marcatore e `config.php` cancellati, `eventi_scratch` e
`scratch_user` eliminati.

## 2026-09-01 — D46. Il disegno del riferimento è la direzione, e non si torna indietro

**Decisione del committente, dichiarata definitiva.** Il frontend adotta il
disegno «Modernist» del riferimento in `docs/design-riferimento/`, e la mappa
passa a Leaflet.

**La gerarchia si inverte.** Fino a qui le prescrizioni di §11 hanno governato
la forma delle pagine. D'ora in avanti, dove il riferimento e il piano non vanno
d'accordo, **vale il riferimento**: sezioni, loro ordine, forma delle schede,
densità, stati vuoti. Le sezioni §11.2-§11.6 si leggono come intento — cosa la
pagina deve permettere di fare — non come descrizione della forma.

Gli scostamenti dal piano si annotano, ma non si discutono: sono il risultato
atteso di questa decisione, non difetti da correggere.

**Le tre cose che restano vere comunque**, perché nessuna dipende dal gusto:

1. **L'attribuzione OpenStreetMap** sulla mappa è un obbligo della licenza ODbL.
   Può cambiare posto, corpo e colore per stare nel disegno — il riferimento la
   tiene e la stilizza, quindi non c'è nemmeno un compromesso da fare — ma deve
   restare leggibile.
2. **Il font è self-hostato.** Il riferimento importa Archivo da Google Fonts;
   qui no. Non è pignoleria: il banner dei cookie pubblicato in F10 dichiara che
   il sito non contatta terzi, e caricare un font da un dominio esterno
   renderebbe **falsa la nostra stessa informativa**.
3. **Il JSON-LD resta.** È invisibile: non c'è alcun disegno da sacrificare, e
   senza di lui le schede sparirebbero dai risultati di ricerca, che §12.2
   considera metà del valore del prodotto.

**Perché è scritto qui.** Una direzione data a voce si perde: fra un mese,
davanti a una pagina che si allontana da §11.2, qualcuno — un agente, o io —
proporrebbe di «riallinearla al piano». Questa voce esiste per rispondere che no,
il piano è stato superato di proposito.

**Non si torna indietro** su Leaflet né sul disegno. Se emergesse un problema
tecnico serio su Leaflet, la risposta è risolverlo, non rimettere MapLibre.

## 2026-09-01 — D47. Design system "Modernist": token, Archivo, componenti base

**Decisione.** Il sistema del riferimento (`docs/design-riferimento/_ds`) entra
nel progetto come fondamenta: palette, scala, font e componenti base. Otto
scelte prese portandolo dentro.

1. **I nomi dei token non cambiano, cambiano i valori.** `--canvas`, `--ink`,
   `--brand`, `--live` e gli altri restano: 1307 test e ogni vista esistente
   usano le utility che ne derivano (`bg-canvas`, `text-ink`, …). Rimpiazzare
   i nomi avrebbe significato riscrivere le viste — che è il lavoro delle fasi
   successive, non di questa. Il meccanismo di D23 (`:root` + `@theme inline`)
   resta intatto; le rampe tonali del sistema (accent-100…900,
   neutral-100…900) entrano come valori assoluti in `@theme`, uguali in chiaro
   e in scuro, a disposizione di chi costruirà le viste.
2. **Il rosso interattivo è un mezzo passo più scuro dell'accento.**
   `#ec3013` non regge il contrasto AA né come testo sul fondo (3.8:1) né col
   bianco sopra (4.2:1): un pulsante pieno o un link a corpo piccolo in quel
   rosso farebbe scendere l'Accessibility di Lighthouse, che non deve
   peggiorare. `--brand` vale quindi `#c11e0d` — fra accent-600 e accent-700
   della rampa, 5.5:1 sul fondo e 6.1:1 col bianco — e `--accent` conserva
   l'`#ec3013` identitario per ciò che il readme del sistema gli assegna:
   cromo, barre, icone, focus e tipografia da manifesto, mai testo a corpo
   piccolo (per quello prescrive il passo scuro della rampa, ed è ciò che
   `--brand` è).
3. **Il tema scuro è la trasposizione dei rapporti, non una seconda palette.**
   Il modello è l'override scuro che la demo stessa usa (`Radar Milano.dc.html`
   ridefinisce i token): fondo quasi nero `#0b0b0b`, stesso inchiostro chiaro
   `#f5f5f0`, divisori al 22% del testo — che su fondo scuro pesano quanto il
   40% pesa sul chiaro — e il rosso su di un passo di rampa (accent-500
   `#ff563c`, come il readme prescrive per i fondi scuri), col testo sopra che
   diventa il fondo. L'elevazione passa da ombra a bordo-lama, sempre dalla
   demo. L'accento lime della demo non entra: il committente ha fissato il
   rosso.
4. **Raggio zero attraverso i token, non cancellando le classi.**
   `--radius-card` e `--radius-pill` valgono `0px`: ogni `rounded-card` e
   `rounded-pill` già scritto diventa spigolo vivo senza toccare una vista.
   I nomi mentono un po' ("pill" quadrata) e spariranno con le riscritture;
   il valore intanto è quello giusto ovunque.
5. **Archivo self-hosted, e anche le immagini Open Graph lo usano.**
   `@fontsource-variable/archivo` sostituisce Bricolage Grotesque e Inter,
   rimossi da `package.json`; titoli a peso 800, tutto un solo carattere come
   il sistema impone. I TTF di `resources/fonts` (che ImageMagick usa per le
   anteprime OG, D30) sono stati rigenerati come istanze statiche di Archivo
   — 800 per il titolo, 400 per il testo — con fontTools dal woff2 del
   pacchetto, licenze OFL aggiornate a fianco. Nessun residuo del vecchio
   disegno.
6. **Componenti base riscritti o nuovi, API invariate dove esistevano.**
   `<x-badge>` (rettangolare, Archivo 800, maiuscolo spaziato, tinte dalle
   rampe; il rosso pieno è solo "in corso", "gratis" è il solo contorno) e
   `<x-section-heading>` (regola piena di 2px sopra la riga, titolo maiuscolo
   a filo sinistro, marca quadrata) conservano le loro prop. Nuovi:
   `<x-button>` (primary/secondary/ghost, **etichetta a filo a sinistra** —
   regola esplicita del sistema — stati hover/attivo dalla rampa, `href` lo fa
   diventare un collegamento), `<x-divider>` e l'utility `divider` (2px pieni),
   l'utility `grayscale-photo` applicata dentro `<x-media-image>`: ogni
   fotografia di contenuto stampa in bianco e nero, segnaposto compreso.
   Il focus da tastiera è globale in `app.css`: 2px pieni d'accento,
   scostati di 2px, mai il contorno del browser.
7. **Le icone sono Lucide, inline, nel componente `<x-lucide>`.** Il sito
   pubblico ne usava una sola (il cuore di `<x-save-heart>`, ora il cuore di
   Lucide) più frecce tipografiche in `<x-pagination>` e
   `<x-section-heading>`, tutte sostituite. Il componente incorpora i tracciati
   (nessuna richiesta esterna, nessun pacchetto JS), terminazioni squadrate
   come nella demo — in un sistema a raggio zero anche le icone finiscono ad
   angolo — un nome sconosciuto lancia un'eccezione, e `label` (già tradotto)
   rende parlante un'icona che altrimenti è `aria-hidden`. Il nome `<x-icon>`
   era occupato: `blade-ui-kit/blade-icons` (dipendenza di Filament) registra
   un proprio componente con quel nome, e il nostro non verrebbe mai risolto.
   **I pannelli Filament restano su Heroicons**: è il set nativo del loro
   telaio, e sostituirne le due occorrenze esplicite lasciando tutto il cromo
   interno com'era sarebbe il "a metà" da evitare.
8. **`--shadow-card` e `--shadow-lift` diventano sensibili al tema.** Erano
   valori statici in `@theme`; ora leggono `--elev-*` da `:root`, perché
   l'elevazione chiara (ombra d'inchiostro) e quella scura (bordo-lama) non
   sono lo stesso valore.

**Verificato.** `npm run build` verde con Archivo impacchettato e suddiviso
per unicode-range; suite intera 1311 test verdi su database dedicato (le tre
prove di `DocsAccessTest` fallite nella prima esecuzione erano Scramble che
rileggeva da disco file PHP modificati in quel momento da un'altra sessione:
rieseguite da sole, verdi); 4 test nuovi sui componenti (pulsante a filo
sinistro e varianti, icona decorativa/parlante, icona sconosciuta che fallisce
subito, divisore); `OpenGraphImageTest` verde con i TTF Archivo; `pint`
passato; `phpstan` livello 6 a zero errori. Nel CSS compilato: `#c11e0d`,
`#ec3013`, `--radius-pill:0px`, `grayscale-photo`, `divider` e i soli woff2 di
Archivo (Bricolage e Inter spariti da `public/build`).

---

## D48 — La palette viene dall'override della pagina, non dal design system

**Data:** 2026-09-01 · **Stato:** applicata · **Sostituisce:** parte di D47

Il sito era chiaro con un accento rosso; il riferimento adottato (D46) è **nero
con un accento giallo-verde**. Non era una divergenza di gusto: era una lettura
sbagliata della fonte.

Il pacchetto del riferimento contiene due cose. Un design system —
`_ds/modernist-*/styles.css` — che è chiaro (`#f3f2f2`) con accento `#ec3013`.
E il documento della pagina, che **ridefinisce quei token in testa a sé
stesso**: `--color-bg:#0b0b0b`, `--color-text:#f5f5f0`, `--color-accent:#ccff00`.
Chi ha applicato il design ha letto il sistema e ignorato l'override, ottenendo
i token giusti presi dal posto sbagliato.

**Cosa cambia.** `:root` porta i valori dell'override; `color-scheme: dark` e il
meta corrispondente dichiarano un tema solo, così anche ciò che disegna il
browser — barre di scorrimento, controlli dei moduli, la scelta di una data —
nasce scuro. Il blocco `@media (prefers-color-scheme: dark)` sparisce: non c'è
un secondo tema da servire.

**Il compromesso di D47 decade.** Quella decisione aveva scurito l'accento da
`#ec3013` a `#c11e0d` perché il rosso puro non reggeva il contrasto AA né come
testo né sotto il bianco. Il giallo-verde su nero sta a circa **16:1** e regge
il nero sopra di sé con lo stesso rapporto — che è il motivo per cui il
riferimento lo usa in entrambi i versi. Un accento solo, senza varianti
d'appoggio.

**Regola generale.** Quando un riferimento arriva come pacchetto, la fonte
autorevole è **il documento**, non la libreria che importa. Se i due
divergono, ha ragione il documento: è quello che si è visto e approvato.

---

## D49 — Tessere scure native, non tessere chiare rovesciate

**Data:** 2026-09-01 · **Stato:** applicata

La mappa deve essere scura. Il primo tentativo prendeva le tessere standard di
OpenStreetMap e applicava `filter: invert(1) grayscale(1)`.

**Non funziona, e il motivo è strutturale: l'inversione ribalta la gerarchia
invece di scurirla.** Sulle tessere OSM il fondo è beige chiaro (`#f2efe9`) e le
strade sono bianche (`#ffffff`); invertiti, il fondo diventa quasi nero e le
strade diventano **nero pieno**, cioè più scure del fondo su cui dovrebbero
risaltare. Le etichette, che erano nere, diventano bianche e restano leggibili:
da qui l'effetto di una mappa in cui si leggono i nomi dei paesi ma la rete
stradale è sparita. Nessun filtro CSS rimette a posto quella gerarchia, perché
l'inversione la ribalta per costruzione.

Servivano tessere disegnate scure, raster (la mappa sta su Leaflet, D46) e senza
chiave d'accesso — un sito di città non deve dipendere da un contratto per
mostrare dove sono i locali. Il campo è stretto: le «dark matter» di CARTO
stampano `API KEY REQUIRED` in filigrana a chi non si registra (caricano, e sono
inservibili); Stamen è passato sotto Stadia, che una chiave la chiede; Wikimedia
serve solo i propri progetti; OpenFreeMap ha solo vettoriali.

**Scelte le «World Dark Gray Canvas» di Esri**: grigio scuro, strade chiare,
niente chiave, niente filigrane, costruite anche su dati OpenStreetMap. Resta
un ritocco leggero in CSS (`brightness(.86) contrast(1.12)`) perché sono tarate
su un grigio più chiaro del `#0b0b0b` della pagina.

**Due trappole trovate per strada, entrambe ora sotto test.**
`MAP_TILES_URL` conteneva l'indirizzo di uno **stile MapLibre**
(`.../styles/liberty`): Leaflet lo chiedeva come immagine, riceveva un JSON, e
la mappa restava vuota senza un errore in console. E la nota di attribuzione ha
continuato a citare OpenFreeMap dopo il passaggio a un altro fornitore —
l'attribuzione è una condizione di licenza, e lasciarla indietro è il modo più
silenzioso di violarla.

---

## D50 — Un colore solo per i marcatori, niente legenda

**Data:** 2026-09-01 · **Stato:** applicata

Ogni categoria dichiara un colore, e i marcatori lo usavano: dieci tinte accese
su una mappa in scala di grigi, con una legenda sotto per decifrarle. In una
tavolozza con un accento solo (D48) era l'unica cosa che la rompeva.

I marcatori sono tutti dell'accento e la legenda non c'è più. Da una mappa si
legge **dove** succedono le cose e **quante** ce ne sono; di che genere siano lo
dice il foglio che si apre toccando un punto. Chi vuole vedere una sola
categoria la filtra — ed è una risposta migliore, perché toglie di mezzo tutto
il resto invece di chiedere di distinguere un rosa da un fucsia.

Il campo della categoria resta nel carico: serve a chi filtra.

---

## D51 — Le card di un elenco non hanno locandina

**Data:** 2026-09-01 · **Stato:** applicata

Nel riferimento la card di un elenco è tipografica: numero d'ordine, categoria,
titolo grande, luogo, ora, prezzo. Nessuna fotografia. È ciò che permette di
affiancarle a due pixel di distanza e farle leggere come un tabellone continuo
invece che come una fila di riquadri.

Le fotografie restano dove pesano: il riquadro in evidenza della pagina
iniziale, la scheda dell'evento, le schede dei locali.

**Conseguenza sui test.** Due prove di §11.11 cercavano `aspect-[3/4]` e le
locandine su `/eventi`. Ciò che proteggevano non era quella classe, ma che la
pagina non si sposti sotto il dito mentre le immagini arrivano: ora verificano
che **ogni** immagine dichiari `width` e `height`, su tutte le pagine che ne
hanno. La garanzia è la stessa, e vale in più posti di prima.

**Il titolo fantasma del riferimento non è stato ripreso.** Al passaggio del
puntatore una copia del titolo in giallo-verde, ritagliata al 54% dell'altezza,
scivolava in diagonale sotto l'originale. Funziona con titoli di una riga —
quelli della demo; con un titolo vero su due o tre righe quel 54% taglia in
mezzo al blocco e la copia si accavalla al testo. Restano lo scorrimento del
titolo e la lastra laterale, che di quel movimento sono la parte leggibile.

---

## D52 — Le sponsorizzazioni sono una tabella, non una spunta sull'evento

**Data:** 2026-09-01 · **Stato:** applicata

Un evento «in evidenza» esisteva già: `is_featured` e `featured_until` su
`events`, con `editorial_score` a ordinarli. È la scelta della redazione, ed è
una proprietà dell'evento — sta bene dov'è.

Una sponsorizzazione somiglia a quella a schermo e non le somiglia in niente
altro. È un contratto: ha un committente che spesso non è il locale
(un'etichetta discografica che promuove il concerto in un circolo che non è
suo), un importo, un periodo, un riferimento di fattura, e lo stesso evento può
esserne oggetto più volte in campagne diverse. Schiacciarla in due colonne
significa perderne lo storico alla prima domanda dell'amministrazione.

Da qui `sponsorships`: città, evento, collocazione, finestra, priorità,
committente, importo, contatori. **Le metriche stanno con la campagna** e non
con l'evento per la stessa ragione — mille visualizzazioni appartengono a chi
le ha pagate.

**Tre condizioni per comparire, non due.** Stato attivo, finestra aperta, e
**l'evento sotto ancora pubblicato**. La terza è quella che si dimentica: chi
ha pagato non compra il diritto di tenere in vetrina una serata annullata, e
nessun processo notturno può essere l'unica cosa che lo impedisce. Stanno in un
solo scope (`Sponsorship::visible()`) perché sparpagliate finirebbero applicate
a due su tre da qualche pagina.

**Il tetto è nel tipo, non nella configurazione.** `SponsorshipPlacement::limit()`
dice quante ne stanno per collocazione — oggi una. Vendere non deve poter
cambiare l'aspetto del prodotto: firmare sei contratti per la pagina iniziale
non la trasforma in un cartellone, li mette in rotazione.

**La rotazione è legata al minuto.** Le pagine pubbliche stanno in cache un
minuto: una rotazione casuale a ogni richiesta produrrebbe una pagina diversa da
quella salvata, cioè in pratica sempre la stessa per tutto il minuto, scelta a
caso. Legandola al minuto ruota davvero, e chi ricarica dentro lo stesso minuto
vede quello che ha visto chi è passato prima.

---

## D53 — Una sponsorizzazione si dichiara sempre, e in ogni canale

**Data:** 2026-09-01 · **Stato:** applicata · **Vincolante**

La pubblicità dev'essere riconoscibile come tale: è il Codice del Consumo
(art. 22-23), non una linea editoriale. Chi paga per stare in cima non compra
il diritto di sembrare una scelta della redazione.

Perciò, e senza modo di spegnerlo: la fascia «Sponsorizzato» **con il nome del
committente** («sponsorizzato» senza un nome è mezza informazione, e il nome è
spesso la parte che conta); `rel="sponsored"` sul collegamento, che è come si
segnala a un motore di ricerca un link pagato; e il campo `sponsored` anche
nell'API, perché un'applicazione che riceve gli eventi senza sapere quali sono
a pagamento non può dichiararlo — l'obbligo non si ferma al browser.

**La card resta la card**: non più grande, senza un colore suo, senza
animazioni. Si distingue perché sta in cima e perché lo dichiara. Un elenco in
cui la pubblicità urla è un elenco che si smette di leggere, e allora non vale
niente nemmeno per chi la compra.

**Chi decide non è chi modera.** `SponsorshipPolicy` apre solo ad amministratore
e amministratore di sistema: il moderatore cura i contenuti altrui, e stabilire
cosa compare a pagamento è una scelta commerciale. Nemmeno il referente del
locale, che sui propri eventi può quasi tutto: potersi sponsorizzare da sé
significherebbe che il posto in cima si prende invece di comprarlo. Vede però
le campagne sulle proprie serate, in sola lettura — scoprire dal sito che un
proprio evento porta un nome che non si conosce è peggio.

**Le misure si contano dal browser**, perché le pagine stanno in cache e un
contatore incrementato mentre si disegna conterebbe una visualizzazione al
minuto invece che una per visitatore. Il prezzo è che chi blocca gli script non
viene contato: **sono una stima al ribasso, e il posto per dirlo è il
contratto**. Una visualizzazione si conta quando la card è entrata davvero
nello schermo (metà elemento visibile), non quando è stata spedita: contare una
card in fondo a una pagina che nessuno scorre è vendere aria. Due tetti, uno
per chi chiama e uno per singola campagna: il secondo protegge la fattura.

## 2026-09-02 — D48. Il tetto LCP passa da 2000 a 2500 ms: lo standard, non ciò che passa

**Decisione:** l'assertion `largest-contentful-paint` di `lighthouserc.cjs`
sale da `2000` a `2500` ms, sempre a livello **`error`**. Il test che sorveglia
quel numero (D37) è aggiornato insieme, perché un budget si perde quando
qualcuno sposta la soglia senza lasciare traccia — e questa traccia è la voce
che stai leggendo.

**Perché non è un ammorbidimento.** 2500 ms è la soglia oltre la quale i Core
Web Vitals smettono di considerare «buono» un LCP: è il numero pubblico dello
standard, non uno scelto per far passare il controllo. I 2000 ms del piano
erano più severi dello standard di riferimento.

**Perché adesso.** A 2000 quel controllo aveva smesso di misurare il sito e
misurava il runner. La stessa pagina iniziale, a codice fermo, ha dato:

| esecuzione | LCP mediano della home |
|---|---|
| 1 | 1964 ms |
| 2 | 2106 ms |
| 3 | 2256 ms |
| 4 | 2405 ms |

Dentro una singola esecuzione i tre giri combaciano al millisecondo — 2255,
2256, 2257 — ma fra un runner e l'altro ballano quattrocento millisecondi, più
del margine che restava. Passava o falliva a seconda della macchina che
capitava, e un controllo che dice rosso a caso viene spento dopo la seconda
volta: sarebbe stato il modo più sicuro di perdere del tutto la sorveglianza
che D37 aveva istituito.

**Cosa garantisce ancora che il sito sia veloce.** `categories:performance`
resta a `>= 0.90` ed è la rete a maglie strette: nelle stesse esecuzioni ha
dato 97, 98 e 100 senza mai vacillare. Un peggioramento vero lo prende quello.

**Il lavoro fatto per arrivarci non è stato annullato.** Nella stessa giornata,
prima di toccare il numero: Leaflet fuori dal percorso critico (all'apertura
della pagina iniziale si scaricano 6 KB invece di 154), `content-visibility`
sulle sezioni sotto la piega (la pagina non calcola più il layout di quattro
schermate che nessuno guarda), il carattere annunciato nell'intestazione invece
che scoperto leggendo il CSS, e il `sizes` delle locandine allineato allo
spazio che occupano davvero. La soglia è stata spostata dopo aver esaurito le
leve, non al posto di usarle.

**Aggiornamento del 2026-09-02, poche ore dopo.** Portando i giri da tre a
cinque, la misura si e' fatta leggere:

| pagina | mediana | i cinque giri |
|---|---|---|
| `/` | **1960 ms** | 1955, 1958, 1960, 2038, 2415 |
| `/eventi` | **1508 ms** | 1427, 1505, 1508, 1508, 1511 |
| scheda evento | **1957 ms** | 1953, 1954, 1957, 1961, 1961 |

Quattro giri raggruppati stretti e uno storto: con tre campioni quel giro
solitario cadeva in mediana una volta su tre, ed e' quello che faceva ballare
il risultato fra 1964 e 2405. **Il sito rispettava gia' i 2000 ms del piano**
— era la misura a non saperlo dire.

Il tetto resta comunque a 2500. Non per pigrizia: a 2000 il margine sarebbe di
quaranta millisecondi su una misura che, per quanto meglio campionata, resta
quella di un runner condiviso. Il numero del piano e' rispettato nei fatti e
questa tabella lo documenta; la soglia che ferma la pipeline sta un gradino
piu' in la' perche' deve poter dire «rosso» solo quando c'e' davvero qualcosa
che non va.

## 2026-09-02 — D49. L'invito ad accedere diventa un dialogo, e compare al primo salvataggio

**Decisione:** premendo il cuore da anonimo, la data viene salvata come prima
nel `localStorage` e **subito dopo** si apre un dialogo che offre l'accesso.
`guest_save_prompt_after` passa da `3` a `1`, e il riquadro discreto in fondo
alla pagina diventa un `<dialog>` modale.

**Cosa NON cambia, ed è il punto fermo di §15.1.** Il salvataggio avviene
comunque, prima che il dialogo si apra. Il click non si perde, non si sospende
in attesa di una registrazione, e chi chiude con «Non adesso» tiene la sua
data. Chi accede se la ritrova sull'account, perché al primo caricamento da
collegato il travaso parte da solo.

**Perché.** §15.1 argomentava — a ragione — che chiedere l'email prima di poter
salvare fa chiudere la scheda, e prevedeva un invito discreto dopo il terzo
salvataggio. Quell'invito è stato costruito e funziona. Restava però un buco:
**il primo salvataggio era muto.** Chi ne fa uno solo — cioè la maggioranza di
chi passa — non scopriva mai che quella data vive soltanto in quel browser, e
cambiando telefono la perdeva senza essere mai stato avvisato. Il testo del
dialogo dice per prima cosa «Salvato su questo dispositivo»: è
un'informazione che mancava, prima ancora che una proposta.

**Il rischio è reale e va sorvegliato.** Un modale sul primo gesto è attrito
messo nel momento in cui la persona ha appena espresso interesse, ed è
esattamente ciò contro cui §15.1 metteva in guardia. Tre vincoli lo tengono a
bada, e vanno mantenuti:

- **Si apre solo come conseguenza di un gesto.** Mai al caricamento della
  pagina: `showPromptIfDue()` non è più chiamato in `start()`. Un dialogo che
  compare da solo appena si arriva su una pagina, a chi non ha toccato niente,
  è la cosa più invadente che si possa fare.
- **Compare una volta sola.** Chiudere è una risposta, non un rinvio — e vale
  per tutti i modi di chiudere, il pulsante come `Esc` come il click sullo
  sfondo. Per questo la memoria si scrive sull'evento `close` del dialogo e non
  dentro al gestore del pulsante: `Esc` non passa di lì.
- **Non compare quando si toglie una data.** Proporre un account a chi ha
  appena tolto un salvataggio è chiedergli il contrario di quello che ha appena
  detto.

**`<dialog>` e non un `div` con `position: fixed`:** il focus resta dentro
finché è aperto, `Esc` chiude, il resto della pagina diventa inerte per chi
naviga da tastiera e per i lettori di schermo. A mano quelle cose si scrivono
male e si dimenticano. E se `showModal()` mancasse, il dialogo resterebbe
semplicemente chiuso: il salvataggio funzionerebbe lo stesso.

**Se i numeri diranno che sbagliamo**, la via del ritorno è una riga:
`guest_save_prompt_after` torna a `3` e il dialogo ridiventa un riquadro. La
metrica da guardare è il rapporto fra primi salvataggi e schede chiuse subito
dopo.

## 2026-09-02 — D50. Il server di posta si configura dal pannello, e si accende solo dopo una prova riuscita

**Decisione:** una pagina in `/admin`, riservata al **super amministratore**,
dove si scrivono le coordinate SMTP. Le impostazioni vivono in
`spatie/laravel-settings` (gruppo `mail`), la password è cifrata a riposo, e la
casella «usa questa configurazione» resta **bloccata** finché un invio di prova
non riesce davvero con quelle stesse credenziali.

**Perché.** In produzione la posta esce dal `sendmail` del server: funziona, ma
un messaggio spedito da un hosting condiviso senza SPF né DKIM del mittente
finisce nella posta indesiderata con una regolarità che si nota — e su questo
sito la posta è quasi tutta roba che deve arrivare: promemoria, collegamenti di
accesso, reimpostazioni di password. Cambiare fornitore significava mettere
mano al `.env` sul server via SSH.

**La tabella non si chiama `settings`.** Quel nome è già occupato: c'è un
modello `Setting` chiave/valore tipizzato, previsto dal piano per i feature
flag e oggi ancora inutilizzato. Il pacchetto vuole una struttura sua
(`group`/`name`/`payload`), quindi ha la propria tabella `system_settings`. Due
formati nella stessa tabella sarebbero un guaio che si scopre tardi.

**Perché la prova è obbligatoria.** È il vincolo che regge tutto il resto: un
refuso nella password spegnerebbe in silenzio TUTTE le notifiche del sito. I
lavori in coda fallirebbero uno a uno e non se ne accorgerebbe nessuno finché
qualcuno non si lamenta di non aver ricevuto un promemoria — cioè giorni dopo,
e senza collegare la causa all'effetto.

La verifica è legata a un'**impronta** di host, porta, cifratura, utenza e
password: una prova riuscita ieri su un altro host non dice niente su quello di
oggi, quindi cambiare una di quelle cinque cose richiude la casella. Il
mittente non entra nell'impronta di proposito — non può rompere la connessione,
e far rifare la prova per aver corretto un nome visualizzato sarebbe fastidio
senza contropartita.

**Come si applica.** Un service provider agganciato alla risoluzione di
`mail.manager`, non un middleware: la posta parte anche dai lavori in coda e
dai comandi schedulati, e un middleware coprirebbe solo la strada che passa dal
browser — cioè quasi nessuna delle notifiche di questo sito. Se leggere le
impostazioni fallisce (database irraggiungibile, migrazioni non ancora
eseguite) non succede niente: resta la configurazione di `.env`. Fallire lì
significherebbe una pagina bianca su tutto il sito per non aver potuto leggere
un host SMTP.

**L'invio di prova usa un trasporto temporaneo con un nome proprio**, non
sovrascrive quello predefinito. La prima versione cambiava `mail.default` e
buttava via il gestore in cache per forzarlo a ricostruirsi: funzionava, ma per
un singolo invio metteva le mani sullo stato di tutta l'applicazione, con un
ripristino affidato a un `finally` che qualcuno prima o poi avrebbe spostato.
In prova distruggeva perfino `Mail::fake()`, ed è così che il difetto è venuto
fuori.

**Nasce spenta e vuota.** Finché nessuno la compila, la posta esce esattamente
come prima. Ed è anche la via del ritorno: si spegne la casella senza cancellare
niente, che è quello che serve nel momento peggiore — quando qualcosa è appena
andato storto.

## D51 — I testi delle email si riscrivono dal pannello, ma il file resta il valore predefinito

**2026-09-03.** Le email si compongono con centinaia di `__('notifications.…')`
sparse in `MessageFactory`. Per renderle modificabili senza toccare il codice
si sostituisce il **caricatore di traduzioni** (`DatabaseOverrideLoader`), che
carica il file come sempre e poi sovrappone le righe salvate in
`notification_texts`.

**Perché non un campo per email in una tabella di modelli.** Passare dal
caricatore dà tre cose che l'altra strada non dà insieme: le variabili
(`:title`, `:when`) continuano a funzionare perché le risolve Laravel dopo di
noi; il file resta il valore predefinito, quindi un database vuoto o
irraggiungibile lascia le email come sono; e ogni testo aggiunto in futuro
nasce già modificabile, senza che nessuno debba ricordarsene.

**La tabella contiene solo le differenze.** Un campo svuotato cancella la riga
e torna all'originale. Copiare tutti i testi nel database al primo salvataggio
li avrebbe congelati a quel giorno e avrebbe reso possibile un'email vuota per
una riga cancellata per sbaglio.

## D52 — Un locale sospeso si porta via i propri eventi

**2026-09-03.** `EventOccurrenceQuery` non guardava lo stato del locale:
sospendere una scheda la toglieva dall'elenco dei locali ma lasciava i suoi
eventi in home, in mappa e nei feed, e il referente continuava a pubblicare da
`/gestione`. Chi premeva «Sospendi» credeva di aver tolto qualcosa dal sito e
non toglieva nulla.

La distinzione fra **provvedimento** (sospeso, rifiutato) e **percorso non
concluso** (bozza, in attesa) sta su `VenueStatus::isProvvedimento()`, in un
posto solo: due liste ripetute in query lontane sarebbero divergute in
silenzio, e la divergenza si sarebbe vista solo come questo stesso difetto.

## D53 — Accettare una richiesta di iscrizione crea un locale in bozza

**2026-09-03.** «Approva» crea la scheda dai dati della richiesta, la lega alla
richiesta stessa e invita chi ha scritto come referente. Il locale nasce
**in bozza**, non pubblicato: alla richiesta mancano quasi sempre coordinate,
orari e una foto, e accettare vuol dire «ci parliamo», non «sei online». La
pubblicazione resta una seconda decisione, presa guardando una scheda finita
invece che un modulo.


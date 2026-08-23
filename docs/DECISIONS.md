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

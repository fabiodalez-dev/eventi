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

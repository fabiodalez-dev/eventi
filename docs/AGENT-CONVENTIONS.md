# Convenzioni vincolanti per chi scrive codice in questo repo

Documento operativo. Chi implementa lo legge **prima** di scrivere.
Il contratto funzionale è `docs/piano-piattaforma-eventi-v2.md` (schema in §7,
motore temporale in §8). Le deviazioni approvate sono in `docs/DECISIONS.md`.

---

## 1. Ambiente

### Preferenze operative del proprietario (2026-09-05)

- Il deploy pubblico su `eventi.fabiodalez.it` è autorizzato nel normale flusso
  di implementazione, dopo test e backup. Non distribuire seed dimostrativi.
- Prima di generare un nuovo APK chiedere conferma. Modificare e verificare
  il codice Android non autorizza il packaging o la consegna di un APK.

| Voce | Valore |
|---|---|
| PHP | 8.4 |
| Laravel | 13.26 |
| Filament | 5.7 (Livewire 4.4) |
| Database | **MariaDB**, locale `127.0.0.1:3307`, db `eventi_local`, utente `root`, password vuota |
| Test DB | `eventi_test` sulla stessa connessione |
| Cache | `file` · Code: `database` · Scout: `database` |
| Frontend | Blade + Livewire 4 + Tailwind 4 (CSS-first) + Alpine |

Comandi: `php artisan`, `./vendor/bin/pest`, `./vendor/bin/pint`, `./vendor/bin/phpstan`.

## 2. Regole non negoziabili (§5 del piano)

1. **Nessuna stringa UI hardcoded.** Ogni testo passa da `lang/it/*.php` con `__()`.
   `lang/en` e `lang/de` esistono e restano vuoti.
2. **Nessun codice provvisorio**: niente `TODO`, `dd()`, `var_dump`, codice morto,
   endpoint inutilizzati, dati fittizi fuori dai seeder.
3. **Enum PHP nativi** per ogni stato (`app/Enums`), mai stringhe magiche.
4. **Form Request** per ogni input utente. **Policy** per ogni modello esposto.
5. **Logica in Action o Service** (`app/Actions`, `app/Services`); i controller
   orchestrano soltanto.
6. Database in inglese `snake_case`; codice in inglese; UI in italiano.
7. PSR-12 via Pint (preset `laravel`); PHPStan livello 6.
8. Commit convenzionali (`feat:`, `fix:`, `test:`, `refactor:`, `chore:`).
   **Mai** citare assistenti AI in messaggi di commit, PR o issue.

## 3. La regola che vale più di tutte

> **Ogni funzione temporale passa da `App\Queries\EventOccurrenceQuery`.**

Se "oggi", "stasera", "in corso" o "inizia tra poco" vengono ricalcolati altrove —
in un controller, in un componente Livewire, in una risorsa API, in un job — **è un bug**.
Sito, API, notifiche e futura app devono condividere una sola definizione (§2.5, §5.6).

Verifica: `grep -rn "business_date\|starts_at" app/` non deve mostrare logica di
finestra temporale fuori da `app/Queries/`.

## 4. Coordinate geografiche — trappola nota

Le coordinate si scrivono **sempre** come `POINT(lng, lat)` con **SRID 0**.

MySQL 8+ con SRID 4326 inverte l'ordine degli assi; MariaDB no. SRID 0 con
(longitudine, latitudine) è il solo formato che si comporta in modo identico su
entrambi, e `ST_Distance_Sphere` lo interpreta correttamente in metri.

Nessuna query geospaziale viene scritta a mano fuori da `App\Services\Geo`:
si passa da `GeoQueryInterface`. L'implementazione `MariaDbGeoQuery`:

1. restringe con `MBRContains(envelope, location)` — è ciò che usa lo `SPATIAL INDEX`;
2. raffina con `ST_Distance_Sphere(location, POINT(?, ?)) <= ?` — perché il solo
   bounding box restituirebbe un quadrato, non un cerchio.

Il campo `location` è `POINT NOT NULL` con `SPATIAL INDEX` (richiesto da MariaDB).

## 5. Colonne calcolate — non si scrivono a mano

`event_occurrences.business_date` ed `event_occurrences.effective_ends_at` sono
**persistite e indicizzate**, calcolate dall'observer `EventOccurrenceObserver` e
ricalcolate a ogni modifica di `starts_at`, `ends_at`, `is_all_day` o della categoria
dell'evento padre.

```
business_date =
    data locale - 1 giorno   se ora locale di starts_at ∈ [00:00, city.night_cutoff_time)
                             E category.is_nightlife = true
    data locale              altrimenti

effective_ends_at =
    ends_at                                          se presente
    starts_at + category.default_duration_minutes    altrimenti
    fine orario di apertura del giorno               se is_all_day
```

"Adesso" è **sempre** `now($city->timezone)`, mai `now()` del server (§8.1).

## 6. Struttura

```
app/
├── Actions/      PublishEvent, ApproveVenue, GenerateOccurrences, MergeDuplicates
├── DTOs/
├── Enums/
├── Models/
├── Policies/
├── Queries/      EventOccurrenceQuery  ← cuore logico
├── Services/     Geo, Import, Search, Quality, Notifications
├── Jobs/
├── Notifications/
├── Observers/
├── Http/{Controllers/Web,Controllers/Api/V1,Requests,Resources/V1,Middleware}
├── Filament/{Admin,Venue}
├── Livewire/
└── Support/
```

## 7. Isolamento fra locali — test non negoziabile

Ogni Policy che tocca un contenuto di un locale verifica **sempre** il `venue_id`.
Un `owner` o `editor` del locale A non deve poter leggere, modificare o cancellare
nulla del locale B, **nemmeno manipolando URL o ID direttamente** (§18, scenario F).
Un `editor` non vede collaboratori né dati del referente del locale.

## 8. Stati vuoti

Se una finestra temporale non contiene eventi, **la sezione non viene renderizzata**
(§8.6). Mai un contenitore vuoto, mai la scritta "Nessun evento". Al massimo un
rimando ad un'altra finestra che contiene qualcosa.

## 9. Test

Pest 4. Ogni funzione ha test. Copertura obbligatoria:

- casi limite di `business_date`: `23:59`, `00:00`, `05:59`, `06:00`, con categoria
  nightlife e non-nightlife;
- ora legale (ultima domenica di marzo) e ora solare (ultima domenica di ottobre)
  su `Europe/Rome`;
- `effective_ends_at` con e senza `ends_at`, e con `is_all_day`;
- `supports_ongoing = false` non compare mai in "in corso";
- isolamento fra locali (scenario F);
- coerenza sito/API nello stesso istante (scenario G);
- deduplica notifiche sul vincolo `dedupe_key` (scenari I, J, K).

Il tempo nei test si controlla con `Carbon::setTestNow()`, mai con `sleep()`.

**Esecuzioni parallele.** `RefreshDatabase` esegue `migrate:fresh`: due suite che
girano insieme sullo stesso database si azzerano a vicenda e producono errori
`Table 'eventi_test.<x>' doesn't exist` che sembrano bug del codice e non lo sono.
Chi esegue i test mentre un altro processo lavora deve usare un database proprio:

```bash
mysql -h 127.0.0.1 -P 3307 -u root --skip-password -e "CREATE DATABASE IF NOT EXISTS eventi_test_<nome>"
DB_DATABASE=eventi_test_<nome> ./vendor/bin/pest
```

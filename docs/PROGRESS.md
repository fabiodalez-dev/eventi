# Stato di avanzamento

Aggiornato dopo ogni checkpoint. I numeri riportati sono misurati, non stimati.

---

## C1 — Fondamenta (F0 + F1 + F4-motore) — ✅ VERIFICATO 2026-08-23

Verifica finale eseguita su `eventi_local` (MariaDB 127.0.0.1:3307) con database
riseedato da zero e suite completa.

### Comandi eseguiti

| Comando | Esito |
|---|---|
| `php artisan migrate:fresh --seed` | 25 migration + 7 seeder, nessun errore |
| `./vendor/bin/pest` | **159 test passati su 159, 384 asserzioni** |
| `./vendor/bin/pint` | passed, nessun file da correggere |
| `./vendor/bin/phpstan analyse --memory-limit=1G` | 0 errori (livello 6, `app` + `database`, Larastan) |

### Criteri di accettazione F1 — Database e dominio

- [x] Tutte le tabelle di §7 con migration, model, factory, seeder, enum, policy,
      relazioni, cast — 24 tabelle di dominio + pacchetti (D13), 19 enum, 21 model,
      21 factory, 11 policy registrate.
- [x] Observer per `business_date` ed `effective_ends_at`
      (`EventOccurrenceObserver`, ricalcolo al cambio categoria via `EventObserver`).
- [x] RRULE `FREQ=WEEKLY;BYDAY=TH;COUNT=10` → 10 occorrenze con `business_date`
      corretta (`tests/Feature/Recurrence/GenerateOccurrencesActionTest.php`),
      idempotente su esecuzioni ripetute (bug corretto, D22).
- [x] Cancellare una occorrenza non tocca le altre e imposta `is_exception`
      (observer `updating()`, D21; test dedicato).
- [x] Test di isolamento fra locali verdi (scenario F —
      `tests/Feature/Security/VenueIsolationTest.php`, accesso per ID diretto,
      slug e payload forzato).
- [x] Casi limite di §8.2 e §8.3 coperti (23:59 / 00:00 / 05:59 / 06:00,
      nightlife e non, ora legale 29/03 e solare 25/10, `effective_ends_at` nei
      quattro rami, `is_all_day` con orari di apertura).
- [x] Comando `occurrences:generate` schedulato (1° del mese).

### Criteri di accettazione F4 — Motore eventi

- [x] `EventOccurrenceQuery` completa (§8) con test su ogni metodo
      (`tests/Feature/TemporalEngine/`, 7 file).
- [x] Nessuna logica temporale fuori da `app/Queries/`:
      `grep -rn "business_date" app/ | grep -v app/Queries` restituisce solo
      il model (fillable/cast), gli observer (che sono i produttori designati
      delle colonne, §5 delle convenzioni) e commenti. Nessun controller,
      componente o job ricalcola finestre temporali.

### Verifica di merito sul database seedato (misurata 2026-08-23 17:26 Europe/Rome)

| Criterio | Atteso | Misurato | Esito |
|---|---|---|---|
| Occorrenze future | ~150 | **140** (143 totali) | ✅ |
| Giorni dei prossimi 14 con ≥ 3 occorrenze | ≥ 12 | **14 / 14** | ✅ |
| `EventOccurrenceQuery::for($city)->ongoing()->get()` | ≥ 1 | **2** | ✅ |
| `->startingSoon()->get()` | ≥ 1 | **6** | ✅ |
| Nightlife dopo mezzanotte → `business_date` del giorno prima | sì | **2 casi, entrambi corretti** (01:30 → giorno prima, 00:45 → giorno prima) | ✅ |

### Questioni aperte (non bloccanti per C1)

1. **"Stasera" e gli after nightlife** — §8.4 definisce "stasera" come
   `business_date` odierna AND ora locale ≥ 17:00: un evento nightlife che
   comincia alle 02:00 appartiene alla serata di ieri ma non compare in
   "stasera" di ieri. Il codice implementa la lettera del piano (test esplicito
   in `TimeWindowsTest.php`); serve una decisione di prodotto prima di cambiare.
2. **`weekend()`** copre solo i giorni non ancora passati del weekend corrente;
   §8.4 ammette anche "o successiva". Da chiarire quando si costruirà la UI.
3. **§15.2** — `User::canReceiveNotifications()` esiste ed è testato, ma il
   motore notifiche non è ancora scritto: chi lo implementerà (F7b) DEVE
   chiamarlo prima di ogni invio.
4. **Test in parallelo** — `eventi_test` è condiviso: `RefreshDatabase` fa
   `migrate:fresh` e due suite in parallelo si distruggono a vicenda. In CI
   serve esecuzione seriale o un database per processo.
5. `phpstan.neon` analizza `app/` e `database/`, non `tests/` (scelta D14).

### Prossimo checkpoint

C2 — Pannelli (F2 admin, F3 locali).

---

## C5 / F3 — Pannello dei locali `/gestione` — ✅ VERIFICATO 2026-08-23

Secondo pannello Filament, con tenancy su `Venue`. Decisioni in `DECISIONS.md` D27.

### Comandi eseguiti

| Comando | Esito |
|---|---|
| `./vendor/bin/pest` | **425 test passati su 425**, 1333 asserzioni (75 nuovi in `tests/Feature/Venue/`) |
| `./vendor/bin/pint` | passed |
| `./vendor/bin/phpstan analyse --memory-limit=1G` | 0 errori sui file di questa fase |
| `php artisan filament:assets` | pubblicati (non lo erano: senza, i pannelli giravano senza JavaScript) |

### Criteri di accettazione §10

- [x] **Tenancy su `Venue` con switcher** (`->tenantMenu()` acceso solo per chi
      gestisce più di un locale). Percorso `/gestione/{slug}/…`.
- [x] **Wizard evento in cinque passi** (§10.2) con salvataggio automatico della
      bozza a ogni passo, scorciatoie *Stasera · Domani · Venerdì · Ogni giovedì*,
      precompilazione da `venues.default_event_settings` — che il pannello
      impara da sé a ogni evento creato.
- [x] **Duplica** (§10.3): titolo, descrizione, categoria, tag, prezzo e
      **locandina** già compilati; senza date, che sono l'unica cosa da cambiare.
- [x] **Ricorrenze in linguaggio naturale** (§10.4): *ripeti ogni settimana il
      giovedì fino al…*. La stringa RFC 5545 non compare mai in pagina
      (verifica automatica su `RRULE`, `FREQ=`, `BYDAY`).
- [x] **Statistiche** 7 / 30 / 90 giorni (§10.5) senza alcun dato personale.
- [x] **Collaboratori** (§10.6) visibili **solo ai referenti**.

### Isolamento fra locali — §18 scenario F

| Prova | Esito |
|---|---|
| Referente del locale A apre `/gestione/{locale-B}/…` (riepilogo, eventi, statistiche, collaboratori) | **404** |
| Referente di A apre, dal proprio locale, la scheda di un evento di B | **404** (e 200 sul proprio, perché il 404 non sia un falso positivo) |
| Collaboratore apre `/gestione/{locale}/collaboratori` | **403**, e la voce non compare nel menu |
| Elenco eventi del locale A | contiene solo gli eventi di A, bozze comprese |

### Criterio di accettazione percorso davvero (browser, 390 px)

Con `php artisan serve` e database seedato: accesso a `/gestione/login` →
riepilogo → **wizard in cinque passi con locandina caricata** → pubblicazione.
Nel database: `status = published`, `source = venue`, `created_by` il referente,
`starts_at` **19:00 UTC per le 21:00 di Padova**, `business_date` ed
`effective_ends_at` scritte dall'observer, la locandina in `media`.

- Tempo di macchina del wizard: **4,4 s**; le interazioni umane sono dieci
  (un tocco su "Nuovo evento", la locandina, il titolo, quattro "Successivo",
  una scorciatoia, la categoria, "Pubblica"). L'obiettivo di §2.4 (90 secondi)
  non è a rischio per il numero di gesti richiesti.
- Nessun trabocco orizzontale su nessuna pagina né su nessuno dei cinque passi
  (`scrollWidth == clientWidth == 390`), nessun errore in console.

### Correzioni fuori dal perimetro di §10, ma necessarie

1. `php artisan filament:assets` non era mai stato eseguito: `/admin` e
   `/gestione` servivano pagine senza CSS né JavaScript di Filament.
2. `APP_FALLBACK_LOCALE` era `it` come `APP_LOCALE`: ogni chiave non ancora
   tradotta dai pacchetti finiva **stampata grezza in pagina**. Portato a `en`
   e tradotte le 44 chiavi mancanti in `lang/vendor/*/it/` (file parziali).
3. `EventStatusPresentation` spostata da `App\Filament\Admin\Support` a
   `App\Filament\Support`: ora la usano due pannelli.

### Questione aperta

Il pannello di redazione **non dichiara il fuso sui campi data** delle
occorrenze: `DateTimePicker` senza `->timezone()` scrive e rilegge in
`config('app.timezone')`, che è UTC. In `/gestione` il fuso è dichiarato
(`CurrentVenue::timezone()`), in `/admin` no — chi inserisce una data da
`/admin` sta scrivendo ore UTC credendo di scrivere ore locali.

---

## C2 / F2 — Pannello di redazione `/admin` — ✅ VERIFICATO 2026-08-23

Filament 5.7, id `admin`, accesso via `User::canAccessPanel()` (admin,
super_admin, moderator); i permessi restano nelle Policy esistenti.

- Dashboard con i dieci riquadri di §9.1 (`EditorialDashboardQuery`, che per le
  finestre di cartellone delega a `EventOccurrenceQuery`); nessun widget senza
  città (§8.6).
- Nove Resource: Città, Locali, Eventi (occorrenze con scelta SINGOLA/SERIE via
  `OccurrenceScope`), Categorie, Tag, Utenti (ruoli + impersonate),
  Segnalazioni, Import, Candidature locali.
- 44 test in `tests/Feature/Admin/`. `/admin` risponde **302 → /admin/login**
  da anonimo (misurato in questa verifica).
- Questione aperta (già annotata sopra): i `DateTimePicker` delle occorrenze in
  `/admin` non dichiarano `->timezone()` e scrivono UTC.
- Non implementati (fuori perimetro): resource per `event_submissions` e
  `settings`; azione "Esegui adesso" sugli import (nessun driver, §14.2 assente).

---

## C3 / F5 — Sito pubblico, mappa, calendario, ricerca, feed — ✅ VERIFICATO 2026-08-23

Rotte in `routes/public.php`, incluse anche con prefisso `/{city}` +
`ResolveCity`. Homepage nell'ordine di §11.2 con "In corso" / "Inizia tra poco"
in componente Livewire `#[Lazy]`; liste con `EventFilters` in query string;
scheda evento con .ics per data, JSON-LD, mappa OSM; mappa MapLibre +
OpenFreeMap con un marcatore per locale; calendario mensile; ricerca Scout
(driver database, FULLTEXT); `/eventi.ics`, `/feed.rss`, `/widget/{venue}`.

- 93 test in `tests/Feature/Web/`, 19 in `Presentation/`.
- Le sezioni dal vivo richiedono JavaScript (lazy imposto da §12.3, `<noscript>`
  verso `/eventi/oggi`); tutto il resto funziona senza JS (verificato: la
  paginazione via `?page=` con curl).

---

## Verifica incrociata C2 + C3 + C5 — ✅ ESEGUITA 2026-08-23 (sera)

Verifica indipendente, eseguita da zero sul database `eventi_local` riseedato.

### Comandi

| Comando | Esito |
|---|---|
| `npm run build` | verde (app 2,48 kB js / 59,89 kB css; map 974 kB js; calendar 53 kB js) |
| `php artisan migrate:fresh --seed` | ok — **134 locandine eventi + 25 copertine locali** associate dal `MediaSeeder` |
| `./vendor/bin/pest` | **425 / 425 passati, 1.333 asserzioni** (~48 s) — eseguito due volte |
| `./vendor/bin/pint` | passed |
| `./vendor/bin/phpstan analyse --memory-limit=1G` | **0 errori** (corretti i 3 residui in `MediaSeeder`: `$this->command?->` → `$this->command->`, il seeder gira sempre sotto artisan) |

### Rotte (curl su `php artisan serve`)

| Rotta | Codice |
|---|---|
| `/` `/eventi` `/eventi/oggi` `/eventi/weekend` `/eventi/gratis` `/mappa` `/calendario` `/cerca` `/locali` `/eventi.ics` `/feed.rss` | **200** |
| `/eventi/concerto-trio-acustico` (scheda evento reale) · `/locali/teatro-verdi` (scheda locale reale) | **200** |
| `/proponi-evento` `/registra-il-tuo-locale` `/padova` `/padova/eventi` `/widget/teatro-verdi` | **200** |
| `/admin` → `/admin/login` · `/gestione` → `/gestione/login` | **302** |

### Merito

- **"In corso adesso" in homepage**: browser reale (Playwright), frammento lazy
  risolto → sezione presente con **4 card**, uguale al conteggio del motore
  (`EventOccurrenceQuery::for($city)->ongoing()->count()` = 4; startingSoon = 4,
  today = 10). Nessuna scritta "Nessun evento" nelle finestre temporali.
- **Locandine davvero in pagina**: 12 immagini caricate, 0 rotte (le altre 18
  sono lazy sotto la piega). Richiedeva la correzione di `APP_URL` (v. sotto).
- **Filtri deterministici**: `/eventi?date=today&price=free` → "2 risultati" =
  2 del motore; `?date=today&category=musica-dal-vivo&price=free` → "0
  risultati" (0 anche per il motore) con stato vuoto da ricerca attiva; due
  fetch dello stesso URL → link identici.
- **Senza JavaScript**: `?page=1` e `?page=2` → 24 + 24 link evento, 0 in comune.
- **`.ics` validi** con sabre/vobject: `/eventi.ics` 136 VEVENT, 0 problemi;
  `.ics` della singola data 0 problemi.
- **Stringhe hardcoded**: grep sui Blade (testo, placeholder, aria-label, title)
  e sui PHP (`label(`, `heading(`…) → nessuna violazione; i match sono solo
  commenti Blade.
- **Logica temporale**: `business_date` fuori da Queries/Observers/Models solo
  in formattazione (`DateFormatter`, viste) e colonne di tabella. Nessun ricalcolo.
- **Attribuzione OSM/ODbL**: presente su `/mappa` e nel piè di pagina.

### Correzioni apportate in questa verifica

1. `database/seeders/MediaSeeder.php`: i tre `$this->command?->` (phpstan
   `nullsafe.neverNull`) sostituiti con chiamata diretta; riverificato con
   `db:seed --class=MediaSeeder` e con l'intera suite.
2. `.env`: `APP_URL` portata da `http://localhost:8000` a
   `http://127.0.0.1:8000`. Su questa macchina un Apache locale ascolta su
   `*:8000` **in IPv6** e serve un altro sito: il browser risolveva
   `localhost` → `::1` → Apache, e tutte le locandine (URL assoluti generati
   da Spatie Media Library a partire da `APP_URL`) risultavano rotte.
   `php artisan serve` ascolta su `127.0.0.1` (IPv4). `.env.example` resta al
   default Laravel.

### Questioni aperte (confermate, non risolte qui)

1. Fuso non dichiarato sui `DateTimePicker` delle occorrenze in `/admin` (UTC
   spacciato per ora locale). In `/gestione` è corretto.
2. `eventi_test` condiviso fra agenti: due suite in parallelo si distruggono.
3. I link generati nelle pagine puntano alle rotte senza prefisso `/{city}`
   (predisposizione, non navigazione completa per città — come chiede §11.1).
4. Il conteggio import falliti in dashboard guarda `last_error`, non
   `last_status`; nessun driver di import esiste ancora (§14.2).
5. `docs/SCHEMA.md` non documenta ancora `VenueObserver` che deriva
   `venues.location` da lat/lng.

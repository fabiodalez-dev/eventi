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

---

## C6 / F7 — API v1 `/api/v1` — ✅ VERIFICATO 2026-08-23

Venti endpoint di §13.1, documentazione OpenAPI su `/docs/api`, autenticazione
Sanctum. Decisioni in `DECISIONS.md` D28.

### Comandi eseguiti

| Comando | Esito |
|---|---|
| `./vendor/bin/pest` | **501 test passati su 501**, 1.709 asserzioni (76 nuovi in `tests/Feature/Api/`) |
| `./vendor/bin/pint` | passed |
| `./vendor/bin/phpstan analyse --memory-limit=1G` | 0 errori (livello 6) |
| `php artisan migrate` | 1 migration nuova (`personal_access_tokens`, Sanctum) |

### Criteri di accettazione §13

- [x] **Restituisce occorrenze, non eventi**, con tutti i campi di §13.2:
      `occurrence_id`, `event_id`, `starts_at`, `effective_ends_at`,
      `ends_at_estimated`, `business_date`, `status`, `title`,
      `poster{thumb,card,full,blurhash,width,height}`, locale ridotto,
      categoria, tag, prezzo, `distance_m` con `near`, `is_saved` se autenticato.
- [x] **`is_saved` assente** — non `false` — per chi non è autenticato (§15.8).
- [x] **`preset=ongoing` e `preset=starting_soon` passano da
      `EventOccurrenceQuery`**, gli stessi metodi del sito: verificato che
      restituiscano gli **stessi identificativi** del motore nello stesso
      istante, sia in tre test dedicati (scenario G di §18) sia sul database
      seedato (`ongoing` → `[29,1,2,3]` in API e nel motore; `starting_soon` →
      `[28,4,5,32]`).
- [x] **`editorial_score` mai esposto** (test che ispeziona il corpo grezzo).
- [x] **Paginazione a cursore ovunque**, anche su rilevanza e popolarità; tre
      pagine consecutive senza ripetizioni né salti; cursore alterato → 400.
- [x] **Formato** `{"data": …, "meta": {"next_cursor", "has_more"}}` e
      `{"error": {"code", "message", "fields"}}`.
- [x] **HTTP corretti**: 400 (cursore), 401 (token assente o revocato), 403
      (previsto dal renderer), 404 (slug, città spenta, bozza), 409
      (segnalazione doppia), 422 (validazione), 429 (limiti), 500 (renderer).
- [x] **ETag + `Cache-Control: public, max-age=60, stale-while-revalidate=300`**
      con 304 a corpo vuoto; `private` quando la richiesta è autenticata.
- [x] **60 req/min anonime, 120 autenticate**, con `X-RateLimit-*` e
      `Retry-After` sul 429.
- [x] **`/v1/map/occurrences` minimale**: esattamente `id`, `event_id`, `lat`,
      `lng`, `category_id`, `title`, `starts_at`.
- [x] **Sanctum**: `register|login|logout`, `password/forgot|reset`. Login
      social escluso (D7).
- [x] **OpenAPI** su `/docs/api` e `/docs/api.json`: 20 operazioni documentate.

### Verifica con `curl` su `php artisan serve` (database seedato)

Tutti gli indirizzi di §13.1 percorsi davvero: `/config`, `/cities`,
`/cities/{slug}`, `/events`, `/events/{slug}`, `/events/{slug}/similar`,
`/occurrences/{id}`, `/calendar`, `/venues`, `/venues/{slug}`,
`/venues/{slug}/events`, `/map/occurrences`, `/search`, `/submissions`,
`/reports`, `/auth/*` → 200/201 attesi; errori nella forma di §13.6.
Percorso anche il giro completo dell'autenticazione: registrazione → token →
lettura con `is_saved` → uscita → token revocato che risponde 401.

### Questioni aperte

1. **Le conversioni della locandina non esistono ancora** (§12.1, altra fase):
   `thumb`, `card` e `full` puntano all'originale e `blurhash`, `width`,
   `height` sono `null`. Le chiavi ci sono sempre, quindi il contratto non
   cambierà quando la pipeline arriverà.
2. **§13.5 (rotte utente: `/me`, salvataggi, follow, feed, device) non è
   implementato**: appartiene a F7b. `GET /v1/config` lo dichiara spegnendo
   `saved_events` e `follows`.
3. `RestrictedDocsAccess` limita `/docs/api` all'ambiente locale: in produzione
   servirà definire il gate `viewApiDocs` o accettare che la documentazione
   resti privata.
4. I testi legali di `/v1/config` sono `null` finché non esisteranno le pagine:
   si accendono con `API_LEGAL_TERMS_URL`, `API_LEGAL_PRIVACY_URL` e
   `API_LEGAL_UPDATED_AT`.
5. `API_PASSWORD_RESET_URL` va impostata prima che l'app mobile esista: senza,
   il collegamento del messaggio punta a `/reimposta-password` del sito, che
   non è ancora una pagina.

---

## C7 / F7b — Account, salvataggi e follow (§15) — ✅ VERIFICATO 2026-08-23

Registrazione, accesso con password e con collegamento, verifica dell'indirizzo,
salvataggio da anonimo con migrazione sull'account, follow, feed personale,
cancellazione con anonimizzazione, e i tredici indirizzi di §15.8.
Decisioni in `DECISIONS.md` D29.

### Comandi eseguiti

| Comando | Esito |
|---|---|
| `php -d memory_limit=1G ./vendor/bin/pest` | **563 test passati su 563** (44 nuovi in `tests/Feature/Account/` e `tests/Feature/Api/MeEndpointsTest.php`) |
| `./vendor/bin/pint` | passed |
| `./vendor/bin/phpstan analyse app database --level=6 --memory-limit=1G` | 0 errori sul codice di questa fase |
| `php artisan migrate:fresh` | 1 migration nuova (`notifications`), `users.name` ora nullable |

### Criteri di accettazione §15

- [x] **Il cuore funziona al primo click, senza registrazione** (§15.1): da
      anonimi il salvataggio vive nel `localStorage` e **nessuna** richiesta
      parte. Il cuore è comunque un modulo: senza JavaScript chi è collegato
      salva con un invio e un ricaricamento.
- [x] **Riquadro al terzo salvataggio**, discreto e chiudibile: lo rivela lo
      script contando le voci nel browser. Offre il **promemoria**, non il
      salvataggio — quello funziona già.
- [x] **`POST /v1/me/saved/merge`** (e il gemello del sito
      `POST /salvataggi/unisci`): ignora duplicati, date passate e
      identificativi inesistenti, e risponde con quante ne sono entrate. Il
      `localStorage` si svuota **solo** dopo quella conferma.
- [x] **Si salva l'occorrenza, non l'evento** (§15.3): una sola data futura si
      salva senza chiedere; più date aprono il selettore compatto con «salva
      tutte le date»; una serie ricorrente offre «Segui questo evento», e ogni
      data generata dopo entra da sola in agenda.
- [x] **Follow di locali, tag e categorie**: alimentano feed e digest, non i
      promemoria. Smettere di seguire **non** toglie dall'agenda ciò che era
      già salvato.
- [x] **`/il-mio-feed` e `GET /v1/me/feed`** (§15.7): date future di ciò che si
      segue, in ordine di data, con il cuore acceso su ciò che è in agenda.
      Senza follow, avvio guidato con i locali più attivi e le categorie
      principali — **mai una pagina vuota**, e «più attivi» è un conteggio del
      motore, non delle righe in tabella.
- [x] **Registrazione con email e password oppure con collegamento senza
      password** (§15.2), nome facoltativo, profilo minimo (nome, email, fuso,
      lingua).
- [x] **Verifica obbligatoria prima di qualunque invio**: un account non
      verificato salva e segue, `canReceiveNotifications()` resta falso.
- [x] **Cancellazione self-service con effetto immediato**: anonimizzazione
      (indirizzo `.invalid`), soft delete, e nella stessa transazione via
      salvataggi, follow, dispositivi, archivio e token, con gli invii in
      attesa portati a `cancelled`.
- [x] **Endpoint di §15.8**: `/v1/me` (GET/PATCH/DELETE),
      `/v1/me/notification-preferences` (GET/PATCH), `/v1/me/saved`
      (GET/POST/DELETE) e `/saved/merge`, `/v1/me/follows` (GET/POST/DELETE),
      `/v1/me/feed`, `/v1/me/devices` (POST/DELETE), `/v1/me/notifications`,
      `/v1/me/export`, più `/v1/auth/magic-link` e `/v1/auth/verify-email`.
      OpenAPI: da 20 a **39 operazioni** documentate.
- [x] **`is_saved` assente per chi non è autenticato** anche su queste rotte, e
      nessuna risposta dell'area personale in cache condivisa.

### Scenario H di §18 — percorso davvero

Con `php artisan serve` e database seedato, con `curl` e un contenitore di
cookie: tre date prese dai cuori di `/eventi` (le stesse che il browser
avrebbe nel `localStorage`), registrazione dal modulo, `POST /salvataggi/unisci`
→ `{"merged":3,"ignored":0,"occurrence_ids":[27,28,29]}`, pagina dei salvataggi
con **tre** cuori accesi, una data tolta e **due** rimaste. Lo stesso scenario è
in `tests/Feature/Account/GuestSaveMergeTest.php`, dove la lista di partenza
contiene anche un duplicato, una data passata e un identificativo inesistente:
ne entrano tre.

### Altre verifiche eseguite davvero

- Giro completo dell'API con token vero: `/me`, `POST /me/saved` (201) →
  `GET /me/saved` con `is_saved: true`, `POST /me/follows` (201) → `/me/feed`
  che passa da `onboarding` valorizzato a 18 date, `PATCH
  /me/notification-preferences`, `POST /me/devices`, `/me/export` con le sette
  sezioni, `DELETE /me` seguito da **401** sulla stessa richiesta di prima.
- **Messaggi resi davvero** dal mailer (`MAIL_MAILER=log`), non solo finti:
  collegamento senza password aperto dal browser → sessione creata e arrivo su
  `/il-mio-feed`; collegamento di verifica aperto → `email_verified_at`
  valorizzata e `canReceiveNotifications()` che passa a vero. Un collegamento
  con la firma manomessa risponde 403; uno emesso prima di un cambio di
  password non vale più.

### Questioni aperte

1. **Il motore di invio non è in questa fase.** `scheduled_notifications` viene
   già ripulita quando serve — togliere un salvataggio annulla i promemoria
   ancora in attesa, cancellare l'account annulla tutto — ma nessuno **crea**
   ancora le righe: sono gli scenari I, J e K di §18, insieme a quiet hours e
   tetto giornaliero. `GET /v1/me/notifications` esiste ed è vuoto finché quel
   motore non scrive.
2. **Il collegamento senza password apre una sessione del sito, non consegna un
   token dell'API** (D29, punto 9). Quando esisterà l'app servirà un
   collegamento profondo, come già previsto per la reimpostazione password con
   `API_PASSWORD_RESET_URL`.
3. **I salvataggi sono letti nella città corrente** perché passano dal motore,
   che è scoped sulla città (D11: oggi ne esiste una). Con la seconda città
   servirà decidere se l'agenda personale le attraversi tutte.

---

## C8 / F8 — Media, SEO, performance e cache (§12) — ✅ VERIFICATO 2026-08-23

Pipeline media completa (MIME reale → strip EXIF → resize → WebP + AVIF →
blurhash → CDN, più l'anteprima Open Graph 1200×630), `sitemap.xml` a indice e
`robots.txt`, JSON-LD verificato con `json_decode`, e la tabella di cache di
§12.3 applicata riga per riga. Decisioni in `DECISIONS.md` D30.

### Comandi eseguiti

| Comando | Esito |
|---|---|
| `./vendor/bin/pest` | **597 test passati su 597** (48 nuovi in `tests/Feature/Media/`, `tests/Feature/Seo/`, `tests/Feature/Cache/`) |
| `./vendor/bin/pint` | passed |
| `./vendor/bin/phpstan analyse --level=6 --memory-limit=1G` | 0 errori su tutto il progetto |
| `php artisan media-library:regenerate --force` + `queue:work` | 162 media, sei varianti ciascuno, 0 lavori falliti |

### Criteri di accettazione §12

- [x] **Validazione MIME reale, non l'estensione** (§12.1): `App\Enums\ImageType`
  legge i primi byte; un file con estensione `.jpg` e contenuto PHP viene
  rifiutato dal modulo e, se arriva da un import, cancellato dalla coda.
- [x] **Strip EXIF** (§12.1): provato su un JPEG con riquadro EXIF costruito
  byte per byte — esce senza `Orientation` e con i lati scambiati, perché i
  pixel vengono ruotati prima che il riquadro sparisca.
- [x] **Varianti `thumb 400w` / `card 800w` / `full 1600w` in WebP e AVIF**
  (§12.1), registrate su `Event` e `Venue` con `registerMediaConversions()`;
  `file` conferma "ISO Media, AVIF Image" sui file generati.
- [x] **Blurhash** (§12.1) e segnaposto `data:` nelle proprietà del media.
- [x] **Open Graph 1200×630 generata automaticamente** con `spatie/image`
  (locandina + titolo + data + marchio), **non** con un browser senza schermo:
  136 anteprime composte dalla coda sul database seedato.
- [x] **Tutto in coda, mai bloccante; max 12 MB; jpg/png/webp/heic** (§12.1).
- [x] **CDN** (§12.1): `MEDIA_CDN_URL` riscrive ogni indirizzo di media e di
  conversione senza che una sola vista cambi.
- [x] **`sitemap.xml` a indice** (§12.2): cinque sezioni — pagine, eventi,
  locali, tassonomie, giorni futuri — con spezzatura oltre la soglia e 404
  oltre la fine.
- [x] **`robots.txt`, canonical, Open Graph, X/Twitter, `hreflang`
  predisposto** (§12.2).
- [x] **JSON-LD `Event` per ogni occorrenza pubblicata**, più `Place`,
  `Organization`, `BreadcrumbList`, `WebSite` con `SearchAction` (§12.2),
  riletti con `json_decode` e controllati campo per campo.
- [x] **Full-page cache 5 min invalidata alla pubblicazione** (§12.3).
- [x] **"In corso" e "Inizia tra poco" in frammento separato, TTL 60 s, chiave
  arrotondata al quarto d'ora** (§12.3), con il test che §12.3 chiede: stessa
  chiave a tre secondi, chiave diversa a venti minuti.
- [x] **Conteggi calendario 30 min, tassonomie 24 h, liste API `ETag` +
  `Cache-Control`** (§12.3).
- [x] **Zero layout shift, lazy loading, preload del LCP** (§11.11): ogni
  `<img>` di ogni pagina dichiara `width` e `height`, le locandine sotto la
  piega sono `lazy`, quella sopra è annunciata con un preload AVIF.

### Verifica con `curl` su `php artisan serve` (database seedato)

- `robots.txt`: `User-agent`, sette `Disallow`, riga `Sitemap:` assoluta.
- `sitemap.xml`: indice con cinque righe, tutte 200 (11, 132, 23, 52, 31
  indirizzi); riletti identici dalla cache su disco; `sitemap-eventi-99.xml`
  risponde 404.
- `X-Page-Cache`: `miss` poi `hit` su `/` e `/eventi`, assente su `/cerca` e
  sugli indirizzi con `near=`.
- Token CSRF: due sessioni ottengono due token diversi dalla stessa copia in
  cache; un invio con quel token passa (302), uno con un token qualsiasi è 419.
- Scheda evento: canonical, `og:image` con misure, `twitter:*`, `hreflang`,
  `<picture>` con AVIF e WebP, segnaposto sfocato, preload del LCP, JSON-LD
  completo. Nessuna chiave di traduzione grezza su nessuna pagina.

### Questioni aperte

1. **La memoria della suite passa a 512 MB** in `phpunit.xml`: con centinaia di
   test in un processo solo e la pipeline media che apre file veri, i 128 MB
   predefiniti della CLI non bastavano più (il guasto precedeva questa fase).
2. **L'anteprima social si compone alla prima visita** e non alla
   pubblicazione: chi condivide per primissimo un evento appena pubblicato
   potrebbe far arrivare la locandina nuda invece della scheda composta. Con un
   worker attivo la finestra è di secondi.
3. **`media.cdn_url` è vuota**: la riga esiste e funziona, il fornitore non è
   stato scelto.

---

## C9 / F7c — Motore di invio delle notifiche (§15.4, §15.5, §15.6, §15.9) — ✅ VERIFICATO 2026-08-24

Le righe di `scheduled_notifications` ora nascono, si riprogrammano, si
annullano e partono. Canali attivi: email e archivio in-app (D8).
Decisioni in `DECISIONS.md` D31. **Nessuna migration**: lo schema di §7.10
bastava già.

### Comandi eseguiti

| Comando | Esito |
|---|---|
| `./vendor/bin/pest` | **662 test passati su 662** (61 nuovi in `tests/Feature/Notifications/`) |
| `./vendor/bin/pint` | passed |
| `./vendor/bin/phpstan analyse app database --level=6 --memory-limit=1G` | 0 errori |
| `php artisan migrate:fresh --seed` | 31 migration, **nessuna nuova** |
| `php artisan schedule:list` | `*/5 * * * * notifications:send`, `0 * * * * notifications:plan` |

### Architettura di §15.5, rispettata alla lettera

- [x] **Il promemoria nasce dal salvataggio**, non da un cron che scandaglia
      `saved_events`: `SaveOccurrences` e `SaveOccurrenceForFollowers` creano le
      righe con `send_at = starts_at − 24h` e `− 3h` (offset configurabili per
      persona) e la chiave di §7.10 (`reminder_3h:user_42:occ_918`).
- [x] **La modifica dell'occorrenza riprogramma o annulla** dall'observer, che è
      il punto attraversato da redazione, gestore e import insieme: orario
      spostato → `send_at` aggiornato; `cancelled` → promemoria annullati e
      annullamento accodato; data finita nel passato → `skipped`.
- [x] **Il worker ogni cinque minuti** preleva con `SELECT ... FOR UPDATE SKIP
      LOCKED` dentro una transazione (D5: senza Redis il blocco è quello del
      database), applica preferenze, ore di silenzio e tetto giornaliero,
      sceglie il canale, invia, scrive `notification_log` e segna la riga
      `sent | skipped (con motivo) | failed` con tre tentativi a distanza
      crescente (5, 15, 45 minuti).
- [x] **`dedupe_key` unica a livello di database** è la garanzia contro il
      doppio invio: il test lo dimostra con l'errore 1062 del motore, non con un
      controllo applicativo.

### Le sette tipologie di §15.4

- [x] Promemoria di data salvata — 24h e 3h, offset configurabile, **attivo**.
- [x] Annullata o spostata — immediato, **non disattivabile**: nessun
      interruttore esiste, e il collegamento di disiscrizione non compare.
- [x] Sold out — immediato, con interruttore, consuma il tetto.
- [x] Nuovi eventi da chi segui — **riepilogo settimanale**, mai per singolo
      evento: otto locali seguiti producono un messaggio con otto voci.
- [x] Riepilogo giornaliero — orario scelto, 17:00 predefinito, disattivo di
      suo; "stasera" lo definisce `EventOccurrenceQuery`, non il riepilogo.
- [x] Newsletter del weekend — giovedì, e solo con la data del consenso in
      `marketing_opt_in_at` (§15.9).
- [x] Ai gestori — pubblicato, rifiutato (con il motivo), locale che non
      pubblica da 21 giorni (al più una volta al mese).

### Regole di volume — vincolanti

- [x] **Mai una notifica per singolo evento nuovo** di un locale seguito.
- [x] **Massimo 2 al giorno**, contate nella giornata locale di chi riceve e
      solo sui tipi intrusivi: i promemoria di ciò che si è messo in agenda a
      mano non contano, come prescrive §15.4.
- [x] **Ore di silenzio su tutti i canali tranne gli annullamenti**: l'invio si
      sposta all'uscita, e se nel frattempo è diventato inutile diventa
      `skipped`.
- [x] **Deep link alla scheda evento** su ogni notifica, mai la home.

### §15.9 — privacy

- [x] Email con **modello Blade a tabelle e stili in linea** (i client di posta
      non hanno un motore moderno), disiscrizione a un click in ogni messaggio,
      intestazioni `List-Unsubscribe` e `List-Unsubscribe-Post` (RFC 8058).
- [x] **Pagina preferenze raggiungibile senza accesso**, con indirizzo firmato a
      trenta giorni: cambia cosa si riceve, non chi si è.
- [x] `notification_log` purgato oltre i dodici mesi da `notifications:plan`.
- [x] Un account **non verificato non riceve nulla** (§15.2): la riga esiste e
      viene saltata con motivo `unverified`.

### Il pannello, che è metà della ragione per cui esiste la tabella

`/admin/scheduled-notifications` elenca ogni invio previsto con destinatario,
oggetto, stato, motivo e chiave di deduplica; filtra per stato, tipo e
"da mandare adesso"; e porta un contatore di righe scadute e non partite — zero
è la risposta normale, un numero che cresce è l'unico modo di accorgersi che il
worker dei cinque minuti non sta girando. Si guarda con `notifications.view`
(anche il moderatore), si ferma un invio con `notifications.manage` (solo chi
amministra) e solo finché è in attesa: ciò che è partito è cronaca.

### Scenari I, J, K di §18 — percorsi davvero

- **I.** Promemoria a 3h, orario spostato di due ore → **la stessa riga**,
  stesso identificativo e stessa chiave, `send_at` avanti di due ore; nessun
  duplicato. Evento spostato a ieri → `skipped` con motivo `occurrence_past`,
  e nessun avviso di spostamento per una data ormai passata.
- **J.** Occorrenza salvata da **40 utenti** che avevano spento tutto il resto →
  40 righe `event_cancelled` in attesa, zero promemoria residui, e 40 messaggi
  consegnati facendo girare il worker cinque minuti dopo; 40 righe in
  `notification_log`.
- **K.** Otto locali seguiti → **un** riepilogo con otto voci, ciascuna con il
  proprio collegamento alla scheda. Tre notifiche intrusive nello stesso giorno
  → due inviate, la terza `skipped` con motivo `frequency_cap`. Promemoria che
  cade a mezzanotte e mezza con silenzio 23:30–08:00 → spostato alle 08:00 e
  ancora in attesa; se la serata nel frattempo è finita, `skipped`. Un
  annullamento all'una di notte parte comunque.

### Verifica sul database seedato (`php artisan serve`, mailer su file)

- Tre date salvate → sei righe in attesa con le chiavi di §7.10 e gli orari
  attesi (`reminder_24h:user_6:occ_42 | 2026-08-25 12:15 | pending`).
- Annullata una delle tre → i due promemoria collegati passano a `cancelled` e
  compare `cancelled:user_6:occ_40` con `send_at` immediato; il worker la manda
  e scrive `notification_log`.
- `notifications:plan` programma il riepilogo giornaliero per l'indomani alle
  17:00 locali e non programma quello settimanale, che cade oltre l'orizzonte
  di 36 ore: la pianificazione ripetuta non aggiunge nulla.
- Messaggio reso davvero: oggetto «Domani: Sagra dei vini del territorio»,
  collegamento `http://…/eventi/sagra-dei-vini-del-territorio`, «Comincia
  mercoledì 26 agosto alle 14:15», «Da Circolo Arci La Fornace, Padova.»,
  entrambe le intestazioni di disiscrizione, zero chiavi di traduzione grezze.
- Con `curl`: pagina preferenze firmata **200** con le caselle e le ore di
  silenzio, non firmata **403**, collegamento di disiscrizione **200** e la
  preferenza risulta davvero spenta subito dopo.

### Questioni aperte

1. **Il tentativo che il motore riprova è quello di accodamento, non di
   consegna.** `ScheduledMessage` è una notifica di coda: se il server di posta
   rifiuta, a riprovare è il worker della coda (`--tries=3` nel cron del
   RUNBOOK), non `scheduled_notifications`, che a quel punto risulta già
   `sent`. È corretto — la riga dice «consegnata al canale» — ma un guasto SMTP
   prolungato si legge in `failed_jobs`, non nel pannello degli invii.
2. **Il riepilogo settimanale copre tutto ciò che si segue**, locali, generi ed
   etichette (D31, punto 10). Se un giorno si vorranno tre riepiloghi distinti,
   servirà un filtro per soli locali in `EventOccurrenceQuery`.
3. **Le ore di silenzio non hanno un valore predefinito** (D31, punto 6): chi
   non le dichiara riceve anche di notte. È una scelta, e va ricontrollata alla
   prima segnalazione di un promemoria arrivato alle tre del mattino.
4. **Il giorno del riepilogo settimanale (martedì 18:00) e quello della
   newsletter (giovedì 16:00) sono in `config/notifications.php`**: §15.4 fissa
   solo il giovedì della newsletter, il resto è una scelta editoriale da
   confermare con il committente.

---

## Verifica finale complessiva — ✅ ESEGUITA 2026-08-24

Verifica indipendente dell'intero progetto, tutta misurata su server attivo
(`php artisan serve`, database `eventi_local` riseedato con
`migrate:fresh --seed`).

### Qualità statica

| Comando | Esito |
|---|---|
| `npm run build` | verde (804 ms; warning noto sui chunk mappa > 500 kB) |
| `php artisan migrate:fresh --seed` | senza errori (135 locandine, 25 copertine) |
| `./vendor/bin/pest` | **662 test verdi, 2438 asserzioni** (~96 s) |
| `./vendor/bin/pint --test` | pulito su tutto il repo |
| `./vendor/bin/phpstan analyse` (livello 6) | **0 errori** |

I tre attriti fra agenti paralleli segnalati nei riepiloghi (ImageSet.php su
PHPStan, Pint sui test Seo e su ViewOnSiteActionTest) risultano già rientrati:
la suite unificata passa per intero.

### Verifiche HTTP (curl, misurate)

- 200 su `/docs/api` (OpenAPI 3.1.0 valida via `json_decode`, 39 operazioni),
  `/api/v1/config|events|venues|map/occurrences|calendar|search|cities`,
  `/sitemap.xml` e le sue 5 sezioni (`sitemap-eventi-99.xml` → 404), `/robots.txt`.
- `GET /api/v1/me` senza token → **401**; ETag + `If-None-Match` → **304**;
  cursore: pagina 1 e 2 senza sovrapposizioni, cursore illeggibile → **400**;
  70 richieste rapide → 60×200 + **10×429** con `X-RateLimit-*` e `Retry-After`.
- `X-Page-Cache: miss` → `hit` alla seconda richiesta della stessa pagina.

### Scenario G di §18 — la verifica che conta

Sito e API interrogati **nello stesso istante** (richieste parallele):

| Preset | Sito `/eventi?date=…` | API `?preset=…` | Esito |
|---|---|---|---|
| `starting_soon` | 3, 4, 5 | 3, 4, 5 | **identici, stesso ordine** |
| `ongoing` | 1, 2 | 1, 2 | **identici, stesso ordine** |

### Altre verifiche di merito

- `is_saved` **assente** (non `false`) per l'anonimo; `editorial_score` a 0
  occorrenze su tutti gli 8 endpoint di lettura (grep sul corpo grezzo).
- JSON-LD della scheda evento: 2 blocchi validi (`Event` + `BreadcrumbList`),
  l'`Event` con `name`, `startDate`, `location`, `offers`, `eventStatus`.
- Chiave di "inizia tra poco" arrotondata al quarto d'ora: due chiamate a
  4 secondi di distanza → stessa chiave `dal-vivo:1:inizia-tra-poco:…T00:15`.
- Nessuna stringa UI hardcoded trovata nei Blade (grep su testo grezzo fra tag).
- Coda smaltita con `queue:work --stop-when-empty`: 160/160 media con
  conversioni generate, 0 `failed_jobs`; il payload `poster` dell'API passa da
  originale+`blurhash: null` a WebP `thumb`/`card` + blurhash + misure.
  **In produzione serve un worker attivo**, o le locandine restano originali.

### Restano aperte (nessuna bloccante, tutte già documentate)

`/docs/api` solo in locale (gate `viewApiDocs` da definire) · `API_PASSWORD_RESET_URL`
e `API_LEGAL_*` vuote · `media.cdn_url` senza fornitore · quiet hours senza
default · giorno/ora del riepilogo settimanale da confermare · retry SMTP
leggibile in `failed_jobs` e non nel pannello · agenda personale multi-città da
decidere alla seconda città · fuso non dichiarato sui DateTimePicker di `/admin`.

---

## C10 / §16 — Pagine legali, consenso e analitica — ✅ VERIFICATO 2026-08-31

Decisioni in `DECISIONS.md` **D34**. Schema in `SCHEMA.md` §3.25, §3.26 e
deviazioni 22-23. Operatività in `RUNBOOK.md`, sezione «Pagine legali, consenso
e analitica».

### Che cosa esiste adesso

| Cosa | Dove |
|---|---|
| `/pagine/{slug}` con contenuto in database | `PageController`, tabella `pages`, `resources/views/pages/show.blade.php` |
| Cinque testi italiani veri | `PageSeeder`: privacy, cookie, termini, chi-siamo, contatti |
| Redazione senza rilascio | `/admin` → Pagine informative (`PageResource`, editor Markdown) |
| Banner del consenso | `<x-cookie-banner>` nel layout, `POST /consenso` |
| Pannello per cambiare idea | `<x-consent-preferences>` in fondo alla Cookie Policy, `DELETE /consenso` |
| Registro del consenso | tabella `consent_logs`, `App\Actions\RecordConsent` |
| Analitica senza cookie | `<x-analytics>`, `AnalyticsScript`, `ANALYTICS_*` in `.env` |

### Test (89 nuovi, tutti verdi)

- `tests/Feature/Legal/PagesTest.php` — 28: rotta, 404 sulla bozza e sullo slug
  inesistente, canonico, markdown senza marcatura grezza, piè di pagina dai
  contenuti pubblicati, **i testi seminati** (coordinate mai salvate, dati
  raccolti uno per uno, cancellazione account, diritti e Garante, conservazione,
  locandine e rimozione nei Termini, D9 sul futuro a pagamento), idempotenza del
  seeder, permessi, e i tre casi editoriali via Livewire (crea → pubblicata sul
  sito; corregge il titolo → **lo slug non cambia**; spegne → 404).
- `tests/Feature/Legal/ConsentTest.php` — 21: compare al primo accesso e non al
  secondo (accettando **e rifiutando**), ricompare al cambio di
  `CONSENT_VERSION`, non impedisce la lettura, **due pulsanti con la stessa
  identica classe**, entrambi `<button type="submit">` senza `tabindex`
  negativo, rifiuto in un solo invio, granularità, «rifiuta» vince su una
  casella rimasta spuntata, registro (versione, finalità, stesso `consent_id`
  alla seconda scelta, utente collegato, **nessuna colonna IP**), revoca, e la
  cache di pagina che **non serve a chi non ha scelto la copia di chi ha
  accettato**.
- `tests/Feature/Legal/AnalyticsTest.php` — 12: con `ANALYTICS_*` vuote
  **nessun indirizzo esterno** nell'HTML (ricerca su tutti gli `src`/`href`, non
  sul nome del fornitore), niente a chi non ha scelto e a chi ha rifiutato,
  attributo giusto per Plausible e per Umami, spenta se manca una variabile, se
  il fornitore è sconosciuto o se lo script è in chiaro.
- `PanelRenderingTest`: elenco, modulo di creazione e scheda di modifica delle
  pagine, senza chiavi di traduzione grezze.

### Verifiche eseguite davvero (server + browser)

- `php artisan serve` su database seedato: 200 su `/pagine/privacy|cookie|
  termini|chi-siamo|contatti`, **404** su `/pagine/inesistente`.
- Unici indirizzi esterni nell'HTML della pagina iniziale: le due attribuzioni
  cartografiche (collegamenti, non risorse). Nessuno script di terze parti.
- Browser a **390 px**: banner in fondo, 326 px su 844, nessun velo, scorrimento
  della pagina libero, **nessun trabocco orizzontale**; «Accetta» e «Rifiuta»
  con la stessa classe, lo stesso sfondo e la stessa misura (171×40); «Rifiuta»
  raggiunto con **un tabulatore** e attivato con Invio → banner rimosso,
  **pagina non ricaricata**, riga `reject_all` nel registro; preferenza
  granulare → `custom` con `statistics: true`; alla visita successiva il banner
  non torna; «Cancella la mia scelta» lo fa tornare. Zero errori in console.
- `/admin/pages/{slug}/edit` si apre con l'editor Markdown, i suggerimenti in
  italiano e l'interruttore di pubblicazione.

### Due difetti trovati provando, non deducendo

1. **`form.action` restituiva `[object RadioNodeList]`** — il modulo contiene
   tre controlli chiamati `action` (i pulsanti), che oscurano la proprietà del
   modulo. La richiesta partiva verso un indirizzo inesistente, il ripiego
   scattava ma senza il pulsante premuto, e il sintomo era «premo Rifiuta e il
   banner resta lì». Corretto leggendo l'attributo e allegando la scelta al
   ripiego.
2. **403 su `/admin/pages` con un amministratore vero** — il permesso
   `pages.manage` non era assegnato finché `RolesAndPermissionsSeeder` non
   rigirava, mentre i test restavano verdi perché ognuno semina i ruoli da capo.
   È il caso già previsto dal RUNBOOK, incontrato per la prima volta.

### Resta aperto

`SEO_ORGANIZATION` e `SEO_ORGANIZATION_EMAIL` vuote → il titolare del
trattamento nei testi è il nome del prodotto e il recapito è `MAIL_FROM_ADDRESS`:
**vanno riempite prima della pubblicazione**, o corretti i testi dal pannello.
Nessuna dichiarazione di accessibilità (non richiesta da §16, e scriverne una
non verificata sarebbe peggio che non averla). Nessuna CSP: §16 la chiede, ma
appartiene alle intestazioni di sicurezza e non a questa fase.

---

## Verifica finale F10 — ✅ ESEGUITA 2026-09-01

Verifica indipendente dell'intera fase F10, tutta misurata su database proprio
`eventi_test_verifica` (MariaDB 127.0.0.1:3307), con server attivo e curl.

### Qualità statica

| Comando | Esito |
|---|---|
| `npm run build` | verde (1.07 s; warning noto sul chunk mappa > 500 kB) |
| `php artisan migrate:fresh --seed` | senza errori (140 locandine, 25 copertine, 5 pagine) |
| `./vendor/bin/pest` | **1064 test verdi, 3570 asserzioni** (~163 s) — erano 781 prima di F10: **+283** |
| `./vendor/bin/pint --test` | pulito su tutto il repo |
| `phpstan` livello 6 | **0 errori** |

### Pagine legali e consenso (curl su `php artisan serve`)

- 200 con contenuto reale su `/pagine/privacy` (28,5 kB), `/pagine/cookie`
  (24,7 kB), `/pagine/termini` (24,7 kB), `/pagine/chi-siamo` (22,5 kB),
  `/pagine/contatti` (22,4 kB); i testi citano coordinate mai salvate,
  localStorage, titolare, locandine e titolarità, gratuito/a pagamento (D9).
  `/pagine/inesistente` → **404**.
- La homepage al primo accesso contiene `consent-banner`.
- Con `ANALYTICS_*` vuote: **zero `src` esterni** nell'HTML di homepage e
  pagine legali; gli unici `href` esterni sono le due attribuzioni
  cartografiche obbligatorie (ODbL/OpenStreetMap), collegamenti e non risorse.

### Backup e restore — la prova che il backup esiste (§16)

1. `backup:run --only-db` → archivio
   `storage/app/private/eventi/2026-08-31-22-59-56.zip`, **57.486 byte**,
   contenente `db-dumps/mariadb-eventi_test_verifica.sql.gz` (84.558 byte),
   «Backup verified».
2. Restore del dump in un database di prova `eventi_test_verifica_restore`.
3. Confronto originale → ripristinato: **47 tabelle = 47 tabelle**;
   locali 25 = 25, eventi 138 = 138, occorrenze 145 = 145, utenti 5 = 5,
   pagine 5 = 5.
4. Database di prova eliminato.

### Endpoint di stato

`/stato` senza chiave → **404**; chiave sbagliata → **404**; chiave giusta →
**503** (degradato: su questo Mac `UsedDiskSpace` legge 100% per APFS, e coda
e scheduler non girano in sviluppo — Database, Cache, ImportSources e
ScheduledTasks sono `ok`). Il comportamento in produzione dipende da
`OPS_HEALTH_TOKEN` in `.env` (vuoto = 404 per tutti, voluto).

### Lighthouse (banco locale del RUNBOOK: nginx + php -S, coda smaltita, mediana di 3 giri)

| URL | Perf | A11y | SEO | LCP |
|---|---|---|---|---|
| `/` | 0.91 | 0.96 | 1.00 | 3378 ms |
| `/eventi` | **0.80** | 0.96 | 1.00 | **5179 ms** |
| scheda evento | 0.96 | 0.97 | 1.00 | 2702 ms |

Soglie di §11.11 **non ammorbidite**: il lavoro `lighthouse` resta rosso su
`categories:performance` di `/eventi` e su LCP < 2000 ms ovunque, come
dichiarato in D37 (il banco parla HTTP/1.1 e resta ~1,5 s sopra la produzione;
`deploy` non dipende da `lighthouse`).

### Resta aperto (invariato rispetto ai riepiloghi degli agenti)

- `SEO_ORGANIZATION`, `SEO_ORGANIZATION_EMAIL`, `OPS_ALERT_EMAIL`,
  `OPS_HEALTH_TOKEN`, chiavi Turnstile: da riempire nel `.env` di produzione.
- Al rilascio: `php artisan migrate`, `db:seed --class=RolesAndPermissionsSeeder`
  (permesso `pages.manage`), `db:seed --class=PageSeeder`,
  `schedule-monitor:sync` (già nel deploy).
- LCP < 2 s non raggiunto in nessun ambiente; su `/eventi` la Performance del
  banco è scesa a 0.80 (5,2 s di LCP): le due cause note di D37 (locandina LCP
  con `loading=lazy` sul mobile, varianti AVIF pesanti) restano da correggere.
- Nessuna CSP (§16 la cita): rimandata alle intestazioni di sicurezza.

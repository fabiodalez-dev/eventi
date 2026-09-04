# Piattaforma Eventi Cittadini — Piano tecnico esecutivo v2

**Documento operativo per agente di sviluppo.**
**Fase 1:** sito web + backend + sistema editoriale + API. Le app Android/iOS vengono dopo e consumano l'API v1 come contratto stabile.

**Nome di lavoro:** `inCittà`.
**Città pilota:** Padova e provincia. **Nessuna riga di codice può dipendere da Padova.**
**Data di stesura:** 23 agosto 2026. Le versioni dello stack in §4 sono verificate a questa data.

---


> ## Stato del documento
>
> **Questo piano è del 23 agosto 2026 ed è il documento di partenza.** La Fase 1
> è stata costruita e messa in produzione su `eventi.fabiodalez.it`; lungo la
> strada alcune prescrizioni si sono rivelate impraticabili o sono state
> superate da decisioni del committente.
>
> I passaggi che **non valgono più** sono barrati sul posto, con la ragione e il
> numero della decisione che li ha sostituiti. Il registro completo, con le
> motivazioni per esteso e le condizioni per tornare indietro, è in
> [`DECISIONS.md`](DECISIONS.md) — quarantacinque voci alla data di quest'ultima
> revisione.
>
> Le tre deviazioni che cambiano di più la fisionomia del sistema:
>
> | Il piano prescriveva | Si è fatto | Perché |
> |---|---|---|
> | PostgreSQL + PostGIS | **MariaDB** con tipi spaziali | shared hosting senza root (D3) |
> | Redis, Horizon, Meilisearch | **file, database, Scout su database** | nessun demone installabile (D5) |
> | Web Push | **push + posta elettronica** | installabile da webpush 12.1 (D54, supera D8) |
>
> **Il disegno del frontend è cambiato** per decisione del committente: si segue
> un riferimento visivo esterno («Modernist»), e dove quel riferimento e le
> prescrizioni di §11 non vanno d'accordo, vale il riferimento. Le sezioni
> §11.2-§11.6 vanno quindi lette come intento — cosa la pagina deve permettere
> di fare — più che come descrizione della forma.
>
> *Ultima revisione: 1 settembre 2026.*

---

## 0. Come usare questo documento

L'agente esegue fino alla fine nell'ordine di §17. Ogni fase termina con i propri criteri di accettazione verdi. Non si apre la fase successiva prima.

Documenti che l'agente deve mantenere aggiornati nel repo:
- `docs/DECISIONS.md` — ogni decisione tecnica presa, con data e motivazione (ADR leggeri).
- `docs/SCHEMA.md` — schema dati corrente e ogni deviazione da questo documento.
- `docs/API.md` + OpenAPI generato.
- `docs/RUNBOOK.md` — deploy, rollback, restore, rotazione segreti.
- `docs/PROGRESS.md` — stato delle fasi e criteri di accettazione spuntati.

---

## 1. Visione

Il prodotto risponde a una sola domanda: **"stasera cosa c'è da fare?"**

Oggi per rispondere servono Instagram, Facebook, Telegram, i siti dei locali e le locandine sui muri. La piattaforma deve diventare **il calendario pubblico degli eventi di una città e della sua provincia**.

Aprendo il sito l'utente deve capire in pochi secondi: cosa è in corso adesso, cosa inizia tra poco, cosa c'è stasera, domani, questo weekend; cosa è gratuito; cosa è vicino; cosa appartiene a un certo genere; dove si trova, quanto costa e come arrivarci.

Non è una directory di locali. È **un motore di scoperta di eventi locali**, con Padova come primo dataset.

### KPI critico
Non "quanti utenti abbiamo", ma: **quando un utente apre il sito, trova davvero qualcosa da fare?**

Misure primarie:
- % dei prossimi 14 giorni con ≥ 3 eventi pubblicati
- numero medio di eventi disponibili nelle prossime 24 ore
- eventi futuri totali in qualunque momento (target ≥ 100)
- locali attivi (≥ 1 evento negli ultimi 30 giorni)

**Dove si guardano** (aggiunto il 2026-09-02). Queste misure sono rimaste a
lungo scritte qui e in nessun altro posto: il pannello contava eventi
incompleti, duplicati e locandine mancanti — cioè se un evento è *scritto
male* — mai se giovedì sera la città è vuota. Il rovescio esatto delle
priorità dichiarate in questo stesso paragrafo: la velocità di una pagina
aveva un controllo bloccante in CI, il fatto che quella pagina avesse
qualcosa da mostrare non aveva nemmeno un numero.

Ora la copertura è un controllo di salute (`CalendarCoverageCheck`): quanti dei
prossimi quattordici giorni hanno almeno tre date, e quante occorrenze future
esistono in tutto. Avvisa sotto il 70% dei giorni coperti, fallisce sotto il
40%. Si legge con `php artisan health:check` e nella pagina di stato.

Si conta per `business_date` e non per `starts_at`: un concerto che comincia
all'una di notte appartiene alla serata prima (§8), e contarlo sul giorno
solare direbbe che mercoledì c'è qualcosa quando per chi legge quella serata è
martedì.

Misure secondarie: click su "Indicazioni", click su "Biglietti", salvataggi, condivisioni, tempo medio di inserimento evento da parte di un gestore, eventi segnalati come errati / totale, tempo medio di approvazione.

---

## 2. Principi non negoziabili

**2.1 L'utente viene prima del database.** Le tabelle esistono per rendere possibile questo percorso: *apro → vedo cosa c'è oggi → filtro Musica → vedo cosa inizia tra poco → scelgo → vedo dove → vado.*

**2.2 Zero pagina vuota.** Una piattaforma di eventi che mostra "Non ci sono eventi" è morta. Il sistema deve funzionare **anche senza locali iscritti**: la redazione crea locali, crea eventi, importa, corregge, verifica, pubblica. Al lancio devono già esistere **25 locali e 120 eventi futuri**, con ≥ 3 eventi nella maggior parte dei prossimi 14 giorni.

**2.3 Il sito è un prodotto autonomo, non l'anteprima dell'app.** Deve essere completo, indicizzabile e usabile per conto proprio. L'app arriverà dopo e consumerà la stessa API.

**2.4 Inserire un evento deve costare meno di 90 secondi da smartphone**, con la locandina come input principale. Se costa di più, i locali non pubblicheranno e il prodotto muore per fame di contenuti.

**2.5 Una sola definizione di "stasera".** Se sito, API, notifiche e app arrivano ad avere quattro definizioni diverse di "oggi", il prodotto è irreparabile. Vedi §8.

**2.6 Estendibilità come test di ogni decisione.** Davanti a un dubbio: *questa scelta rende più facile o più difficile arrivare a 50 città?* Se lega il sistema a Padova, a un singolo locale, a un singolo provider o a una singola app, è probabilmente sbagliata.

---

## 3. Attori

| Ruolo | Può |
|---|---|
| **Guest** | Navigare, cercare, filtrare, mappa, calendario, condividere, aggiungere al calendario, proporre eventi, segnalare errori |
| **User** | Tutto quanto sopra + salvare eventi, seguire locali/categorie/tag, notifiche, feed personalizzato, preferenze |
| **Venue Owner** | Gestire il proprio locale, creare/modificare/duplicare eventi, ricorrenze, statistiche, invitare collaboratori |
| **Venue Editor** | Gestire eventi del locale. **Non** può toccare dati sensibili del locale, proprietari, inviti |
| **Moderator** | Approvazioni, moderazione eventi/locali, segnalazioni, duplicati, qualità contenuti |
| **Admin** | Tutto il prodotto: città, tassonomie, locali, utenti, eventi, import, featured, configurazione |
| **Super Admin** | Infrastruttura: queue, feature flag, diagnostica, impostazioni di sistema |

Implementazione: `spatie/laravel-permission` per i ruoli globali + pivot `venue_user` (`role` = `owner|editor`) per l'appartenenza. **Ogni Policy verifica sempre il `venue_id`.** L'isolamento fra locali ha test dedicati e non negoziabili (§18, Scenario F).

---

## 4. Stack tecnologico (verificato al 23 agosto 2026)

### Backend
**Laravel 13** — release corrente, uscita il 17 marzo 2026, richiede **PHP 8.3 minimo**; bugfix fino a Q3 2027, security fix fino a Q1 2028. Usare PHP 8.4 se tutto lo stack è compatibile.
Laravel 12 sarebbe già una release precedente: per un progetto nuovo non ha senso partire da lì.

Laravel 13 include primitive AI di prima parte, risorse JSON:API e ricerca vettoriale nativa: rilevanti più avanti per l'estrazione dati dalle locandine (§14.3) e per gli "eventi simili" (§11.5). **Non usarle in fase 1** se non dove esplicitamente indicato.

### Pannelli
**Filament 5** (rilasciato il 16 gennaio 2026), che richiede **Livewire 4**.

> **Task bloccante in F0.** Filament 5 non aggiunge funzionalità rispetto a Filament 4: esiste per supportare Livewire 4. Alcuni plugin di terze parti potrebbero non essere ancora compatibili con la v5. L'agente deve, **prima di scrivere qualsiasi altra cosa**, verificare la compatibilità v5 di tutti i pacchetti in §4.9 e dei plugin Filament necessari. Se un pacchetto indispensabile non è pronto, ripiegare su **Filament 4 + Livewire 3** è una scelta legittima: registrarla in `docs/DECISIONS.md` con l'elenco dei pacchetti bloccanti e la data di ricontrollo. Non bloccare il progetto per inseguire una major che non porta funzionalità.

Due pannelli separati: `/admin` (staff) e `/gestione` (locali, con tenancy su `Venue`).

Valutare in F0 **Laravel Boost** e **Filament Blueprint**: entrambi servono a dare agli agenti AI linee guida e piani di implementazione più accurati sul framework. Registrare la scelta in `docs/DECISIONS.md`.

### Frontend pubblico
**Blade + Livewire 4 + Tailwind CSS 4 + Alpine.js. Nessuna SPA.**
Motivi: SEO (metà del valore del prodotto arriva da chi cerca su Google), TTFB, URL condivisibili e indicizzabili, rendering server-side, semplicità di manutenzione per un team piccolo.

### Database
**MariaDB 10.11+** (D3). ~~PostgreSQL 16 + PostGIS~~: il server di destinazione è shared hosting cPanel, dove nessun demone PostgreSQL è installabile. Coordinate come `POINT` con `SPATIAL INDEX` e SRID 0 — sempre `POINT(lng, lat)`, il solo formato che si comporta allo stesso modo su MySQL e MariaDB (D4). Le query geospaziali passano da `GeoQueryInterface`: `MBRContains` per usare l'indice, `ST_Distance_Sphere` per raffinare. Nessuna dipendenza obbligatoria da Google Maps.

### Altri componenti
- Cache/queue: **file e database** (D5). ~~Redis + Horizon~~: nessun demone Redis sulla shared hosting. Il worker gira da cron con `queue:work --stop-when-empty`, e senza lock distribuito la garanzia contro il doppio invio è il vincolo `dedupe_key UNIQUE`, che §7.10 già prescriveva
- Ricerca: **Laravel Scout con driver `database`** e indici FULLTEXT (D5). ~~Meilisearch~~: nessun demone installabile
- Media: **Spatie Media Library** + Intervention Image, storage S3-compatible (Cloudflare R2 / Hetzner Object Storage) dietro CDN
- Mappe: **Leaflet** con tile OpenStreetMap. ~~MapLibre GL~~: il disegno adottato inverte le tile in scala di grigi con un filtro CSS, e MapLibre le disegna in WebGL, dove i filtri CSS non arrivano. Leaflet pesa anche molto meno
- Geocoding: dietro `GeocodingServiceInterface`, implementazione iniziale Nominatim/Photon. **Il codice non deve mai dipendere direttamente dal provider.**
- API auth: **Laravel Sanctum**
- OpenAPI generato automaticamente, esposto su `/docs/api`
- Mail: Postmark o Brevo
- Push (fase mobile): FCM via `laravel-notification-channels/fcm`
- Analytics: Plausible o Umami self-hosted

### 4.9 Pacchetti (verificare compatibilità in F0)
```
filament/filament                 spatie/laravel-permission
spatie/laravel-medialibrary       spatie/laravel-sluggable
spatie/laravel-activitylog        spatie/laravel-query-builder
spatie/laravel-sitemap            spatie/icalendar-generator
sabre/vobject                     rlanvin/php-rrule
laravel/scout + meilisearch       laravel/sanctum
laravel/horizon                   laravel/pulse
intervention/image                dedoc/scramble
pestphp/pest                      larastan/larastan
laravel/pint
```

### Qualità
Pest (+ browser testing), PHPStan/Larastan livello 6+, Laravel Pint (preset `laravel`), Lighthouse CI con soglie bloccanti.

---

## 5. Regole per l'agente

**5.1 Non improvvisare.** Lo schema di §7 è il contratto. Serve una modifica? migration + aggiornamento di `docs/SCHEMA.md` + nota in `docs/DECISIONS.md` + test. Mai modifiche silenziose al modello.

**5.2 Ogni fase va chiusa.** Ordine in §17. Una fase non è finita finché i suoi test non passano.

**5.3 Nessun codice provvisorio.** Niente `TODO`, `dd()`, codice morto, mock permanenti, endpoint inutilizzati, dati fake fuori dai seeder, query duplicate.

**5.4 Convenzioni.** PSR-12 + Pint; PHPStan 6+; DB in inglese snake_case; codice in inglese; UI in italiano via `lang/it` (predisporre `lang/en`, `lang/de`); **nessuna stringa UI hardcoded**; enum PHP nativi per ogni stato; Form Request per ogni input; Policy per ogni modello esposto; logica in Action/Service, controller di sola orchestrazione.

**5.5 Commit e branch.** Conventional commits (`feat:`, `fix:`, `test:`, `refactor:`, `chore:`), un branch per fase (`feature/f5-public-site`), PR con la checklist dei criteri di accettazione.

**5.6 Ogni funzione temporale passa da `EventOccurrenceQuery`.** Se compare una seconda implementazione di "oggi", "stasera" o "in corso" da qualunque parte del codice, è un bug da correggere subito.

---

## 6. Struttura del progetto

```
app/
├── Actions/          PublishEvent, ApproveVenue, GenerateOccurrences, MergeDuplicates…
├── DTOs/
├── Enums/
├── Models/
├── Policies/
├── Queries/          EventOccurrenceQuery  ← cuore logico
├── Services/         Geocoding, Import, Search, Quality
├── Jobs/
├── Notifications/
├── Http/
│   ├── Controllers/Web/
│   ├── Controllers/Api/V1/
│   ├── Requests/
│   ├── Resources/V1/
│   └── Middleware/
├── Filament/
│   ├── Admin/
│   └── Venue/
├── Livewire/
└── Support/
```

Domini: Cities · Venues · Events · Occurrences · Taxonomies · Users · Moderation · Imports · Notifications · Analytics.

---

## 7. Modello dati

Tutte le tabelle hanno `id`, `created_at`, `updated_at`. Soft delete su `venues`, `events`, `users`.

### 7.1 `cities`
```
name  slug  province_code  province_name  region  country_code
timezone  center_lat  center_lng  default_zoom  bounds  radius_km
locale  is_active  launched_at
night_cutoff_time      -- default '06:00', vedi §8.2
starting_soon_minutes  -- default 180, vedi §8.4
settings (json)        -- categorie attive, testi home, colore accento, feature flag
```
Aggiungere Vicenza deve significare `INSERT INTO cities`, non un deploy.

### 7.2 `venues`
```
city_id  name  slug  type  description  short_description
address  address_extra  postal_code  municipality  province_code
lat  lng  location(geography Point 4326)
phone  email  website  socials(json)  opening_hours(json)
capacity  accessibility(json)  requires_membership  membership_notes
status(draft|pending|approved|suspended|rejected)  is_verified
auto_publish              -- se true, gli eventi del locale saltano la coda di moderazione
approved_at  approved_by  rejection_reason  claim_token
default_event_settings(json)  stats_cache(json)
```
Tipi: `bar, pub, circolo, centro_sociale, club, teatro, cinema, libreria, associazione, galleria, spazio_pubblico, ristorante, altro`.

Pivot `venue_user`: `venue_id, user_id, role(owner|editor), invited_at, accepted_at`.

### 7.3 `venue_applications`
```
user_id  venue_id(nullable)  venue_name  contact_name  contact_role
contact_phone  contact_email  address  type  socials(json)  message
documents(media)  status(pending|approved|rejected)
reviewed_by  reviewed_at  notes
```
Workflow: `pending → approved` → creazione venue + assegnazione owner + email di attivazione; oppure `pending → rejected` con motivazione inviata via email.

### 7.4 `categories` — **attenzione, qui stanno tre campi critici**
```
name  slug  icon  color  sort_order  is_active  parent_id(nullable)
default_duration_minutes   -- usato per calcolare effective_ends_at (§8.3)
supports_ongoing (bool)    -- se false, mai mostrata in "In corso adesso"
is_nightlife (bool)        -- se false, il cutoff notturno NON si applica (§8.2)
```

Set iniziale con i tre flag:

| Categoria | durata default | supports_ongoing | is_nightlife |
|---|---|---|---|
| Musica dal vivo | 180 | sì | sì |
| DJ set / Nightlife | 300 | sì | sì |
| Teatro e danza | 120 | sì | no |
| Cinema | 120 | sì | no |
| Arte e mostre | — (usa orari) | **no** | no |
| Libri e presentazioni | 90 | sì | no |
| Politica e attivismo | 120 | sì | no |
| Sport | 120 | sì | no |
| Food e sagre | 300 | sì | no |
| Mercatini | 480 | **no** | no |
| Corsi e workshop | 120 | sì | no |
| Bambini e famiglie | 120 | sì | no |
| Comunità e assemblee | 120 | sì | no |
| Altro | 120 | sì | no |

### 7.5 `tags`
```
name  slug  category_id(nullable)  is_approved  usage_count  synonyms(json)
```
Estendibili senza deploy. Esempi: punk, hardcore, jazz, techno, house, cantautorato, open-mic, jam-session, benefit, fiaccolata, assemblea, corteo, stand-up, presentazione-libro, proiezione, torneo, laboratorio, vinyl-market. Pivot `event_tag`.

### 7.6 `events` — **l'entità editoriale, non una data**
```
city_id  venue_id(nullable)  category_id  created_by
organizer_name  organizer_url
title  slug  subtitle  description  short_description
poster  gallery
price_type(free|donation|ticket|membership|unknown)
price_min  price_max  currency  price_notes
ticket_url  booking_required  booking_url  booking_phone
age_restriction  language  is_outdoor  custom_location(json)
external_links(json)
source(manual|venue|submission|import_ics|import_api)  source_ref
verification_status(unverified|venue_confirmed|editorial_checked)
status(draft|pending|published|rejected|cancelled|archived)  rejection_reason
is_featured  featured_until  editorial_score(int, default 0)
published_at  seo(json)  views_count  saves_count
```
`custom_location` serve agli eventi senza locale registrato (piazze, cortei, sagre): `{name, address, lat, lng}`.
`editorial_score` è la priorità redazionale usata nell'ordinamento di §8.5. Non esporla in API.

### 7.7 `event_occurrences` — **la tabella su cui gira tutto il prodotto**
```
event_id
starts_at (timestamptz, UTC)
ends_at (timestamptz, nullable)
effective_ends_at (timestamptz, NOT NULL)   -- calcolato, vedi §8.3
doors_at  is_all_day
business_date (date, indicizzato)           -- calcolato, vedi §8.2
status(scheduled|cancelled|sold_out|postponed|moved)  status_note
price_override(json)  capacity_left
recurrence_id(nullable)  is_exception
```
Indici: `(business_date, status)`, `(starts_at)`, `(effective_ends_at)`, `(event_id, starts_at)`.

**Nessuna query di calendario, lista, mappa o API interroga direttamente `events`.**

### 7.8 `event_recurrences`
```
event_id  rrule (RFC 5545)  until  exdates(json)  generated_until
```
Materializzare almeno 12 mesi di occorrenze; scheduler mensile che estende `generated_until`. Il gestore non vede mai la sintassi RRULE (§10.4).

### 7.9 `lineups`
```
occurrence_id  name  role(live|dj|opening|special_guest|speaker)
starts_at(nullable)  url  sort_order
```

### 7.10 Utenti e interazioni
```
users
  name  email  password  email_verified_at  timezone  locale
  notification_preferences(json)   -- vedi §15.4
  daily_digest_time(time|null)     -- es. 17:00, null = disattivo
  quiet_hours(json)                -- {from:'23:30', to:'08:00'}
  marketing_opt_in_at(nullable)    -- consenso newsletter, separato e tracciato
  last_active_at

saved_events
  user_id  occurrence_id  created_at
  reminder_sent_at(json)           -- {24h: ts, 3h: ts} per idempotenza
  UNIQUE(user_id, occurrence_id)

follows
  user_id  followable_type/id (Venue|Tag|Category)  notify(bool)  created_at
  UNIQUE(user_id, followable_type, followable_id)

devices
  user_id  platform(ios|android|web)
  push_token(nullable)             -- FCM, per le app
  endpoint  keys(json)             -- l'iscrizione Web Push del browser (D54)
  app_version  locale  last_seen_at  revoked_at

scheduled_notifications           -- vedi §15.5, è il motore delle notifiche
  user_id  notifiable_type/id  type  channel(mail|push|database)
  send_at  sent_at  status(pending|sent|skipped|failed|cancelled)
  dedupe_key(unique)  payload(json)  attempts  last_error

notification_log                  -- per frequency capping e analitica
  user_id  type  channel  sent_at  opened_at  clicked_at

event_submissions   title  raw_text  poster  contact_email  status  …
reports             reportable_type/id  reason  note  reporter_email  status
event_views_daily   event_id  date  views  unique_views  direction_clicks  ticket_clicks  shares
```
`event_views_daily` è **aggregato**: mai una riga per click.
`dedupe_key` è la garanzia contro il doppio invio: `"reminder_3h:user_42:occ_918"`. Vincolo di unicità a livello di database, non solo applicativo.

### 7.11 Contenuti e infrastruttura
```
import_sources   city_id  venue_id(nullable)  type(ics|json|rss|api|manual)
                 url  credentials(encrypted)  mapping(json)  default_category_id
                 is_active  last_run_at  last_status  last_error
activity_log     (spatie) ogni cambio di stato su venue/event
settings         key/value tipizzato, feature flag
```

---

## 8. Motore temporale — `EventOccurrenceQuery`

**È il cuore logico dell'applicazione.** Sito, API, calendario, notifiche e futura app usano questa classe e nient'altro.

```php
EventOccurrenceQuery::for($city)
    ->today() ->tonight() ->tomorrow() ->weekend()
    ->ongoing() ->startingSoon()
    ->between($from, $to) ->onDate($date)
    ->near($lat, $lng, $radiusKm)
    ->inCategories([...]) ->withTags([...])
    ->priceFree() ->priceMax(20)
    ->timeOfDay('evening')
    ->atVenue($venue)
    ->search($q)
    ->orderByRelevance();
```

### 8.1 Adesso
"Adesso" è sempre calcolato nel fuso della **città**, non del server né dell'utente. `$now = now($city->timezone)`.

### 8.2 `business_date` — la giornata evento
Un concerto che inizia venerdì alle 23:30 e finisce sabato alle 3:00 è, per l'utente, **venerdì sera**.

Regola: se l'orario locale di `starts_at` è compreso fra `00:00` e `city.night_cutoff_time` (default `06:00`) **e** la categoria dell'evento ha `is_nightlife = true`, allora `business_date = data locale - 1 giorno`. Altrimenti `business_date = data locale`.

> Il doppio vincolo è necessario. Un cutoff applicato solo per città non distingue un after techno da un convegno: entrambi stanno nella stessa città. È la categoria a determinare se la logica notturna ha senso.

Calcolo in un observer di `EventOccurrence`, ricalcolato a ogni modifica di `starts_at` o della categoria dell'evento padre.

Test obbligatori: `23:59`, `00:00`, `05:59`, `06:00`, categoria nightlife vs non-nightlife, passaggio a ora legale (ultima domenica di marzo) e a ora solare (ultima domenica di ottobre).

### 8.3 `effective_ends_at` — **senza questo, "in corso" non funziona**
La stragrande maggioranza degli eventi inseriti da un gestore avrà solo l'ora di inizio. Se "in corso" richiede `ends_at`, la sezione resterà quasi sempre vuota e la funzione sarà inutile.

```
effective_ends_at =
    ends_at                                        se presente
    starts_at + category.default_duration_minutes  altrimenti
    fine dell'orario di apertura del giorno        se is_all_day (mostre, mercatini)
```
Campo persistito e indicizzato, ricalcolato dall'observer. In API va esposto come `ends_at_estimated: true|false`, così che i client possano dire "fino a circa le 24:00" invece di mentire.

### 8.4 Le finestre temporali
```
IN CORSO
  starts_at <= now  AND  effective_ends_at >= now
  AND status = scheduled
  AND category.supports_ongoing = true
  AND is_all_day = false

INIZIA TRA POCO
  starts_at > now  AND  starts_at <= now + city.starting_soon_minutes (default 180)
  AND status = scheduled

OGGI
  business_date = today(city)

STASERA
  business_date = today(city)  AND  ora locale di starts_at >= 17:00

DOMANI
  business_date = today + 1

WEEKEND
  business_date ∈ {venerdì, sabato, domenica} della settimana corrente o successiva
```
`supports_ongoing = false` esclude mostre e mercatini: un'esposizione aperta tutti i giorni risulterebbe permanentemente "in corso" e seppellirebbe i concerti sotto contenuto inerte.

### 8.5 Ordinamento di "in corso" e "inizia tra poco"
1. eventi già in corso prima di quelli che devono iniziare
2. minuti mancanti all'inizio (crescente)
3. distanza dall'utente, se la posizione è stata concessa
4. `editorial_score` decrescente
5. `id` (stabilità e determinismo dei test)

### 8.6 Regola sugli stati vuoti
Se una finestra temporale non contiene eventi, **la sezione non viene renderizzata**. Mai contenitori vuoti. Al massimo un rimando: *"Niente inizia a breve. Guarda cosa c'è stasera →"*.

---

## 9. Pannello admin `/admin`

### 9.1 Dashboard
Eventi in attesa · Locali in attesa · Eventi pubblicati oggi / questa settimana · Eventi annullati · Segnalazioni aperte · Import falliti · **Eventi senza locandina** · **Eventi con informazioni incomplete** · **Locali inattivi** · **Duplicati sospetti**.

### 9.2 Risorse
- **Città** — CRUD, centro e bounds su mappa, `night_cutoff_time`, `starting_soon_minutes`, feature flag.
- **Locali** — CRUD, filtri per città/tipo/stato, mappa con marker trascinabile, approva/rifiuta/sospendi/verifica, `auto_publish`, assegnazione owner ed editor, media, eventi collegati, statistiche.
- **Eventi** — form completo, repeater occorrenze, editor ricorrenze, lineup, media, preview della scheda pubblica, cronologia modifiche. Azioni: pubblica, rifiuta (con motivo), annulla, sposta, sold out, featured, duplica, **modifica singola occorrenza** vs **modifica intera serie**.
- **Categorie** — inclusi `default_duration_minutes`, `supports_ongoing`, `is_nightlife`.
- **Tag** — con merge dei duplicati.
- **Utenti** — ruoli, impersonate.
- **Segnalazioni**, **Import**, **Qualità contenuti** (§14.5).

---

## 10. Pannello locale `/gestione`

Deve essere **molto più semplice** dell'admin. Tenancy su `Venue`, switcher se l'utente gestisce più locali.

### 10.1 Dashboard
```
I TUOI EVENTI          OGGI 3      PROSSIMI 12      VISUALIZZAZIONI (30gg) 1.420
```

### 10.2 Wizard evento — **obiettivo 90 secondi**
```
STEP 1  Locandina + titolo
STEP 2  Data e ora            scorciatoie: Stasera · Domani · Venerdì · Ogni giovedì
STEP 3  Categoria + tag
STEP 4  Prezzo
STEP 5  Descrizione (opzionale) + link biglietti
```
Salvataggio automatico della bozza a ogni step. Precompilazione da `venue.default_event_settings`. Mobile-first: il wizard viene usato in piedi dietro al bancone, non alla scrivania.

### 10.3 Duplica
Un pulsante che crea un nuovo evento con titolo, descrizione, categoria, tag, prezzo, immagine e lineup già compilati. Il gestore cambia solo data e ora. **Funzione essenziale**: la maggior parte dei locali fa serate ricorrenti.

### 10.4 Ricorrenze
Interfaccia in linguaggio naturale: *Ripeti [ogni settimana] il [giovedì] fino al [31 dicembre]*. L'agente genera la RRULE internamente. **Il gestore non deve mai vedere la sintassi RRULE.**

### 10.5 Statistiche
Visualizzazioni · Salvataggi · Click su indicazioni · Click su biglietti, su 7 / 30 / 90 giorni. Nessun dato personale degli utenti.

### 10.6 Collaboratori
Invito via email di un `venue_editor`. Visibile solo agli `owner`.

---

## 11. Sito pubblico

### 11.1 Rotte
```
/                              /eventi
/eventi/oggi                   /eventi/domani
/eventi/weekend                /eventi/{yyyy-mm-dd}
/eventi/categoria/{slug}       /eventi/tag/{slug}
/eventi/gratis                 /eventi/{slug}
/mappa                         /calendario
/locali                        /locali/{slug}
/cerca                         /proponi-evento
/registra-il-tuo-locale        /pagine/{slug}
```
Predisporre `/{city}/eventi` senza renderlo obbligatorio per la città di default.

### 11.2 Homepage — ordine vincolante
1. Header: città · data odierna · ricerca
2. **In corso adesso** (solo se non vuota)
3. **Inizia tra poco** (solo se non vuota)
4. **Stasera** (dalle 17:00)
5. Scroller dei prossimi 14 giorni con indicatore di densità
6. Oggi durante il giorno
7. In evidenza
8. Questo weekend
9. Griglia per categoria
10. Locali attivi
11. CTA doppia: *Sei un locale? Pubblica i tuoi eventi* / *Newsletter del weekend*

### 11.3 Lista e filtri
Filtri: data (oggi/domani/weekend/settimana/intervallo) · categoria · tag · prezzo (gratis, offerta, ≤10€, ≤20€) · fascia oraria (pomeriggio/sera/notte) · quartiere o comune · locale · distanza · accessibilità · all'aperto · adatto a famiglie · ordinamento.

**Tutti i filtri finiscono nella query string**: `/eventi?date=today&category=musica&price=free&time=evening`. L'URL deve essere condivisibile, indicizzabile e riproducibile. Ogni combinazione genera `<title>`, `<h1>` e meta description sensati. Infinite scroll con fallback `?page=` funzionante senza JavaScript.

### 11.4 Card evento — componente unico riusato ovunque
Locandina 3:4 lazy con placeholder blur · badge categoria · titolo · locale e zona · data/ora in forma umana · prezzo · stato.
Badge possibili: `IN CORSO` · `INIZIA TRA 25 MIN` · `STASERA · 21:30` · `GRATIS` · `€8` · `ANNULLATO` · `SOLD OUT`.

### 11.5 Scheda evento
Locandina, titolo, sottotitolo, locale linkato, **tutte le date** (non solo la prima), orari, prezzo, biglietti, prenotazione, descrizione, lineup, tag cliccabili, mappa, indicazioni (deep link Google/Apple Maps), aggiungi al calendario (`.ics` e Google Calendar), condivisione nativa, eventi simili, altri eventi dello stesso locale, segnala errore.

Il pulsante **Salva** funziona anche da anonimo e, se l'evento ha più date future, apre il selettore descritto in §15.3.

JSON-LD `Event` **per ogni occorrenza pubblicata**, con dati coerenti con la singola data: `location: Place` con indirizzo e coordinate, `offers`, `eventStatus`, `eventAttendanceMode`, `organizer`, `image`, `performer`.

### 11.6 Mappa
Leaflet: clustering, marker colorati per categoria, filtri condivisi con la lista, bottom sheet con la card, geolocalizzazione opzionale, pulsante **"Cerca in quest'area"** al pan. Query per bounding box + `business_date`.

### 11.7 Vicino a me
**Non chiedere il GPS all'apertura del sito.** Chiedere solo quando serve davvero, con una frase chiara. Raggi: 1 / 5 / 10 / 25 km. La posizione non viene mai salvata: serve solo alla query.

### 11.8 Calendario
Vista mensile con conteggio per giorno e 2-3 titoli in preview; click → `/eventi/{yyyy-mm-dd}`. **Una sola query aggregata per mese, in cache.**

### 11.9 Scheda locale
Logo, copertina, descrizione, tipo, indirizzo e mappa, orari, contatti e social, accessibilità, tesseramento, badge verificato, prossimi eventi, archivio eventi passati paginato (ottimo per SEO), pulsante Segui.

### 11.10 Feed e widget — leva di crescita, non optional
- `/eventi.ics` filtrabile per città, categoria, tag e locale: chiunque può iscriversi al calendario della città dal proprio telefono.
- `/feed.rss`
- **Widget incorporabile** (`<script>` o iframe) che il locale mette sul proprio sito per mostrare i propri prossimi eventi presi dalla piattaforma. Costa poco e crea dipendenza reciproca: il locale ha interesse a tenere aggiornati i dati da voi.

### 11.11 Base tecnica
Mobile-first. Lighthouse ≥ 90 su Performance / SEO / Accessibility, LCP ≤ 2,5s su mobile simulato (era «< 2s»: la verifica in CI usa la soglia pubblica dei Core Web Vitals, vedi D48). Dark mode via `prefers-color-scheme`. Accessibilità: tastiera, focus visibile, contrasto AA, `aria-label`, alt text. Zero layout shift sulle immagini.

---

## 12. Media, SEO, performance, cache

### 12.1 Pipeline media
```
upload → validazione MIME reale → strip EXIF → resize → WebP + AVIF
       → blurhash → CDN
```
Varianti: `thumb 400w`, `card 800w`, `full 1600w`, più Open Graph `1200×630` generata automaticamente (locandina + titolo + data + logo). Tutto in coda, mai bloccante. Max 12MB, formati jpg/png/webp/heic.

### 12.2 SEO
Ogni evento è una pagina indicizzabile. `sitemap.xml` a indice (eventi, locali, categorie, giorni futuri), `robots.txt`, canonical, Open Graph, metadata X/Twitter, `hreflang` predisposto. JSON-LD: `Event`, `Place`, `Organization`, `BreadcrumbList`, `WebSite` con `SearchAction`.

### 12.3 Strategia di cache — **attenzione al conflitto**
Una finestra mobile di 3 ore ("inizia tra poco") è incompatibile con una full-page cache: o la pagina è in cache e la sezione è sbagliata, o è corretta e il TTFB crolla. Soluzione vincolante:

| Contenuto | Cache |
|---|---|
| Scheletro homepage, "stasera", "weekend", categorie, locali attivi | full-page, TTL 5 min, invalidata alla pubblicazione di un evento |
| **"In corso" e "Inizia tra poco"** | frammento separato, TTL 60s, **chiave arrotondata al quarto d'ora** (`now->floorMinutes(15)`), caricato lazy dopo il first paint |
| Conteggi calendario mensile | cache 30 min, invalidata sulla pubblicazione |
| Tassonomie | cache 24h |
| Liste API pubbliche | `ETag` + `Cache-Control: public, max-age=60, stale-while-revalidate=300` |

L'arrotondamento al quarto d'ora è ciò che rende la cache utile: senza, ogni secondo genera una chiave diversa e la cache non serve a niente.

---

## 13. API v1

Base `/api/v1`. JSON UTF-8. Date ISO 8601 con offset (`2026-09-12T21:30:00+02:00`) **più** `business_date`. Contratto stabile: nessun breaking change dentro la v1.

### 13.1 Endpoint pubblici
```
GET  /v1/config          versione minima app, categorie (con i tre flag), tag popolari,
                         città attive, feature flag, testi legali
GET  /v1/cities                      GET /v1/cities/{slug}
GET  /v1/events                      GET /v1/events/{slug}
GET  /v1/events/{slug}/similar       GET /v1/occurrences/{id}
GET  /v1/calendar                    GET /v1/venues
GET  /v1/venues/{slug}               GET /v1/venues/{slug}/events
GET  /v1/map/occurrences             GET /v1/search
POST /v1/submissions                 POST /v1/reports
```

### 13.2 `GET /v1/events` — parametri
```
city, date=YYYY-MM-DD | from & to
preset = today | tonight | tomorrow | weekend | week | starting_soon | ongoing
categories[], tags[], price=free|donation|paid|max:20
venue, time_of_day=day|evening|night
bbox=minLng,minLat,maxLng,maxLat | near=lat,lng & radius_km
q, sort=start|distance|popular|relevance
cursor, limit (max 50), include=venue,tags,lineup
updated_since=<ISO8601>
```
Restituisce **occorrenze**, non eventi. Ogni item: `occurrence_id`, `event_id`, `starts_at`, `effective_ends_at`, `ends_at_estimated`, `business_date`, `status`, `title`, `poster{thumb,card,full,blurhash,width,height}`, `venue` ridotto, `category`, `tags`, `price`, `distance_m` (se `near`), `is_saved` (se autenticato).

**`preset=starting_soon` e `preset=ongoing` devono esistere in API.** La futura app non deve ricostruire quella logica: se lo fa, in sei mesi avrete due definizioni divergenti.

### 13.3 Mappa
```json
{"id":123,"event_id":45,"lat":45.406,"lng":11.876,
 "category_id":2,"title":"Concerto","starts_at":"2026-09-05T21:30:00+02:00"}
```
Payload minimale: la mappa carica centinaia di marker.

### 13.4 Autenticazione
Sanctum. `POST /auth/register|login|logout|password/forgot|password/reset`.
~~Backend predisposto per Google e Apple~~ (D7): `laravel/socialite` non è compatibile con Guzzle 8, che Laravel 13 porta con sé. Registrazione con email e password oppure con collegamento via posta. Lo schema `users` non contiene nulla che impedisca di aggiungerlo dopo; Sign in with Apple resterà obbligatorio se un giorno ci sarà login social su iOS.
Client API key (`X-Client-Key`) per rate limiting e telemetria. Limiti: 60 req/min anonime per IP, 120 autenticate, header `X-RateLimit-*`.

### 13.5 Endpoint utente
```
GET/PATCH /v1/me
GET/POST/DELETE /v1/me/saved
GET/POST/DELETE /v1/me/follows
GET /v1/me/feed
POST/DELETE /v1/me/devices
```

### 13.6 Formato
```json
{"data": [], "meta": {"next_cursor": "...", "has_more": true}}
{"error": {"code": "VALIDATION_FAILED", "message": "...", "fields": {}}}
```
Paginazione **a cursore** ovunque, mai offset su liste temporali. HTTP corretti: 400/401/403/404/409/422/429/500. `updated_at` su ogni risorsa + supporto `?updated_since=` per la sincronizzazione offline dell'app (cache dei prossimi 7 giorni).

---

## 14. Contenuti, import, moderazione, qualità

### 14.1 Provenienza e verifica
Ogni evento porta `source` (`manual|venue|submission|import_ics|import_api`) e `verification_status` (`unverified|venue_confirmed|editorial_checked`). L'interfaccia pubblica deve poter distinguere un evento confermato dal locale da uno importato e non verificato. È una questione di fiducia: se l'utente trova la saracinesca abbassata due volte, non torna.

### 14.2 Import
Prima sorgente: **ICS**. Il locale fornisce l'URL pubblico del proprio calendario.
```
fetch → parse → map → deduplicate → preview → moderazione → publish
```
Idempotente su `source_ref`: nessun duplicato a ogni esecuzione. Esecuzione oraria, log errori, alert su fallimento.

Interfaccia comune `ImportSourceDriver` (`fetch(): array`, `map(): EventDto`). Implementazione iniziale `IcsImportDriver`. Sorgenti future (Comune, teatri, cinema, portali, RSS, API) si aggiungono **senza toccare il core**.

### 14.3 Inserimento redazionale rapido
Pannello che accetta una locandina caricata e/o testo incollato e precompila titolo, data, ora, locale, prezzo, descrizione, categoria e tag.
L'estrazione automatica via modello linguistico può arrivare in un secondo momento (Laravel 13 ha primitive AI di prima parte). **Mai pubblicazione automatica di contenuti estratti da AI senza revisione umana.**

### 14.4 Duplicati
Al salvataggio confronta: similarità del titolo, stessa `business_date`, stesso locale o distanza < 300m, organizer simile. Se `similarity >= 0.85` e le condizioni temporali/geografiche coincidono → flag `possible_duplicate` e avviso.
**Nessuna cancellazione automatica.** La decisione (`ignora` / `unisci`) resta al moderatore.

### 14.5 Dashboard "Qualità contenuti"
Eventi senza immagine · senza prezzo · senza descrizione · senza categoria · sospetti duplicati · scaduti non archiviati · locali inattivi da 60 giorni · import falliti · eventi non aggiornati da X giorni.

### 14.6 Segnalazioni
Motivi: `wrong_info, duplicate, spam, offensive, cancelled, copyright`. Azioni del moderatore: `ignore, fix, contact venue, cancel, merge`. Notifica al moderatore e, dove ha senso, al locale.

### 14.7 Anti-abuso
Turnstile su tutti i form pubblici, rate limit per IP e per account, honeypot, blocklist di domini email temporanei, moderazione obbligatoria per `source = submission`.

---

## 15. Account utente, salvataggi e notifiche

Funzione di fase 1, non rimandata alle app. È ciò che trasforma la piattaforma da consultazione occasionale a strumento che l'utente ritrova.

### 15.1 Salvare senza account — regola di prodotto
**Il cuore deve funzionare al primo click, senza registrazione.** Un utente che scopre il sito e si vede chiedere email prima di poter salvare un concerto, chiude la scheda.

```
click sul cuore (guest)
  → salvato in localStorage, feedback immediato, nessuna interruzione
  → subito dopo, dialogo: "Salvato su questo dispositivo — con un account lo
    ritrovi ovunque e ti avviso prima che cominci."   [D49: era un banner
    discreto dopo il 3° salvataggio; il primo restava muto e chi ne faceva uno
    solo non scopriva mai che vive in quel browser]
  → chi chiude tiene la sua data e non se lo vede più chiedere
  → alla registrazione, i salvataggi locali vengono migrati sull'account (merge, non sostituzione)
```
La migrazione avviene con una chiamata `POST /v1/me/saved/merge` che accetta l'elenco di `occurrence_id` locali, ignora i duplicati e gli eventi già passati. Il `localStorage` viene svuotato solo dopo conferma del server.

**La leva per registrarsi è la notifica, non il salvataggio.** Il salvataggio da solo funziona anche da anonimo: quello che l'account aggiunge è il promemoria, la sincronizzazione fra dispositivi e il feed personalizzato. È così che va comunicato.

### 15.2 Registrazione e accesso
- Email + password, oppure **magic link** (raccomandato come opzione primaria: meno attrito, niente password da ricordare per un uso saltuario come questo).
- Verifica email obbligatoria **prima di qualunque invio di notifica**. Un account non verificato può salvare, non può ricevere.
- Google e Apple predisposti a livello di backend (Sign in with Apple diventa obbligatorio se ci sarà login social su iOS), attivabili senza modifiche di schema.
- Profilo minimo: nome (facoltativo), email, fuso orario, lingua. Nessun altro dato raccolto.
- Eliminazione account self-service, con anonimizzazione ed effetto immediato sui salvataggi e sulle notifiche programmate.

### 15.3 Cosa si salva: occorrenza, non evento
Un evento ricorrente ha molte date. Salvare "l'evento" è ambiguo e produce promemoria sbagliati.

```
1 sola occorrenza futura   → il cuore salva quella, senza chiedere niente
più occorrenze future      → il cuore apre un selettore compatto di date
                             (con "salva tutte le date" come opzione)
serie ricorrente           → "Segui questo evento": ogni nuova occorrenza
                             generata viene salvata automaticamente
```
Il pulsante "Segui" su un locale, un tag o una categoria è un'altra cosa: alimenta il feed e i digest (§15.4), non i promemoria puntuali.

### 15.4 Tipologie di notifica

| Notifica | Quando | Canale | Default |
|---|---|---|---|
| Promemoria evento salvato | 24h prima e 3h prima (offset configurabile dall'utente) | push, fallback email | **attivo** |
| Evento salvato **annullato o spostato** | immediato | push + email | **attivo, non disattivabile** |
| Evento salvato sold out | immediato | push | attivo |
| Nuovi eventi dai locali che segui | **digest**, non immediato — settimanale | email | attivo |
| Digest giornaliero "stasera nei tuoi generi" | orario scelto dall'utente, default 17:00 | push | disattivo |
| Newsletter "Questo weekend" | giovedì pomeriggio | email | **opt-in esplicito e separato** |
| Ai gestori: evento pubblicato / rifiutato / non pubblichi da 21 giorni | evento di dominio | email | attivo |

**Regole di moderazione del volume — vincolanti:**
- Mai una notifica per singolo evento nuovo di un locale seguito. Un utente che segue 8 locali riceverebbe 30 push a settimana e disinstallerebbe tutto. Sempre digest aggregato.
- Massimo **2 push al giorno** per utente, esclusi i promemoria di eventi che ha salvato esplicitamente (quelli sono richiesti, non intrusivi).
- **Quiet hours** rispettate su tutti i canali tranne annullamenti: niente push notturne. Se un invio cade nella finestra di silenzio, viene spostato all'orario di uscita, e se nel frattempo è diventato inutile viene marcato `skipped`.
- Ogni notifica ha un deep link diretto alla scheda evento. Mai una notifica che apre la home.

### 15.5 Motore di invio — architettura
**Non** fare un cron che ogni minuto scansiona `saved_events` cercando cosa inviare: non regge la crescita e non è ispezionabile.

```
salvataggio evento
  → crea righe in scheduled_notifications (send_at = starts_at − 24h, − 3h)
     con dedupe_key univoca

modifica dell'occorrenza (orario, stato)
  → observer: riprogramma o annulla le righe pending collegate
     - orario spostato   → aggiorna send_at
     - status cancelled  → annulla i promemoria E accoda la notifica di annullamento
     - occorrenza passata → skipped

worker ogni 5 minuti
  → preleva le righe pending con send_at <= now, in lock
  → applica preferenze utente, quiet hours, frequency cap
  → sceglie il canale (§15.6), invia, scrive notification_log
  → segna sent | skipped (con motivo) | failed (con retry esponenziale, max 3)
```
La tabella rende ogni invio previsto **visibile e verificabile in anticipo** dal pannello admin: un job in coda ritardato non lo è. Job cancellabili, riprogrammabili e testabili.

### 15.6 Canali e deduplica
Un utente con app, browser e email non deve ricevere la stessa cosa tre volte. Ordine di preferenza per notifica:

```
push su device attivo negli ultimi 30 giorni
  → altrimenti email
  → sempre in-app (tabella notifications) come archivio consultabile
```
- **Web Push** (VAPID + service worker) è attivo (D54, che supera D8). L'ostacolo di allora — la catena `minishlink/web-push` → `web-token` → `brick/math` — non esiste più: `laravel-notification-channels/webpush` 12.1 dichiara `illuminate/* ^13.13`. I canali attivi sono quindi tre: **push** a chi ha un browser iscritto e visto negli ultimi trenta giorni, **email** a tutti gli altri, archivio in-app sempre. Come previsto, è stato un canale in più e non una riscrittura del motore: la scelta vive in `ChannelSelector`, e l'iscrizione del browser sta nella tabella `devices` che §15.8 popolava già.
- **FCM** con le app native (fase F11).
- Token invalidi o rifiutati → `revoked_at`, device escluso, fallback su email al prossimo invio.

### 15.7 Feed personalizzato
`/il-mio-feed` (e `GET /v1/me/feed`): eventi futuri dei locali, tag e categorie seguiti, ordinati per data, con evidenza sui salvati. Se l'utente non segue ancora niente, mostrare un onboarding con i locali più attivi e le categorie principali, non una pagina vuota.

### 15.8 API
```
POST   /v1/auth/register | login | logout | magic-link | verify-email
POST   /v1/auth/password/forgot | reset
GET/PATCH /v1/me
DELETE /v1/me                       -- cancellazione account
GET/PATCH /v1/me/notification-preferences
GET    /v1/me/saved                 -- ?upcoming=1 default
POST   /v1/me/saved                 {occurrence_id}
POST   /v1/me/saved/merge           {occurrence_ids: []}   -- migrazione dai guest
DELETE /v1/me/saved/{occurrence_id}
GET/POST/DELETE /v1/me/follows
GET    /v1/me/feed
POST/DELETE /v1/me/devices
GET    /v1/me/notifications         -- archivio in-app
GET    /v1/me/export                -- portabilità dati GDPR
```
Nelle risposte pubbliche, se il chiamante è autenticato ogni occorrenza porta `is_saved`. Senza autenticazione il campo è assente, non `false`: evita che il client lo interpreti come "non salvato" quando semplicemente non lo sa.

### 15.9 Privacy e GDPR
- Notifiche **transazionali** (promemoria di ciò che l'utente ha salvato, annullamenti) e **marketing** (newsletter) sono giuridicamente diverse: consenso separato, tracciato con data e origine in `marketing_opt_in_at`.
- Disiscrizione a un click in ogni email, e pagina preferenze raggiungibile senza login tramite token firmato.
- Nessun tracciamento cross-site. `notification_log` conserva aperture e click in forma aggregabile, con retention 12 mesi.
- Cancellazione account: rimozione di salvataggi, follow, device e notifiche programmate; conservazione anonimizzata solo di ciò che è legalmente necessario.

### 15.10 Metriche
Registrazioni / visitatori unici · % di guest che convertono dopo il banner del terzo salvataggio · salvataggi per utente · **tasso di apertura e click delle notifiche per tipo** · disiscrizioni per tipo · ritorno a 7 e 30 giorni degli utenti registrati contro anonimi.

Se una tipologia di notifica ha un tasso di disiscrizione alto, va disattivata, non ottimizzata.

---

## 16. Sicurezza, privacy, GDPR

**Sicurezza:** HTTPS, HSTS, CSP restrittiva (attenzione a Leaflet e CDN immagini), `X-Content-Type-Options`, `Referrer-Policy`, ~~Argon2id~~ **bcrypt** (D43: gli hash esistenti non sono convertibili senza la password in chiaro, servirebbe il rehash al primo accesso), 2FA per admin e moderatori, CSRF, rate limiting su login/registrazione/reset, sanitizzazione HTML con whitelist (no `<script>`, no iframe arbitrari), validazione MIME reale, storage fuori dal webroot, secret management fuori da git.

**Privacy:** raccogliere il minimo — email, nome, password. **Nessuna coordinata GPS dell'utente viene salvata**: la posizione serve solo alla query. Log accessi admin 90 giorni.

**GDPR:** Privacy Policy, Cookie Policy, Termini, Contatti, Chi siamo. Cookie banner con consenso preventivo, granulare, rifiuto semplice quanto l'accettazione, log del consenso. Analytics privacy-first. Eliminazione account con anonimizzazione dei contenuti pubblicati dove necessario.

**Copyright locandine:** in fase di iscrizione il locale dichiara di avere diritto di utilizzo dei contenuti caricati. Prevedere procedura di segnalazione, rimozione e audit log.

**Backup:** database giornaliero, storage, retention 30 giorni. **Un backup non è valido finché non è stato testato un restore reale**, documentato in `docs/RUNBOOK.md`.

**Monitoring:** Sentry, uptime, code e scheduler via `spatie/laravel-health` e `schedule-monitor` (~~Horizon~~, che richiede Redis), storage, database. Alert su: queue bloccata, import fallito, database irraggiungibile, storage quasi pieno.

---

## 17. Fasi

### F0 — Fondamenta
Verifica compatibilità Filament 5 / Livewire 4 con tutti i pacchetti (§4, task bloccante: **chiuso con esito positivo**, D2) → repo → ~~Docker Compose~~ ambiente nativo, perché il bersaglio è shared hosting senza root → Laravel 13 + **PHP 8.4** (D1: tre pacchetti lo richiedono) → Pest, Pint, Larastan → GitHub Actions (lint → analisi statica → test) → `.env.example` documentato → valutazione Laravel Boost / Filament Blueprint → documenti in `docs/`.
**Accettazione:** `docker compose up` produce un'app funzionante; CI verde; `php artisan test` passa; `docs/DECISIONS.md` contiene la decisione sullo stack Filament con la lista dei pacchetti verificati.

### F1 — Database e dominio
Tutte le tabelle di §7 con migration, model, factory, seeder, enum, policy, relazioni, cast. Observer per `business_date` ed `effective_ends_at`. `GenerateOccurrencesAction` (RRULE → 12 mesi di occorrenze) + comando schedulato.
**Accettazione:** RRULE `FREQ=WEEKLY;BYDAY=TH;COUNT=10` genera 10 occorrenze con `business_date` corretta; cancellare una occorrenza non tocca le altre e imposta `is_exception`; test di isolamento fra locali verdi; tutti i casi limite di §8.2 e §8.3 coperti.

### F2 — Admin
Pannello `/admin` completo (§9).
**Accettazione:** un admin crea città → locale → evento → 3 occorrenze → pubblicazione, senza toccare codice, in meno di 3 minuti.

### F3 — Locali
`/registra-il-tuo-locale`, workflow di approvazione, rivendicazione scheda (`claim_token`), pannello `/gestione` (§10).
**Accettazione:** ciclo completo registrazione → approvazione → email → login → creazione evento → pubblicazione, **interamente da smartphone**; creazione evento cronometrata ≤ 90 secondi con locandina già disponibile; un `venue_editor` non vede né Collaboratori né dati del referente.

### F4 — Motore eventi
`EventOccurrenceQuery` completa (§8), con test unitari su ogni metodo.
**Accettazione:** ogni finestra temporale ha test con casi limite; nessuna logica temporale esiste altrove nel codice (verifica manuale con grep su `business_date` e `starts_at`).

### F5 — Sito pubblico
Homepage, lista, filtri, calendario, mappa, scheda evento, scheda locale, ricerca, "in corso", "inizia tra poco", feed ICS/RSS, widget (§11).
**Accettazione:** un utente arriva da homepage a *Musica + Stasera + Gratis* in massimo 3 interazioni; l'URL risultante è condivisibile e rende la stessa pagina; senza JavaScript la lista resta navigabile.

### F6 — Media, SEO, performance
Pipeline immagini, AVIF/WebP, CDN, OG generate, sitemap, JSON-LD, canonical, strategia di cache di §12.3, Lighthouse CI bloccante.
**Accettazione:** Lighthouse ≥ 90 su tutte e tre le metriche; LCP < 2s; la homepage in cache mostra "inizia tra poco" corretto entro 15 minuti reali.

### F7 — API v1
API completa (§13), OpenAPI, collection versionata.
**Accettazione:** ogni endpoint testato su happy path + 401/403/404/422/429; cursori, ETag e 304 funzionanti; **la schermata "cosa succede stasera" si costruisce con una sola chiamata**; `preset=starting_soon` e `preset=ongoing` restituiscono esattamente gli stessi risultati del sito nello stesso istante.

### F7b — Account, salvataggi e notifiche
Registrazione, magic link, verifica email, profilo, cancellazione account. Salvataggio anonimo in `localStorage` e migrazione all'account. Selettore date sul cuore. Follow di locali/tag/categorie. Feed personalizzato. Preferenze notifiche. Motore `scheduled_notifications` con observer di riprogrammazione. Web Push (D54): notifiche sul dispositivo con ripiego su email e archivio in-app. La PWA resta fuori — su iOS le push web richiedono il sito installato sulla schermata Home, ed è l'unico pezzo che manca. Newsletter con opt-in separato. Endpoint di §15.8.
**Accettazione:** un guest salva 3 eventi, si registra e li ritrova tutti sull'account; un promemoria programmato a 3h si sposta correttamente se il locale cambia l'orario dell'occorrenza; l'annullamento di un'occorrenza annulla i promemoria e invia la notifica di annullamento entro 5 minuti; nessun utente riceve la stessa notifica due volte (test sul vincolo `dedupe_key`); il cap di 2 push al giorno e le quiet hours sono rispettati; disattivando tutte le notifiche non parte più nulla tranne gli annullamenti.

### F8 — Popolamento (**inizia già durante F2, non alla fine**)
Inserimento redazionale, import ICS, proposte pubbliche, contatto diretto con i locali, rivendicazione schede.
**Accettazione:** 25 locali, 120 eventi futuri, ≥ 3 eventi nella maggior parte dei prossimi 14 giorni.

### F9 — Moderazione e qualità
Duplicati, segnalazioni, dashboard qualità, archiviazione automatica degli scaduti, audit log (§14).

### F10 — GDPR, analytics, lancio
Pagine legali, cookie banner, analytics, newsletter, backup con restore testato, monitoring, Sentry, deploy, rollback.

### F10b — Manutenzione ordinaria (aggiunta il 2026-09-02)

F10 copre come **arrivare** in produzione: deploy, monitoring, backup,
rollback. Non copriva come **restarci**, e i tre guasti veri delle prime
settimane sono stati tutti di quel genere:

| cosa è successo | come si è manifestato |
|---|---|
| il backup notturno ha esaurito la quota mentre scriveva | la pagina iniziale rispondeva 500, le altre no |
| una locandina da 231 KB in apertura | «tempo di disegno alto» in un referto di prestazioni |
| il calendario che si svuota | *niente* — nessuno se ne sarebbe accorto |

Hanno in comune la cosa peggiore: **nessuno fa cadere il sito nel momento in
cui accade**, e tutti si presentano più tardi come qualcos'altro. Il costo non
è il guasto: è l'indagine per risalire dal sintomo alla causa.

**Cosa esiste ora**, oltre ai controlli che c'erano già (database, cache, disco,
code, scheduler, sorgenti di import):

- **`CalendarCoverageCheck`** — il KPI di §1, finalmente misurato: quanti dei
  prossimi quattordici giorni hanno almeno tre date.
- **`BackupFreshnessCheck`** — non solo «esiste un backup recente» ma anche
  «pesa abbastanza da essere intero». Un archivio troncato ha un nome perfetto
  e non si apre; `backup:monitor` da solo non se ne accorge.
- **`MediaWeightCheck`** — quante locandine da elenco superano i 120 KB. Con
  soglia **proporzionale**: alcune immagini sono irriducibili, e un controllo
  sempre rosso viene spento dopo la seconda volta.
- **`SpazioSufficiente`** sul backup: non parte se non c'è spazio per finirlo,
  e non si stima — si prova a scrivere. Su hosting condiviso
  `disk_free_space()` riporta il volume di tutti, non la quota dell'account.
- **`CompressOversizedConversion`** e **`media:compress-oversized`**: il
  listener tiene nel tetto le conversioni nuove, il comando ripassa lo storico.
  Un difetto corretto solo in avanti resta a terra per tutto ciò che c'era
  prima — erano 84 locandine su 495.

**Criterio di accettazione**, da applicare anche in futuro: *un guasto
ordinario ha un controllo che lo nomina e una pagina del RUNBOOK che dice cosa
fare*. Se un incidente si scopre leggendo un log, manca uno dei due.

### F11 — Documento app mobile
Solo quando sito, backend, API e contenuti sono stabili. Produrre `MOBILE-APP-SPEC.md`.
Stack raccomandato: **React Native + Expo + TypeScript + Expo Router** — codebase unico Android/iOS, EAS per build, distribuzione e aggiornamenti.
Schermate: Oggi · Esplora · Mappa · Calendario · Salvati · Profilo.
Funzioni native che giustificano l'esistenza dell'app: push ("stasera 3 eventi che ti interessano"), GPS, calendario di sistema, share nativo, cache offline 7 giorni via `updated_since`, widget, deep linking. Requisiti store: account deletion in-app (Apple), privacy nutrition label, versione minima forzata via `/v1/config`.

### Ordine complessivo
```
F0 → F1 → F2 → F3 → F4 → F5 → F6 → F7 → F7b → F9 → F10 → F10b → F11
        └──────── F8 in parallelo da qui ────────┘
```
F8 parte appena esiste l'admin: raccogliere contenuti richiede settimane di relazioni con i locali, non giorni di codice. È il vero rischio del progetto, e non è tecnico.

---

## 18. Test end-to-end obbligatori

**A — Utente.** Apre il sito → vede cosa c'è oggi → filtra Musica → filtra Gratis → apre evento → vede mappa → clicca indicazioni.

**B — Inizia tra poco.** Sono le 19:00: un evento alle 19:30 appare; uno alle 22:30 no.

**C — In corso.** Evento 18:00–20:00, ora 19:00 → `IN CORSO`.

**C2 — In corso senza `ends_at`.** Concerto alle 21:00 senza ora di fine, categoria con durata 180 min, ora 22:00 → `IN CORSO`. Alle 00:30 → non più in corso.

**C3 — Mostra.** Esposizione con `supports_ongoing = false` → **non** appare mai in "In corso adesso", nemmeno durante l'orario di apertura.

**D — Evento notturno.** Venerdì 23:30 → sabato 03:00, categoria nightlife → `business_date` = venerdì. Stesso orario con categoria non-nightlife → nessuno spostamento.

**E — Locale.** Registrazione → approvazione admin → email all'owner → login → creazione evento → pubblicazione.

**F — Sicurezza.** Il locale A non può leggere, modificare o cancellare eventi del locale B, **nemmeno manipolando URL o ID direttamente**.

**G — Coerenza sito/API.** Nello stesso istante, `/eventi?preset=starting_soon` e `GET /v1/events?preset=starting_soon` restituiscono la stessa lista nello stesso ordine.

**H — Salvataggio guest.** Un anonimo salva 3 eventi → si registra → ritrova esattamente quei 3 eventi, senza duplicati, senza quelli già passati.

**I — Riprogrammazione.** Evento salvato con promemoria a 3h; il locale sposta l'orario di 2 ore → il promemoria viene riprogrammato, non duplicato. Sposta l'evento a ieri → il promemoria diventa `skipped`, non viene inviato.

**J — Annullamento.** Un'occorrenza salvata da 40 utenti passa a `cancelled` → 40 notifiche di annullamento entro 5 minuti, zero promemoria residui, anche per gli utenti che avevano disattivato tutte le altre notifiche.

**K — Volume.** Un utente che segue 8 locali attivi non riceve più di 2 push in un giorno né alcuna push dentro le proprie quiet hours.

---

## 19. Definition of done

Una funzione è finita quando: codice scritto · test scritto · policy verificata · autorizzazione verificata · responsive verificato · accessibilità verificata · stato di errore gestito · stato di caricamento gestito · **stato vuoto gestito** · log presente dove serve · traduzione presente · documentazione aggiornata · CI verde.

---

## 20. Decisioni da prendere prima di F1

1. Nome definitivo e dominio.
2. Padova città o intera provincia; raggio iniziale in km.
3. Eventi senza locale registrato ammessi in v1? *(Raccomandazione: sì, via `custom_location`, gestiti dalla redazione.)*
4. **Modello economico.** ~~Non va implementato ora~~ — **deciso e costruito** (2026-09-02, D50 e seguenti). Restano validi i vincoli che questa voce poneva: lo schema permette featured, sponsorship, premium venue, promoted event e newsletter sponsorship, e la gratuità per associazioni e no-profit è una scelta commerciale ancora aperta.

   **Cosa esiste oggi.** Campagne sponsorizzate su **eventi veri del catalogo** — non banner: la card sponsorizzata è la stessa degli altri eventi, con l'etichetta «sponsorizzato», il nome di chi paga e `rel="sponsored"`, dichiarata anche nell'API. Quattro collocazioni (apertura, card in pagina iniziale, cima delle liste, foglio della mappa), ognuna con un tetto fisso: vendere non può cambiare l'aspetto del prodotto.

   Si vende **a quota, non a posizione**: `weight` distribuisce le apparizioni in proporzione (peso 3 contro 1 significa tre volte su quattro), mentre `priority` resta l'impegno contrattuale «sei sempre in cima». Prima esisteva solo la priorità, e in una collocazione da uno solo chi comprava meno non compariva **mai**: si poteva vendere un posto solo. Ci sono tetti di consegna a visualizzazioni e ad aperture, misure giorno per giorno, un riepilogo settimanale automatico ai committenti, e — per chi è autenticato — un'inclinazione verso le categorie che ha salvato.

   **La riserva del piano resta vera e va tenuta a mente**: l'obiettivo primario è avere eventi. Il sistema è costruito perché sia *possibile* vendere con onestà e misurare cosa si è venduto, non perché la monetizzazione venga prima del popolamento. Il KPI di §1 resta il metro: se la copertura del calendario scende mentre le campagne salgono, si sta ottimizzando la cosa sbagliata.

   **Una cosa da mettere nel contratto, non nel codice**: le misure si contano dal browser, quindi chi blocca gli script non viene contato e le cifre sono una stima al ribasso. Va scritto prima del primo cliente, non dopo la prima contestazione.
5. Chi modera nella pratica e con quali tempi dichiarati ai locali.
6. ~~Account utente nel primo rilascio?~~ **Deciso: sì.** Account, salvataggi e notifiche sono in fase 1 (F7b, §15). ~~Resta da scegliere se attivare Web Push al lancio~~ — **deciso** (D8, poi D54): escluso per un ostacolo tecnico che nel frattempo è caduto, quindi attivato. Si parte con push ed email insieme, con l'email come ripiego di §15.6.
7. Lingue al lancio: solo italiano, o anche inglese per studenti e turismo.
8. Cutoff notturno e categorie iniziali (§7.4) — validare la tabella con qualche gestore reale prima di scolpirla.
9. Provider: storage, email, geocoding.
10. Politica sulle locandine e testo della dichiarazione di diritto d'uso.

---

## 21. Regola finale per l'agente

Davanti a un dubbio, non inventare una soluzione locale. Chiediti:

> **Questa decisione rende il sistema più facile o più difficile da estendere a 50 città?**

Se la soluzione lega il sistema a Padova, a un singolo locale, a un singolo provider o a una singola app mobile, è probabilmente sbagliata.

Il prodotto va costruito come **un motore generalizzato per scoprire eventi locali, con Padova come primo dataset** — non come "un sito di eventi di Padova che forse un giorno diventerà altro".

Al termine della fase 1 il sito web è **il primo client del sistema, non il sistema stesso**: Android e iOS si costruiranno sopra la stessa API, senza riscrivere una riga di logica applicativa.

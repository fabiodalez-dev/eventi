# Schema del database — stato effettivo

Documento generato dallo schema realmente creato da `php artisan migrate:fresh` su
MariaDB 12.3 (`127.0.0.1:3307`, db `eventi_local`), non dal piano.
Il contratto funzionale resta `docs/piano-piattaforma-eventi-v2.md` §7; qui sotto
c'è ciò che esiste, con ogni **deviazione** motivata.

Tutte le migration stanno in `database/migrations`, prefisso `2026_08_23_1xxx00`,
numerate nell'ordine di dipendenza delle foreign key.

---

## 1. Convenzioni applicate a tutte le tabelle

| Aspetto | Scelta | Motivo |
|---|---|---|
| Chiave primaria | `id` bigint unsigned auto increment (tranne `event_tag`, chiave composta) | default Laravel |
| Date | **`DATETIME`**, mai `TIMESTAMP` | `TIMESTAMP` in MariaDB viene convertito con il fuso della sessione: due server con `time_zone` diversi leggerebbero valori diversi. `DATETIME` conserva l'istante UTC scritto da Laravel |
| `created_at` / `updated_at` | `$table->datetimes()` | genera `DATETIME NULL`, non `TIMESTAMP` |
| `deleted_at` | `$table->softDeletesDatetime()` su `users`, `venues`, `events` | stessa ragione |
| Stati | colonne `ENUM` allineate 1:1 agli enum PHP di `app/Enums` | il database rifiuta un valore che l'applicazione non conosce |
| JSON | `$table->json()` → `longtext` con `CHECK (json_valid(...))` su MariaDB | comportamento nativo di MariaDB, non una scelta |
| Charset | `utf8mb4` / `utf8mb4_unicode_ci` | default della connessione |

**I valori degli ENUM sono ripetuti come stringhe letterali dentro le migration,
non importati da `App\Enums`.** Una migration è una fotografia del passato: se
citasse la classe, aggiungere un caso all'enum cambierebbe retroattivamente il
significato di una migration già eseguita. La corrispondenza fra i due elenchi è
verificata (vedi §6).

---

## 2. Enum PHP — `app/Enums`

19 enum backed by string. Ognuno espone:

- `label(): string` — legge `lang/it/enums.php` con `__('enums.<gruppo>.<valore>')`;
- `options(): array` — mappa `valore => etichetta`, per select e filtri;
- `values(): array` — elenco dei valori, per validazione.

| Enum | Valori | Chiave di traduzione |
|---|---|---|
| `VenueType` | bar, pub, circolo, centro_sociale, club, teatro, cinema, libreria, associazione, galleria, spazio_pubblico, ristorante, altro | `enums.venue_type.*` |
| `VenueStatus` | draft, pending, approved, suspended, rejected | `enums.venue_status.*` |
| `VenueRole` | owner, editor | `enums.venue_role.*` |
| `VenuePlan` | free, premium | `enums.venue_plan.*` |
| `ApplicationStatus` | pending, approved, rejected | `enums.application_status.*` |
| `EventStatus` | draft, pending, published, rejected, cancelled, archived | `enums.event_status.*` |
| `EventSource` | manual, venue, submission, import_ics, import_api | `enums.event_source.*` |
| `VerificationStatus` | unverified, venue_confirmed, editorial_checked | `enums.verification_status.*` |
| `PriceType` | free, donation, ticket, membership, unknown | `enums.price_type.*` |
| `OccurrenceStatus` | scheduled, cancelled, sold_out, postponed, moved | `enums.occurrence_status.*` |
| `LineupRole` | live, dj, opening, special_guest, speaker | `enums.lineup_role.*` |
| `ImportSourceType` | ics, json, rss, api, manual | `enums.import_source_type.*` |
| `ReportReason` | wrong_info, duplicate, spam, offensive, cancelled, copyright | `enums.report_reason.*` |
| `ReportStatus` | pending, reviewing, resolved, dismissed | `enums.report_status.*` |
| `NotificationChannel` | mail, push, database | `enums.notification_channel.*` |
| `NotificationStatus` | pending, sent, skipped, failed, cancelled | `enums.notification_status.*` |
| `SubmissionStatus` | pending, approved, rejected | `enums.submission_status.*` |
| `FollowableType` | venue, tag, category, **event** | `enums.followable_type.*` |
| `DevicePlatform` | ios, android, web | `enums.device_platform.*` |

Tutti gli 86 casi hanno un'etichetta italiana in `lang/it/enums.php`. `lang/en` e
`lang/de` esistono e restano vuoti (`.gitkeep`), come da §2 delle convenzioni.

---

## 3. Tabelle

Colonne elencate nell'ordine fisico. `?` = nullable.

### 3.1 `cities`
```
id · name · slug(unique) · province_code(4) · province_name · region
country_code(2, IT) · timezone(64, Europe/Rome) · center_lat(10,7) · center_lng(10,7)
default_zoom(tinyint, 12) · bounds(json?) · radius_km(smallint, 30) · locale(5, it)
is_active(bool, false) · launched_at? · night_cutoff_time(time, 06:00:00)
starting_soon_minutes(smallint, 180) · settings(json?) · created_at · updated_at
```
Indici: `slug` unique, `is_active`.

`is_active` nasce **false**: una città si accende quando è pronta, non appena
inserita. Aggiungere Vicenza resta un `INSERT`.

### 3.2 `venues`
```
id · city_id→cities[RESTRICT] · name · slug(unique) · type(enum VenueType, altro)
description(text?) · short_description(500?)
address · address_extra? · postal_code(10?) · municipality · province_code(4)
lat(10,7) · lng(10,7) · location(POINT NOT NULL, SRID 0)
phone(40?) · email? · website? · socials(json?) · opening_hours(json?)
capacity(uint?) · accessibility(json?) · requires_membership(bool) · membership_notes(text?)
status(enum VenueStatus, draft) · is_verified(bool) · is_nonprofit(bool) · plan(enum VenuePlan, free)
auto_publish(bool) · approved_at? · approved_by→users[SET NULL] · rejection_reason(text?)
claim_token(64, unique?) · default_event_settings(json?) · stats_cache(json?)
created_at · updated_at · deleted_at
```
Indici: `slug` unique, `claim_token` unique, `(city_id, status)`, `municipality`,
**SPATIAL KEY su `location`**.

`is_nonprofit` e `plan` vengono da **D9** (monetizzazione), non da §7.2.

**Coordinate.** `location` è `POINT NOT NULL` — MariaDB rifiuta uno `SPATIAL INDEX`
su colonna nullable. Si scrive sempre `ST_GeomFromText('POINT(lng lat)', 0)`:
longitudine prima, SRID 0 (§4 delle convenzioni, D4). `lat` e `lng` restano come
colonne decimali leggibili: servono ai client che non parlano SQL spaziale e a
ricostruire `location` senza interrogare il motore geometrico.

**Il cast del model.** `Venue` usa `HasSpatial` e `'location' => Point::class`
(`matanyadaev/laravel-eloquent-spatial`). Il costruttore della libreria è
`new Point($lat, $lng, 0)` — **latitudine prima**, l'inverso di ciò che finisce
nel database — perché `getWktData()` emette `"{lng} {lat}"`. Verificato scrivendo
Padova con `new Point(45.4064, 11.8768, 0)`: il database contiene
`POINT(11.8768 45.4064)` con `SRID 0`, `ST_X` = 11.8768 (longitudine) e
`ST_Y` = 45.4064 (latitudine). L'ordine richiesto da §4 delle convenzioni è quindi
rispettato, ma **solo** perché la conversione la fa la libreria: scrivere WKT a
mano richiede l'ordine opposto rispetto al costruttore.

Verificato su questo schema:
- `SRID(location) = 0`, `ST_X` = longitudine, `ST_Y` = latitudine;
- `ST_Distance_Sphere` fra Padova e Vicenza restituisce **30 787 m**;
- `EXPLAIN` di una `MBRContains` riporta `key: venues_location_spatialindex`,
  cioè l'indice spaziale viene realmente usato.

### 3.3 `venue_user`
```
id · venue_id→venues[CASCADE] · user_id→users[CASCADE] · role(enum VenueRole, editor)
invited_at? · accepted_at? · created_at · updated_at
```
Unique `(venue_id, user_id)`; indice `(user_id, role)`.

### 3.4 `venue_applications`
```
id · user_id→users[SET NULL]? · venue_id→venues[SET NULL]?
venue_name · contact_name · contact_role? · contact_phone(40?) · contact_email
address? · type(enum VenueType, altro) · socials(json?) · message(text?) · documents(json?)
status(enum ApplicationStatus, pending) · reviewed_by→users[SET NULL]? · reviewed_at?
notes(text?) · created_at · updated_at
```
Indice `(status, created_at)`.

`user_id` è **nullable**: §7.3 lo dava pieno, ma la cancellazione account (§15.9,
GDPR) deve poter rimuovere l'utente lasciando la pratica e la sua motivazione
nell'archivio della moderazione.

### 3.5 `categories`
```
id · name · slug(unique) · icon(64?) · color(16?) · sort_order(smallint, 0)
is_active(bool, true) · parent_id→categories[SET NULL]?
default_duration_minutes(smallint?) · supports_ongoing(bool, true) · is_nightlife(bool, false)
created_at · updated_at
```
Indice `(is_active, sort_order)`.

`default_duration_minutes` è **nullable** perché il piano stesso, alla riga "Arte
e mostre", scrive «— (usa orari)». `null` significa: la durata non si deduce dalla
categoria, `effective_ends_at` va calcolato dagli orari di apertura (§8.3).

### 3.6 `tags`
```
id · name · slug(unique) · category_id→categories[SET NULL]? · is_approved(bool, false)
usage_count(uint, 0) · synonyms(json?) · created_at · updated_at
```
Indice `(is_approved, usage_count)`.

### 3.7 `events`
```
id · city_id→cities[RESTRICT] · venue_id→venues[SET NULL]? · category_id→categories[RESTRICT]
created_by→users[SET NULL]? · organizer_name? · organizer_url?
title · slug(unique) · subtitle? · description(longtext?) · short_description(500?)
poster? · gallery(json?)
price_type(enum PriceType, unknown) · price_min(8,2?) · price_max(8,2?) · currency(3, EUR) · price_notes?
ticket_url? · booking_required(bool) · booking_url? · booking_phone(40?)
age_restriction(40?) · language(5?) · is_outdoor(bool) · custom_location(json?) · external_links(json?)
source(enum EventSource, manual) · source_ref? · verification_status(enum, unverified)
status(enum EventStatus, draft) · rejection_reason(text?)
is_featured(bool) · featured_until? · editorial_score(int, 0) · published_at? · seo(json?)
views_count(ubigint, 0) · saves_count(ubigint, 0)
created_at · updated_at · deleted_at
```
Indici: **`(city_id, slug)` unique**, `(city_id, status)`, `(venue_id, status)`,
`(category_id, status)`, `(source, source_ref)`, `(is_featured, featured_until)`,
`editorial_score`.

`(source, source_ref)` è indicizzato ma **non** unico: `source_ref` è nullo per
tutto ciò che non arriva da un import, e l'idempotenza dell'import (§14.2) si
gioca su una `SELECT` di quella coppia. Un unique su colonna nullable in MariaDB
non impedirebbe comunque i doppioni con `NULL`.

Lo slug è unico **per città** e non in assoluto (D12): due città possono avere
il proprio "Concerto di Natale" senza che la seconda si porti dietro un `-1`.
`Event::getSlugOptions()` passa la stessa coppia a `extraScope()`, così il
generatore di slug e il vincolo del database guardano lo stesso insieme.

`city_id` e `category_id` sono `RESTRICT`: cancellare una città o una categoria
che ha eventi deve fallire, non svuotare il catalogo.

### 3.8 `event_tag`
```
event_id→events[CASCADE] · tag_id→tags[CASCADE]
```
Chiave primaria composta `(event_id, tag_id)`, indice su `tag_id`. Nessuna
`id`, nessun timestamp: è una pivot pura.

### 3.9 `event_recurrences`
```
id · event_id→events[CASCADE] · rrule(500) · until? · exdates(json?) · generated_until?
created_at · updated_at
```
Indice su `generated_until` — è la colonna che lo scheduler mensile interroga per
sapere quali serie estendere.

Creata **prima** di `event_occurrences`, che la referenzia.

### 3.10 `event_occurrences`
```
id · event_id→events[CASCADE] · recurrence_id→event_recurrences[SET NULL]?
starts_at · ends_at? · effective_ends_at(NOT NULL) · doors_at? · is_all_day(bool)
business_date(DATE NOT NULL)
status(enum OccurrenceStatus, scheduled) · status_note? · price_override(json?)
capacity_left(uint?) · is_exception(bool) · created_at · updated_at
```
Indici richiesti dal piano, tutti presenti:
`(business_date, status)` · `(starts_at)` · `(effective_ends_at)` · `(event_id, starts_at)`.

`business_date` ed `effective_ends_at` sono **persistiti e NOT NULL**, mai scritti
a mano: li calcola `EventOccurrenceObserver` (§5 delle convenzioni). Non sono
colonne generate SQL perché la regola dipende dal fuso della città e dai flag
della categoria, cioè da dati che stanno su altre due tabelle.

`recurrence_id` è `SET NULL`: cancellare la regola di ricorrenza non deve
cancellare le date già pubblicate e già salvate dagli utenti.

### 3.11 `lineups`
```
id · occurrence_id→event_occurrences[CASCADE] · name · role(enum LineupRole, live)
starts_at? · url? · sort_order(smallint, 0) · created_at · updated_at
```
Indice `(occurrence_id, sort_order)`.

### 3.12 `saved_events`
```
id · user_id→users[CASCADE] · occurrence_id→event_occurrences[CASCADE]
reminder_sent_at(json?) · created_at · updated_at
```
Unique `(user_id, occurrence_id)`, indice su `occurrence_id`.

### 3.13 `follows`
```
id · user_id→users[CASCADE] · followable_type(64) · followable_id(ubigint)
notify(bool, true) · created_at · updated_at
```
Unique `(user_id, followable_type, followable_id)` (nome esplicito
`follows_user_followable_unique`), indice `(followable_type, followable_id)`.

`followable_type` è lungo 64 e contiene un alias breve (`venue`, `tag`,
`category`, `event` — vedi `FollowableType`), non il nome completo della classe:
la morph map va registrata con `Relation::enforceMorphMap()`. Un nome di classe
nel database lega i dati al namespace PHP.

Il quarto alias, `event`, è «Segui questo evento» di §15.3 e **non** alimenta il
feed: significa «salvami ogni data nuova» e produce righe in `saved_events`
(D29). Chi legge il feed guarda i soli `FollowableType::feedSources()`.

### 3.14 `devices`
```
id · user_id→users[CASCADE] · platform(enum DevicePlatform, web)
push_token(512?) · endpoint(512?) · keys(json?) · app_version(32?) · locale(5?)
last_seen_at? · revoked_at? · created_at · updated_at
```
Indici `(user_id, revoked_at)` e `last_seen_at` — servono alla regola "device
attivo negli ultimi 30 giorni" di §15.6.

Nessun indice unico su `push_token` / `endpoint`: sono lunghi 512 e la loro
unicità è per utente, non globale. La deduplica avviene in fase di registrazione
del device.

**Da D54 questa tabella è anche l'archivio delle iscrizioni Web Push**, e non
la `push_subscriptions` che il pacchetto porta con sé: una riga `platform=web`
con `endpoint` e `keys` (`p256dh` e `auth`) è un'iscrizione, e
`App\Models\WebPushSubscription` la legge nella forma che il canale si aspetta.
Due tabelle per lo stesso fatto avrebbero costretto, a ogni invio, a decidere
quale delle due dice la verità. Nessuna colonna aggiunta: c'era già tutto.

### 3.15 `scheduled_notifications`
```
id · user_id→users[CASCADE] · notifiable_type(64?) · notifiable_id(ubigint?)
type(64) · channel(enum NotificationChannel, mail) · send_at · sent_at?
status(enum NotificationStatus, pending) · dedupe_key(191, UNIQUE) · payload(json?)
attempts(utinyint, 0) · last_error(text?) · created_at · updated_at
```
Indici: `dedupe_key` **unique**, `(status, send_at)`, `(notifiable_type, notifiable_id)`,
`(user_id, type)`.

`dedupe_key` è lungo **191** e non 255: è la lunghezza sicura per un indice unico
su `utf8mb4` anche con formati di riga meno recenti. Le chiavi hanno forma
`reminder_3h:user_42:occ_918` — ampiamente sotto il limite.

Verificato: il secondo inserimento della stessa `dedupe_key` fallisce con
`ERROR 1062 Duplicate entry`. Senza Redis e senza lock distribuito (D5) questo
vincolo **è** la garanzia contro il doppio invio.

`(status, send_at)` è l'indice su cui gira il worker ogni 5 minuti con
`SELECT ... FOR UPDATE SKIP LOCKED`.

`last_error` porta **due** cose, e lo stato della riga dice quale: su `failed`
l'errore dell'ultimo tentativo, su `skipped` il motivo per cui l'invio non
serviva più (`NotificationSkipReason`, reso in italiano dal pannello). §15.5
pretende «skipped con motivo» e questa è l'unica colonna di testo libero della
tabella: una seconda colonna avrebbe distinto due parole che gli stati già
distinguono (D31, punto 1). Nessuna migration è stata aggiunta per il motore
delle notifiche — lo schema di §7.10 bastava.

### 3.16 `notification_log`
```
id · user_id→users[CASCADE] · type(64) · channel(enum NotificationChannel, mail)
sent_at · opened_at? · clicked_at? · created_at · updated_at
```
Indici `(user_id, sent_at)` — frequency capping — e `(type, sent_at)` — analitica
per tipo e purga a 12 mesi (§15.9).

### 3.17 `event_submissions`
```
id · city_id→cities[SET NULL]? · venue_id→venues[SET NULL]? · event_id→events[SET NULL]?
title · raw_text(longtext?) · poster? · venue_hint? · starts_at_hint?
contact_name? · contact_email · status(enum SubmissionStatus, pending)
reviewed_by→users[SET NULL]? · reviewed_at? · notes(text?) · ip_address(45?)
created_at · updated_at
```
Indice `(status, created_at)`.

§7.10 chiudeva l'elenco con «…». Le colonne aggiunte: `city_id`, `venue_id`,
`event_id` (l'evento creato all'approvazione, per non perdere il legame con la
proposta), `venue_hint` e `starts_at_hint` (ciò che il proponente indica in
chiaro, prima che un moderatore lo colleghi a un locale reale), `contact_name`,
`reviewed_by`, `reviewed_at`, `notes`, `ip_address` (anti-abuso, §14.7).

### 3.18 `reports`
```
id · reportable_type(64) · reportable_id(ubigint) · reason(enum ReportReason)
note(text?) · reporter_email? · reporter_user_id→users[SET NULL]?
status(enum ReportStatus, pending) · reviewed_by→users[SET NULL]? · reviewed_at?
resolution_note(text?) · ip_address(45?) · created_at · updated_at
```
Indici `(reportable_type, reportable_id)` e `(status, created_at)`.

`reason` **non ha default**: chi segnala deve dire perché.
`reporter_user_id`, `reviewed_by`, `reviewed_at`, `resolution_note` e
`ip_address` sono aggiunte all'elenco di §7.10, che finiva con «…»:
§14.6 prevede azioni del moderatore, e un'azione senza autore né esito non è
tracciabile.

### 3.19 `event_views_daily`
```
id · event_id→events[CASCADE] · date(DATE)
views(uint,0) · unique_views(uint,0) · direction_clicks(uint,0)
ticket_clicks(uint,0) · shares(uint,0) · created_at · updated_at
```
Unique `(event_id, date)` — è ciò che rende la tabella aggregata per costruzione:
il vincolo rende impossibile una riga per click. Indice su `date` per i totali di
periodo.

### 3.20 `import_sources`
```
id · city_id→cities[RESTRICT] · venue_id→venues[SET NULL]? · type(enum ImportSourceType, ics)
url(1000?) · credentials(text?) · mapping(json?) · default_category_id→categories[SET NULL]?
is_active(bool, true) · last_run_at? · last_status(32?) · last_error(text?)
created_at · updated_at
```
Indice `(is_active, last_run_at)`.

`credentials` è `TEXT` e non una colonna cifrata a livello di database: la cifratura
è del cast `encrypted` di Laravel, e il testo cifrato è più lungo dell'originale.
`url` arriva a 1000 caratteri perché i calendari ICS pubblici portano spesso
token lunghi in query string.

### 3.21 `settings`
```
id · key(unique) · value(longtext?) · type(enum: string|integer|float|boolean|json, string)
group(64?) · description? · created_at · updated_at
```
Indice su `group`.

`type` è la tipizzazione richiesta da §7.11 («key/value tipizzato»). Non ha un
enum PHP dedicato: non era nell'elenco richiesto e non descrive uno stato del
dominio, ma il tipo di un valore di configurazione.

### 3.22 `promotions` (D9)
```
id · venue_id→venues[CASCADE] · event_id→events[CASCADE]? · type(64)
starts_at · ends_at · notes(text?) · created_at · updated_at
```
Indici `(starts_at, ends_at)` e `(venue_id, type)`.

`type` è una **stringa**, non un enum: D9 istituisce la tabella ma non elenca i
tipi di promozione, e un enum PHP con valori inventati sarebbe una decisione di
prodotto presa dal codice. Va promosso a enum quando i tipi saranno definiti.
La tabella nasce vuota: nessun pagamento è implementato in v1.

### 3.23 `users` (§7.10)
Migration di Laravel modificata sul posto, non estesa con una `ALTER` separata:
il progetto è nuovo e non esistono dati da migrare.
```
id · name? · email(unique) · email_verified_at? · password
timezone(64, Europe/Rome) · locale(5, it) · notification_preferences(json?)
daily_digest_time(TIME?) · quiet_hours(json?) · marketing_opt_in_at? · last_active_at?
remember_token · created_at · updated_at · deleted_at
```
Indice su `last_active_at`.

`email_verified_at` è stato convertito da `TIMESTAMP` a `DATETIME` per coerenza
con tutto il resto dello schema; `password_reset_tokens.created_at` idem.

`marketing_opt_in_at` è separato da `notification_preferences` perché il consenso
marketing è giuridicamente distinto dalle notifiche transazionali (§15.9): deve
avere una data propria, non una chiave dentro un JSON.

`name` è **nullable**: §15.2 lo dichiara facoltativo, e una stringa vuota non è
un nome mancante ma un nome vuoto (D29). La forma di `notification_preferences`
la conosce un punto solo, `App\DTOs\NotificationPreferences`, che porta anche i
valori predefiniti di §15.4 — una chiave assente non significa «spento».

### 3.23-bis `notifications` (§15.6, D8 e D29)
```
id(uuid) · type · notifiable_type(64)/notifiable_id · data(json)
read_at? · created_at · updated_at
```
Indice `(notifiable_type, notifiable_id)`.

È la tabella del canale `database` di Laravel — l'**archivio in-app** che §15.6
vuole «sempre», e che D8 ha promosso a canale di destinazione insieme all'email
dopo l'esclusione del Web Push. Rispetto alla migration del framework cambiano
solo le due cose che valgono per tutto questo schema: date `DATETIME` e colonna
morph di lunghezza dichiarata, perché `notifiable_type` contiene l'alias `user`
della morph map.

### 3.24 Tabelle dei pacchetti (D13)

Pubblicate insieme ai model che le usano, **verbatim** come le genera il pacchetto:

| Migration | Tabelle | Usata da |
|---|---|---|
| `2026_08_23_110000_create_media_table` | `media` | `Venue` ed `Event` (`spatie/laravel-medialibrary`) |
| `2026_08_23_110100_create_permission_tables` | `roles`, `permissions`, `model_has_roles`, `model_has_permissions`, `role_has_permissions` | `User` (`spatie/laravel-permission`) |
| `2026_08_23_110200_create_activity_log_table` | `activity_log` | `Venue` ed `Event` (`spatie/laravel-activitylog`) |
| `2026_08_23_110300_create_personal_access_tokens_table` | `personal_access_tokens` | `User` (`laravel/sanctum`, token dell'API v1 — D28) |

Sono le uniche tabelle con `TIMESTAMP` al posto di `DATETIME` insieme a
`failed_jobs`: vale la stessa ragione, non appartengono al dominio e riscriverle
renderebbe più costoso ogni aggiornamento del pacchetto.

`personal_access_tokens.tokenable_type` contiene l'alias `user` e non il nome
della classe: la morph map di `AppServiceProvider` vale anche per le tabelle dei
pacchetti, ed è ciò che tiene i namespace PHP fuori dal database (deviazione 12).

`activity_log` non è opzionale: `LogsActivity` scrive una riga a ogni cambio di
stato di un locale o di un evento, quindi senza quella tabella il salvataggio
fallisce.

---

### 3.25 `pages` (§11.1, §16 — D34)
```
id · slug(191, UNIQUE) · title · excerpt(500?) · body(longtext)
is_published(bool, false) · seo_title? · seo_description(500?) · sort_order(smallint, 0)
created_at · updated_at
```
Indice `(is_published, sort_order)`.

Sono le pagine di `/pagine/{slug}`: informativa privacy, cookie policy, termini,
chi siamo, contatti. Stanno nel database perche un errore in un'informativa
privacy va corretto **oggi**, non al prossimo rilascio (D34, punto 1).

`body` e **Markdown**, non HTML: la conversione lo rende scartando ogni
marcatura grezza, quindi non esiste un percorso per cui uno `<script>` scritto
nel campo finisca in pagina, e non c'e una whitelist da tenere allineata (§16).
Lo slug **non si rigenera** al cambio del titolo: e un indirizzo che finisce nei
registri dei trattamenti e nelle email gia spedite.

### 3.26 `consent_logs` (§16 — D34)
```
id · consent_id(uuid) · user_id→users[SET NULL]? · action(enum ConsentAction)
choices(json) · policy_version(32) · created_at · updated_at
```
Indici su `consent_id` e su `created_at`.

Il «log del consenso» che §16 richiede. **Non contiene l'indirizzo IP**: per
dimostrare un consenso basta poter riconoscere che quella scelta appartiene a
quel browser, e a questo serve `consent_id` — un UUID casuale generato dal
server e conservato solo nel cookie della scelta. Raccogliere l'IP per provare
il rispetto della privacy sarebbe l'unico dato personale introdotto dalla
funzione.

`$table->uuid()` su MariaDB 10.7+ crea una colonna di tipo **nativo `uuid`**
(16 byte), non un `char(36)` come su MySQL: e supportato anche dalla 10.11 di
produzione, ed e il motivo per cui la colonna appare come `uuid` in
`SHOW CREATE TABLE`.

Le righe non si aggiornano mai: cambiare idea ne scrive una nuova con lo
**stesso** `consent_id`. `policy_version` e il valore di `CONSENT_VERSION`
vigente al momento della scelta; cambiandolo, ogni scelta precedente torna a
valere come «non espressa» e il banner ricompare.

## 4. Foreign key — criterio applicato

| Regola | Dove | Perché |
|---|---|---|
| `CASCADE` | `event_occurrences.event_id`, `event_recurrences.event_id`, `event_tag.*`, `lineups.occurrence_id`, `saved_events.*`, `follows.user_id`, `devices.user_id`, `scheduled_notifications.user_id`, `notification_log.user_id`, `event_views_daily.event_id`, `venue_user.*`, `promotions.*` | il figlio non ha significato senza il padre |
| `RESTRICT` | `venues.city_id`, `events.city_id`, `events.category_id`, `import_sources.city_id` | cancellare un dato di base con contenuti collegati deve fallire in modo rumoroso |
| `SET NULL` | `events.venue_id`, `events.created_by`, `event_occurrences.recurrence_id`, `tags.category_id`, `categories.parent_id`, tutti i `reviewed_by` / `approved_by`, `venue_applications.*`, `event_submissions.*`, `import_sources.venue_id`, `import_sources.default_category_id`, `reports.reporter_user_id`, `consent_logs.user_id` | il contenuto sopravvive alla sparizione del riferimento — vale in particolare per la cancellazione account GDPR |

38 vincoli in totale.

Cancellare un evento porta via, in cascata verificata: occorrenze → salvataggi
degli utenti, lineup, tag, statistiche giornaliere, promozioni collegate.

---

## 5. Deviazioni dal piano — elenco completo

| # | Deviazione | Motivo |
|---|---|---|
| 1 | `venues.location` è `POINT` con **SRID 0**, non `geography Point 4326` come scrive §7.2 | D3 e D4: su MariaDB non esiste il tipo `geography`; SRID 4326 su MySQL 8 inverte gli assi. SRID 0 con `POINT(lng, lat)` è il solo formato identico sui due motori |
| 2 | Colonna creata con `$table->geometry('location', 'point', 0)`, non `$table->point()` | `point()` non esiste più nel Blueprint di Laravel 13; il subtype si passa a `geometry()`. Il DDL prodotto è `location point NOT NULL` |
| 3 | `venues.is_nonprofit`, `venues.plan`, tabella `promotions` | D9, non presenti in §7 |
| 4 | Aggiunto l'enum `DevicePlatform` (fuori dai 18 richiesti) | regola 3 delle convenzioni: enum PHP per ogni stato, mai stringhe magiche. `devices.platform` sarebbe stata l'unica colonna enum senza controparte PHP |
| 5 | `ReportStatus` = pending, reviewing, resolved, dismissed | §7.10 nomina `status` senza elencarne i valori; §14.6 elenca le *azioni* del moderatore (ignore, fix, contact venue, cancel, merge), che sono un'altra cosa — l'esito di una segnalazione, non il suo stato di lavorazione. Da ricontrollare quando si disegnerà il pannello moderazione |
| 6 | `categories.default_duration_minutes` nullable | il piano stesso lascia la cella vuota per "Arte e mostre" |
| 7 | `venue_applications.user_id` nullable | cancellazione account (§15.9) senza perdere la pratica |
| 8 | Colonne extra in `event_submissions` e `reports` | i due elenchi di §7.10 finiscono con «…»; le aggiunte servono ai flussi di §14.6 e §14.7 |
| 9 | `dedupe_key` lungo 191 | limite sicuro per un indice unico su utf8mb4 |
| 10 | `(source, source_ref)` indicizzato ma non unico | `source_ref` è nullo fuori dagli import e `NULL` non è mai duplicato per un unique |
| 11 | Tutte le date sono `DATETIME`, comprese `created_at`/`updated_at`/`deleted_at` | un `TIMESTAMP` viene riletto attraverso il fuso della sessione MariaDB |
| 12 | `follows.followable_type` e `reports.reportable_type` lunghi 64, pensati per una morph map | tenere i nomi delle classi PHP fuori dal database |
| 13 | `promotions.type` resta stringa | D9 non definisce i tipi; da promuovere a enum quando esisteranno |
| 14 | `settings.type` è un enum SQL senza enum PHP | non è uno stato del dominio ma il tipo di un valore |
| 15 | `cities.is_active` default `false` | una città si pubblica quando è pronta |
| 16 | `events.slug` unico per `(city_id, slug)` e non globalmente | D12: `/{city}/eventi` è predisposto da §11.1 e la provincia da D11 |
| 17 | Tabelle `media`, permessi e `activity_log` pubblicate dai pacchetti | D13: i trait dichiarati sui model le usano davvero |
| 18 | Tabella `personal_access_tokens` pubblicata da Sanctum | D28: l'API v1 autentica con token per dispositivo (§13.4). Stessa regola di D13: migration di terze parti, lasciata verbatim |
| 19 | `FollowableType` guadagna il caso `event` (quattro valori, non tre) | §15.3 chiede «Segui questo evento», che ha bisogno di un magazzino: la colonna è già una stringa e la morph map conteneva già l'alias, quindi una tabella nuova sarebbe stata un duplicato di `follows` con lo stesso vincolo unico (D29) |
| 20 | `users.name` è nullable | §15.2 dichiara il nome facoltativo; una stringa vuota non è un nome mancante. Migration cambiata sul posto: il progetto è nuovo e non esistono dati da migrare |
| 21 | Nuova tabella `notifications` (canale `database` di Laravel) | D8 ha promosso l'archivio in-app a canale di destinazione e §15.8 espone `GET /v1/me/notifications`: un archivio vuoto è una risposta legittima, un endpoint assente costringe l'app a due strade |

| 22 | Nuove tabelle `pages` e `consent_logs` | §16 chiede Privacy Policy, Cookie Policy, Termini, Contatti, Chi siamo e il **log del consenso**, e §11.1 la rotta `/pagine/{slug}`, ma §7 non prevedeva alcuna tabella per l'una ne per l'altro (D34) |
| 23 | `consent_logs` non ha una colonna per l'indirizzo IP | D34, punto 9: il registro deve provare una scelta, non tracciare chi l'ha fatta. Basta l'identificativo casuale conservato nel cookie |

Fuori portata, lasciato com'era: `failed_jobs.failed_at` resta `TIMESTAMP` —
è la migration di Laravel, non tocca il dominio.

Non ancora creata, perché non richiesta in questa fase: `notifications`
(archivio in-app, canale `database` — arriverà con
`php artisan notifications:table`).

### 5.1 Estensioni mobile del 2026-09-04

| Oggetto | Modifica |
|---|---|
| `devices` | `token_hash` SHA-256 unico e `installation_id` indicizzato; il push token grezzo non viene usato come chiave applicativa |
| `personal_access_tokens` | `device_id` nullable con FK: collega una sessione revocabile al dispositivo fisico |
| `event_occurrences` | `deleted_at`: tombstone necessario alla sincronizzazione incrementale mobile |
| `mobile_auth_challenges` | challenge magic-link hashato, monouso, con `expires_at` e `consumed_at` |
| Telescope | tabelle di osservabilità installate ma provider disattivato per default e dati sensibili nascosti |

---

## 6. Verifiche eseguite

| Verifica | Esito |
|---|---|
| `php artisan migrate:fresh` | 25 migration, nessun errore |
| Colonne `ENUM` del database confrontate una a una con `Enum::values()` (20 colonne) | nessuna divergenza |
| Etichette: 86 casi di enum risolti con `__()` | 0 mancanti, nessuna chiave grezza restituita |
| `SRID(location)`, `ST_X`, `ST_Y` su dati reali | SRID 0, X = longitudine, Y = latitudine |
| `ST_Distance_Sphere` Padova→Vicenza | 30 787 m |
| `EXPLAIN` su `MBRContains` | usa `venues_location_spatialindex` |
| `dedupe_key` duplicata | `ERROR 1062 Duplicate entry` |
| Cascata `DELETE FROM events` | occorrenze e salvataggi rimossi |
| `./vendor/bin/pint --test` | passed |
| `./vendor/bin/phpstan analyse app database --level=6` | 0 errori |

I dati di prova usati per queste verifiche sono stati rimossi con un
`migrate:fresh` finale: il database è vuoto.

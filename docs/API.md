# API v1 — stato reale

Aggiornato con il ticketing del 2026-09-05. Il contratto è verificato con
test di integrazione e consumato dall'app Android in `android/` contro dati reali.

## Coordinate

| Voce | Valore |
|---|---|
| Base | `/api/v1` |
| Documentazione | `/docs/api` (Scramble) · JSON versionato in `docs/openapi.json` (rigenerato il 19/11/2026 con `php artisan scramble:export --path=docs/openapi.json`) — OpenAPI **3.1.2**, **183 operazioni** |
| Autenticazione | Bearer token (Sanctum) per `/me`, `auth/logout` e scritture ticketing; policy aggiuntive per il locale |
| Formato risposte | `{data, meta{next_cursor, has_more}}` · errori `{error{code, message, fields}}` |
| Paginazione | catalogo a cursore (`limit` 1–50); cursore illeggibile → **400**. Biglietti: `page`, 30 prenotazioni, `meta.next_page` nullable |
| Cache | catalogo: `ETag` + `Cache-Control: max-age=60, public, stale-while-revalidate=300` (private se autenticato), **304**. Ticketing, e `home` e `sponsorships` quando portano un gettone usa e getta: `private, no-store`, senza ETag |
| Rate limit | 60 req/min anonimi, 120 autenticati, con header `X-RateLimit-Limit/-Remaining/-Reset` e `Retry-After` sul 429 |

## Rotte principali

### Scoperta e contenuti (pubbliche)

| Metodo | Rotta |
|---|---|
| GET | `/config` |
| GET | `/home` · `/sync` · `/stats` · `/areas` |
| GET | `/cities` · `/cities/{slug}` |
| GET | `/events` · `/events/{slug}` · `/events/{slug}/similar` · `/events/{slug}/occurrences` |
| GET | `/occurrences/{id}` |
| GET | `/calendar` |
| GET | `/venues` · `/venues/{slug}` · `/venues/{slug}/events` · `/venues/{slug}/past` |
| GET | `/map/occurrences` (payload minimale, 7 campi) |
| GET | `/search` |
| GET | `/categories` · `/categories/{slug}` · `/tags` · `/tags/{slug}` · `/pages` · `/pages/{slug}` |
| GET | `/sponsorships` |
| POST | `/submissions` · `/reports` |
| POST | `/reports/sponsorships/{id}/metrics/{impressions|clicks}` |

I preset temporali di `/events` (`?preset=ongoing`, `?preset=starting_soon`,
ecc.) passano da `DatePreset` → `EventOccurrenceQuery`: sito e API condividono
una sola definizione di "adesso" (scenario G, verificato sotto).

### Autenticazione

`POST /auth/register` · `login` · `logout` · `magic-link` ·
`magic-link/exchange` · `verify-email` · `verification/resend` ·
`password/forgot` · `password/reset`.

Il link mobile porta un challenge casuale monouso e a scadenza breve. Solo
`magic-link/exchange` lo trasforma in un token Sanctum: il bearer definitivo
non compare mai nell'URL, nei log del browser o nel referrer. Ogni nuovo link
invalida quelli precedenti dello stesso account. Il client genera un verifier casuale
(43–128 caratteri PKCE), conserva il segreto sul dispositivo e invia
`code_challenge = BASE64URL(SHA256(code_verifier))` nella richiesta `magic-link`.
Lo scambio richiede `token` e `code_verifier`. Una prova errata non consuma il link;
un cambio password lo invalida. I vecchi client senza PKCE devono essere aggiornati.
Il collegamento HTTPS `/app/auth/magic` usa gli Android App Links verificati;
la pagina di ripiego non riflette il token e imposta `no-store` e `no-referrer`.

#### Registrazione (D64)

`POST /auth/register` non rivela se l'indirizzo è già registrato: risponde
**sempre `202`** con lo stesso corpo e **nessun token**, per un indirizzo nuovo
come per uno esistente.

```json
{ "data": { "message": "Ti ho scritto: apri il messaggio per entrare." } }
```

Campi: `email`, `password` (regole di `Password::defaults()`), facoltativi
`name`, `first_name`, `last_name`, `password_confirmation` (se presente deve
coincidere), `marketing_opt_in`, `device_name` e `code_challenge` (PKCE S256,
43 caratteri base64url, come `magic-link`). Gli errori di validazione dei campi
restano `422 VALIDATION_FAILED`; l'esistenza dell'email non è mai un errore.

- **Indirizzo nuovo:** l'account nasce con l'email da confermare e arriva il
  messaggio «Conferma l'indirizzo ed entra» con il collegamento di accesso.
  Con `code_challenge` è il collegamento mobile `/app/auth/magic?token=…`: lo
  scambio `magic-link/exchange` con il `code_verifier` della stessa
  installazione emette il token, conferma l'email e **lascia valida la
  password scelta** (la sfida è legata a chi l'ha scelta). Senza
  `code_challenge` arriva il collegamento del sito, che entra dal browser ma,
  non essendo legato a nessuno, azzera la password alla prima apertura.
- **Indirizzo esistente:** l'account non cambia (né password né dati inviati)
  e arriva «Hai già un account»: collegamento di accesso ordinario (lo scambio
  prova la casella e, se l'account è ancora in sospeso, azzera le credenziali
  scelte da altri) e invito a usare «password dimenticata».
- **Account in sospeso con la password giusta** (chi rifà la registrazione
  perché il messaggio non arriva): riparte «Conferma l'indirizzo ed entra»,
  legato alla nuova `code_challenge`. È il modo in cui l'app reinvia.

Tetti: `api-auth` (coppia indirizzo+email, casella di destinazione con una
chiave che non guarda il database — l'email in minuscolo e senza accenti —,
indirizzo), `outbound-email` (10 l'ora per indirizzo) e al massimo cinque
messaggi l'ora per account da registrazione e accesso; oltre il tetto il
messaggio non parte, nessuna sfida PKCE viene creata o cancellata e la risposta
resta `202`. Per un account eliminato la risposta è la stessa e non parte
niente.

Se Android apre `/app/auth/magic?token=…` nel browser (App Links non
verificati), la pagina di ripiego offre «Apri nell'app»: un `intent://auth/magic`
con il pacchetto esplicito, costruito nel browser leggendo il gettone da
`location.search` (l'HTML non lo contiene; `no-store`, `no-referrer`).

`POST /auth/login` con la password giusta su un account la cui casella non è
ancora dimostrata (`email_claim_pending_at`, cioè registrato e mai confermato
dal collegamento) risponde `403 EMAIL_CONFIRMATION_PENDING` senza token e
rimanda il messaggio «Conferma l'indirizzo ed entra»; se la richiesta porta
`code_challenge`, il collegamento si scambia con il verificatore di quella
installazione e la password resta valida. Con la password sbagliata la
risposta resta `401 INVALID_CREDENTIALS`, quindi il `403` lo vede solo chi la
password la conosce.

### Account (`/me`, Bearer token; senza token → 401)

`GET|PATCH|DELETE /me` · `GET /me/export` · `GET /me/feed` ·
`GET|POST /me/saved` · `POST /me/saved/merge` · `DELETE /me/saved/{occurrence}` ·
`GET|POST /me/follows` · `DELETE /me/follows/{type}/{id}` ·
`GET /me/notifications` · `GET|PATCH /me/notification-preferences` ·
`GET|POST /me/devices` · `DELETE /me/devices/{device}` ·
`GET /me/sessions` · `DELETE /me/sessions/{session}` ·
`PATCH /me/notifications/{notification}/read` ·
`POST /me/notifications/read-all`.

### Biglietteria gratuita

`GET /occurrences/{id}/booking` (disponibilità pubblica live),
`POST /occurrences/{id}/bookings`, `GET /me/bookings`,
`POST /me/bookings/{id}/cancel`, `POST /me/bookings/{id}/email`,
`POST /ticketing/{id}/check-in` (solo proprietario del locale/admin).

Vedi [TICKETING.md](TICKETING.md) per payload, inventario facoltativo, idempotenza,
lista d'attesa, annullamenti e operatività. Il vincolo generale di sola lettura
è superato: restano autenticazione, policy e test di isolamento.

### Regole della wishlist

- il server deriva sempre l'utente dal bearer token; non accetta mai `user_id`;
- un salvataggio riguarda `occurrence_id`, quindi una data precisa e non un
  evento astratto;
- `saved/merge` unisce gli ID ospite in modo idempotente e ignora quelli non
  più pubblici;
- la cache privata varia per `Authorization` e `X-Installation-ID`;
- l'app cancella i dati autenticati dalla memoria prima di cambiare account e
  rimuove i salvati ospite solo dopo una risposta di merge riuscita.

## Garanzie di contratto (con test dedicati)

- `is_saved` è **assente** (non `false`) per un chiamante anonimo — misurato.
- `editorial_score` non compare in nessuna risposta — misurato con `grep` sul
  corpo grezzo di events (lista e dettaglio), venues, map, calendar, search,
  config, cities: 0 occorrenze ovunque.
- `external_links` (dettaglio evento) è **sempre** una lista di
  `{"label": "…", "url": "…"}`, vuota quando non ci sono link: mai `null`, mai
  una mappa. La colonna `events.external_links` passa dal cast
  `App\Casts\AsExternalLinks`, che scarta le righe senza etichetta e quelle
  con un indirizzo diverso da `http`/`https` — un client non deve difendersi da
  uno schema `javascript:` arrivato dall'API. Il tetto è **8 link per evento**
  (`App\DTOs\ExternalLinkList::MAX_LINKS`), l'etichetta al massimo 40
  caratteri; la regola che lo impone in scrittura è `App\Rules\ExternalLinks`,
  usata in entrambi i pannelli. Esempio:

  ```json
  "external_links": [
    {"label": "Evento Facebook", "url": "https://facebook.com/events/123"},
    {"label": "Rassegna stampa", "url": "https://giornale.example/pezzo"}
  ]
  ```

- Il payload `poster` porta `thumb`/`card` WebP dalle conversioni, `blurhash`,
  `width`, `height`; `full` punta all'originale quando l'originale è già la
  misura piena. Le conversioni le genera la coda: **serve un worker attivo**
  (`queue:work`), altrimenti il payload ripiega sull'originale con
  `blurhash: null`.

## Verifica del 2026-08-24 (misurata)

| Verifica | Esito |
|---|---|
| `GET /docs/api` · `/docs/api.json` | 200, JSON valido, 60 operazioni |
| config, events, venues, map, calendar, search, cities | tutti 200 |
| `GET /me` senza token | **401** |
| `If-None-Match` con l'ETag della prima risposta | **304** |
| Cursore: pagina 1 `3,4,5,29,28` → pagina 2 `27,32,34,33,17` | nessuna sovrapposizione |
| Cursore illeggibile (`?cursor=zzzz`) | **400** `VALIDATION_FAILED` |
| `limit=100` (oltre il massimo 50) | **400** `VALIDATION_FAILED` |
| 70 richieste rapide a `/events` | 60×200 + **10×429** con `X-RateLimit-*` e `Retry-After` |
| **Scenario G** `starting_soon`: sito `3,4,5` vs API `3,4,5` | **identici, stesso ordine** |
| **Scenario G** `ongoing`: sito `1,2` vs API `1,2` | **identici, stesso ordine** |

## Dipendenze operative esterne

1. `/docs/api` è protetto da `RestrictedDocsAccess`: visibile solo in ambiente
   `local`. Per esporlo in produzione va definito il gate `viewApiDocs`.
2. `API_PASSWORD_RESET_URL` e `API_LEGAL_*` non sono valorizzate: il link di
   reset punta a `/reimposta-password` del sito e `legal` in `/config` è `null`.
3. `media.cdn_url` è vuota: il meccanismo esiste ed è coperto da un test, il
   fornitore non è stato scelto.
4. Il backend FCM e la revoca automatica dei token Android non validi sono
   pronti. Per accendere la push nativa servono il progetto Firebase e le
   credenziali `FIREBASE_CREDENTIALS`; non sono segreti generabili dal codice.

### Aspetto del profilo

`GET /api/v1/me` e le risposte di autenticazione espongono `appearance`
(`dark`, `light` oppure `null` = segui il sistema, dal 19/11/2026). `PATCH
/api/v1/me` accetta lo stesso campo, `null` compreso, e modifica solo il
profilo autenticato. Il sito e l'app Android condividono la preferenza; un
client che conosce solo `dark` e `light` tratta `null` come il tema del
telefono.
I visitatori del sito partono dal tema di sistema, memorizzato per un anno nel
cookie necessario `incitta_appearance`, e possono cambiarlo da Aspetto.


### Tessera dell’evento e alternative geografiche

`GET /api/v1/events`, le relative mappe e facets accettano `membership=required|not_required`. Nel dettaglio `content_details.membership` riporta il requisito dell’evento oppure null. Non viene dedotto dal locale: null non equivale a “non richiesta”. Le facets geografiche consentono il cambio diretto di comune, quartiere e locale ignorando la selezione dello stesso campo e quelle dipendenti, mantenendo gli altri filtri.

`content_details.practical_items` restituisce le informazioni confermate di «Prima di andare» come elenco di `{label, icon, text}`. Include caratteristiche selezionate dal catalogo, requisiti strutturati e voci libere. Le icone sono identificatori della lista consentita; non contengono SVG o HTML. Android usa la stessa lista e icone native corrispondenti. Dettagli in [BEFORE-GOING.md](BEFORE-GOING.md).

### Allineamento profilo Android (12 settembre 2026)

`PATCH /v1/me/notification-preferences` accetta anche `marketing_opt_in` e `quiet_hours`, con le stesse regole del profilo: `null` ripristina gli orari predefiniti, `{}` disattiva il silenzio, `{from,to}` salva una finestra personalizzata. Il consenso conserva la data originale se già attivo. La risposta continua a distinguere scelta e `quiet_hours_effective`; il motore di invio è condiviso da web e Android.

Il dettaglio locale espone `logo` con lo stesso formato immagine di `cover`, e `is_nonprofit`. Le prenotazioni espongono `ends_at`, basato sulla fine effettiva, per distinguere eventi in corso e passati. `/v1/me` include `management_links` (label, URL, icona): sono collegamenti ai pannelli autorizzati, senza sostituire le policy delle destinazioni. Newsletter compare solo per superadmin.

Il codice Android offre sezioni profilo con ritorno, fuso selezionabile, immagini intere, filtri biglietti e accesso alle impostazioni notifiche di Android. I pannelli amministrativi e lo scanner ingresso si aprono nel browser. La verifica di questo aggiornamento compila il codice ed esegue i test senza generare nuovi APK.

### Informazioni per decidere e preferenze (24 settembre 2026)

- `GET /events?preset=last_hours`: date con inizio fra adesso e la finestra
  `starting_soon_minutes` della città, in ordine cronologico anche quando viene
  richiesto un altro ordinamento. Include le date esaurite dichiarandone lo stato;
  esclude annullate e rinviate. La risposta non viene conservata in cache.
- Le occorrenze espongono `availability: {remaining, total}|null` e
  `capacity_left` risolti dalle prenotazioni quando è attiva la biglietteria.
  Capienza sconosciuta resta `null`; un contatore manuale non la sostituisce.
- `declared_costs` contiene `items: [{label,cents}]`, `total_cents`, `complete`
  e `currency`, oppure `null` senza voci. Le quattro voci sono ingresso,
  consumazione obbligatoria, tessera e altri costi. Zero è un valore dichiarato,
  campo vuoto è un dato mancante. Con un totale parziale `price.is_partial=true`;
  `min` è soltanto il subtotale noto e `max=null`. I filtri di budget escludono
  totali parziali e confrontano quelli completi con il limite richiesto.
- Nel dettaglio evento, `occurrences[].content_details` risolve le informazioni
  pratiche in ordine locale → evento → data. `practical_items` comprende anche
  indicazioni esplicite sui dati non dichiarati. Le stringhe sono testo semplice.
- `POST /me/follows` accetta `notification_mode=all|new_only|none`. La risposta e
  la lettura del follow includono lo stesso campo. `notify` resta compatibile:
  senza modalità esplicita, `true` equivale a `all` e `false` a `none`.
  `new_only` riceve i primi annunci, ma non i riepiloghi periodici delle fonti
  seguite. I promemoria delle date salvate hanno preferenze indipendenti.
- `GET /me/feed` include `recommendation_reasons` per ciascuna data. Le fonti
  sono follow e categorie scelte esplicitamente; non vengono creati interessi
  dedotti né profili pubblicitari.
- `GET /me/calendar/export?saved_only=1&days=90` esporta solo le proprie date
  salvate e ancora attive, indipendentemente dalle categorie nascoste nella
  scoperta. `days` ammette 7, 30 e 90. Le annullate non sono nel nuovo snapshot:
  il client nativo elimina dal proprio calendario le righe non più presenti.
- Lo scanner web accetta un `request_key` UUID facoltativo: il ritentativo della
  stessa lettura dello stesso operatore restituisce l'esito già registrato;
  una lettura indipendente dello stesso biglietto resta respinta. I permessi
  dello staff sono limitati alle date assegnate e non concedono elenco completo,
  esportazione o configurazione della biglietteria.

Il JSON versionato è stato rigenerato il 19/11/2026: l'errore `GEN001` sul
controller della singola occorrenza non compare più e `scramble:analyze` passa
(lo verifica `OpenApiContractTest`).

## Gestione nativa Android 1.17

Tutte le rotte seguenti richiedono Bearer e restituiscono `Cache-Control: private, no-store`:

- `GET /management/dates?period=upcoming|past&page=1`: solo date gestite o assegnate allo staff; `meta.next_page` per paginazione.
- `GET /management/dates/{id}`: staff limitato a identità della data e permessi; dettagli, elenco staff e statistiche solo ai gestori.
- `POST /management/dates/{id}/staff`: `email`, `remove` opzionale; autorizzazione di gestione della singola data.
- `PATCH /management/dates/{id}/details`: `practical_details` e `cost_breakdown`; oggetti vuoti rimuovono le eccezioni, zero è distinto da assenza. Nessun campo estraneo ammesso.
- `GET /management/campaigns?page=1` e `GET /management/campaigns/{id}`: campagne autorizzate dei locali di cui si è referente e, dal 25 novembre 2026, delle organizzazioni gestite (vedi «Gestione: fondamenta dell'app»), con rapporti aggregati e soglie di riservatezza.
- `POST /ticketing/{id}/check-in`: `code` di 64 caratteri e `request_key` UUID persistente per recuperare una risposta persa. Una lettura in attesa non autorizza l’ingresso.

## Aggiunte del 23 ottobre 2026 (D60)

Tutte dietro `auth:sanctum` salvo dove indicato; stessi limitatori del sito.

- Biglietteria, per chi gestisce una data: `GET /v1/ticketing/{occurrence}/manifest` (impronte e nomi per il controllo senza rete), `POST /v1/ticketing/{occurrence}/message` (`subject`, `body`, `include_waitlisted`), `POST /v1/ticketing/{occurrence}/guest-booking` (`names` una per riga, `email`, `phone`, `note`). `POST /v1/ticketing/{occurrence}/check-in` accetta anche `scanned_at`. `GET /v1/me/bookings` riporta `meta.no_shows`.
- Ricerche salvate: `GET|POST /v1/me/searches` (`filters` come la query string di `/eventi`, `name` facoltativo), `DELETE /v1/me/searches/{id}`.
- Raccolte: `GET|POST /v1/me/collections`, `GET|PATCH|DELETE /v1/me/collections/{id}`, `POST /v1/me/collections/{id}/items` (`occurrence_id`, `note`), `DELETE /v1/me/collections/{id}/items/{occurrence}`; pubblica senza token `GET /v1/collections/{token}`, 404 se la raccolta è privata.
- Calendari pubblici senza token, `text/calendar`, cache di quindici minuti: `/locali/{slug}/calendario.ics`, `/organizzatori/{slug}/calendario.ics`, `/eventi/categoria/{slug}/calendario.ics`; programma settimanale `/locali/{slug}/programma.pdf?settimana=YYYY-MM-DD`.
- Le schede evento riportano i co-organizzatori (`organizers`), le foto (`gallery`) e il video (`video`, solo descrizione: `provider`, `embed_url`, `watch_url`). La scheda del locale (`GET /v1/venues/{slug}`, non le liste) riporta `notice`: `{kind, body, until}` dell'avviso in vista oggi, oppure `null`.
- L'app Android 1.19.0 usa tutte queste aggiunte: galleria, video e co-organizzatori nella scheda evento, raccolte con i link `/raccolte/{token}`, ricerche salvate, assenze nei biglietti, messaggio ai prenotati e prenotazione dal banco, controllo ingressi senza rete con il manifest e `scanned_at`, avviso e calendario ICS nella scheda del locale. Il dettaglio è in [MOBILE-APP-SPEC.md](MOBILE-APP-SPEC.md).

## Aggiunte del 23 ottobre 2026 (D61)

Forme complete in [PIANO-UTENTE-2026-10-23](PIANO-UTENTE-2026-10-23.md). In breve:

- `POST /v1/me/saved` riporta `conflicts` (date salvate o prenotate che si sovrappongono).
- `GET /v1/venues/{slug}` e `GET /v1/venues` riportano `open_now` e `open_until`; `GET /v1/venues?open_now=1` filtra.
- `GET|PATCH /v1/me/content-preferences`: `hidden_venues` e `access_needs` (facoltativi nel `PATCH`), più `hidden_venue_options`; `POST|DELETE /v1/me/hidden-venues/{venue}`.
- `GET /v1/events?access=a,b` filtra per esigenze di accessibilità.
- `BookingResource.tickets[].share_url`; `POST /v1/me/tickets/{ticket}/send` (`email`, `consent`).
- `GET /v1/me/availability-alerts`, `POST|DELETE /v1/me/availability-alerts/{occurrence}`.
- `GET /v1/me/attended?page=N`.
- `POST /v1/me/presence` (`occurrence_id`, `display_name`, `note`), `DELETE /v1/me/presence/{token}`.
- `POST /v1/ticketing/{occurrence}/check-in` con `scanned_at` vale anche dopo la chiusura della porta, entro ventiquattro ore, se l'istante della scansione cade nella serata.

## Aggiunte del 24 ottobre 2026 (D62)

- `GET /v1/venues/{slug}/reviews` e `GET /v1/organizers/{slug}/reviews`: ogni elemento di `reviews` porta `reply` (`{author, body, date}` oppure `null`), la risposta pubblica del locale o dell'organizzatore.
- `GET /v1/occurrences/{id}/booking` riporta `questions`: `[{id, label, type, options, required}]` con `type` fra `text`, `choice`, `yes_no`; lista vuota se il gestore non chiede nulla.
- `POST /v1/occurrences/{id}/bookings` accetta `answers`, un oggetto per `id` della domanda: testo fino a 500 caratteri, una delle `options`, oppure `true`/`false` (o `"1"`/`"0"`) per i sì e no. Le chiavi sconosciute si ignorano; le risposte obbligatorie mancanti o fuori elenco danno 422.
- `BookingResource` porta `answers`: `[{question, answer}]` con il testo della domanda com'era al momento della prenotazione; si svuota trenta giorni dopo la data.

## Aggiunte del 24 ottobre 2026 (D63)

- Le date (`OccurrenceResource`) portano `booking_opens_at`: l'apertura delle prenotazioni in ISO 8601, `null` se la biglietteria non è attiva o non c'è un'apertura fissata.
- `GET /v1/me/opening-alerts` → `{data: [occurrence_id, …]}`; `POST /v1/me/opening-alerts/{occurrence}` → 201 (422 se l'apertura non è davanti o la biglietteria non è attiva); `DELETE /v1/me/opening-alerts/{occurrence}` → 204. Notifica `bookings_open`.
- `GET /v1/venues/{slug}` e gli elementi di `GET /v1/venues`: `closure_now` (`{from, to, note}` oppure `null`) e `closures` (in corso e in arrivo); durante una chiusura `open_now` è `false` e `GET /v1/venues?open_now=1` esclude il locale.
- `GET /v1/events/{slug}` porta `edition_alert_offered`. `GET /v1/me/edition-alerts` → `{data: [event_id, …]}`; `POST /v1/me/edition-alerts/{event}` → 201 (422 se l'evento ha ancora date in programma); `DELETE /v1/me/edition-alerts/{event}` → 204. Notifica `next_edition`.

## Aggiunte del 19 novembre 2026 (pagine di servizio e app)

Solo aggiunte: nessun campo tolto o rinominato. Le rotte nuove hanno un limite di frequenza con il nome dell'azione, come le altre.

### Campi nuovi

- `GET /v1/me`: `first_name` e `last_name` (nullable), per precompilare chi prenota; se mancano si divide `name`. `appearance` può essere `null` (vedi «Aspetto del profilo»).
- `GET /v1/me/bookings` (`MobileBooking`): `poster`, nella stessa forma di `EventResource.poster`, oppure `null`.
- `GET /v1/management/dates` e `/management/dates/{id}`: `poster` e `counts` = `{confirmed, checked_in, waitlisted, cancelled, no_show, capacity}` (interi; `capacity` `null` senza tetto). Nell'elenco i numeri sono calcolati per tutte le date insieme.
- `VenueResource` (`GET /v1/venues`, `/v1/venues/{slug}`, `venue` della scheda evento e delle date con `include=venue`): `claimable` (locale pubblico senza referente, nemmeno invitato) e `claim_url` (la pagina web «Rivendica», `null` se non rivendicabile). L'app apre `claim_url` nel browser.
- Passaggi (`RideResource`, l'offerta): `poster`, nella stessa forma di `EventResource.poster`, oppure `null`.
- `GET /v1/events/{slug}`: `share_links`, un oggetto per canale (`native`, `whatsapp`, `telegram`, `email`) con `url`, il link breve `/s/{code}`. L'app lo mette nel messaggio di condivisione al posto di `url`: l'apertura si conta lato server anche senza consenso, come contatore anonimo per link e giorno (decisione del 19/11/2026), e l'app non deve mandare altro. I codici nascono alla prima richiesta e poi restano gli stessi.

### `GET /v1/management/dates/{id}/bookings`

Le prenotazioni di una data, per chi la **gestisce** (stessa autorizzazione della pagina `/gestione-biglietti/{id}`): 401 senza token, 403 a chi non gestisce la data, anche al personale che fa solo il controllo ingressi (per loro c'è `manifest`). `Cache-Control: private, no-store`. Limite: 60 al minuto (`management-bookings`).

Parametri: `status` fra `confirmed`, `checked_in` (prenotazioni con almeno un ingresso registrato), `waitlisted`, `cancelled` (altro valore → 422); `q` (fino a 120 caratteri) cerca nel nome dei partecipanti e nell'email dell'account, i caratteri `%` e `_` valgono per quello che sono; `page` (30 per pagina, le più recenti prima).

```json
{
  "data": [{
    "id": 41, "status": "confirmed", "created_at": "2026-11-19T10:12:00+00:00",
    "staff_booking": false, "email": "ada@example.test",
    "booker": {"phone": "…"}, "answers": [{"question": "…", "answer": "…"}],
    "privacy_accepted_at": "2026-11-19T10:12:00+00:00", "tickets_count": 1,
    "tickets": [{"id": 90, "attendee_name": "Ada Lovelace", "first_name": "Ada", "last_name": "Lovelace",
                 "status": "valid", "checked_in_at": null, "code": "…64 caratteri…"}]
  }],
  "meta": {"next_page": null, "status": null,
           "counts": {"all": 3, "confirmed": 2, "checked_in": 1, "waitlisted": 0, "cancelled": 1}}
}
```

`tickets[].code` c'è solo finché il biglietto è valido: serve a registrare l'ingresso dalla riga con `POST /v1/ticketing/{id}/check-in`, come il pulsante della pagina web; per gli altri stati è `null`. `meta.counts` non dipende dal filtro: sono i numeri da mostrare accanto a ciascun filtro.

### `POST /v1/reports/content/{type}/{id}`

Le misure di una scheda aperta dall'app: `type` fra `event`, `venue`, `organizer`; corpo `{metric, occurrence?, consent?}` con `metric` fra `views`, `direction_clicks`, `ticket_clicks`, `shares`, `website_clicks`, `phone_clicks`, `email_clicks`, `calendar_clicks`, `poster_clicks`, `booking_clicks`. Risponde sempre **204** senza corpo.

- Stessa regola del sito (decisione del 19/11/2026): si conta solo con il consenso alle statistiche. L'app lo raccoglie e manda `consent: true`; senza, la risposta è la stessa e non si scrive nulla.
- Nessun identificativo: niente `X-Installation-ID`, niente utente; nei contatori aggregati per giorno (`event_views_daily`, `profile_views_daily`, `occurrence_views_daily` con `occurrence`) finisce solo un +1.
- 404 per una scheda che non esiste o non è pubblica, o per una `occurrence` che non è dell'evento (anche senza consenso). 422 per una misura sconosciuta.
- Limiti: 60 al minuto per indirizzo, in comune con `/misure/{type}/{id}` del sito (`content-metrics`); oltre trenta misure uguali l'ora per la stessa scheda dallo stesso indirizzo si risponde 204 senza contare. L'indirizzo resta in memoria per il limite e non si salva.
- Si spegne senza un rilascio dell'app con `APP_CONTENT_METRICS=false` (`eventi.analytics.app_content_metrics`): allora la rotta risponde 404 e l'app deve ignorarlo.

### `GET /v1/me/venue-applications`

«Le mie richieste» (registrare o rivendicare un locale, registrare un'organizzazione) dell'account collegato, comprese quelle che aspettano la conferma dell'email; mai quelle degli altri, mai le note della redazione o i recapiti del referente. 20 per pagina con `page`, `meta.next_page`; `meta.manage_url` (la pagina web `/le-mie-richieste`), `meta.new_venue_url`, `meta.new_organizer_url`. `Cache-Control: private, no-store`. Limite: 60 al minuto, in comune con la pagina web (`account-applications`).

Ogni elemento: `id`, `kind` (`venue|claim|organizer`) e `kind_label`, `name`, `type`/`type_label`/`address` (`null` per le organizzazioni), `state` (`awaiting|pending|approved|rejected`) e `state_label`, `next` (il testo «cosa succede adesso» della pagina web), `created_at`, `reviewed_at` (solo se valutata), `can_confirm` (richiesta scritta senza accedere con l'indirizzo di questo account: si conferma o si elimina dalla pagina web), `panel_url` (il pannello, solo se l'accesso c'è davvero), `public_url`, `venue_slug`, `organizer_slug` (solo se pubblici). Le azioni restano pagine del sito: l'app apre `manage_url` o `panel_url` nel browser.

### `GET /v1/calendar?month=YYYY-MM&personal=1`

Con un token e `personal=1` il mese segue gli interessi della persona, come il calendario della pagina iniziale del sito: locali, organizzatori, categorie e tag seguiti e categorie scelte, meno categorie e locali nascosti. Chi non ha espresso interessi riceve il mese della città meno ciò che ha nascosto. `meta.personal` dice se il calendario è personale, `meta.interests` se la persona ha interessi espressi (falso → il client può spiegare che sta mostrando il calendario della città). Senza token `personal=1` non cambia nulla. Con un token la risposta è `private, no-store` e il mese personale non entra in nessuna cache del server. `personal` non booleano → 422.

## Sponsorizzazioni: gettone usa e getta e avanzamento (D70, 20 novembre 2026)

Parte del lavoro sulle sponsorizzazioni a visualizzazioni (D70 in `docs/DECISIONS.md`). Solo campi aggiunti: nessun campo tolto, rinominato o di un altro tipo. Lo verifica `MobileHomeAndSponsorshipTest` confrontando le risposte con la fotografia presa il 19/11/2026 prima delle modifiche (`tests/Fixtures/api/sponsorship-responses-2026-11-19.json`).

### Campi nuovi

- `impression_token` (stringa) in ogni elemento di `GET /v1/sponsorships`, in `data.sponsorships.hero` e `data.sponsorships.card` di `GET /v1/home` e in `data` di `GET /v1/sponsorships/banner` (e della sua gemella web `/banner-sponsorizzato`). È un gettone firmato dal server, **nuovo a ogni risposta**: porta la riga della campagna, la collocazione in cui la si mostra (`banner` per il banner, altrimenti quella della campagna), il minuto di emissione e un numero casuale. Vale sei ore (`sponsorship.token_ttl_hours`). Il numero casuale appartiene alla risposta, non alla persona: il client non lo salva e non lo riusa per un'altra visualizzazione.
- `metric_token` resta com'era (il gettone giornaliero): le app già installate continuano a mandarlo e a contare.

### Come si usa nelle misure

`POST /v1/reports/sponsorships/{id}/metrics/{impressions|clicks}` accetta il gettone nuovo nell'intestazione `X-Impression-Token`, accanto a `X-Metric-Token` di sempre. Con `X-Impression-Token` la risposta è **sempre 204**, anche quando la misura non conta (gettone scaduto, falso o di un'altra campagna, campagna in pausa, programma automatico, redazione o committente collegati): così chi prova a gonfiare non sa quando riprovare. Lo stesso gettone conta una visualizzazione sola e un clic solo; un clic che arriva prima della sua visualizzazione le registra tutte e due. Se arrivano tutti e due i gettoni vale quello nuovo.

Con il solo `X-Metric-Token` la rotta si comporta come prima, errori compresi (`400 INVALID_TOKEN` per un gettone sbagliato, deduplica di 15 minuti per installazione, utente o indirizzo): le app già installate continuano a contare. Anche su questa strada non contano più i programmi automatici, la redazione e i membri del committente collegati. Anche qui l'obiettivo di visualizzazioni è un massimo: la visualizzazione di una campagna che l'ha già raggiunto non conta, con il controllo sotto il lucchetto della riga. **Questa strada è da chiudere**: la deduplica dipende da `X-Installation-ID`, che sceglie chi chiama. Dal giorno dopo la data in `sponsorship.legacy_metric_token_until` (variabile `SPONSORSHIP_LEGACY_METRIC_TOKEN_UNTIL`, formato `AAAA-MM-GG`, ora di Roma) la misura con il solo `X-Metric-Token` risponde 204 e non conta. Vuota, come oggi, la strada resta aperta: la data va fissata quando quasi tutte le installazioni avranno la 1.23.0, che manda il gettone nuovo.

La rotta web `POST /sponsorizzazioni/{id}/{impressions|clicks}` usata dalle card del sito vuole lo stesso gettone (intestazione `X-Impression-Token`, che la pagina prende da `data-sponsorship-token`); senza risponde 204 e non conta. Anche il banner del sito, quando riceve `impression_token`, manda le misure a questa rotta, con la sessione e il token CSRF: è la sola che sa chi è collegato, e quindi non conta la redazione e il committente che guardano il proprio banner. La rotta dell'API resta per le app e per il gettone giornaliero.

Un clic con un gettone emesso da non più di una casella della cache di pagina (`page_cache.ttl_minutes`) conta anche se nel frattempo la campagna ha raggiunto l'obiettivo: la card era ancora a schermo. La visualizzazione che il clic si porterebbe dietro, invece, no. Una campagna in pausa non conta nemmeno i clic.

Il segnale dell'occasione non venduta è `POST /sponsorizzazioni/occasione` con `token`, il gettone dell'occasione che lo spazio vuoto porta in `data-sponsorship-opportunity-token` (forma `o1.{città}.{collocazione}.{minuto}.{numero}.{firma}`, sei ore di vita, firmato come quello delle visualizzazioni ma con un dominio suo). Città e collocazione vengono dal gettone; `city` e `placement` nel corpo restano per compatibilità e non decidono niente. Ogni gettone conta una volta sola, con al massimo 12 segnali all'ora per indirizzo, città e collocazione (`sponsorship.opportunity_per_hour`). Aggiunge una visita allo spazio, niente sulla campagna; ma da quelle visite nasce la previsione che decide lo spazio delle campagne a visualizzazioni, per questo non si accetta più senza gettone. Anche il foglio della mappa senza sponsorizzazione porta il suo segno.

Una visualizzazione si manda quando metà dell'elemento resta sullo schermo per un secondo di fila (`sponsorship.viewable_ms`), con l'app o la scheda in primo piano. Il banner web lo dice anche nella pagina: `data-placement="banner"` e `data-viewable-ms` sull'elemento `[data-live-sponsorship]`.

Le risposte di `GET /v1/home` e `GET /v1/sponsorships` che portano almeno un `impression_token` hanno `Cache-Control: private, no-store` e nessun `ETag` (`CacheJsonResponse::SINGLE_USE`): una cache condivisa che le servisse a più client darebbe a tutti lo stesso gettone, che conterebbe una volta sola, e l'`ETag` cambierebbe comunque a ogni risposta. Senza sponsorizzazioni restano pubbliche per un minuto, con `ETag` e 304 come prima. L'app Android non usa una cache HTTP, quindi per lei non cambia niente.

### Avanzamento nelle risposte di gestione

`GET /v1/management/campaigns` (ogni voce) e `GET /v1/management/campaigns/{id}` portano i numeri dell'**unità di consegna** della campagna (l'abilitazione se la riga ne fa parte, altrimenti la riga stessa):

- `goal`: visualizzazioni comprate, `null` per il prodotto a periodo;
- `delivered`: visualizzazioni consegnate dall'unità (per un'abilitazione la somma delle sue righe, comprese quelle nel cestino);
- `pacing_state`: `on_track`, `slightly_behind`, `behind`, `ahead`, `at_risk`, `completed`, `missed`; `null` senza obiettivo.

Il calcolo è lo stesso dei pannelli e dei rapporti (`App\Services\Sponsorship\SponsorshipProgress`, tramite `SponsorshipProgressResource`): app e pannello non possono dire due stati diversi della stessa campagna. Una campagna la cui ultima data è passata mentre il periodo continua è «ferma» e si giudica sul periodo, perché all'evento si possono aggiungere date; finché le visualizzazioni attese sono meno dell'1% dell'obiettivo non risulta in ritardo. Lo verifica `MobileManagementTest`. L'app 1.23.0 li legge e, con un server che non li manda, mostra la scheda di prima.

### App Android 1.23.0 (versionCode 50)

Manda `X-Impression-Token` con visualizzazioni e clic del banner quando il server l'ha dato, conta la visualizzazione solo dopo un secondo con metà banner sullo schermo e l'app in primo piano, ed evita di rimandare la stessa visualizzazione una volta per gettone (con un server senza gettone, una volta per campagna come prima). Nella gestione mostra barra, «1.240 di 3.000 visualizzazioni» e stato del ritmo quando la risposta li porta.

## Gestione: fondamenta dell'app (25 novembre 2026)

Le basi dell'area di gestione nativa: chi gestisce che cosa, gli elenchi dei moduli, il caricamento delle immagini. Le forme delle risposte sono anche in `docs/openapi.json`. Tutte le rotte chiedono il token e rispondono `Cache-Control: private, no-store`.

### Soggetto di gestione

Le rotte di un locale stanno sotto `/v1/management/venues/{venue}/…`, quelle di un'organizzazione sotto `/v1/management/organizers/{organizer}/…`, con l'**id numerico**. Le protegge `ResolveManagementSubject`: chiede `User::canAccessTenant`, la stessa barriera dei pannelli (appartenenza accettata, locale senza provvedimento, organizzazione attiva), e risponde **404** se dice di no, senza rivelare se il soggetto esiste. È ciò che impedisce al membro di un locale sospeso di scrivere dall'app: le policy degli eventi guardano l'appartenenza ma non lo stato del locale. Dentro il soggetto ogni azione passa ancora dalla sua policy (403 se dice di no), e ogni modello annidato si cerca solo fra quelli del soggetto (`App\Services\Management\ManagedContent`): gli eventi del locale per `venue_id`, quelli dell'organizzazione per `organizer_id` o co-organizzazione, come gli elenchi dei pannelli. Un test di architettura verifica che ogni rotta sotto i due prefissi abbia la porta e che i controller di `App\Http\Controllers\Api\V1\Management` non usino Filament. Le rotte stanno in `routes/management/<area>.php`, una per pacchetto.

Gli stessi servizi di statistiche e sponsorizzazioni dei pannelli (`ManagementAnalytics`, `EventAnalyticsDashboard`, `SponsorshipReport`, `EventShares::channels`) leggono il soggetto da `App\Support\Management\ManagementSubject`, che nei pannelli è il tenant di Filament e nell'API quello legato dalla porta: nei pannelli non cambia niente.

### Rotte

- `GET /v1/management/subjects`: `data.venues[]` `{id, slug, name, city{name, timezone}, role (owner|editor), status, logo, ticketing_enabled, auto_publish, permissions}`, `data.organizers[]` `{id, slug, name, city|null, role (owner|member), permissions}`, `data.checkin_only`. Gli insiemi sono quelli dello switcher dei pannelli. `permissions` ha sempre quattordici chiavi booleane, ognuna calcolata dalla policy che il pannello usa per la stessa pagina: `events_create`, `events_publish_directly`, `ticketing_manage`, `profile_update`, `collaborators_manage`, `import_manage`, `analytics_view`, `analytics_channels`, `sponsorships_view`, `sponsorships_purchase`, `social_use`, `comments_moderate`, `reviews_reply`, `leave`.
- `GET /v1/management/options`: tipi di prezzo, stati della data (insieme del locale e insieme dell'organizzazione), frequenze, ruoli del lineup, accessibilità, mezzi, fasce d'età, tessera, icone e caratteristiche di «Prima di andare», tipi di domanda delle prenotazioni, formati social, collocazioni delle sponsorizzazioni, `limits` (link esterni 8, voci 12, chiusure 20, avvisi ogni 7 giorni, messaggi ai prenotati 3 al giorno, galleria 30, …) e `images` (peso, tipi ed estensioni, misure minime, superficie massima) da `config/media.php`.
- `POST` e `DELETE /v1/management/{venues|organizers}/{id}/events/{event}/poster`: locandina, campo multipart `image`. Permesso `EventPolicy::update`. Risposta `{poster, status, sent_to_review}`. Una locandina nuova su un evento pubblicato lo rimanda in revisione quando il locale non pubblica da sé, e sempre per un'organizzazione: è la regola delle pagine «Modifica evento» dei pannelli, ora in `App\Services\Events\EventReviewRule` e usata da tutti e tre. Togliere la locandina non rimanda in revisione.
- Solo locale, permesso `VenuePolicy::update` (referente): `GET /media` (`logo`, `cover`, `gallery[]` con `id`), `POST` e `DELETE /media/{logo|cover}`, `POST /media/gallery` (201), `DELETE /media/gallery/{media}`, `PUT /media/gallery/order` con `ids[]` (tutte e sole le foto).

Le immagini vanno nelle raccolte media dei pannelli: `poster`, `logo` e `cover` tengono un file solo, quindi il caricamento sostituisce e il file vecchio sparisce dal disco; riduzione dell'originale, pulizia EXIF e varianti partono da sole. Validazione come nei pannelli: `App\Rules\RealImage` (tipo letto dai byte, estensione coerente, misure minime, superficie massima) e peso massimo `config('media.max_upload_bytes')` (12 MB). La galleria ha al massimo 30 foto, la trentunesima riceve 422. Le scritture di immagini hanno un limite di 20 al minuto per persona (`management-upload`).

### Campi nuovi

- `GET /v1/me`: `management{venues, organizers, checkin}`, tre conteggi (locali senza provvedimento, organizzazioni attive, date non finite come personale d'ingresso). In `management_links` la voce «Gestione biglietti» non compare più a chi è referente solo di locali sospesi o rifiutati.
- `GET /v1/config`: `features.management` (`true`), l'interruttore con cui l'app accende l'area nuova.

### Comportamenti fissati

- `PATCH /v1/management/dates/{id}/details` resta una **sostituzione**: `practical_details` e `cost_breakdown` sono obbligatori (anche vuoti) e ogni chiave non mandata torna a «eredita». Lo fissa `MobileManagementTest`.
- `GET /v1/management/campaigns` e `/{id}` includono le campagne delle organizzazioni gestite, proprietario e membri, con la regola di `SponsorshipReport`: eventi firmati dall'organizzazione, campagne della redazione o pagate con una sua abilitazione. Prima l'elenco guardava solo i locali.


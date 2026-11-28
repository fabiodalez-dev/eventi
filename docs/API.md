# API v1 — stato reale

Aggiornato con il ticketing del 2026-09-05. Il contratto è verificato con
test di integrazione e consumato dall'app Android in `android/` contro dati reali.

## Coordinate

| Voce | Valore |
|---|---|
| Base | `/api/v1` |
| Documentazione | `/docs/api` (Scramble) · JSON versionato in `docs/openapi.json` (rigenerato il 26/11/2026 con `php -d memory_limit=2G artisan scramble:export --path=docs/openapi.json`) — OpenAPI **3.1.2**, **627 operazioni** |
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


## Gestione: commenti e recensioni (P7)

Le pagine «Commenti agli eventi» e «Recensioni ricevute» dei pannelli, per l'app. Stesse rotte sotto `/v1/management/venues/{venue}/…` e `/v1/management/organizers/{organizer}/…`, con la porta `ResolveManagementSubject` e le restrizioni di `ManagedContent::comments()` (gli eventi del locale per `venue_id`; dell'organizzazione per il solo `organizer_id`, perché un co-organizzatore non modera i commenti altrui) e `ManagedContent::reviews()` (solo le recensioni pubblicate del soggetto). Il collaboratore può tutto quello che può il referente, come nel pannello. Qui non si cancella un commento e non si approva una recensione: restano della redazione.

### Commenti

- `GET /comments?status=&q=&event=&page=`: i commenti agli eventi del soggetto, i più recenti prima, 20 per pagina. `status` è `published` o `hidden` (vuoto = tutti), `q` cerca nel titolo dell'evento, nel nome dell'autore e nel testo (al massimo 100 caratteri), `event` restringe a un evento. Ogni voce: `id`, `event{id, title, slug}`, `author` (il nome dell'account, come nel pannello), `body`, `is_reply`, `reactions_count`, `status`, `status_label`, `created_at`, `moderated_by`, `moderated_at`, `revision`, `can_hide`, `can_restore`. `meta{next_page, total}`. Permesso: `EventCommentPolicy::viewAny`.
- `POST /comments/{comment}/hide` con `revision` (obbligatoria) e `note` facoltativa (al massimo 1000 caratteri, resta nella gestione). Permesso `EventCommentPolicy::hide`; solo su un commento pubblicato.
- `POST /comments/{comment}/restore` con `revision`. Permesso `EventCommentPolicy::restore`: un commento nascosto dalla redazione riceve 403. Solo su un commento nascosto.

Le due azioni passano da `App\Actions\Comments\ModerateComment`, la stessa del pannello. Una revisione vecchia, o un'azione su un commento che ha già cambiato stato, riceve 422 con il messaggio in `error.fields.revision`: l'app rilegge l'elenco. La risposta è la voce aggiornata in `data` e il messaggio in `meta.message`. Un commento di un evento di altri riceve 404.

### Recensioni

- `GET /reviews?page=`: le recensioni pubblicate del soggetto, le più recenti prima, 20 per pagina. Ogni voce: `id`, `author` (il nome pubblico, come nel pannello), `rating` (1–5 o `null`), `body`, `created_at`, `published_at`, `reply{body, author, replied_at}` o `null`, `can_reply`, `can_remove_reply`, `can_report`. `meta{next_page, total, can_report}`.
- `PUT /reviews/{review}/reply` con `reply` (da 2 a 2000 caratteri): scrive o cambia la risposta pubblica. Permesso `CatalogReviewPolicy::reply`. Passa da `CatalogReviews::reply`: solo la prima risposta avvisa chi ha scritto la recensione.
- `DELETE /reviews/{review}/reply`: toglie la risposta. Permesso `CatalogReviewPolicy::removeReply`. Senza risposta non fa niente.
- `POST /reviews/{review}/report` con `body` (da 10 a 3000 caratteri): segnala la recensione alla redazione, come il pulsante «Segnala» della scheda pubblica e con la stessa condizione (poter partecipare alla community, altrimenti 403). Risposta `{case_id, message}`; una seconda segnalazione aperta della stessa persona restituisce la stessa pratica.

Una recensione non pubblicata o di un altro soggetto riceve 404.

### Limiti

Nascondere, rimettere in vista e rispondere hanno un secchio di 60 al minuto per persona (`management-community`); i pannelli non ne hanno, serve a fermare un client che impazzisce. La segnalazione usa il secchio della scheda pubblica (`catalog-review-report`, 10 all'ora): dal sito e dall'app si consuma lo stesso.

### Campi nuovi nelle schede pubbliche

- `GET /v1/events/{slug}/comments`: `manage_url`, l'indirizzo della pagina «Commenti agli eventi» del pannello con `?event={id}`, solo a chi gestisce il locale dell'evento o l'organizzazione che lo firma; `null` per tutti gli altri.
- `GET /v1/venues/{slug}/reviews` e `/v1/organizers/{slug}/reviews`: `manage_url`, l'indirizzo della pagina «Recensioni ricevute» del pannello, solo a chi gestisce la scheda (`User::canAccessTenant`); `null` per tutti gli altri.

L'app traduce questi indirizzi nelle schermate native (`ManagementDestination`); una versione che non li conosce li apre nel browser.

## Gestione: collaboratori, accesso e inviti (P8, 25 novembre 2026)

Le pagine «Collaboratori» e «Il tuo accesso» del pannello del locale, e la risposta agli inviti, nell'app. Le regole sono una sola per pannello e API: l'elenco, il tetto degli inviti, il reinvio, il ritiro e la rimozione stanno in `App\Services\Management\VenueCollaborators` e `App\Actions\RemoveVenueMember`, la risposta all'invito al personale d'ingresso in `App\Actions\Account\RespondToCheckinStaffInvitation` (usata anche dalla pagina del collegamento). L'organizzazione non ha collaboratori né inviti (la pivot `organizer_user` non ha ruoli e il pannello `/organizza` non ha una pagina per i membri): sotto `organizers/{organizer}` queste rotte non esistono e rispondono 404.

### Collaboratori del locale (permesso `VenuePolicy::manageCollaborators`, il referente)

- `GET /v1/management/venues/{venue}/members?q=&page=`: `data[]` `{id, name, email, role (owner|editor), role_label, pending, accepted_at, invited_at, is_me, can_resend, can_withdraw, can_remove}`, prima chi ha accettato per nome, poi gli inviti per email; 50 per pagina, `meta{next_page, invite_expires_days, invites_per_hour, invites_per_day}`. Un invito in attesa ha `name` nullo ed `email` mascherata (`m****@esempio.it`); `q` cerca il nome solo fra chi ha accettato, l'email fra tutti. Il collaboratore riceve 403.
- `POST …/members` con `name` ed `email` (obbligatori, max 255): 201 con la riga e `message`. Il ruolo è sempre collaboratore. 422 su `error.fields.email` se la persona è già del locale o ha già un invito in attesa.
- `POST …/members/{member}/resend`: un collegamento nuovo, valido altri sette giorni; quello vecchio smette di valere.
- `DELETE …/members/{member}/invitation`: ritira un invito in attesa.
- `DELETE …/members/{member}`: toglie un collaboratore che ha accettato (riga `accesso_locale_revocato` nel registro di sicurezza); l'account e i suoi eventi restano.

Reinvio e ritiro valgono solo per un invito da collaboratore in attesa, la rimozione solo per un collaboratore che ha accettato: altrimenti 403. `{member}` fuori dal locale: 404. Invito e reinvio contano nello **stesso tetto della pagina del sito**, 10 l'ora e 30 al giorno per persona e locale, con le stesse chiavi del rate limiter (`venue-invite:{utente}:{locale}` e `…:daily`): oltre, 429 `RATE_LIMITED` con il motivo anche in `error.fields.invite`. Le scritture hanno in più un limite di rotta di 30 al minuto (`management-members`).

### Il tuo accesso (ogni persona del locale)

- `GET /v1/management/venues/{venue}/access`: `{venue{id, name}, role, role_label, member_since, is_last_owner, can_leave, can_manage_collaborators}`.
- `POST …/access/leave`: esce dal locale (`LeaveVenueAction`), `{message, remaining}` con i locali e le organizzazioni che restano. L'ultimo referente riceve 409 `CONFLICT` e lo chiede alla redazione. Limite 10 al minuto (`management-leave`).

### Inviti ricevuti

Sul sito un invito si accetta dal collegamento firmato dell'email, aperto con l'account invitato. Nell'app il collegamento non c'è: le rotte chiedono il token **e** l'indirizzo confermato (`RequireConfirmedAccount`, 403 `EMAIL_VERIFICATION_REQUIRED` altrimenti), così risponde chi ha dimostrato la casella. Un invito di un altro account, scaduto (sette giorni dall'invito o dal reinvio), reinviato nel frattempo o su una data finita risponde 404.

- `GET /v1/me/invitations`: solo inviti di locali senza provvedimento (gli stati di `canAccessTenant`); quello di un locale sospeso non compare, non si conta in `/me` e `POST /v1/me/invitations/venues/{venue}` risponde 404 finché il provvedimento resta (anche il collegamento della mail non lo accetta). `venues[]` `{venue{id, slug, name, city}, role, role_label, sent, invited_at, expires_at}` e `checkin[]` `{id, occurrence{id, title, starts_at, venue}, sent, invited_at, expires_at}`.
- `POST /v1/me/invitations/venues/{venue}` con `decision` (`accept`|`decline`) e `sent` (quello dell'elenco): accettando si entra nel locale con il ruolo offerto (`subject{type, id}`); il registro di sicurezza segna `tramite: app`.
- `POST /v1/me/invitations/checkin/{invitation}` con `decision` e `sent`: accettando si entra nel personale d'ingresso della data (`occurrence_id`).

Le due risposte consumano il secchio `venue-invitations` delle pagine del sito (30 al minuto). L'accettazione scegliendo la password (`AcceptVenueInvitationsOnPasswordReset`) resta quella del sito.

### Campo nuovo

- `GET /v1/me`: `management.invitations`, gli inviti in un locale e al personale d'ingresso ancora senza risposta. Non accende «Gestione eventi»: nell'app ha la sua voce nel profilo.

## Gestione: biglietteria completa (P3, 25 novembre 2026)

Tutto quello che la pagina `/gestione-biglietti/{data}` del sito fa e l'app non faceva. Le rotte restano sulla data, accanto a quelle di prima (`routes/management/ticketing-dates.php`): il permesso è `BookingPolicy::manage` (referente di un locale attivo, proprietario o membro dell'organizzazione che firma l'evento, admin). Il collaboratore del locale e il personale d'ingresso ricevono 403, come sul sito (decisione 6). Token obbligatorio, `Cache-Control: private, no-store`.

- `PATCH /v1/management/dates/{id}/settings`: impostazioni delle prenotazioni e domande, con le regole del modulo del sito (`ManageRequest::settingsRules()`): `booking_enabled` e `booking_waitlist` booleani obbligatori, `booking_limit` 1–20 obbligatorio, `booking_capacity` 1–1.000.000 o `null` (nessun limite), `booking_opens_at`, `booking_closes_at` (dopo l'apertura), `cancellation_closes_at` come ora della città (`2026-12-05 21:00`) o istante con scarto (`2026-12-05T21:00:00+01:00`), `null` = subito / inizio evento; `booking_instructions` max 3000; `booking_fields` `{phone, address, city, postal_code, country}` → `hidden|optional|required`; `booking_questions[]` `{id?, label, type (text|choice|yes_no), options (una per riga), required}`, al massimo 5, una scelta con almeno due opzioni. È una sostituzione come il modulo: le tre ore che mancano tornano vuote; `booking_instructions`, `booking_fields` e `booking_questions` che mancano restano come sono. 422 su `error.fields.ticketing` se il locale non è abilitato o se i posti scendono sotto quelli già presi. Risponde con la scheda della data aggiornata. Il salvataggio passa da `App\Actions\Ticketing\ConfigureBookings`, lo stesso del sito e di «Configura ticketing» nel pannello. Limite `ticketing-settings`, 30 al minuto.
- `POST /v1/management/dates/{id}/bookings/{booking}/cancel`: annulla a nome del locale una prenotazione intera o un biglietto (`ticket_id`), con `reason` facoltativo (max 500) che arriva a chi ha prenotato. Vale anche oltre il termine di annullamento e per le prenotazioni prese al banco, come sul sito. Il permesso sulla data si chiede prima di cercare la prenotazione: chi non gestisce la data riceve sempre 403, qualunque `booking` mandi; per chi la gestisce la prenotazione si cerca solo fra quelle della data (404). Risponde con la riga della prenotazione (`ManagedBookingResource`, la stessa forma di `GET …/bookings`). Limite `ticketing-cancel`, 60 al minuto.
- `GET /v1/management/dates/{id}/messages`: gli ultimi dieci messaggi scritti a chi ha prenotato, i più recenti prima: `data[]` `{id, subject, body, include_waitlisted, recipients_count, sender (nome), created_at}`, `meta{daily_limit, sent_last_24h}`.
- `GET /v1/management/dates/{id}/export.csv` e `GET /v1/management/dates/{id}/list.pdf`: il CSV dei partecipanti e la lista d'ingresso da stampare, gli stessi del sito (`TicketingController@export` e `@printList`), con lo stesso registro attività e gli stessi limiti (`tickets-export`, `tickets-print`, 10 al minuto). L'app li scarica nella sua cache e li apre con «Apri con» o «Condividi».
- `GET /v1/management/venues/{venue}/events/{event}/ticketing` (solo locale): il riquadro «Ticketing inCittà» della modifica evento: `{event_id, title, ticketing_enabled, timezone, dates_count, dates[]{id, starts_at, venue, booking_enabled, ticketing_enabled, ended, counts}}`. Permesso `EventPolicy::update`; `dates` contiene solo le date che la persona gestisce, quindi il collaboratore vede il riquadro senza date, come nel pannello.

Campi aggiunti alle rotte di prima:

- `GET /v1/management/dates?q=` cerca anche nel nome del locale della data (o dell'evento), come il riepilogo del sito; i caratteri jolly si cercano per quello che sono. Ogni riga dell'elenco e della scheda porta `booking_enabled`, `ticketing_enabled` ed `ended`.
- `GET /v1/management/dates/{id}`, solo a chi gestisce: `timezone`, `address` e `settings` `{booking_enabled, booking_capacity, booking_limit, booking_waitlist, booking_opens_at, booking_closes_at, cancellation_closes_at (salvati, null = predefinito), booking_instructions (testo semplice), booking_instructions_rich, booking_fields (tutti e cinque), booking_questions (con options come elenco), limits{booking_limit_max, questions, question_options, question_label_max, instructions_max}}`. Con `booking_instructions_rich` vero le istruzioni sono state scritte sul sito con la formattazione: l'app le manda solo se le cambia.
- `GET /v1/management/dates/{id}/bookings`: ogni riga porta anche `cancellation_reason`.

## Gestione: profilo del locale e dell'organizzazione (P4)

La scheda pubblica del locale e il profilo dell'organizzazione, come le pagine «Il tuo locale» e «Profilo» dei pannelli. Rotte in `routes/management/profile.php`, controller `VenueProfileController`, `OrganizerProfileController`, `ContactConfirmationController`. Il salvataggio passa dalle stesse azioni delle pagine (`App\Actions\UpdateVenueProfile`, `App\Actions\UpdateOrganizerProfile`); l'avviso da `App\Actions\PublishVenueNotice`; la conferma del recapito da `App\Services\Contact\ContactEmailConfirmation`, con gli stessi tetti giornalieri del pulsante dei pannelli. Logo, copertina e galleria restano su `V/media` (fondamenta).

### Permessi

- Locale: `VenuePolicy::update`, cioè il solo referente. Il collaboratore riceve 403 e `permissions.profile_update` falso.
- Organizzazione: `OrganizerPolicy::updateProfile` (nuova), cioè solo chi l'ha creata (`owner_id`) finché è attiva. La usa anche la pagina «Profilo» del pannello, che prima confrontava `owner_id` a mano; `OrganizerPolicy::update` resta della redazione. Il membro riceve 403.

### Rotte

- `GET /v1/management/venues/{venue}/profile`: tutti i campi della scheda nella forma del modulo: `short_description`, `description` (HTML), indirizzo (`address`, `address_extra`, `postal_code`, `municipality`, `zone`, `lat`, `lng`), `opening_hours[]` come righe `{day: mon…sun, open, close}` (`HH:mm`), `closures[]` `{from, to, note}` (solo quelle in corso o in arrivo), `capacity`, `phone`, `email`, `website`, `socials[]` come coppie `{name, url}` nell'ordine salvato, `transit[]` `{mode, text}`, `accessibility` (solo le voci dichiarate: `true`/`false`; una voce assente è «non dichiarato»), `info[]` `{label, value}`, `requires_membership`, `membership_notes`, `content_details` (le chiavi di «Prima di andare» e delle informazioni editoriali, sempre presenti, `null` se vuote), `seo{title, description, image}`. In più: `name`, `type{value, label}`, `status`, `city{name, timezone}` in sola lettura; `contact{mode, email, confirmed, pending, can_resend, link_days, note, modes[]}`; `notices{min_days_between, latest, next_available_at, followers}`; `public_url`, `calendar_url` (ICS da sottoscrivere), `widget{url, code}` (il riquadro incorporabile); `choices{weekdays, municipalities, districts, district_municipality, parking_types, notice_kinds}`; `limits` (righe di orario 28, chiusure 20, social 12, come arrivare 6, scheda 12, voci libere 12, domande 30, testo dell'avviso 500, …). Gli elenchi comuni (mezzi, voci di accessibilità, fasce d'età, tessera, icone e caratteristiche) sono in `GET /management/options`.
- `PATCH /v1/management/venues/{venue}/profile`: **per sezione**. Si mandano solo le chiavi di una sezione; si convalidano e si salvano solo quelle, le altre restano. `content_details` e `seo` si fondono con quelli salvati (le chiavi mandate sostituiscono, le altre restano), così «Prima di andare» e le informazioni editoriali non si cancellano a vicenda; `seo.indexing` non è accettato. Le regole sono quelle dello schema del pannello: indirizzo obbligatorio se mandato, comune fra quelli della provincia, quartiere obbligatorio per il comune di Padova e tolto per gli altri, orari `HH:mm` con giorno valido, chiusure con fine non prima dell'inizio e nota fino a 120 caratteri, telefono con lo stesso schema, social `http(s)` con nome di lettere e cifre, recapito del modulo obbligatorio se i messaggi sono accesi. Errori 422 sul campo o sulla riga (`opening_hours.2.close`, `socials.0.url`). Risponde con la scheda aggiornata. Limite 30 al minuto (`management-profile`).
- `POST /v1/management/venues/{venue}/notices`: «Avvisa chi ti segue», `{kind: pause|closure|reopening|info, body (max 500), until (yyyy-MM-dd, da oggi, facoltativo)}`. Uno ogni sette giorni: prima, 422 su `body` con il messaggio del pannello. Risposta 201 `{notice{id, kind, kind_label, body, until, created_at}, notified, message}`.
- `GET` e `PATCH /v1/management/organizers/{organizer}/profile`: `description` (HTML, fino a 20000), `website`, `email`, `contact{…}` come sopra, `public_url`, `calendar_url`. Il `PATCH` salva solo le chiavi mandate.
- `POST /v1/management/{venues|organizers}/{id}/contact/resend`: rimanda il collegamento di conferma del recapito del modulo di contatto. 422 su `contact_email` se non c'è un recapito o è già confermato; 429 `RATE_LIMITED` quando i tetti giornalieri della scheda o dell'indirizzo sono esauriti (gli stessi del pannello); 500 se la posta non parte. Risposta `{contact, message}`.

Le rotte del solo locale (`notices`) sotto un'organizzazione rispondono 404. Un test di parità (`tests/Feature/Api/Management/ProfileTest.php`) manda gli stessi valori non validi alla pagina «Il tuo locale» e all'API.

## Gestione: eventi (P1, 25 novembre 2026)

Gli eventi del locale e dell'organizzazione nell'app, 1:1 con le pagine «I tuoi eventi», «Nuovo evento», «Modifica evento» e la scheda in lettura dei pannelli. Le regole non sono riscritte: le pagine Filament e i controller chiamano gli stessi servizi, `App\Services\Events\VenueEventEditor` (bozza, prima data, valori imparati, categoria di ripiego, ritorno in revisione per testo, link, locandina e voci di listino), `OrganizerEventEditor` (sempre bozza, prima data nell'ora della città, ritorno in revisione di ogni modifica a un evento pubblicato), `EventSubmission` (pubblica o manda alla redazione; invia alla redazione), `RecurrenceSetup` («Si ripete»), `EventDateChecks` («Stessa sera in zona» e chiusura straordinaria), `App\Services\Import\FacebookImportThrottle` (3 al minuto e 30 al giorno, stessi secchi `facebook-import:{id}` del sito). Le regole dei campi stanno in `EventFormRules`. `S` è `/v1/management/venues/{venue}` o `/v1/management/organizers/{organizer}`, `V` solo il locale, `O` solo l'organizzazione; una rotta dell'altro soggetto risponde 404.

| Metodo e percorso | Permesso | Note |
|---|---|---|
| `GET S/events?tab=all\|upcoming\|past\|drafts&status=&q=&page=` | membro | card `{id, slug, title, status{value,label}, source{value,label,imported}, poster, next_starts_at, last_starts_at, past, venue}`, 20 per pagina, `meta.next_page` e `meta.total`; ordine del pannello (prossime per data, poi passate dalla più recente) |
| `GET S/events/{event}` | `view` | i campi del modulo (con «Prima di andare» già compilato con i valori del locale, `venue_before_going` a parte), `rejection_reason`, `scheduled_publish_at`, `dates.upcoming[]`/`dates.past[]` in breve, `ticket_tiers[]` dell'evento, `public_url` se pubblicato, `abilities{update, delete, publish, publish_needs_dates, publish_directly, duplicate, schedule, cancel_schedule, submit, preview, ticket_tiers}`, `links{tickets_occurrence_id, statistics, social, sponsor, site}` |
| `GET S/events/form?venue_id=` | membro | categorie attive, città (O), abitudini del locale e `start_time`, scorciatoie della data già calcolate nell'ora della città (`shortcuts[]{value, label, starts_at, repeats, weekday}`), «Prima di andare» del locale, stati del listino, giorni, modalità, parcheggio, tipo di organizzatore, tetti |
| `POST V/events` | `create` sul locale, `update` sulla bozza ripresa | `publish` falso = «Salva come bozza» (basta il titolo; categoria mancante = abituale o prima del catalogo); vero = «Pubblica» (servono `starts_at`, categoria e tipo di prezzo; scrive prima data, `repeat{frequency, weekdays[], until}`, valori imparati, poi online o in attesa). `draft_id` riprende la bozza dello stesso modulo: salvare due volte non crea due eventi; vale solo per le bozze del locale (`organizer_id` nullo), quella di un'organizzazione risponde 404. `starts_at` non può stare più di 7 giorni nel passato (`EventFormRules::PAST_START_DAYS`, 422), e la ripetizione materializza solo le date da oggi in avanti. `facebook_import` riprende i dati letti da Facebook. 201 (200 sulla bozza ripresa), `meta.outcome` `draft\|published\|pending\|scheduled`, `meta.facebook_photo` |
| `POST O/events` | `create` (`managedBy`) | sempre bozza; descrizione, città, locale principale, categoria e prezzo obbligatori; prima data e luogo facoltativi (`starts_at` al massimo 7 giorni nel passato, 422); 201 |
| `PATCH S/events/{event}` | `update` | modifica parziale (le chiavi assenti restano). Locale: anche `ticket_tiers[]{id?, name, price, status, url, note}` (al massimo 12, le voci di una singola data non si toccano), `facts[]`, link, editoriali, `organizer_name`/`organizer_url`, `seo{title, description, image}`. Organizzazione: città e locale principale, niente luogo; `city_id` e `venue_id` li cambia solo l'organizzazione principale (`organizer_id`): un co-organizzatore che manda un valore diverso da quello salvato riceve 422 su quel campo (lo stesso valore passa). `content_details.feature_ids` al massimo 60, senza doppioni (anche nel profilo del locale). `content_details` si fonde con quello salvato e i valori uguali a quelli del locale si ereditano. `meta.sent_to_review` |
| `DELETE V/events/{event}` | `delete` | 204 |
| `POST V/events/{event}/publish` | `update` (+ `publish` per andare online) | 422 senza date o da uno stato che non lo consente. `auto_publish` vale solo per gli eventi del locale: quello di un'organizzazione va in attesa (`outcome: pending`) |
| `POST O/events/{event}/submit` | `update` | solo bozza o rifiutato, 422 senza date |
| `POST V/events/{event}/duplicate` | `create` sul locale | copia in bozza con tag e locandina, senza date e senza organizzazione (la copia è del locale); 201 |
| `PUT` / `DELETE V/events/{event}/schedule` | `update` | `publish_at` futuro con scarto; regole di `ScheduleEventPublication` |
| `GET S/events/{event}/preview` | `update` | la forma di `GET /events/{slug}` anche per un evento non pubblicato |
| `POST S/events/checks` | membro | `starts_at`, `ends_at`, `category_id`, `custom_location`, `venue_id`/`city_id` (O), `event_id`: `{same_night[], radius_km, closure}` |
| `POST S/events/import/facebook` | `create` | `url`, `city_id` (O): `{fields, import, photo_url, source, location_address}`; 429 `RATE_LIMITED` oltre i limiti del sito, 422 su `url` se la lettura non riesce. La foto si scarica e diventa la locandina solo alla creazione |
| `GET S/events/lookup/tags?q=` · `GET S/events/lookup/venues?q=` | membro | tag per nome; locali approvati (locale principale, «organizzatore registrato») |
| `POST V/tags` | `CreateSharedEventTag` | tag condiviso nuovo, `name` (80), `event_id` facoltativo |

Le scritture hanno il secchio `management-events` (60 al minuto per persona, 30 per i tag), gli avvisi `management-events-checks` (120), l'import `management-events-import` (10) oltre ai limiti di Facebook. Le immagini restano quelle delle fondamenta (`POST/DELETE S/events/{event}/poster`).

## Gestione: calendario collegato (P9, 25 novembre 2026)

Il calendario che il locale collega (W-IM-01…07), con le regole della pagina «Calendario collegato» del pannello, estratte in `App\Services\Import\VenueCalendarImport` e usate da entrambi. Solo il locale: sotto `organizers/{organizer}` le rotte non esistono (404). Le apre chi apre la pagina, `ImportSourcePolicy::create` sul locale, cioè il referente; il collaboratore riceve 403. «Leggi adesso», sospensione e scollegamento chiedono in più `run`, `update` e `delete` sulla sorgente.

- `GET /v1/management/venues/{venue}/import`: `{source, form{type, url, exclude_keywords}, default_exclude_keywords, limits{max_keywords, max_keyword_length, max_url_length, max_file_bytes, preview_size}}`. `source` è `null` senza calendario, altrimenti `{id, type (ics|csv), type_label, address, url, is_upload, is_active, last_run_at, last_status, last_status_label, last_error, can_run, can_update, can_delete}`; per un CSV caricato `url` è nullo, `is_upload` vero e `address` è `upload:<nome>` come nella pagina.
- `POST …/import/preview` con `type` (`ics`|`csv`), `url` (max 1000, obbligatorio per l'ICS e per il CSV senza file, respinto da `ImportUrlGuard` se punta alla rete interna), `file` (solo CSV, multipart, `text/csv` o `text/plain`, al massimo `import.max_bytes`), `exclude_keywords[]` (max 100 parole da 100 caratteri): `{rows[]{title, starts_at, ends_at, when, where, all_day, recurring, cancelled, notes[]}, error, preview_token, expires_in_minutes}`. Non scrive niente. Un calendario che non si legge torna in `error` (200), come nella pagina; il `preview_token` arriva lo stesso.
- `POST …/import` con gli stessi campi e `preview_token`: collega (201) o aggiorna (200) e accoda subito la prima lettura; risponde con lo stato e `message`. È un `POST` e non un `PUT` perché porta il file in multipart. Il segno è cifrato, vale un'ora e lega locale, persona, tipo e indirizzo (o impronta SHA-256 del file): senza, scaduto, di un'altra persona o di un altro indirizzo/file, 422 su `error.fields.preview_token`. Le parole escluse, come nella pagina, si possono cambiare dopo l'anteprima.
- `POST …/import/run`: una lettura subito, `{outcome (success|partial|failed), outcome_label, title, message, counters, state}`. Una lettura che non riesce è `failed` con il motivo in `message` (200), non un errore della richiesta.
- `POST …/import/pause` e `…/import/resume`: sospende e riattiva, `{data: stato, message}`.
- `DELETE …/import`: scollega; le date già pubblicate restano.

Limiti di rotta (la pagina non ne ha): anteprima 20 al minuto (`management-import-preview`), «Leggi adesso» 10 (`management-import-run`), le altre scritture 20 (`management-import`).

**Dove finisce il CSV.** Sul disco privato `local` (`storage/app/private`), in `imports/<locale>/`; la sorgente lo indica come `upload:<nome>` e `CsvImportDriver` lo legge solo da lì. Dalla pagina lo salva il campo di Filament con un nome casuale; dall'app lo salva il collegamento con il nome `calendario-<sha256>.csv`, così lo stesso foglio resta un file solo. L'anteprima dell'app non lascia file (entra nella cartella solo per la lettura). Quando la sorgente passa a un altro file o a un indirizzo, o si scollega, il file di prima si cancella, insieme ai file di anteprime della pagina mai collegate più vecchi di un giorno.

## Gestione: registrare o rivendicare (P13, 25 novembre 2026)

«Registra il tuo locale» con la ricerca «è già a catalogo?», «Questo locale è mio», «Registra la tua organizzazione» e la conferma o il ritiro di una richiesta scritta da ospite con l'indirizzo dell'account, nell'app. Le regole sono una sola per sito e API: i campi in `App\Support\VenueApplications\ApplicationRules`, l'invio, la conferma, il ritiro e la ricerca nel catalogo in `App\Actions\SubmitVenueApplication`, la coda, le ricevute e i tetti in `App\Actions\CreateVenueApplication`. I moduli del sito (`VenueApplicationController`, `VenueClaimController`, `OrganizerApplicationController`) usano le stesse classi.

Nell'app si scrive solo da collegati con l'email confermata (`auth:sanctum` + `RequireConfirmedAccount`, 403 `EMAIL_VERIFICATION_REQUIRED` altrimenti): niente email nel modulo, niente password, tempo minimo, campo esca né Turnstile. La richiesta risponde all'email dell'account ed entra subito in coda. Le rotte stanno in `routes/management/applications.php`, dentro il gruppo `v1`, fuori dalle letture in cache (`Cache-Control: private, no-store`).

**Da ospite (Q2, 26 novembre 2026).** Le tre letture del modulo (`options`, `lookup`, `GET …/claim`) sono pubbliche come le pagine del sito che le mostrano, con il token facoltativo: per un ospite `account_email` è `null`. L'app tiene la bozza sul telefono (mai la password), crea l'account con `POST /v1/auth/register` (sempre 202, D64) e manda la richiesta con queste rotte quando arriva la sessione: da sola se lo scambio del collegamento risponde `registration: true` (`POST /v1/auth/magic-link/exchange`, vero solo quando il collegamento chiude la registrazione fatta da quella installazione), altrimenti — l'indirizzo aveva già un account — riapre il modulo precompilato e l'invio lo fa la persona. Nessuna rotta di scrittura nuova per gli ospiti.

| Metodo e percorso | Limite (stessa chiave del sito) | Note |
|---|---|---|
| `GET /v1/me/venue-applications/options` | `venue-application-options` 60/min | `types[]{value, label}`, `municipality` (quella della città, valore iniziale del campo «Comune»), `account_email`, `limits{pending, image_max_kb, image_min_width, image_min_height, claim_message_min}` |
| `GET /v1/venue-applications/lookup?q=` | `venue-catalogue-search` 60/min | `q` da 3 a 80 caratteri; al massimo sei locali pubblici `{id, slug, name, address, municipality, claimable}` |
| `POST /v1/me/venue-applications` | `public-forms` (5 l'ora per IP) | i campi di `/registra-il-tuo-locale` senza la parte da ospite: `venue_name`, `type`, `address`, `postal_code`, `municipality`, `website`, `public_email`, `public_phone`, `instagram`, `facebook`, `capacity`, `description`, `message`, `contact_name`, `contact_role`, `contact_phone`; JSON, oppure multipart con `image` (jpg/png/webp, al massimo 5 MB, almeno 400×300). 201 `{state: queued, message, application}`; 409 `CONFLICT` con tre richieste aperte |
| `GET /v1/venues/{slug}/claim` | `venue-claim-target` 60/min | `{id, slug, name, type_label, address, municipality}`; 404 se il locale non è pubblico o ha un referente, anche solo invitato (`forms.claim.already_managed`) |
| `POST /v1/venues/{slug}/claim` | `venue-claim` 10 l'ora | `contact_name`, `contact_role`, `contact_phone`, `message` (10–5000). 201 come sopra con `application.kind = claim`; 404 come sopra; 409 con una rivendicazione già aperta per lo stesso locale o tre richieste aperte |
| `POST /v1/me/organizer-applications` | `organizer-application` 10 l'ora | `venue_name`, `website`, `message`, `contact_name`, `contact_role`, `contact_phone`. 201 `{state: organizer, …}`; 409 come sopra |
| `POST /v1/me/venue-applications/{id}/confirm` | `venue-application-confirm` 10 l'ora | solo una richiesta propria in attesa di conferma (`can_confirm`): entra in coda, `{message}`. 404 altrimenti |
| `DELETE /v1/me/venue-applications/{id}` | `venue-application-confirm` 10 l'ora | come sopra; via anche l'immagine allegata, `{message}` |

`application` ha la forma delle card di `GET /v1/me/venue-applications`.

**Limiti e invii ripetuti (26 novembre 2026).** I limiti delle tre letture hanno un nome e contano la persona dal token anche fuori da `auth:sanctum` (per l'ospite l'IP, con il /64 in IPv6): sono gli stessi secchi delle pagine del sito (`/registra-il-tuo-locale` per `venue-catalogue-search`, `/locali/{locale}/rivendica` per `venue-claim-target`). Le tre scritture dei moduli (`POST /v1/me/venue-applications`, `POST /v1/venues/{slug}/claim`, `POST /v1/me/organizer-applications`) accettano `Idempotency-Key`: l'app manda la chiave salvata nella bozza, che cambia solo quando cambia il contenuto, e lo stesso invio ripetuto riceve la risposta del primo con `Idempotency-Replayed: true`; la stessa chiave con un contenuto diverso (anche in multipart, dove contano campi e impronta dell'immagine) è un 409. Senza la chiave, come le versioni dell'app già pubblicate, ogni invio è una richiesta.

### Campi nuovi

- `GET /v1/me/venue-applications`: `subject{type, id}` (il locale o l'organizzazione che «Apri il pannello» apre nella gestione nativa, solo se l'account lo gestisce) e `retry{form: venue|claim|organizer, venue_slug}` (dove porta «Nuova richiesta», solo per le respinte). `manage_url`, `panel_url` e gli indirizzi dei moduli restano per le versioni dell'app già installate.

## Gestione: date e ricorrenze (P2, 25 novembre 2026)

Le date di un evento nell'app, 1:1 con la sezione «Date» della modifica evento del locale (`OccurrencesRelationManager`) e con «Date e luoghi» dell'organizzazione (`DatesRelationManager`), più «Ripeti» e «Tutto esaurito» sulla prossima data dell'elenco eventi del locale. Le regole stanno in `App\Services\Events\EventDates` (moduli, lineup, stati, esaurito sulla prossima data), `UpdateOccurrencesAction` (annulla una data o la serie senza toccare il passato né le date modificate a mano), `RecurrenceSetup` («Ripeti») e `OrganizerEventEditor::afterDateSaved` (il ritorno in revisione di «Date e luoghi», che il pannello ora chiama da lì). L'evento si cerca fra quelli del soggetto e la data fra quelle dell'evento: 404 altrimenti. Una rotta del solo locale sotto un'organizzazione risponde 404 (405 per `DELETE` sulla data).

| Metodo e percorso | Permesso | Note |
|---|---|---|
| `GET S/events/{event}/dates?period=all\|upcoming\|past&page=` | `EventPolicy::view` | 50 per pagina; `all` in ordine di inizio come la tabella, `upcoming` da oggi, `past` dalla più recente. Ogni riga: `{id, starts_at, ends_at, doors_at, is_all_day, business_date, past, status{value,label}, status_note, highlight, capacity, capacity_left, venue{id,name}\|null, place, lineup[]{id, name, role{value,label}}, practical_details{}, cost_breakdown{}, currency, series, is_exception, stats{views, clicks, saves}, ticketing_date_id, poster_pdf, poster_pdf_url, abilities{update, delete, cancel, sold_out}}`. `ticketing_date_id` solo a chi gestisce la biglietteria (`BookingPolicy::manage`: il collaboratore no). `meta{next_page, total, event{id, title, status, city, timezone, currency}, recurrence{frequency, weekdays[], until, description}\|null, abilities{create, recurrence, recurrence_needs_dates}}` |
| `GET S/events/{event}/dates/{occurrence}` | `view` | la riga con i numeri e `meta` come sopra (senza paginazione) |
| `POST S/events/{event}/dates` | `EventOccurrencePolicy::create` sull'evento | 201; `meta.sent_to_review` |
| `PATCH S/events/{event}/dates/{occurrence}` | `EventOccurrencePolicy::update` (anche il collaboratore) | le chiavi assenti restano; `meta.sent_to_review` |
| `POST V/events/{event}/dates/{occurrence}/status` | `update` | `status` `cancelled\|sold_out\|scheduled`, `scope` `single\|series` (vale solo per l'annullamento e solo se la data è di una serie). 422 su una data già annullata, su `scheduled` da una data non esaurita. `meta.changed` |
| `DELETE V/events/{event}/dates/{occurrence}` | `delete` | 204 |
| `PUT V/events/{event}/recurrence` | `EventPolicy::update` | `frequency` `daily\|weekly\|biweekly\|monthly`, `weekdays[]` `mon…sun`, `until` `Y-m-d` nella città (compreso). 422 su `frequency` se l'evento non ha ancora una data. `data` è la ripetizione salvata, `meta.created` le date nuove |
| `PUT V/events/{event}/next-date/sold-out` | `EventOccurrencePolicy::update` sulla prossima data | `sold_out` booleano esplicito (non un interruttore: due telefoni non si annullano). Sempre la sola data. 422 se non ci sono date in arrivo. Risponde con la riga della data e `meta.changed` |
| `GET S/events/{event}/dates/{occurrence}/poster.pdf` | `view` | la locandina A4 con il QR, la stessa del sito, per eventi pubblicati o archiviati (404 altrimenti); secchio `event-poster` del sito (30 al minuto) |

Campi del modulo. Entrambi: `starts_at` (obbligatorio alla creazione), `ends_at` (dopo l'inizio, anche quando arriva da sola: si confronta con l'inizio salvato), istanti con lo scarto o ora della città. `practical_details{accessibility (yes\|no\|unknown), membership (required\|not_required), entrance_notes, membership_notes, parking_notes, transit_notes, food_notes, start_notes (max 2000)}` e `cost_breakdown{admission, drink, membership, other}` (0–100000, due decimali): quando ci sono **sostituiscono l'intera parte** salvata, un campo `null` vuol dire «usa quello dell'evento o del locale», `null` per tutta la parte la svuota. È la stessa regola di `PATCH /management/dates/{id}/details`, che resta com'è per le app installate (lì il permesso è ancora `BookingPolicy::manage`). Locale: `capacity`, `capacity_left` (0–1.000.000), `highlight` (max 40), `lineup[]{id?, name (max 255), role (live\|dj\|opening\|special_guest\|speaker)}` (max 100) che sostituisce gli artisti della data: quelli con un `id` della data si aggiornano, gli altri si aggiungono, i mancanti si tolgono. Organizzazione: `is_all_day`, `venue_id` (locale approvato o `null` = locale principale), `status` fra i cinque, `status_note` (max 2000, testo formattato). Le chiavi dell'altro modulo si ignorano. Il locale che aggiunge o modifica una data non rimanda l'evento in revisione; l'organizzazione sì, con una data nuova o portata in un altro locale su un evento pubblicato (non chi modera).

Altre aggiunte. `GET S/events` e `GET S/events/{event}` portano `next_date{id, starts_at, status}` (la prossima data da oggi, `null` se non c'è), per «Tutto esaurito» dalla card. `POST S/events/checks` con l'`event_id` di un evento del soggetto prende dall'evento quello che il modulo non manda (città, categoria, indirizzo a mano, locale principale) e per l'organizzazione mette prima il locale scelto per la data, come «Date e luoghi». Le voci del «Programma» (`content_details.agenda[]`) portano `starts_at`/`ends_at` come le salva il pannello (UTC, `Y-m-d H:i:s`) e `occurrence_id` (una data dell'evento, `null` = tutte): l'app ora li modifica.

Le scritture hanno il secchio `management-dates` (60 al minuto per persona).

## Gestione: statistiche (P5, 25 novembre 2026)

Le tre pagine del gruppo «Statistiche» dei pannelli, per l'app: «Statistiche», «Statistiche in dettaglio» e la pagina di un evento, un locale o un organizzatore (che per il proprio locale o la propria organizzazione è la prima pagina del pannello). Stesse rotte sotto `/v1/management/venues/{venue}/…` e `/v1/management/organizers/{organizer}/…`, con la porta `ResolveManagementSubject`. I numeri li calcolano i servizi dei pannelli (`ManagementAnalytics`, `EventAnalyticsDashboard`, `EventShareReport`, `OccurrenceAnalytics`, `EventShares`) letti attraverso `ManagementSubject`; `App\Services\Management\ManagedAnalytics` li mette in una forma da disegnare, con le etichette e le frasi del pannello (`label`, `hint`).

Chi vede che cosa, come nel pannello: ogni membro del soggetto (referente e collaboratore del locale, proprietario e membri dell'organizzazione) vede le stesse statistiche; i canali propri sono solo del referente del locale. Le statistiche di un'organizzazione comprendono solo gli eventi di cui è l'organizzatrice principale (`events.organizer_id`), non quelli solo co-organizzati; in un locale, dei profili degli organizzatori dei suoi eventi si danno i numeri degli eventi ma non le misure del profilo (`null`, «n.d.»).

### Rotte (permesso: membro del soggetto, `canAccessTenant`)

- `GET /analytics/summary?period=week|month|quarter&page=&share_page=`: «Statistiche». `period{value, days, from, until}`, `periods[]`, `cards[]{key, label, value, hint}` (aperture eventi, aperture profilo, pulsanti toccati, salvataggi, chi ti segue), `empty`, `series[]{date, views, profile_views, clicks}` un giorno per riga, `interactions[]`, `profile[]`, `details[]` (`{key, label, value}`), `paid{impressions, clicks, ctr, channels[], breakdown[]}`, `events{columns[], data[]{id, title, values{…}}, meta}` (25 per pagina), `shares{data[]{title, occurrence, channel, url, shares, clicks}, meta}` (20 per pagina).
- `GET /analytics/report?period=week|month|quarter|year|all|custom&from=&until=&event=&channel=`: «Statistiche in dettaglio». `period`, `from` (`null` per tutto lo storico), `until`, `periods[]`, `filters{event, channel}`, `active_filters[]{key, label, value}`, `channels[]{value, label}` (i quattro di sistema più i canali propri di chi guarda), `context`, `cards[]` (i sei numeri), `empty`, `selected_event`, `series{granularity: day|month, points[]{date, views, short_shares, short_clicks}}`, `series_labels`, `charts[]{key, link, metric_label, secondary_label, rows[]{id, name, value, secondary}, totals[]}` (chiavi `events`, `channels`, `actions`, `venues`, `organizers`, `community`, `bookings`, `paid`), `datasets[]{key, label, count}`. Con `period=custom` serve `until`; senza `period` e con `until` vale `custom`. Un evento di un altro soggetto nel filtro riceve 403 (come il pannello), un canale altrui 422.
- `GET /analytics/report/table?…&dataset=&page=`: una tabella di «Tutti i dati», 20 righe per pagina. `dataset` è uno di `events`, `venues`, `organizers`, `channels`, `daily`, `links`, `occurrences`. Risposta `{dataset, label, columns[]{key, label, numeric}, rows[]{cells[], links{colonna: {type, id}}}, unavailable, meta{page, next_page, total}}`; le colonne sono quelle della vista, senza gli id tecnici.
- `GET /analytics/report/export?…&dataset=&format=csv|xlsx`: il file del pannello (`EventAnalyticsExport::download`), CSV della tabella o Excel completo con tutti i fogli. Secchio `management-analytics-export`, 10 al minuto.
- `GET /analytics/subjects/{type}/{id}?period=&from=&until=&channel=` con `type` `event`, `venue` o `organizer`: la pagina di un soggetto, lo stesso rapporto più `subject{type, id, name, title, event, related[], information[], description, profile_report[]}`. Il soggetto si ricontrolla a ogni richiesta (404 se chi guarda non lo vede); i filtri sono solo periodo e canale. Le stesse `…/table` ed `…/export`.
- `GET /analytics/events?q=`: gli eventi fra cui scegliere («Guarda un altro evento», filtro «Evento»), con le regole del selettore dei pannelli (`App\Queries\EventChoices`): `upcoming[]` e `past[]` di `{id, title, poster, starts_at, timezone, past, more, venue}`.
- `GET /events/{event}/dates/analytics`: le date di un evento del soggetto con `views`, `clicks`, `saves` e `can_view_report` (`EventOccurrencePolicy::update`, come l'azione «Statistiche» dell'elenco delle date).
- `GET /events/{event}/dates/{occurrence}/analytics`: le statistiche di una data (`OccurrenceAnalytics::report`): `note`, `cards[]`, `series[]{date, views, clicks}` degli ultimi 30 giorni. 403 senza `update` sulla data.
- `GET /events/{event}/share-links`: i link brevi tracciati dell'evento, uno per canale di sistema, gli stessi della scheda pubblica: `{available, links[]{channel, label, url}}`. Per un evento che il pubblico non può aprire `available` è falso e non si crea nessun link.

### Canali propri (solo locale, solo referente: `VenuePolicy::update`)

- `GET /analytics/channels`: `data[]{code, channel, target, url, shares, clicks}` (i link della scheda del locale e i canali propri), `meta{max, custom}`. Il QR lo disegna l'app dal link breve.
- `POST /analytics/channels` con `label` (al massimo 38 caratteri) ed `event` facoltativo (un evento del locale): 201 con l'elenco aggiornato, `created` (il codice nuovo) e `message`. Le regole del pannello (`EventShares::channelProblem`): un nome che non dà almeno due lettere e il tetto di 12 canali rispondono 422 su `label` con il messaggio del pannello. Secchio `management-analytics-channels`, 30 al minuto.

### Limiti e campi nuovi

Tutte le letture stanno nel secchio `management-analytics` (60 al minuto per persona): sono rapporti pesanti su un hosting condiviso, e l'app non si aggiorna da sola. Nella scheda dell'evento (`GET …/events/{event}`) `links.statistics` adesso dice se le statistiche dell'evento sono del soggetto (falso per un evento solo co-organizzato).

## Gestione: sponsorizzazioni (P6, 25 novembre 2026)

Le pagine «Sponsorizzazioni» e «Statistiche sponsorizzazioni» dei due pannelli, per l'app. Le regole non sono riscritte: la pagina Filament (`App\Filament\Shared\SponsorshipsPage`) e il controller chiamano lo stesso servizio, `App\Services\Sponsorship\SponsorshipDesk` (eventi sponsorizzabili, invio con il tetto di cinque invii l'ora sulla chiave di sempre `sponsorship-request:{utente}`, tetto delle richieste aperte, abilitazioni, ultimi 20 ordini, riprova, annulla, ritira, ritorno dal checkout, scelta degli eventi su un periodo attivo, stop di una campagna, campagne della redazione e totali); le statistiche passano da `SponsorshipAnalyticsReport`, che chiama anche `SponsorshipAnalyticsPage`. Le campagne visibili le decide `SponsorshipReport` con il soggetto di `ManagementSubject`. `S` è `/v1/management/venues/{venue}` o `/v1/management/organizers/{organizer}`: le rotte sono le stesse per i due soggetti.

Permessi come sul sito: vede ogni membro (referente, collaboratore, proprietario e membro dell'organizzazione); comprare, riprovare, annullare e ritirare solo chi passa `SponsorshipOrderPolicy::purchase` (il referente del locale, il proprietario dell'organizzazione); mettere in evidenza un evento su un periodo già pagato e fermare una campagna chi può modificare l'evento (`EventPolicy::update`, collaboratore compreso: decisione 7). Un id (ordine, abilitazione, campagna) di un altro committente risponde 404.

| Metodo e percorso | Permesso | Note |
|---|---|---|
| `GET S/sponsorships?campaigns_page=` | membro | la pagina intera: `subject`, `can_purchase`, `pays_online`, `how{title, steps[], disclosure}` (i passi cambiano con Stripe acceso o spento), `not_owner{title, body}` per chi non compra, `limits{requests_per_hour, max_open_requests, note_max, events_max}`, `sponsorable_count`, `selectable_grants[]{id, placement, ends_at, active_event_ids[]}` (i periodi attivi «a scelta», il primo scade per primo), `orders[]` (gli ultimi 20), `measures`, `totals{impressions, clicks}` (anche le righe tolte), `grants[]` (anche scaduti), `campaigns[]` (della redazione, 10 per pagina) e `campaigns_next_page` |
| `GET S/sponsorships/campaigns?page=` | membro | le altre pagine delle campagne della redazione; `meta{next_page, total}` |
| `GET S/sponsorships/events?q=&limit=&grant=` | membro | gli eventi pubblicati con una data in programma, in ordine di data; `q` nel titolo (80 caratteri), `limit` da 1 a 60; ogni evento `{id, title, slug, poster, date, already_sponsored, active_on_grant}` (`active_on_grant` rispetto a `grant`); `meta{has_more, total}` |
| `GET S/sponsorships/catalog` | membro | `pays_online`, `price_vat`, `placements[]{value, label, description, rotation, sells_views, from_cents, from_label, priced, durations[]{key, months, months_label, amount_cents, amount_label, pays_online}}`: le voci del listino o, senza listino per una posizione, 1, 3, 6 e 12 mesi con «Prezzo da concordare» |
| `POST S/sponsorships/orders` | `purchase` | `scope` `event`\|`all`, `event_id` (con `event`), `placement`, `duration` (la `key` del catalogo), `note` (1000, solo per le richieste). 201 con `data{mode, checkout_url, order}`: `mode: checkout` e `checkout_url` se si paga online, `mode: request` altrimenti. 422 su `event_id` (evento non sponsorizzabile o di altri) o `duration`; 429 con il motivo in `error.fields.order` oltre i cinque invii l'ora o con tre richieste aperte; 409 `CONFLICT` se la pagina di pagamento non si apre |
| `POST S/sponsorships/orders/{order}/retry` | `purchase` | solo un ordine in attesa: `mode: checkout` con la pagina nuova (quella vecchia si chiude su Stripe), `mode: paid` se risultava già pagato; 409 se la tariffa non regge più (l'ordine si annulla) o se Stripe non risponde |
| `POST S/sponsorships/orders/{order}/cancel` | `purchase` | solo un ordine in attesa: `mode: cancelled`, oppure `mode: paid` se nel frattempo è stato pagato; 409 se Stripe non risponde |
| `DELETE S/sponsorships/orders/{order}` | `purchase` | ritira una richiesta non ancora decisa: `mode: withdrawn` |
| `POST S/sponsorships/orders/{order}/reconcile` | membro | il ritorno dal checkout, come la pagina aperta con `?order=`: con `cancelled: true` risponde `returned_cancelled`; altrimenti chiede a Stripe e risponde `paid` o `returned_pending`. Il messaggio da mostrare è in `message` |
| `POST S/sponsorships/grants/{grant}/events` | `update` sull'evento | `event_id`: mette in evidenza subito l'evento con un periodo attivo «a scelta» del committente (`GrantCampaigns::choose`); 422 su `event_id` se periodo o evento non lo permettono |
| `POST S/sponsorships/campaigns/{campaign}/stop` | `update` sull'evento | ferma (mette in pausa) una campagna di un proprio periodo «a scelta» |
| `GET S/sponsorships/campaigns/{campaign}` | membro | la scheda di una campagna: evento, posizione, periodo, `phase`, numeri, `progress` della sua unità, `delivery` (consegnate e attese giorno per giorno, solo a visualizzazioni), `economics` e `economics_help` |
| `GET S/sponsorships/analytics?days=&from=&to=&campaign=` | membro | `days` `7`, `30`, `90`, `365`, `all` o `custom` (con `from` e `to`, giorni della città, al massimo 731 giorni); `periods[]`, `window{from, to}`, `totals{impressions, clicks, rate, rate_label, min_ctr, rate_threshold, explain}`, `series[]{date, impressions, clicks}`; con `campaign` anche `campaign`, `progress`, `progress_lead`, `delivery`, `economics`. Una campagna di altri: 404 |
| `GET S/sponsorships/analytics/clicks?…&page=` | membro | il registro dei clic dello stesso periodo, 25 per pagina: `{clicked_at, event, title, venue, channel, placement, page}`; nessun dato della persona |
| `GET S/sponsorships/analytics/campaigns?q=` | membro | le ultime 50 campagne per il filtro, cercando nel titolo dell'evento o nel nome del locale |

Una riga d'ordine: `{id, placement{value,label}, months, months_label, mode, event, what, created_at, amount_cents, currency, amount_label, status{value,label,color}, reason, hint, request_note, can_retry, can_cancel, can_withdraw}`. Una scheda di periodo: `{id, placement, mode, mode_label, status_label, starts_at, ends_at, complimentary, paid_at, payment_label, progress, events[]{id, event, impressions, clicks, numbers, share, share_label, active, stopped_label, can_stop}}`. L'avanzamento (`progress`) è quello del riquadro del pannello: `percent`, `expected_percent` (la tacca), `color`, `state`, `badge`, `show_badge`, `numbers`, `phrase`, `days_left`, `days_left_label`, `notes[]`, `started`, `ended`, `completed`, `halted`. Frasi, stati e prezzi arrivano già scritti in italiano.

**Il pagamento** (decisione 1). L'app apre `checkout_url` nel browser. L'indirizzo di ritorno che il server dà a Stripe per un ordine fatto dall'app è `/app/sponsorizzazioni/ritorno?subject=venue|organizer&id={soggetto}&order={ordine}` (con `&cancelled=1` se la pagina di Stripe si chiude senza pagare): è un App Link dell'app, e la pagina, se il browser la mostra lo stesso, ha il pulsante «Torna all'app» (`intent://` con il pacchetto). La pagina non tocca l'ordine; l'app chiama `reconcile` quando torna in primo piano o quando arriva dal collegamento, e il webhook di Stripe resta la conferma definitiva. Senza le chiavi Stripe (`STRIPE_SECRET` vuoto) vale solo il ramo «richiesta».

Limiti: le letture hanno il secchio `management-sponsorships` (60 al minuto), le scritture `management-sponsorship-writes` (20–30 al minuto), le statistiche `management-sponsorship-analytics` (30 al minuto); il tetto vero degli invii resta quello del pannello, condiviso.

La vecchia `GET /v1/management/campaigns` resta per le app già installate; con l'area di gestione accesa l'app legge le campagne da qui.

## Gestione: grafiche social (P10, 25 novembre 2026)

Lo studio delle grafiche social del locale (W-SO-01…09) e l'anteprima della grafica di una data (W-DT-12). Decisione 3 del piano: la scelta delle serate e le opzioni sono dell'app, le immagini le disegna il server con lo stesso `SocialStudio` del pannello. Le regole che stavano nella pagina «Social» (periodi, giorno di partenza, calendario, un giorno per pacchetto, titolo solo con una serata, ultimi dodici pacchetti) sono in `App\Services\Social\SocialStudioDesk`, usato anche da `InteractsWithSocialStudio`; anteprima, immagini e ZIP in `App\Services\Social\SocialBatchFiles`, usato anche da `SocialDownloadController`. Lo studio esiste solo sotto `venues/{venue}` (sotto `organizers/{organizer}` 404) e lo apre ogni membro del locale, referente o collaboratore, come la pagina. Il parametro `event` restringe lo studio a un evento del locale (`update` sull'evento; un evento di un altro locale è 404).

| Metodo e percorso | Note |
|---|---|
| `GET V/social/catalog` | `period` (`today`, `week`, `next30` predefinito, `custom` con `from`/`until`, messi in ordine e tenuti entro un anno), `search` (100), `limit` (24 per volta, fino a 240), `event`. `data[]{id, event_id, title, starts_at, timezone, is_all_day, business_date, status{value,label}, selectable, demo, poster, venue}`, anche le serate annullate o rinviate (`selectable` falso); `meta{period, from, until, today, timezone, total, limit, page_size, max_cards, event{id,title}}` |
| `GET V/social/calendar` | `month` (`Y-m`), `date` (il giorno scelto), `search`, `event`: sei settimane da lunedì, `data[]{date, day, count, in_month, today, selected}`, `meta{month, label, previous, next, today}` |
| `GET V/social/day` | `date`, `event`: le serate che si possono preparare quel giorno (stessa forma delle card). Senza `date` è il giorno da cui si apre la pagina: oggi, con `event` la sua prossima data, e se lì non c'è niente la prima serata in programma dei prossimi trenta giorni. `meta{date, label, today, timezone, max_selection, title_max, event}` |
| `GET V/social/events?q=` | gli eventi del locale per il foglio «Scegli un evento» (`upcoming[]`, `past[]`, forma di `EventChoices`) |
| `POST V/social/batches` | `date`, `occurrences[]` (da 1 a 100, tutte di quel giorno e preparabili: altrimenti 403), `format` (`portrait` predefinito, `square`, `story`), `monochrome`, `show_address`, `show_price` (veri se assenti), `image_fit` (`contain` predefinito, `cover`), `title` (300, solo con una serata: altrimenti 422 su `title`), `event`. 201 con il pacchetto; una grafica che non si disegna (un titolo troppo lungo) è 422 su `generate` |
| `GET V/social/batches` | gli ultimi dodici pacchetti del locale `{id, date, format{value,label,width,height}, count, created_at}`, `meta.keep` |
| `GET V/social/batches/{batch}` | il pacchetto con `timezone`, `caption` e `items[]{index, title, filename, occurrence_id, event_id, time, venue, url}`: `url` è l'immagine firmata del sito (`social.image`, sette giorni), da guardare senza token. Un pacchetto di un altro locale è 404 (`SocialBatchPolicy::view`) |
| `GET V/social/batches/{batch}/images/{index}` | il JPEG, per «Salva nella galleria» e «Condividi» |
| `GET V/social/batches/{batch}/zip` | lo ZIP con immagini e didascalie, divise in caroselli da dieci, come «Scarica tutte le grafiche» |
| `GET S/events/{event}/dates/{occurrence}/social-preview` | l'anteprima JPEG della grafica della data (verticale), `EventPolicy::update`; vale per il locale e per l'organizzazione. Le righe di `GET S/events/{event}/dates` hanno `social_preview`, vero solo per l'organizzazione (il pulsante «Grafica social» del suo elenco delle date) |

Limiti: le letture e i file `management-social` (60 al minuto per persona), la preparazione `management-social-generate` (10 al minuto), l'anteprima lo stesso secchio del sito (`social-preview`, 120 al minuto). La preparazione è sincrona come nel pannello: con molte serate può avvicinarsi al tempo massimo di risposta dell'hosting.

**Pulizia.** `SocialBatch` è `Prunable` e `model:prune` gira ogni notte alle 04:40 (`routes/console.php`): restano i dodici pacchetti più recenti di ogni locale e della redazione di ogni città (quelli che lo studio mostra), più quelli con una pubblicazione programmata, in coda, in corso, fallita o incerta, e quelli con pubblicazioni di oggi o di ieri. Le immagini in `storage/app/social/{id}` si cancellano con il pacchetto. Le pubblicazioni invece restano: dal 26/11/2026 `social_publications.social_batch_id` è nullable con `nullOnDelete` (migrazione `2026_11_26_100000_keep_social_publications_without_batch`), quindi lo storico della redazione mostra anche le pubblicazioni dei pacchetti cancellati, con la nota che le grafiche non ci sono più e senza «Riprova»; `social:daily` riconosce il carosello già pubblicato dalla `dedupe_key`, che non dipende dal pacchetto.

## Gestione: riepilogo (P11, 25 novembre 2026)

La prima pagina dei due pannelli senza il rapporto (W-DB-01…08, W-PB-02): l'app lo legge da `GET S/analytics/summary` e dalla pagina del soggetto delle statistiche (P5). Le domande dei riquadri stanno in `App\Services\Management\DashboardSummary`, che chiedono sia i widget del pannello sia l'API: oggi, prossimi, bozze e ultime visite hanno una risposta sola. Stesse rotte sotto `/v1/management/venues/{venue}/…` e `/v1/management/organizers/{organizer}/…`, con la porta `ResolveManagementSubject`; permesso: membro del soggetto (`canAccessTenant`), come la pagina del pannello. Token obbligatorio, `Cache-Control: private, no-store`.

- `GET /dashboard` (secchio `management-dashboard`, 60 al minuto): `subject{type, id, name}`, `timezone` (il fuso con cui il pannello scrive le ore; le ore sono in UTC), `missing_location` (solo locale: il punto coincide con il centro della città), `counts{today, upcoming, drafts}` («Il tuo cartellone»), `today[]` (le serate di oggi), `upcoming[]` (cinque eventi, uno per evento, oggi escluso), `recent_views{days: 7, items[]}` (le tre schede più viste negli ultimi sette giorni, con `views`), `programme{available, this_week, next_week}` (solo locale, i due lunedì; `available` è falso finché il locale non è approvato) oppure `null`. Ogni card è `{event{id, slug, title, status{value, label}, poster}, date{id, starts_at, place, status{value, label}, scheduled}|null, views|null, links{edit, tickets_occurrence_id, statistics, social, sponsor}}`: i collegamenti sono quelli che il pannello mette sulla card, con le stesse condizioni (`EventPolicy::update`, `BookingPolicy::manage` sulla prossima data, statistiche solo dell'organizzatore principale, grafiche social solo del locale, sponsorizzazione solo per un evento pubblicato). Due regole del pannello restano com'erano: le date dell'organizzazione comprendono gli eventi co-organizzati, le bozze e le ultime visite solo quelli con il suo `organizer_id`; «oggi» lo decide la città del locale, per l'organizzazione quella del suo evento più recente. Le scorciatoie «Vai a» le compone l'app da `permissions`.
- `GET /dashboard/programme?week=AAAA-MM-GG` (solo locale): il programma della settimana in PDF, lo stesso foglio di `/locali/{slug}/programma.pdf` (`App\Services\Events\VenueProgramme`), come allegato. `week` è un giorno qualsiasi della settimana; senza, questa settimana; una data scritta male risponde 422. Come la pagina pubblica esiste solo per un locale approvato (altrimenti 404) e consuma lo stesso secchio `venue-programme` (30 al minuto).

## Gestione: notifiche e chiusura del giro (P12, 25 novembre 2026)

Gli avvisi a chi gestisce (`NotificationType::isForVenueStaff`: `event_published`, `event_rejected`, `event_new_comment`, `venue_inactive`) portano ora, accanto all'`url` pensato per l'email, la schermata della gestione da aprire nell'app. L'email non cambia.

### Campi nuovi

- Archivio (`GET /v1/me/notifications`, `data` della riga): `management{subject_type (venue|organizer), subject_id, area (event|comments|home), id}`. `event` è la scheda dell'evento (`id` dell'evento), `comments` i commenti di quell'evento da moderare, `home` la pagina del locale (`id` nullo). Il soggetto è quello da cui la persona vede il contenuto nei pannelli, con la stessa barriera `User::canAccessTenant` e le regole di `ManagedContent`: prima il locale dell'evento se ne è membro attivo, poi l'organizzazione principale, poi una co-organizzatrice (per i commenti solo la principale). Il campo si scrive all'invio e manca se la persona non gestisce quel soggetto: allora vale l'`url`. Le righe scritte prima non lo hanno. Lo calcola `App\Services\Management\ManagementNoticeTarget`.
- Push FCM degli stessi tipi: gli stessi dati piatti, perché FCM vuole stringhe: `management_subject_type`, `management_subject_id`, `management_area`, `management_id` (vuoto per `home`).
- **Avvisi che erano solo email (Q3).** `sponsorship_order_decided`, `sponsorship_weekly_report`, `venue_monthly_report`, `venue_application_approved`, `venue_application_rejected`, `venue_access_granted`, `organizer_access_granted`, `venue_moderated`: l'email resta la stessa e parte una volta sola; chi ha un account riceve anche la riga nell'archivio (stessa forma: `type`, `title`, `body`, `url`, `items`, `occurrence_id`, `event_id`, `management`) e la push (FCM/WebPush, solo se `ChannelSelector` sceglie la push o entrambe, fuori dalle ore di silenzio, con l'interruttore `venue_report` per il rapporto mensile). Il committente scritto su una campagna senza account riceve solo l'email. Aree nuove di `management`: `sponsorships` (`id` l'ordine), `sponsorship_stats` (`id` la campagna del riepilogo), `statistics` (rapporto mensile), `home` (locale moderato e approvato, organizzazione concessa, richiesta accolta), sempre ricontrollate con `canAccessTenant` all'invio e dal server quando l'app apre la schermata. Senza un soggetto gestito, `subject_type` `account` (`subject_id` la persona) con area `applications` (richiesta rifiutata) o `invitations` (invito ancora da accettare). Nell'archivio non finiscono mai i collegamenti per scegliere la password: l'invito porta il suo collegamento firmato da accettare, oppure la pagina di accesso.
- `GET /v1/me/notification-preferences`: `venue_report_available`, vero per chi ha accettato l'accesso ad almeno un locale; è la stessa regola della pagina delle preferenze del sito (`App\Support\Notifications\VenueReportPreference`), e dice all'app quando mostrare l'interruttore `venue_report`. Gli avvisi a chi gestisce non hanno interruttore, il rapporto mensile sì.

### Rotte (sotto `V` e `O`, porta `ResolveManagementSubject`)

| Rotta | Permesso | Cosa fa |
|---|---|---|
| `GET S/notifications?cursor=&limit=` | membro del soggetto | gli avvisi a chi gestisce (con Q3 anche ordine deciso, riepilogo della sponsorizzazione, rapporto mensile, locale moderato, accesso concesso, richiesta accolta) con `management` di questo soggetto, dal più recente, con la forma di `GET /me/notifications` (`id`, `type`, `data`, `read`, `read_at`, `created_at`) e `meta{next_cursor, has_more, unread_count}`. Si segnano come letti con `PATCH /me/notifications/{id}/read` |
| `GET S/events/by-slug/{slug}` | `EventPolicy::view` | `data{id, slug, title}` di un evento del soggetto (`ManagedContent::events()`): gli indirizzi del pannello portano lo slug, l'app apre la scheda per id. 404 per un evento di altri |

## Redazione: fondamenta dell'app (26 novembre 2026)

Le basi dell'area «Redazione» nativa: il pannello `/admin` sotto `/v1/editorial/…` (sigla `E`). Tutte le rotte chiedono il token e rispondono `Cache-Control: private, no-store`; le forme sono anche in `docs/openapi.json`.

### La porta e la città

`ResolveEditorialDesk` sta su ogni rotta `E` (lo verifica `tests/Architecture/EditorialRoutesTest.php`): risponde **404** a chi non è moderatore, admin o super admin (`User::isEditorialStaff`), come un indirizzo che non esiste, e a tutti quando l'interruttore d'emergenza `API_EDITORIAL_ENABLED` è spento. Legge la città di lavoro da `?city=<slug>`, anche di una città non ancora attiva (uno slug che non esiste risponde 404); senza parametro vale la prima città attiva, poi la prima in assoluto, come i riquadri del pannello. Poi lega `App\Support\Editorial\EditorialDesk` (persona, ruolo più alto, città), che i servizi delle statistiche e delle sponsorizzazioni leggono al posto di `Filament::getCurrentPanel()`: nel pannello il banco si ricava come prima. `SponsorshipReport` senza soggetto dà tutte le campagne solo dentro la redazione (pannello o app) e solo ad admin e super admin. Le policy restano il controllo di ogni azione. Le rotte dei pacchetti stanno in `routes/editorial/<pacchetto>.php`.

### Sblocco

Il token dell'app dura 90 giorni; la redazione chiede in più la password dell'account. `POST /v1/editorial/unlock` con `{password}` sblocca **questo token** per 12 ore e conferma le azioni pericolose per 15 minuti (tabella `editorial_unlocks`, una riga per token, cancellata con il token). Password sbagliata: 422 su `error.fields.password`; limite 6 tentativi ogni 15 minuti per persona (`editorial-unlock`); senza un token salvato (accesso dal sito) 423. Ogni tentativo va nel registro di sicurezza (`redazione_sbloccata`, `redazione_sblocco_fallito`). Alla decima password sbagliata di fila in 24 ore per la stessa persona (`EditorialUnlocks::LOCKOUT_FAILURES`; uno sblocco riuscito azzera il conto) il token si revoca e la risposta è **401 `UNAUTHENTICATED`**: riga `redazione_token_revocato` nel registro ed email alla persona e ai super admin (`EditorialUnlockLockout`).

- Ogni rotta `E` tranne `desk`, `options` e `unlock` passa da `RequireEditorialUnlock`: **423 `EDITORIAL_LOCKED`** se lo sblocco manca o ha più di 12 ore.
- Le azioni pericolose (unire eventi e tag, eliminare, sospendere, annullare un evento, cambiare i ruoli, modificare una città, conservare le prove) passano anche da `RequireRecentPassword`: **423 `PASSWORD_REQUIRED`** se la password non è stata scritta negli ultimi 15 minuti. L'app chiede la password, chiama `unlock` e ripete la richiesta.
- Ogni riga del registro attività e di sicurezza scritta da una richiesta `E` porta `canale: app`.

### Rotte

- `GET /v1/editorial/desk?city=`: `data{user{id, name}, role (moderator|admin|super_admin), city{id, slug, name, timezone, is_active}|null, cities[] (tutte, anche le spente), permissions, badges, unlock{unlocked, unlocked_until, password_confirmed_until, unlock_hours, password_minutes, lock_after_minutes}}`. `permissions` ha sempre 34 chiavi booleane, ognuna calcolata dalla policy (o dal controllo di ruolo) che il pannello usa per la stessa pagina o azione: `events_view`, `events_edit`, `events_publish`, `events_moderate`, `events_feature`, `submissions_publish`, `venues_view`, `venues_edit`, `venues_moderate`, `venues_members`, `applications_review`, `users_view`, `users_manage`, `users_roles`, `reports_resolve`, `reviews_moderate`, `comments_moderate`, `comments_delete`, `community_manage`, `cases_manage`, `carpool_manage`, `messages_read`, `sponsorships_manage`, `social_use`, `analytics_view`, `search_misses_manage`, `catalog_manage`, `pages_manage`, `imports_manage`, `notifications_view`, `notifications_cancel`, `system_view`, `system_manage`, `mail_manage`. `badges` sono i contatori del menu con le stesse query e chiavi di cache del pannello (`nav-badge:*`): `events_pending`, `event_submissions_pending`, `venues_pending`, `venue_applications_pending`, `users_registrations`, `reports_open`, `event_comments_hidden`, `catalog_reviews_pending`, `carpool_open`, `sponsorship_orders_awaiting`, `scheduled_notifications_due`; `null` dove la voce non si vede (recensioni e ordini al moderatore). Non chiede lo sblocco.
- `GET /v1/editorial/options`: stati ed elenchi dei moduli (eventi, provenienze, proposte, locali, tipi di locale, richieste, segnalazioni, commenti, recensioni, casi dei passaggi, invii, posizioni, ritmi e stati delle sponsorizzazioni, ordini), `roles[]` `{value, label, assignable, assignable_in_app}` per chi chiede (solo un super admin conferisce `super_admin`; dall'app al massimo `moderator`), `limits{per_page_default, per_page_max (50), reason_min (5), reason_max (1000), unlock_hours, password_minutes, lock_after_minutes}`. Non chiede lo sblocco.
- `POST /v1/editorial/unlock`: vedi sopra. Risponde con l'oggetto `unlock` di `desk`.

### Campi nuovi

- `GET /v1/me`: `management.editorial` (booleano), vero per moderatore, admin e super admin.
- `GET /v1/config`: `features.editorial`, acceso salvo `API_EDITORIAL_ENABLED=false`. L'app apre la redazione nativa solo con tutti e due veri; altrimenti la riga «Amministrazione» apre il pannello sul sito, come prima.
- Codici d'errore nuovi: `EDITORIAL_LOCKED` e `PASSWORD_REQUIRED`, tutti e due con stato 423.

## Redazione: riepilogo (R1, 26 novembre 2026)

Il riepilogo di `/admin` nell'app: le stesse sezioni, gli stessi numeri e le stesse destinazioni dei riquadri del pannello (R-DB-01…09). Una operazione nuova, in `routes/editorial/dashboard.php`.

- `GET /v1/editorial/dashboard?city=` (redazione sbloccata, limite 30 al minuto `editorial-dashboard`, non pericolosa): `data{city{id, slug, name, timezone, is_active}|null, sections[]}`. Le sezioni arrivano nell'ordine della pagina del pannello e solo quelle che il pannello mostra a chi chiede: `queue` (eventi, locali e richieste in attesa, segnalazioni aperte), `publishing` (pubblicati oggi e in settimana, in programma oggi, annullati), `quality` (senza locandina, incompleti, duplicati sospetti, locali inattivi, import falliti), `registrations` (a chi apre l'elenco degli utenti), `returns` (ritorno, prenotazioni, presenze), `promotion` (abilitazioni e pagamenti, solo admin e super admin), `sponsorships` (campagne in corso, in scadenza, viste e aperture della settimana), `sponsorship_trend` (andamento di 30 giorni). Senza nessuna città resta solo `registrations`, come nel pannello.
- Una sezione di riquadri è `{key, title, description, stats[]}`; ogni riquadro `{key, label, value (già scritto come nel pannello: «12», «38%», «—», «1.250,00»), count (numero o null), description, tone (warning|danger|success|gray|null), target|null}`. `target` è `{area (events|venues|applications|reports|import_sources|users), tab, filters{chiave: valore} (sempre un oggetto; `1` per i sì/no), web_path}`: l'elenco con la convenzione `filter[chiave]`/`tab` e la stessa pagina del pannello già filtrata (`admin/events?filters[status][value]=pending`).
- `registrations` è `{key, title, description (la frase del pannello con i conteggi), counts{recent, pending}, links[]{key (pending|all), label, badge, target}, rows[]{id, name, detail, state{value, label, description, tone}, registered_at, abilities{update}, web_path|null}, empty{title, description}}`: le ultime cinque iscrizioni; `web_path` (la modifica nel pannello) solo per chi può modificare la persona.
- `sponsorship_trend` è `{key, title, description, chart{days[] (AAAA-MM-GG, fuso della città), labels[], series[]{key (impressions|clicks), label, values[]}}}`.

Numeri, frasi, colori e destinazioni li calcola `App\Services\Editorial\DeskSummary` (le iscrizioni `App\Support\Editorial\DeskRegistrations`), che usano anche i widget del pannello: riquadro del sito e riquadro del telefono sono la stessa riga. Chi vede quale sezione lo dice `DeskSummaryPayload`, con le regole dei `canView()` dei widget. «Social di oggi» (R9) e il calendario (R14) non sono in questa risposta.

## Redazione: utenti (R5, 26 novembre 2026)

La risorsa «Utenti» del pannello sotto `E/users` (R-US-01, 02, 04…06, 08…12), con le classi che usa anche il pannello: `UserDirectory` (schede, filtri, ricerca), `SyncUserRoles` (ex salvataggio di `RoleField`), `UpdateUserByStaff` (ex `EditUser`: credenziali, revoca di token e dispositivi, registro), `CommunityModeration`, `WhatsappVerification`, `WhatsappHistory`. `RegistrationState` è passata da `App\Filament\Admin\Resources\Users` ad `App\Enums`. Restano solo sul sito (decisioni 4, 8, 9): esportazione dell'elenco (R-US-03), impersonificazione (R-US-07, R-WB-02), eliminazione definitiva, conferimento e revoca di admin e super admin, email e password di un'altra persona.

### Rotte (tutte con lo sblocco delle 12 ore; «password» = `RequireRecentPassword`, 423 `PASSWORD_REQUIRED` oltre i 15 minuti)

| Metodo e percorso | Chi | Note |
|---|---|---|
| `GET E/users` | redazione (`UserPolicy::viewAny`) | convenzione degli elenchi: `tab` (`all`, `pending`, `admins`, `staff`, `owners`, `editors`, `users`, `unlinked`), `filter[registration\|city\|venue_city\|venue\|missing_city\|role\|email_verified\|whatsapp_verified\|suspended\|trashed]` (sì/no con `1`/`0`; `trashed` = `with`\|`only`, senza: niente cestinati), `q` (nome, email, locale, nome utente), `sort` (`name`, `created_at`, `city`, `whatsapp_verified_at`, `community_suspended_at`, `last_active_at`, con `-` discendente; predefinito il più recente). `meta.tabs` con i conteggi di tutte le schede |
| `GET E/users/options` | redazione | `registration_states`, `roles`, `assignable_roles` (dall'app: fino a `moderator`), `cities`, `venues`, `timezones`, `locales`, `default_timezone`, `default_locale` |
| `POST E/users` | admin, super admin (`create`), password | `name`, `email`, `password` (`Password::defaults()`), `city_id`, `timezone`, `locale`, `roles[]` fra quelli assegnabili dall'app (altrimenti 422 su `roles.N`; 422 su `roles` se insieme a un ruolo di staff c'è `venue_owner` o `venue_editor`). 201 con la scheda |
| `GET E/users/{user}` | redazione | anche nel cestino. La riga più `form{city_id, name, email, timezone, locale, roles}`, `whatsapp_masked` (ultime quattro cifre), `assignable_roles` |
| `PATCH E/users/{user}` | `UserPolicy::update` | `city_id`, `name`, `timezone`, `locale`; `email` e `password` rifiutate (422). La data di conferma dell'email non si salva (vedi sotto) |
| `PUT E/users/{user}/credentials` | solo la propria scheda, password | `email`, `password` facoltativa. Cancella tutti i token (anche quello della richiesta) e i dispositivi; `meta.signed_out`. Un'email nuova va riconfermata (`email_verified_at` vuoto, collegamento di verifica al nuovo indirizzo) e il vecchio indirizzo riceve un avviso (`EmailAddressChanged`) |
| `PUT E/users/{user}/roles` | `update` + `users.manage`, password | `roles[]` fra gli assegnabili dall'app; admin e super admin già presenti restano. Dall'app `venue_owner` e `venue_editor` non si danno a chi è o diventa staff (422 su `roles`; chi li ha già li tiene). `ruoli_cambiati` nel registro solo se cambia qualcosa |
| `DELETE E/users/{user}` | `UserPolicy::delete`, password | nel cestino; mai se stessi né un super admin se non lo si è |
| `POST E/users/{user}/restore` | `UserPolicy::restore` | |
| `POST E/users/{user}/community/suspend` | `community.manage`, rango più alto, password | motivo predefinito dell'azione, come il pannello |
| `POST E/users/{user}/community/unsuspend` | `community.manage` | anche verso chi sta sopra, come il pannello |
| `POST E/users/{user}/community/handle` | `community.manage`, rango più alto | `handle` (max 40, regole del modulo del profilo), `reason` (5–1000) |
| `DELETE E/users/{user}/whatsapp` | admin, super admin | 404 se non c'è una verifica |
| `GET E/users/{user}/whatsapp/history` | redazione | ultimi 30 tentativi: `created_at`, `phone_masked`, `status` (`used`, `pending`, `sent`, `failed`), `status_label`, `attempts`, `expires_at` |

Ogni riga ha `abilities{update, roles, credentials, destroy, restore, suspend, unsuspend, handle, whatsapp_revoke, whatsapp_history}`. Le risposte delle scritture sono la scheda aggiornata con `meta.message`. Tutti i registri (`ruoli_cambiati`, `email_cambiata_da_staff`, `password_impostata_da_staff`, `utente_eliminato`, `utente_ripristinato`, `community`) portano `canale: app`.

### Differenze dal pannello da sapere

- Nel pannello il campo «Email verificata il» del modulo non salva (non è in `$fillable` di `User`): l'API non lo accetta e l'app lo mostra in sola lettura.
- Nel pannello il filtro «Ruoli» non trova nessuno (le opzioni hanno come chiave il nome del ruolo, il filtro confronta l'id): l'API filtra per nome.
- Nel pannello non c'è un pulsante «Ripristina» (solo il filtro dei cestinati): l'API e l'app lo offrono, con la stessa policy dell'eliminazione.

## Redazione: moderazione dei contenuti (R6, 26 novembre 2026)

Le tre risorse del gruppo «Moderazione» del pannello `/admin` (segnalazioni, recensioni, commenti agli eventi) sotto `/v1/editorial/…`, con la porta, lo sblocco e la convenzione degli elenchi delle fondamenta (`page`, `per_page` fino a 50, `q`, `filter[chiave]`, `sort` `campo|-campo`, `meta{current_page, last_page, per_page, total}`, `abilities{}` su ogni riga). Dodici operazioni nuove. Il pannello vede tutte le città in queste pagine, e così l'API: `?city=` non filtra. Le scritture passano dalle stesse azioni del pannello: `App\Actions\Moderation\ResolveReport` (estratta da `EditReport`), `CatalogReviews::moderate` e `reply`, `ModerateComment`. La descrizione del soggetto di una segnalazione è in `App\Support\Moderation\ReportSubject`, usata anche dalla colonna del pannello.

### Segnalazioni (tutta la redazione: `reports.view`, `reports.resolve`)

- `GET /v1/editorial/reports`: filtri `filter[status]` (`ReportStatus`), `filter[reason]` (`ReportReason`), `filter[open]=1` (da esaminare o in esame); `sort=created_at|-created_at` (predefinito dalla più recente); niente ricerca, come la tabella del pannello. Riga: `id, created_at, reason, reason_label, status, status_label, subject{type, id, label, community}, note, resolution_note, reviewed_by (nome), reviewed_at, abilities{resolve, destroy}`. Contatto e indirizzo IP di chi segnala non escono, come nel pannello. `subject.community` vero per profili, post e commenti della community: l'app offre «Modera il soggetto», che apre l'area Community.
- `GET /v1/editorial/reports/{report}`: la stessa riga.
- `PATCH /v1/editorial/reports/{report}` `{status, resolution_note?}`: l'esito; `reviewed_by` e `reviewed_at` li scrive il server (`ResolveReport`), qualunque cosa arrivi nel corpo. Risponde con la riga e `meta.message`.
- `DELETE /v1/editorial/reports/{report}`: **pericolosa** (password recente, 423 `PASSWORD_REQUIRED`). Elimina la riga per sempre, come «Elimina» del pannello: il modello non ha il cestino. Resta la riga `segnalazione_eliminata` nel registro di sicurezza (id, autore, oggetto, motivo, stato; non il testo).

### Recensioni (solo admin e super admin: `CatalogReviewPolicy`; il moderatore riceve 403)

- `GET /v1/editorial/reviews`: filtri `filter[type]` (`venue|organizer`) e `filter[status]` (`pending|approved|rejected`), `q` sul nome dell'autore, `sort=created_at|-created_at` (predefinito: ultime toccate, `updated_at`). Riga: `id, subject{type, type_label, id, name}, author, rating, body, status, status_label, created_at, moderated_by, moderated_at, moderation_note, revision, reply{body, replied_at}|null, abilities{approve, reject, remove_reply}`.
- `POST /v1/editorial/reviews/{review}/moderate` `{status: approved|rejected, revision, note?}`: nota da 5 a 1000 caratteri, obbligatoria per rifiutare. Revisione diversa da quella attuale: 422 su `revision` (qualcuno ha deciso nel frattempo). Avvisa l'autore e scrive nel registro dei passaggi (`catalog_review_moderated`), come il pannello.
- `DELETE /v1/editorial/reviews/{review}/reply`: toglie la risposta del gestore (`catalog_review_reply_removed` nel registro); senza risposta non fa niente. Non è pericolosa: nel pannello chiede solo una conferma.

### Commenti agli eventi (tutta la redazione; eliminare solo admin e super admin)

- `GET /v1/editorial/comments`: `filter[status]` (`published|hidden`), `q` su titolo dell'evento, nome dell'autore e testo, `sort=reactions_count|-reactions_count` (predefinito dal più recente). Riga: `id, event{id, title, slug, poster}|null, author, body, is_reply, status, status_label, created_at, reactions_count, moderated_by, moderated_at, moderation_note, revision, abilities{show, hide, restore, destroy}`.
- `GET /v1/editorial/comments/{comment}`: «Dettagli», la stessa riga (policy `hide`, come il pannello).
- `POST /v1/editorial/comments/{comment}/hide` `{revision, note?}` (nota fino a 1000 caratteri) e `POST …/restore` `{revision}`: con `ModerateComment`. Un commento già nello stato di arrivo o una revisione vecchia: 422 su `revision`.
- `DELETE /v1/editorial/comments/{comment}`: **pericolosa** (password recente). Elimina il commento e le sue risposte (`EventCommentPolicy::delete`: admin, super admin, l'autore sul proprio). Resta la riga `commento_eliminato` nel registro di sicurezza (id, autore, evento, numero di risposte; non il testo).

Limiti di frequenza per persona: letture 60/min (`editorial-reports`, `editorial-reports-show`, `editorial-reviews`, `editorial-comments`, `editorial-comments-show`), decisioni 30/min, eliminazioni 20/min. Errori: 404 a chi non è della redazione o per un id che non esiste, 403 dove la policy dice no, 422 con `error.fields`, 423 per sblocco e password.

## Redazione: locali, richieste, organizzatori (R4, 26 novembre 2026)

Il gruppo «Luoghi» del pannello `/admin` nell'app: **41 operazioni** sotto `E` = `/v1/editorial` (rotte in `routes/editorial/venues.php`, controller `VenueController`, `VenueMediaController`, `VenueMemberController`, `ApplicationController`, `OrganizerController`). Tutte chiedono lo sblocco delle 12 ore; quelle segnate «password» anche la password scritta negli ultimi 15 minuti (423 `PASSWORD_REQUIRED`). `{venue}`, `{application}`, `{organizer}` sono sempre id numerici, mai lo slug. La città di lavoro non filtra: come il pannello, si vedono i locali, le richieste e gli organizzatori di tutte le città. Ogni elenco segue la convenzione della redazione (`page`, `per_page` fino a 50, `q`, `tab`, `filter[…]`, `sort`, `meta.tabs`) e ogni riga porta `abilities`.

La logica che stava nel pannello ora è condivisa: `ModerateVenueAction::canApprove|canReject|canSuspend` (quando una decisione ha senso), `ReviewVenueApplication` (approva e rifiuta sotto lock, avviso facoltativo, contatore del menu), `EditorialApplications` (coda, doppioni probabili), `EditorialVenueMembers` (persone del locale con il registro di sicurezza), `App\Support\ActivityHistory` (cronologia e «Ripristina», anche per gli eventi), `VenueProfileRequest::fieldRules|checkTogether|normalize` (le regole della scheda pubblica, le stesse del referente), `VenueApplicationImage`, `VenueCover`. Il pannello chiama le stesse classi.

### Locali

- `GET E/venues` (`venues.view`, tutta la redazione): `tab` `all|pending|approved|suspended` con i conteggi; `filter[city]` (id), `filter[type]`, `filter[status]`, `filter[is_verified]` e `filter[auto_publish]` (`1`/`0`), `filter[inactive]=1` (approvati senza date negli ultimi giorni, la stessa regola del riepilogo); `q` su nome e comune; `sort` `name` (predefinito), `municipality`, `events_count`, con `-` per il verso opposto. Riga: `id, name, slug, municipality, subtitle, type{value,label}, status{value,label}, is_verified, auto_publish, city{id,name}, events_count, cover_url, abilities{view, update, approve, reject, suspend, verify, ticketing, members, destroy}`. «Approva» non c'è su un locale approvato, «Rifiuta» non su uno rifiutato, «Sospendi» solo su uno approvato.
- `GET E/venues/export`: lo stesso elenco con gli stessi criteri in un foglio Excel (nome, comune e tipo, stato, eventi, verificato), `Cache-Control: private, no-store`. 10 al minuto. Come il pannello, non chiede un permesso in più di `venues.view`. Al massimo 5.000 righe, come gli eventi.
- `GET E/venues/choices` (`VenuePolicy::create`): città, comuni per città, centro di ogni città (`centers`, per «Centra sulla città»), quartieri, tipi, stati, piani, indicizzazione.
- `POST E/venues/locate` `{address, municipality}` (chi crea o modifica i locali): «Trova» della scheda Dove, lo stesso geocodificatore del pannello; `{lat, lng, message}` o 422 su `address` se l'indirizzo non si trova. 20 al minuto.
- `POST E/venues` (`VenuePolicy::create`, admin e super admin): nome, `city_id`, `type`, `address`, `municipality` (fra quelli della provincia della città), `zone` (obbligatorio per il comune di Padova), `province_code` (fino a 4), `lat`, `lng` obbligatori; il resto facoltativo. Nasce in bozza, piano gratuito. 201 con il modulo.
- `GET E/venues/{venue}` (`VenuePolicy::view`): la scheda in sola lettura con testata, informazioni, descrizione, `ticketing_enabled`, `site_url` (solo se approvato), i prossimi 8 eventi e chi lo gestisce.
- `GET E/venues/{venue}/form` (`VenuePolicy::update`): la scheda pubblica nella forma di `GET /management/venues/{venue}/profile`, più `editorial{name, slug, city_id, type, province_code, status, is_verified, auto_publish, is_nonprofit, plan, rejection_reason, ticketing_enabled, seo_indexing, is_demo, can_ticketing}`, `editorial_choices` e `profile_options` (gli elenchi di `GET /management/options`).
- `PATCH E/venues/{venue}` (`VenuePolicy::update`): per sezione, si convalidano e si salvano solo le chiavi mandate. Campi della scheda pubblica con le regole e il salvataggio del profilo del referente (`UpdateVenueProfile`); in più quelli della redazione: `name`, `slug` (unico; vuoto lo ricava dal nome), `city_id`, `type`, `province_code`, `status`, `is_verified`, `auto_publish`, `is_nonprofit`, `plan`, `rejection_reason`, `ticketing_enabled` (solo admin e super admin, come il campo del pannello), `seo_indexing` (`automatic|excluded`), `is_demo`. Indirizzo e coordinate, se mandati, sono obbligatori come nel modulo del pannello. Risponde con il modulo. `status` non si salva da qui (si ignora): lo cambiano solo `approve`, `reject` e `suspend`.
- `POST E/venues/{venue}/approve`, `/reject` (`reason` obbligatorio), `/suspend` (`reason`, password), `/verify` (`verified` sì/no; senza, inverte): `VenuePolicy::moderate` (moderatore compreso). Avvisano chi gestisce il locale (`VenueModerated`). Un'azione fuori posto risponde 409. Risposta `{message, venue}`.
- `PUT E/venues/{venue}/ticketing` `{enabled}`: `VenuePolicy::update` e ruolo admin o super admin.
- `DELETE E/venues/{venue}` (password): `VenuePolicy::delete`, nel cestino. Eliminazione definitiva e ripristino restano sul sito (decisione 4; il pannello non ha un filtro dei cestinati per i locali).
- `GET E/venues/{venue}/events`: gli eventi del locale, `filter[status]`, `q` sul titolo, i più recenti prima; riga con `occurrences_count`, `published_at`, `poster`, `abilities{view, update}`.
- `GET E/venues/{venue}/activity`: la cronologia (`created_at`, `event{value,label}`, `causer`, `changes`, `restorable_fields`, `abilities.restore`). `POST E/venues/{venue}/activity/{activity}/restore` (`VenuePolicy::update`): rimette i contenuti di prima (non stato, verifica, piano); 409 se non c'è niente da rimettere.
- Immagini (`VenuePolicy::update`), stessi corpi e risposte di `management/venues/{venue}/media`: `GET …/media`, `POST|DELETE …/media/{logo|cover}`, `POST …/media/gallery` (201), `DELETE …/media/gallery/{media}`, `PUT …/media/gallery/order`. 20 scritture al minuto.
- Persone (`VenuePolicy::manageCollaborators`, admin e super admin): `GET …/members` (`members[]{id, name, email, role, invited_at, accepted_at, pending}`, `roles[]`), `GET …/members/candidates?q=` (email confermata, non ancora del locale, al massimo 50), `POST …/members/invite` `{name, email, role}` (predefinito referente; 409 a chi è già referente o ha già quel ruolo; 10 al minuto), `POST …/members` `{user_id, role}` (entra subito), `PATCH …/members/{user}` `{role, accepted_at}` (`null` = invito ancora da accettare; confermarlo assegna il ruolo globale), `DELETE …/members/{user}` (scollega: non è un'eliminazione e non chiede la password). Ogni accesso dato, cambiato o tolto va nel registro di sicurezza (`accesso_locale_concesso|modificato|revocato`). Invito, aggiunta e modifica con `role: owner` chiedono la password recente (423 `PASSWORD_REQUIRED`); con `editor` no.

### Richieste di iscrizione

- `GET E/applications` (tutta la redazione): `tab` `pending` (predefinita) `|approved|rejected|all` con i conteggi; `filter[kind]` (`venue|claim|organizer`), `filter[type]`; `q` su nome, contatto ed email; `sort` `created_at` (predefinito, la più recente prima) o `venue_name`. Riga con `possible_duplicate` (solo in attesa) e `abilities{approve, reject, notes, destroy}`. Le richieste che aspettano la conferma dell'email non ci sono mai (404 anche per id).
- `GET E/applications/{application}`: esito, tipo, date, chi ha deciso, soggetto collegato, doppioni probabili (`kind` `application|venue|organizer`, `id`, `label`), dati del locale o dell'organizzazione, contatti pubblici (non per le rivendicazioni), `has_image`, contatto, account, note.
- `GET E/applications/{application}/image`: l'immagine dal disco privato, come la pagina del sito.
- `POST …/approve` (`VenueApplicationPolicy::approve`: `update` e mai sulla propria richiesta, cioè mandata dal proprio account o con la propria email come contatto: 403): crea il locale (o l'organizzatore, o consegna il locale rivendicato); 422 su `status` se già approvata o non in coda. Risposta `{message, detail, created{kind, id, name}, application}`.
- `POST …/reject` `{notes (obbligatorio, 5000), notify, message (2000)}`: solo in attesa, 409 se qualcuno ha già deciso; l'avviso va solo all'account che ha mandato la richiesta.
- `PATCH …/notes` `{notes}`: le note interne; non cambia lo stato e non firma la richiesta.
- `DELETE E/applications/{application}` (password): `VenueApplicationPolicy::delete`.

### Organizzatori

- `GET E/organizers` (`OrganizerPolicy`: admin e super admin; il moderatore riceve 403): per nome, con `choices{cities, contact_modes}`. `GET E/organizers/people?q=`: le persone per responsabile e collaboratori (al massimo 50).
- `GET E/organizers/{organizer}`, `POST E/organizers` (`name`, `city_id`, `owner_id` obbligatori; nasce non attivo), `PATCH E/organizers/{organizer}`: `name`, `city_id`, `owner_id`, `users[]` (collaboratori), `description` (20000), `website`, `email`, `contact_mode`, `contact_email` (obbligatorio con i messaggi accesi), `is_active`. Nessuna eliminazione. Ogni cambio di responsabile o di collaboratori scrive `accesso_organizzatore_concesso|revocato` (`ruolo: responsabile|collaboratore`, `organizer_id`) nel registro di sicurezza, come il pannello.

### Permessi

`GET E/desk` ha una chiave in più, `organizers_manage` (`OrganizerPolicy::viewAny`), in fondo all'elenco: ora le chiavi sono 35.

## Redazione: eventi e proposte (R2, 26 novembre 2026)

L'elenco eventi di `/admin/events`, la scheda in sola lettura, le decisioni della redazione, la cronologia delle modifiche e le proposte dal pubblico di `/admin/event-submissions`, sotto `E` = `/v1/editorial` (porta, sblocco e `canale: app` come nelle fondamenta). 22 operazioni in `routes/editorial/events.php`. Le regole non sono riscritte: pannello e controller chiamano gli stessi servizi e azioni, cioè `App\Services\Editorial\EditorialEvents` (query, schede, dodici filtri, ricerca, ordinamento, righe con `abilities`, scheda), `App\Services\Editorial\ActivityHistory` (racconto di una riga del registro e «Ripristina i valori di prima», con gli stessi campi ripristinabili; la usa anche la sezione «Modifiche» di locali e abilitazioni), `App\Actions\RejectEventSubmission`, e le azioni di sempre (`PublishEventAction`, `ModerateEventAction`, `DuplicateEventAction`, `MergeEventsAction`, `PublishEventSubmission`).

### Eventi

- `GET E/events`: righe `{id, slug, title, status{value,label}, source{value,label,imported}, poster, next_starts_at, last_starts_at, past, venue{id,name}, city{id,name,timezone}, category{id,name}, place, occurrences_count, published_at, scheduled_publish_at, is_featured, featured_until, trashed, abilities{view, edit, publish, unpublish, reject, cancel, feature, duplicate, merge, destroy, restore, preview, site}}`. `tab`: `pending`, `published`, `draft`, `cancelled`. `filter[…]`: `status`, `city`, `venue`, `category`, `source`, `is_featured` (`1`/`0`), `published_today`, `published_this_week`, `missing_poster`, `incomplete`, `possible_duplicates` (`1`), `trashed` (`with`, `only`; senza: solo i non cestinati). `q` cerca nel titolo e nel nome del locale; `sort` `title`, `published_at`, `created_at` (con `-`; senza, i più recenti prima). Come il pannello non restringe alla città: `?city=` dice solo quando è «oggi» (prossima data, pubblicati oggi e in settimana, doppioni). `meta.tabs{all, pending, published, draft, cancelled}`. Permesso: redazione. Limite 120 al minuto (`editorial-events`).
- `GET E/events/filters`: le scelte dei filtri `{cities, categories, venues, sources, statuses}` come `{value, label}`.
- `GET E/events/export`: il foglio Excel delle righe dell'elenco con gli stessi criteri (al massimo 5.000), colonne titolo, quando e dove, stato, date, pubblicazione. Come il pannello, a tutta la redazione. Limite 10 al minuto (`editorial-events-export`).
- `GET E/events/{event}`: la scheda, anche di un evento cestinato. In più della riga: `organizer`, `price_type`, `price_min`, `price_max`, `tags[]`, `description`, `description_text`, `dates{upcoming[], past[]}` (come nella gestione), `editorial{source, verification, created_by, created_at, updated_at, published_at, scheduled_publish_at, editorial_score, rejection_reason}`, `cancel_impact{upcoming_dates, saved_by}`, `abilities.sponsor`, `abilities.activity`, `links{statistics, social, sponsor, site}` (i primi tre sono percorsi del pannello da aprire sul sito, `null` a chi non li vede; `site` l'indirizzo pubblico). Permesso `EventPolicy::view`.
- `GET E/events/{event}/preview`: la forma di `GET /events/{slug}` anche per una bozza (`EventPolicy::view`).
- `POST E/events/{event}/publish` (`EventPolicy::publish`, anche al moderatore come il pannello; un evento senza date: 422 su `event`), `/unpublish` (`publish`), `/reject` con `reason` obbligatorio (`moderate`), `/feature` con `featured` e `until` facoltativo ISO 8601 (`feature`, solo admin e super admin), `/duplicate` (`create` sul locale: la copia nasce bozza, risposta 201 con la sua scheda), `/restore` (`restore`, solo da cestinato). Un'azione che lo stato non permette (pubblicare un pubblicato) risponde 409 `CONFLICT`. Le decisioni su un evento cestinato rispondono 404. Risposta: `{data: <scheda>, message}`.
- Pericolose, con la password recente (423 `PASSWORD_REQUIRED`): `POST E/events/{event}/cancel` con `note` facoltativa fino a 255 caratteri (`moderate`; annulla le date future), `POST E/events/{event}/merge` con `absorbed` (`delete` su tutti e due: 403 se manca sull'assorbito; un evento della stessa città, non cestinato e diverso: altrimenti 422; risposta con `report{dates_merged, dates_moved, saves, bookings}` e `detail`), `DELETE E/events/{event}` (`delete`, nel cestino), `POST E/events/bulk-delete` con `ids[]` fino a 50 (ognuno con il suo `delete`; risposta `{deleted[], skipped}`). L'eliminazione definitiva non c'è, né nell'app né nel pannello.
- `GET E/events/{event}/merge-candidates?q=`: i primi venti eventi della stessa città per titolo, `{id, title, slug, label}` (`delete`).
- `GET E/events/{event}/activity`: la cronologia, dalla più recente, `{id, created_at, event{value,label}, causer, changes, restorable_fields[], abilities{restore}}`; `POST E/events/{event}/activity/{activity}/restore` rimette i valori di prima dei soli campi di contenuto (409 se non c'è niente da rimettere). Tutte e due `EventPolicy::update`, come la sezione «Modifiche» che sta nella modifica dell'evento.

### Proposte dal pubblico

- `GET E/submissions`: `{id, title, contact_name, starts_at_hint, venue_hint, city{id,name,timezone}, status{value,label}, created_at, event_id, abilities{publish, reject, open_event}}`, `filter[status]` (`pending`, `approved`, `rejected`), `q` nel titolo. Permesso `EventSubmissionPolicy::viewAny` (redazione con `events.moderate`).
- `GET E/submissions/{submission}`: in più `contact_email`, `raw_text`, `raw_text_html`, `notes`, `reviewed_at`, `timezone`, `limits{title, raw_text, notes}` e, se in attesa, `form{title, raw_text, venue_id, category_id, starts_at_hint, ends_at, price_type (unknown), price_min}` e `choices{venues (approvati della città della proposta), categories (attive), price_types}`.
- `POST E/submissions/{submission}/publish` con i campi del modulo: le date ISO 8601 con il fuso (o senza fuso nell'ora della città). Chiede `events.publish` e `EventPolicy::create` oltre a `update` (solo admin e super admin; il moderatore riceve 403, come il pulsante che nel pannello non vede). Errori 422 sui campi (`venue_id`, `ends_at`…); 409 su una proposta già rifiutata. Risposta 201 `{data{event_id, submission}, message}`.
- `POST E/submissions/{submission}/reject` con `notes` obbligatorio fino a 2.000 caratteri (`update`). 409 se non è più in attesa.

## Redazione: configurazione tecnica (R13, 26 novembre 2026)

Le pagine di configurazione del pannello `/admin` (gruppo «Sistema», più la Newsletter) sotto `/v1/editorial/…`, con la porta e lo sblocco delle fondamenta. Diciannove operazioni nuove. Decisione 5 del piano: l'app legge tutto e fa le azioni operative (svuota e verifica la cache, messaggio di prova della posta); scrive testi delle email, newsletter, registro dei cookie e indirizzi cambiati; SMTP, script di consenso, impostazioni della cache e credenziali di Redis si scrivono solo dal sito. Le password (SMTP, Redis) non escono mai: al loro posto `has_password`. La città non filtra: sono impostazioni di tutto il sito. Nessuna rotta è pericolosa (il pannello non elimina niente in queste pagine). Le regole stanno in classi condivise con il pannello: `App\Services\Settings\NotificationTextEditor` (estratta da `NotificationTexts`), `NewsletterConfiguration` (da `Newsletter`), `MailConfigurationTester` (da `MailConfiguration::inviaProva`); le risposte in `App\Services\Editorial\EditorialSettings`.

### Testi delle email (admin e super admin; i testi della newsletter solo il super admin)

- `GET /v1/editorial/notification-texts`: `data{groups[{key, label, texts[{key, label, default, value, modified, variables[{name, label}], preview, rows, revision}]}], newsletter_included}`. `label` è il pezzo dell'email («Oggetto»), `preview` l'esempio con i dati dimostrativi del pannello.
- `POST /v1/editorial/notification-texts/preview` `{key, value}`: `data{key, preview, unknown[]}` (i segnaposti che l'originale non ha). Non salva niente.
- `PUT /v1/editorial/notification-texts/{key}` `{value, revision}`: un testo per volta, con la revisione letta (422 su `revision` se nel frattempo è cambiato). Vuoto o uguale all'originale cancella la riscrittura, come il pannello; un segnaposto sconosciuto dà 422 su `value`. Chiave che non esiste o riservata: 404. Risponde con il testo e `meta.message`.
- `POST /v1/editorial/notification-texts/reset`: «Riporta tutto all'originale» per le chiavi che la persona vede (all'admin restano quelle della newsletter). Solo conferma, come il pannello.

### Posta (solo super admin)

- `GET /v1/editorial/mail`: `enabled, active, host, port, encryption, encryption_label, username, has_password, from_address, from_name, verified, verified_at, stale_check, status{custom, title, detail}, recipient`.
- `POST /v1/editorial/mail/test`: il messaggio di prova all'indirizzo di chi lo chiede, con la configurazione **salvata** (il pannello prova quella scritta nel modulo). Se parte, l'impronta viene registrata e sul sito si può accendere la configurazione. 200 in tutti e due i casi: `data{result{sent, title, detail}, mail}`; `detail` porta il messaggio del server di posta per esteso. Account senza email: 422 su `recipient`. 5 al minuto.

### Newsletter (solo super admin)

- `GET /v1/editorial/newsletter`: `enabled, weekday, time, max_items, texts{subject|heading|line|action: {value, default, max, variables}}, weekdays[{value, label}], limits{max_items_min, max_items_max}`.
- `PUT /v1/editorial/newsletter` `{enabled, weekday 1–7, time HH:MM, max_items 1–30, subject ≤200, heading ≤200, line ≤2000, action ≤120}`: le regole del modulo del pannello; i testi vuoti tornano all'originale, un segnaposto sconosciuto dà 422 sul campo.

### Script di consenso (admin e super admin, sola lettura)

- `GET /v1/editorial/consent-scripts` (`q` sul nome, ordine di esecuzione): riga `id, name, category, category_label, enabled, sort_order, src, has_code, abilities{}`; `meta.categories`.
- `GET /v1/editorial/consent-scripts/{script}`: la riga con `code`. Creare, modificare, accendere ed eliminare: solo dal sito.

### Cookie (admin e super admin)

- `GET /v1/editorial/cookies` (`tab` = finalità, predefinita `necessary`; `q` su nome, fornitore e scopo; ordine `sort_order`): riga `id, category, category_label, name, provider|null («questo sito»), purpose, duration, sort_order, abilities{reorder}`; `meta.tabs{necessary, statistics, marketing}`, `meta.tab`, `meta.categories[{value, label, description}]`.
- `POST /v1/editorial/cookies` `{category, name ≤255, provider?, purpose ≤255, duration? ≤64}`: 201. Nome già presente nella finalità: 422 su `name` (il vincolo unico del database).
- `POST /v1/editorial/cookies/order` `{category, ids[]}`: il nuovo ordine della scheda (1, 2, …), come il trascinamento del pannello; un id di un'altra finalità: 422. Il pannello non modifica né elimina queste righe, e nemmeno l'app.

### Indirizzi cambiati (`RedirectPolicy`: `pages.manage`)

- `GET /v1/editorial/redirects` (`q` sui due indirizzi; `sort=hits|-hits|last_hit_at|-last_hit_at`, predefinito dal passaggio più recente): riga `id, from_path, to_path, city{id, name}|null, status, status_label, is_wildcard, hits, last_hit_at, abilities{}`; `meta.abilities.create`.
- `GET /v1/editorial/redirects/options`: `cities[{id, name}]` (tutte, come il pannello), `statuses[{value, label}]`, `default_status`.
- `POST /v1/editorial/redirects` `{from_path ≤191, to_path ≤191 (percorso interno, `InternalRedirectTarget`), city_id?, status 301|302, is_wildcard?}`: 201. Lo stesso indirizzo nella stessa città: 422 su `from_path`. Il pannello non modifica né elimina le righe (la policy lo permetterebbe), e nemmeno l'app.

### Cache e prestazioni (admin e super admin)

- `GET /v1/editorial/cache`: `managed, enabled, store, store_label, ttl_minutes, redis{host, port, database, username, tls, has_password}, environment{redis_extension, litespeed, server}`.
- `POST /v1/editorial/cache/clear`: «Pulisci tutte le cache del sito» (`FrontendCacheConfiguration::clear`, non tocca sessioni, prenotazioni, lock). 10 al minuto.
- `POST /v1/editorial/cache/verify`: verifica Redis con la configurazione salvata, senza attivarlo: `data{ok}` e `meta.message`.

Limiti di frequenza per persona: letture 60/min, anteprima 120/min, scritture 20–30/min, ripristino dei testi 10/min. Errori: 404 a chi non è della redazione, 403 al ruolo che il pannello non fa entrare (moderatore ovunque; admin su posta e newsletter), 422 con `error.fields`, 423 per lo sblocco.

## Redazione: social (R9, 26 novembre 2026)

La pagina «Social» del pannello `/admin` (lo studio della redazione), lo storico e la pubblicazione sui canali della città, la pagina «Account e pubblicazione social» e il riquadro «Social di oggi» del riepilogo, sotto `E` = `/v1/editorial` (porta, sblocco delle 12 ore e convenzioni delle fondamenta). **23 operazioni** in `routes/editorial/social.php`, controller `SocialStudioController`, `SocialPublicationController`, `SocialSettingsController` di `App\Http\Controllers\Api\V1\Editorial`. Tutte per admin e super admin (`social_use`, come `Social::canAccess`): il moderatore riceve 403, chi non è della redazione 404. Con R14 «Pubblica sui canali collegati», «Programma» e «Riprova» sono **pericolose** (decisione 4): mettono un post visibile a tutti sui canali della città e chiedono la password scritta negli ultimi 15 minuti (`RequireRecentPassword`, 423 `PASSWORD_REQUIRED`), oltre alla conferma nell'app come il `wire:confirm` della pagina; riprogrammare e annullare no.

Una regola sola per pannello e app: lo studio usa `SocialStudioDesk` (già di P10), con in più `today()` e `prepareToday()` per «Social di oggi»; la pubblicazione e lo storico sono in `App\Services\Social\EditorialPublishing` (estratto da `InteractsWithSocialStudio`); le impostazioni in `App\Services\Social\SocialSettingsDesk` (estratto da `SocialSettings`); le forme di card e pacchetti in `App\Services\Social\SocialStudioPayload`, usato anche dallo studio del locale. Lo studio della redazione è quello del locale senza locale (tutte le serate della città) e senza eventi dimostrativi, come la pagina.

### Studio e «Social di oggi» (città di lavoro: `?city=`, anche non attiva)

| Metodo e percorso | Note |
|---|---|
| `GET E/social/catalog` | come `GET V/social/catalog` di P10 (`period`, `from`, `until`, `search`, `limit`, `event`), più `meta.city{id, slug, name}`. Con `event` (`update` sull'evento, 404 se non esiste) lo studio si restringe a lui e la città diventa la sua, come la pagina aperta dalla scheda dell'evento |
| `GET E/social/calendar` | come P10: `month`, `date`, `search`, `event` |
| `GET E/social/day` | come P10: senza `date` il giorno da cui la pagina si apre; `meta.city` |
| `GET E/social/today` | R-DB-10: le serate di oggi della città (card), `meta{date, label, timezone, city}` |
| `POST E/social/today` | «Scarica le grafiche di oggi»: il pacchetto verticale con lo stile predefinito e tutte le serate di oggi. 201 con il pacchetto (lo ZIP si scarica da `batches/{batch}/zip`); senza serate 422 su `generate` |
| `GET E/social/dates/{occurrence}/preview` | l'anteprima JPEG della grafica di una serata (`EventPolicy::update`), stesso secchio del sito (`social-preview`, 120 al minuto) |
| `GET E/social/batches` | gli ultimi dodici pacchetti della redazione nella città |
| `POST E/social/batches` | come P10 (`date`, `occurrences[]` da 1 a 100 del giorno e preparabili, altrimenti 403; `format`, `monochrome`, `show_address`, `show_price`, `image_fit`, `title` solo con una serata, `event`). 201 |
| `GET E/social/batches/{batch}` | il pacchetto come P10, più `city`, `captions[]` (il testo automatico di ogni parte del carosello, «Anteprima del testo automatico» della pagina) e `in_history` (già nello storico). Qualunque pacchetto, come `openBatch` della pagina (`SocialBatchPolicy::view`) |
| `GET E/social/batches/{batch}/images/{index}`, `…/zip` | il JPEG e lo ZIP, come P10 |

### Pubblicazione e storico (R-SO-03, R-SO-04)

| Metodo e percorso | Note |
|---|---|
| `POST E/social/batches/{batch}/publish` | «Pubblica sui canali collegati». `data[]` le righe dello storico del pacchetto, `meta.message`. Gli errori di `SocialPublisher::enqueue` (collegamento non verificato, storie, didascalia oltre 2.200 o 1.024 caratteri per Telegram, evento cambiato) sono 422 su `publish`; un errore del database o un record sparito non diventa un messaggio (`App\Support\Social\SocialErrorMessage`: 500 o 404 senza il testo tecnico) |
| `POST E/social/batches/{batch}/schedule` | `scheduled_at` `Y-m-dTH:i` nel fuso della città del pacchetto, nel futuro (422 su `scheduled_at`); un pacchetto già nello storico 422 («si cambia da lì»). 201 |
| `GET E/social/publications` | lo storico della città, dalla più recente, anche con il pacchetto tolto dalla pulizia; `per_page` predefinito 30 come la pagina (massimo 50), `filter[status]`. Riga `{id, created_at, platform, platform_label, part (da 1), status{value,label}, scheduled_at, timezone, error, batch_id, batch_gone, abilities{reschedule, cancel, retry}}`. `error` non porta mai il testo di un errore del database: uno salvato da una versione vecchia esce come «Non è stato possibile completare l'operazione», e il lavoro che pubblica (`PublishSocial`) non lo salva più |
| `PATCH E/social/publications/{publication}` | «Usa la nuova data e ora» (`scheduled_at`, fuso della città del collegamento). Solo se ancora programmata: altrimenti la riga com'è con `meta.changed` falso, come la pagina che non fa niente |
| `POST E/social/publications/{publication}/cancel` | «Annulla programmazione» |
| `POST E/social/publications/{publication}/retry` | «Riprova dopo aver controllato»: solo una non riuscita o incerta (422 altrimenti), con il pacchetto (422 su `publish` se è stato tolto) e con gli eventi ancora uguali (422 su `publish`) |

### Impostazioni (R-SO-05…07, R-WB-04)

| Metodo e percorso | Note |
|---|---|
| `GET E/social/settings` | `values{app_id, city_id, page_id, instagram_id, graph_version, facebook_enabled, instagram_enabled, telegram_chat_id, telegram_enabled, automatic, graphic_options{monochrome, show_address, show_price, image_fit}, publish_time, caption}`, `status{exists, app_secret_saved, access_token_saved, telegram_bot_token_saved, meta_verified_at, telegram_verified_at}`, `cities[]`, `tokens[]{token, label}`, `callback_url`, `limits`, `web_path`. I segreti non escono mai, solo se ci sono |
| `PUT E/social/settings` | i campi di `values` con le regole del modulo della pagina; segnaposti sconosciuti 422 su `caption`. `app_secret`, `access_token`, `telegram_bot_token` sono **rifiutati** (422, decisione 5: si scrivono dal sito). Un cambio dei dati Meta o Telegram toglie la verifica; con l'automatico acceso e nessun canale verificato l'automatico si spegne e `meta.warning` lo dice |
| `POST E/social/settings/verify` | «Verifica collegamento» sui valori salvati; non riuscita 422 su `verify` |
| `POST E/social/settings/telegram/verify` | «Verifica Telegram» senza mandare messaggi; non riuscita 422 su `telegram` con il motivo |
| `GET E/social/meta/connect` | decisione 14: `{url, callback_url, return_path}`, l'indirizzo del sito (`social.meta.connect`) da aprire nel browser con l'accesso al sito. Senza App ID e App Secret salvati 422 su `meta` |
| `POST E/social/meta/disconnect` | «Scollega Meta»: via il token della Pagina, la verifica e l'automatico |

Limiti di frequenza per persona: letture `editorial-social` 60/min, preparazione `editorial-social-generate` 10/min, pubblicazione `editorial-social-publish` 20/min, impostazioni `editorial-social-settings` 20/min, verifiche `editorial-social-verify` 10/min, anteprima `social-preview` (il secchio del sito). La pagina e l'API non scrivono nel registro attività per queste azioni (il modello `SocialConnection` non è registrato): l'app fa come il pannello.

## Redazione: sistema, import, invii, cron (R12, 26 novembre 2026)

Tre voci del gruppo «Sistema» del pannello `/admin` (sorgenti di import, notifiche programmate, cron e programmazioni) sotto `E` = `/v1/editorial`, con la porta, lo sblocco e la convenzione degli elenchi delle fondamenta. **Tredici operazioni** nuove (rotte in `routes/editorial/system.php`, controller `ImportSourceController`, `ScheduledNotificationController`, `ScheduledOperationsController`). La città di lavoro non filtra: come il pannello, si vedono le sorgenti e gli invii di tutte le città. Le regole sono quelle del pannello, nello stesso codice: `ImportSourcePolicy` e `ScheduledNotificationPolicy`, le regole del modulo in `App\Support\Import\ImportSourceRules` (usate anche dal modulo del pannello), l'anteprima in `App\Support\Import\ImportPreviewRows::forSource` (spostata da `App\Filament\Support`, la usa anche la finestra del pannello), il soggetto di un invio in `App\Support\Notifications\ScheduledNotificationSubject` (la usa anche la colonna del pannello).

### Sorgenti di import (admin e super admin: `import_sources.manage`; il moderatore riceve 403)

- `GET E/import-sources`: filtri `filter[type]` (`ImportSourceType`), `filter[active]` (`1` o `0`), `filter[failed]=1` («Import falliti»: attive e ferme su un errore, la stessa condizione del riquadro del riepilogo); `sort=last_run_at|-last_run_at` (predefinito dall'ultima eseguita); niente ricerca, come la tabella del pannello. Riga: `id, city{id,name}, venue{id,name}|null, type, type_label, url, has_credentials, mapping|null, default_category{id,name}|null, is_active, last_run_at, last_status, last_status_label, last_error, runnable, web_path, abilities{update, destroy, preview, run, runs}`. Le credenziali non escono mai, nemmeno cifrate: `has_credentials` dice solo se ci sono, `web_path` è la scheda del pannello dove si cambiano. `runnable` è falso per i tipi senza lettore (oggi tutti tranne ICS e CSV): niente anteprima né esecuzione, come i pulsanti del pannello.
- `GET E/import-sources/form`: le scelte del modulo `{types[{value,label,runnable}], cities[{id,name,slug,is_active}], categories[{id,name}], defaults{city_id,type,is_active}, limits{max_url_length, max_mapping_entries, max_mapping_key_length, max_mapping_value_length}}`. `GET E/import-sources/venues?q=`: i primi venti locali per nome, `{id, name, city}`.
- `POST E/import-sources` (201) e `PATCH E/import-sources/{source}` con `city_id`, `venue_id` (facoltativo), `type`, `default_category_id` (obbligatorio senza locale), `url` (fino a 1000 caratteri, verificato con `SafeImportUrl` come nel modulo), `mapping` (dizionario di chiavi testuali, valori testo, numero, sì/no o elenco di testi, al più 50 voci), `is_active`. La modifica è parziale: si mandano solo i campi che cambiano, e `{"is_active": false}` da solo è «disattiva». `credentials` è rifiutato con 422: le credenziali si scrivono dal sito (decisione 6). La policy viene prima dei campi: chi non può scrivere riceve 403.
- `DELETE E/import-sources/{source}`: **pericolosa** (password recente, 423 `PASSWORD_REQUIRED`). Elimina per sempre la sorgente e il suo storico, come «Elimina» del pannello; gli eventi già importati restano.
- `POST E/import-sources/{source}/preview`: le prime date che entrerebbero, `{rows[{when, title, where, notes[], cancelled}], error, timezone}`, con `when` già scritto nell'ora della città come nella finestra del pannello. Un calendario che non si legge risponde 200 con il motivo in `error`. Non scrive niente e non tocca `last_run_at`.
- `POST E/import-sources/{source}/run` (202): accoda `ImportSourceJob`, come «Esegui adesso». Per un tipo senza lettore, anteprima ed esecuzione rispondono 422 su `type`.
- `GET E/import-sources/{source}/runs`: lo storico, solo lettura, `filter[status]` (`ImportRunStatus`), `sort=created_at|-created_at` (predefinito dalla più recente). Riga: `id, created_at, status, status_label, created_count, updated_count, unchanged_count, excluded_count, cancelled_count, error_count, message`.

### Notifiche programmate (tutta la redazione: `notifications.view`; fermare: admin e super admin, `notifications.manage`)

- `GET E/scheduled-notifications`: filtri `filter[status]` (`NotificationStatus`), `filter[type]` (`NotificationType`), `filter[due]=1` («in scadenza»); `q` sull'email del destinatario, l'unica colonna cercabile della tabella; `sort=send_at|-send_at` (predefinito dal più lontano). Riga: `id, send_at, type, type_label, recipient (email), subject («Data · Concerto all'alba»), status, status_label, channel, channel_label, attempts, sent_at, reason (il motivo di un salto in parole, o l'errore), dedupe_key, abilities{cancel}`.
- `POST E/scheduled-notifications/{notification}/cancel`: ferma un invio ancora in attesa; la riga resta con lo stato «annullata» e torna nella risposta con `meta.message`. Uno già partito, saltato o fermato: 403 (la policy guarda lo stato). Non chiede la password recente: nel pannello chiede solo una conferma.

### Cron e programmazioni (admin e super admin, come `canAccess` della pagina; il moderatore riceve 403)

- `GET E/scheduled-operations`: la pagina di sola lettura del pannello come dati, da `SchedulerOverview`: `installed{readable, scheduler, worker}` (le righe trovate nel crontab), `cron{scheduler, worker}` (le due righe da installare), `queue`, `social[{city_id, city, telegram_verified, verified_at, instagram_enabled, automatic, publish_time}]`, `tasks[{name, explanation, command, expression, next_due_date, last_finished, last_failed, monitored}]` (in UTC). Aprirla non esegue niente.

### Opzioni

`GET E/options` ha tre elenchi in più: `notification_types`, `import_source_types`, `import_run_statuses`, con le etichette del sito.

Limiti di frequenza per persona: letture 60/min (`editorial-import-sources`, `-form`, `-venues`, `-show`, `-runs`, `editorial-scheduled-notifications`), creazione 20/min, modifica 30/min, eliminazione 20/min, anteprima ed esecuzione 10/min (leggono un calendario esterno), annullamento di un invio 30/min, cron 20/min. Errori: 404 a chi non è della redazione o per un id che non esiste, 403 dove la policy dice no, 422 con `error.fields`, 423 per sblocco e password.

### Differenze dal pannello da sapere

- Il modulo del pannello riverifica l'indirizzo a ogni salvataggio, anche quando non cambia: per una sorgente con un CSV caricato da un locale (`upload:…`), per come leggo il codice (la regola `url` del campo), il salvataggio dal pannello fallisce; non l'ho provato nel pannello. La modifica parziale dell'API verifica l'indirizzo solo se arriva, così attivare o disattivare quelle sorgenti dall'app funziona.
- La mappatura nel pannello è un campo chiave-valore di testi; dall'API accetto anche gli elenchi di testi (`exclude_keywords`), che è la forma che il lettore usa davvero, e rifiuto i dizionari annidati.
- «Esegui adesso» su una sorgente disattivata accoda il lavoro, che poi non legge niente (`ImportSourceJob` salta le sorgenti spente): è così anche nel pannello. L'app lo dice nella conferma.
- L'eliminazione in gruppo della tabella del pannello non c'è: nell'app si elimina una sorgente alla volta, con la password.

## Redazione: correzioni della revisione di sicurezza dell'ondata 3 (26 novembre 2026)

Nessuna rotta nuova. Cambiano i permessi di otto rotte, alcune risposte e il registro di sicurezza.

- **Password recente** (`RequireRecentPassword`, 423 `PASSWORD_REQUIRED` oltre i 15 minuti) anche su `PUT E/social/settings`, `POST E/social/meta/disconnect`, `PUT E/newsletter`, `PUT E/notification-texts/{key}`, `POST E/notification-texts/reset`, `POST E/import-sources`, `PATCH E/import-sources/{source}` e `POST E/import-sources/{source}/run`. Mettono qualcosa davanti a tutti (pubblicazione automatica, testo dei post, mail agli iscritti e testi che partono dall'indirizzo del sito, eventi importati che nascono pubblicati) o si rimediano solo dal sito (Meta). Il test di architettura le ha in `EDITORIAL_DANGEROUS_NAMES`.
- **Credenziali degli import e origine**: `PATCH E/import-sources/{source}` su una sorgente con credenziali risponde 422 su `url` («l'indirizzo di una sorgente con credenziali si cambia dal sito») se schema, host o porta cambiano; un percorso diverso sulla stessa origine va bene. Nel pannello, se l'origine cambia e il campo delle credenziali resta vuoto, le credenziali si cancellano con un avviso.
- **Indirizzi**: `url` con `utente:password@` è rifiutato (422, anche nel modulo del pannello). La riga di una sorgente ha `url_masked`: quando la sorgente ha credenziali o l'indirizzo apre un calendario privato (`private`, `secret`, `token` nel percorso o nella query, o un gettone di almeno 16 lettere e cifre), `url` è solo `schema://host/…`, e lo stesso vale per gli indirizzi dentro `last_error`, `runs[].message` e `error` dell'anteprima. L'indirizzo mascherato rimandato com'è in un `PATCH` non cambia niente; l'app lo mostra in sola lettura.
- **Anteprima**: 15 secondi in tutto (redirect compresi), connessione in 5, un solo redirect (`import.preview` in `config/import.php`); l'esecuzione in coda conserva i suoi tempi. Oltre al limite della rotta per persona c'è un tetto per tutto il sito, condiviso con il pannello (`ImportPreviewLimiter`: 12 al minuto, 10 per persona): oltre, 429 `RATE_LIMITED`.
- **Prova della posta**: `detail` porta il messaggio per esteso solo per un errore del trasporto SMTP; per ogni altro guasto un messaggio generico, e l'eccezione va nel registro degli errori. Lo stesso nella notifica del pannello.
- **Registro di sicurezza** (`activity_log`, `log_name = sicurezza`, `causer` = chi scrive, `properties.canale = app` dall'app): `social_impostazioni_cambiate`, `meta_scollegato`, `import_sorgente_creata|modificata|eliminata|eseguita`, `testi_email_cambiati`, `testi_email_ripristinati`, `newsletter_cambiata`, `redirect_creato`, `cookie_creato`, `cookie_riordinati`, `cache_svuotata`, `invio_programmato_annullato`, `post_social_riprogrammato`, `post_social_annullato`. Ogni riga ha `oggetto`, `id` dove c'è, `prima` e `dopo`; mai i segreti (dei segreti social solo il nome in `segreti_cambiati`, delle credenziali di un import solo `credenziali: cambiate|tolte`, l'indirizzo segreto mascherato). Le stesse righe le scrive il pannello, perché le scritture passano dalle stesse classi.

## Redazione: community e passaggi (R7, 26 novembre 2026)

Le pagine «Profili e post» (`/admin/community`) e «Passaggi in auto» (`/admin/carpool`) del pannello sotto `E` = `/v1/editorial` (porta, sblocco e `canale: app` come nelle fondamenta). 19 operazioni in `routes/editorial/community.php`. Le letture delle due pagine stanno in `App\Services\Editorial\CommunityDesk` e `CarpoolDesk`, che usa anche il pannello; le scritture sono le stesse chiamate del pannello (`CommunityModeration::apply` e `restoreRestriction`, `CommunitySafety::manage`, `freezeChat`, `inspect`, `restrict`, `cancelRide`, `retryDelivery`, `RideReviews::moderate`), che ricontrollano permesso, rango e revisione e scrivono il registro dei passaggi (`carpool_audits`) e, per la community, il registro delle attività. Ogni azione chiede `reason` da 5 a 1000 caratteri (422 su `error.fields.reason`). Il pannello vede tutte le città in queste pagine, e così l'API. Elenchi con `page`, `per_page` (fino a 50), `q`, `filter[status]`, dal più recente; righe con `kind` e `abilities{}`.

### Profili e post (`community.manage`, come `Community::canAccess`; senza: 403)

- `GET E/community/{profiles|posts|comments|restrictions}`: `q` cerca nel nome mostrato (profili), nel nome della persona (serate bloccate) o nel testo (pubblicazioni e commenti), fino a 80 caratteri con `%` e `_` presi alla lettera. Righe: profilo `{kind: profile, id, user_id, display_name, handle, bio, visibility, visibility_label, featured, suspended, abilities{feature, suspend, unsuspend}}`; pubblicazione e commento `{kind: post|comment, id, user_id, author, body, hidden, event_title (solo pubblicazioni), abilities{hide, restore}}`; serata bloccata `{kind: restriction, id, user_id, name, occurrence_id, event_title, starts_at (UTC), timezone, abilities{remove}}`. `suspend` e `hide` sono falsi verso chi nello staff sta pari o sopra (il servizio risponderebbe 403). Limite 60/min (`editorial-community`).
- `POST E/community/profiles/{profile}/feature` `{enabled, reason}`: mette o toglie dall'evidenza. Risponde con la riga del profilo e `meta.message`.
- `POST E/community/users/{user}/suspend` `{reason}`: **pericolosa** (password recente). Sospende dalla community chi sta sotto nello staff (altrimenti 403); `POST …/unsuspend` `{reason}` riattiva, libero. Rispondono con la riga del profilo della persona (o `{kind: user, user_id, suspended}` senza profilo). È la stessa sospensione di `E/users/{user}/community/suspend` (R5).
- `POST E/community/{posts|comments}/{id}/hide` e `/restore` `{reason}`: nascondere una pubblicazione blocca anche la serata per quella persona; ripristinarla toglie il blocco. Rispondono con la riga.
- `DELETE E/community/restrictions/{restriction}` `{reason}`: toglie il blocco e ripubblica le pubblicazioni della persona su quella serata. Non pericolosa (non elimina un'entità: nome `remove`). Risponde `{kind: restriction, id, removed: true}`; una seconda volta 404.

### Passaggi in auto (`community_cases.manage`, come `Carpool::canAccess`; senza: 403)

- `GET E/carpool/{cases|rides|reviews|feedback|users|audit|deliveries}`: `rides`, `reviews`, `feedback`, `users` e `deliveries` chiedono anche `carpool.manage` (403), come il pannello. La ricerca vale dove la applica il pannello: zona o conducente (passaggi), testo (recensioni), tipo (feedback), nome o email (persone), azione (registro); non sui casi né sulle consegne. `filter[status]` per i casi (`CarpoolCaseStatus`) e i passaggi (`RideStatus`; gli stati sono in `options.ride_statuses`). Righe: caso `{kind: case, id, reporter, status, status_label, created_at, assignee, reason}`; passaggio `{kind: ride, id, title, leg, leg_label, status, status_label, driver, zone, departure_at, capacity, requests_count, abilities{cancel}}` (`cancel` solo in bozza, aperto o chiuso); recensione `{kind: review, id, author, driver, rating, body, confirmed_at, ride_request_id, status, hidden, abilities{hide, restore}}`; feedback `{kind: feedback, id, author, feedback_kind, kind_label, ride_request_id, created_at, body}`; persona `{kind: user, id, name, email_verified_at, whatsapp_verified_at, social_suspended, carpool_suspended, community_suspended, abilities{suspend_social, restore_social, suspend_carpool, restore_carpool, suspend_global, restore_global}}` (l'email non esce; «tutta la community» chiede `users.manage`; sospendere solo chi sta sotto); registro `{kind: audit, id, created_at, action, subject_type, subject_id, actor}`; consegna `{kind: delivery, id, user_id, attempts, last_error, available_at, abilities{retry}}` (`retry` dopo otto tentativi). Limite 60/min (`editorial-carpool`).
- `GET E/carpool/cases/{case}`: il caso aperto: in più `body`, `review{rating, body}`, `social{type, id}`, `ride_offer_id`, `ride_request_id`, `closed_at`, `revision`, `hold_until` (solo a chi gestisce la conservazione), `messages[]{id, author, recipient, internal, body, created_at}`, `people[]{id, label}` (a chi si può rispondere), `abilities{assign, note, resolve, reopen, reply, freeze, unfreeze, hold, release, evidence, export}`. `hold`, `release` ed `evidence` solo ad admin e super admin; `export` dice che l'esportazione è possibile, ma si fa dal pannello sul sito (decisione 8).
- `POST E/carpool/cases/{case}/manage` `{op: assign|note|resolve|reopen, reason, revision}`, `POST …/reply` `{recipient_id, message, revision}` (una persona del caso, altrimenti 422; il caso passa in attesa e la persona riceve un avviso), `POST …/freeze` `{freeze, reason}` (solo casi con una richiesta di passaggio, altrimenti 422). Revisione vecchia: 409 `CONFLICT`. Rispondono con il caso.
- `POST E/carpool/cases/{case}/hold` `{op: hold|release, reason, revision}`: **pericolosa** (password recente; nel pannello la password si scrive nella finestra). Admin e super admin (il moderatore riceve 403).
- `POST E/carpool/cases/{case}/evidence` `{reason}`: **pericolosa** (password recente), admin e super admin. Risponde con le evidenze `{case_id, request_id, review, messages[]{id, body, at}, generated_at, operator_id, audit[] (a chi legge la sicurezza), sha256}` e scrive `evidence_view` con il motivo nel registro. Consuma anche il secchio del sito (`carpool-evidence`, 20 l'ora) oltre al suo (`editorial-carpool-cases-evidence`).
- `POST E/carpool/rides/{offer}/cancel` `{reason}` (`carpool.manage`; 409 se non è più annullabile), `POST E/carpool/reviews/{review}/moderate` `{visible, reason}` (`carpool.manage`), `POST E/carpool/users/{user}/suspend` `{scope: social|carpool|global, reason}` (**pericolosa**) e `POST …/restore` `{scope, reason}`, `POST E/carpool/deliveries/{delivery}/retry` `{reason}` (`carpool.manage`; 409 sotto gli otto tentativi). Rispondono con la riga aggiornata (la consegna con `{kind: delivery, id, retried: true}`).
- Solo sul sito: l'esportazione delle evidenze di un caso (R-CP-11, decisione 8).

Limiti per persona: letture 60/min, decisioni 20–30/min, conservazione 10/min, evidenze 20 l'ora. Errori: 404 a chi non è della redazione o per un id che non esiste, 403 dove permesso o rango dicono no, 409 per un caso cambiato o un'azione che lo stato non permette più, 422 con `error.fields`, 423 per sblocco e password.

## Redazione: promozione (R8, 26 novembre 2026)

Il gruppo «Promozione» di `/admin` (campagne, abilitazioni e pagamenti, ordini, listino, banner, statistiche) sotto `E` = `/v1/editorial`, con porta, sblocco delle 12 ore e `canale: app` come nelle fondamenta. 29 operazioni in `routes/editorial/promotion.php`. Tutto è di admin e super admin, come nel pannello: il moderatore riceve 403 `FORBIDDEN`, referenti e utenti 404. Le regole non sono riscritte: pannello e controller chiamano gli stessi servizi, cioè `App\Services\Sponsorship\Editorial\CampaignBuilder` (salvataggio e regole dei quattro passi, stima, chi occupa lo spazio, prezzo dal listino), `SponsorshipProgress`, `SponsorshipCapacity`, `UnitProgress` e `ProgressDetail` (spostati da `App\Filament\Admin\Resources\Sponsorships\Support`), `App\Actions\Sponsorship\ToggleSponsorship`, `App\Support\Sponsorship\SponsorableEventChoices` (gli eventi sponsorizzabili del selettore), `SponsorshipOrders`, `GrantPayment`, `GrantCampaigns`. Importi in euro come stringa (`"150.00"`), date ISO 8601 con il fuso (senza fuso valgono nell'ora della città della campagna; per le abilitazioni nell'ora di Padova, come il modulo del pannello).

### Campagne (`SponsorshipPolicy`, permesso `sponsorships.manage`)

- `GET E/sponsorships`: righe `{id, event{id, title, slug, poster, venue, date, published}, event_gone, placement{value,label}, status{value,label}, phase{value,label,color}, progress{state, state_label, color, goal, delivered, percent, expected_percent, summary}, timezone, starts_at, ends_at, advertiser_name, impressions, clicks, click_rate, grant_id, toggle_label, abilities{update, toggle, destroy}}`, più `choices{placements, statuses, pacings, pacing_states}` e `can_create`. `filter[…]`: `placement`, `status`, `pacing_state` (stati dell'avanzamento e `period`), `running` (`1`, solo quelle visibili adesso). `q` nel titolo dell'evento e nel committente; `sort` `starts_at`, `ends_at`, `impressions`, `clicks` (predefinito `-ends_at`). Limite 60 al minuto (`editorial-sponsorships`).
- `GET E/sponsorships/export`: il foglio Excel con gli stessi criteri e le colonne dell'elenco. Limite 10 al minuto.
- `GET E/sponsorships/{sponsorship}`: in più `form{event_id, placement, status, starts_at, ends_at, priority, weight, impressions_cap, clicks_cap, impressions_goal, pacing, advertiser_name, advertiser_email, advertiser_url, amount, currency, invoice_reference, notes}`, `on_grant`, `grant_help`, `event_warning` (evento non pubblicato), `period_only`, `amount_label`, `progress_detail{progress, true_end, sentence, legend, cards[], provisional, daily[], daily_empty, events[], events_lead}`, `choices`.
- `PATCH E/sponsorships/{sponsorship}`: solo le chiavi inviate, con le regole del modulo (fine dopo l'inizio, priorità 0–1000, peso 1–100, obiettivo 1–10.000.000 e vietato sulle collocazioni solo a periodo, email e sito validi). Su una riga di un'abilitazione evento, priorità, peso, obiettivo e distribuzione si ignorano (spenti nel pannello). Se l'evento cambia, la città lo segue; un evento deve essere sponsorizzabile o quello di adesso.
- `POST E/sponsorships/{sponsorship}/toggle`: «Sospendi» o «Attiva»; risposta `{data: <riga>, message}`.
- Pericolose, con la password recente (423 `PASSWORD_REQUIRED`): `DELETE E/sponsorships/{sponsorship}` (nel cestino, come il pannello) e `POST E/sponsorships/bulk-destroy` con `ids[]` fino a 50 (ognuna con la sua `delete`; risposta `{deleted[]}`).

### Nuova sponsorizzazione (`SponsorshipPolicy::create`)

- `GET E/sponsorships/prefill?event=`: lo stato iniziale (anche dall'arrivo da «Sponsorizza questo evento»: committente il locale, poi l'organizzatore, altrimenti esterno) e le scelte `{placements, statuses, pacings, months, sponsor_types, scopes, products}` con la riga di aiuto.
- `GET E/sponsorships/sponsors?type=venue|organizer&q=&all=`: i committenti (locali approvati, organizzatori attivi; `all=1` tutti) con `{id, label, name, city_id, email, website}`.
- `GET E/sponsorships/events?sponsor_type=&venue_id=&organizer_id=&venue_filter=&keep=&q=`: gli eventi sponsorizzabili del committente (`any` = tutti, per la modifica), al massimo 50 (30 cercando), con `already_sponsored` e `more_dates`; `meta{count, ready}`.
- `POST E/sponsorships/estimate`: con lo stato del percorso dà `{scope, city_id, timezone, period_lead, ends_at (dai mesi), period_only, price{amount,label}, price_hint, estimate{views, days, provisional, heading, description, status}, occupants[], all_events{sponsor, count, events[]}, placement_lead}`. Con `step` (1–4) prima controlla i passi fino a quello: 422 con i campi, come il pannello andando avanti. Limite 120 al minuto (`editorial-sponsorships-estimate`).
- `POST E/sponsorships`: controlla i quattro passi e salva. «Un evento» crea una campagna (`kind: sponsorship`), «tutti gli eventi» un'abilitazione automatica con le sue righe (`kind: grant`, `SponsorshipGrantPolicy::create`). Risposta 201 `{data{kind, id, message}}`. Un obiettivo oltre l'80% della stima risponde 422 su `impressions_goal`; un evento non del committente o non sponsorizzabile 422 su `event_id`.

### Ordini (`SponsorshipOrderPolicy::decide`)

- `GET E/sponsorship-orders`: `{id, created_at, sponsor, sponsor_kind, event, event_label, placement, months, months_label, mode, amount_cents, amount_label, status{value,label,color}, buyer, paid_at, decided_at, decided_by, request_note, decision_note, payment_reference, approve_lead, reject_lead, suggested_amount, abilities{approve, approve_request, reject}}`, `filter[status]`, `choices{statuses, methods, settlements}`.
- `POST E/sponsorship-orders/{order}/approve`: un ordine pagato senza corpo; una richiesta con `settlement` `paid` (`amount` in euro fino a 100.000, `payment_method` fra `bank_transfer`, `cash`, `card_pos`, `other`, `payment_reference` facoltativo) o `free` (`reason` fino a 2.000). `POST E/sponsorship-orders/{order}/reject` con `reason` fino a 2.000: un ordine pagato online si rimborsa su Stripe, e siccome un rimborso non si annulla chiede la password recente (423 `PASSWORD_REQUIRED`, controllata nel controller con `RequireRecentPassword::ensure()` perché dipende dall'ordine); una richiesta senza pagamento online no. Un ordine che non aspetta più una decisione: 409 `CONFLICT`. Risposta `{data: <riga>, message}`. Chi ha ordinato riceve l'avviso di sempre.

### Abilitazioni (`SponsorshipGrantPolicy`)

- `GET E/sponsorship-grants`: `{id, sponsor, sponsor_kind, status_label, mode, placement, enabled, complimentary, timezone, starts_at, ends_at, paid_at, amount_label, progress, impressions, clicks, abilities{update, destroy}}`, `q` nel committente, `filter[venue]`, `filter[organizer]`, `choices{modes, placements, pacings, months}`.
- `GET E/sponsorship-grants/{grant}`: in più `form{…}`, `period_only`, `progress_detail`.
- `POST E/sponsorship-grants` (201) e `PATCH E/sponsorship-grants/{grant}`: le regole del modulo (un locale o un organizzatore, mai entrambi; importo obbligatorio se non gratuita; motivo nelle note se gratuita; pagata non nel futuro). Committente, modalità e collocazione si scelgono solo alla nascita. Al salvataggio `created_by`, pagamento svuotato per le gratuite e sincronizzazione delle righe, come le pagine del pannello. Nessuna eliminazione (405).

### Listino, banner, statistiche

- `GET E/sponsorship-prices` (`filter[is_active]`, `sort`), `POST`, `PATCH E/sponsorship-prices/{price}` con `placement`, `months` (1, 2, 3, 6, 12, una sola voce per collocazione e durata: 422 «già nel listino»), `amount`, `is_active`; `DELETE E/sponsorship-prices/{price}` con la password recente (`SponsorshipPricePolicy`).
- `GET E/sponsorship-banners` e `PUT` con `web_enabled`, `android_enabled` (admin e super admin, come la pagina «Banner sponsorizzati»).
- `GET E/sponsorships/analytics`, `/analytics/clicks`, `/analytics/campaigns`, `/analytics/campaigns/{campaign}`: le stesse risposte di `S/sponsorships/analytics…` della gestione, con tutte le campagne (`SponsorshipReport` nella redazione, solo admin e super admin).

## Redazione: statistiche, SEO, ricerche (R10, 26 novembre 2026)

Le tre voci del gruppo «Analisi» del pannello `/admin` (`EventShareAnalytics` con `EventAnalyticsDetail`, `SeoOverview`, `SearchMissResource`) sotto `E` = `/v1/editorial`, con porta, sblocco e convenzione degli elenchi delle fondamenta. 15 operazioni in `routes/editorial/analytics.php`, nessuna pericolosa. I numeri li calcolano gli stessi servizi del pannello e dell'app di gestione (`EventAnalyticsDashboard`, `ManagedAnalytics`, `EventAnalyticsExport`): il banco della redazione prende il posto del soggetto e la città di lavoro (`?city=`) dà il fuso in cui si legge «oggi». Logica estratta dal pannello: `App\Services\Seo\SeoAudit::overview` e `::tools` (schede e pagine del controllo, strumenti esterni), `App\Services\Seo\SearchConsoleVerification` (regole e salvataggio del codice), `App\Services\Editorial\EditorialSearchMisses` (elenco ed eliminazione); `ManagedAnalytics::window` chiede il fuso a `EventAnalyticsDashboard::timezone`.

### Statistiche (admin e super admin, `analytics_view`; il moderatore riceve 403)

- `GET E/analytics/report`: «Statistiche in dettaglio» su tutti gli eventi. Filtri `period` (`week|month|quarter|year|all|custom`), `from`/`until` (`AAAA-MM-GG`, con il tetto di `AnalyticsWindow`), `venue`, `organizer`, `event`, `channel`. La forma è quella di `GET S/analytics/report` della gestione (`period, from, until, periods[], filters{event, channel, venue, organizer}, active_filters[], channels[], context, cards[6], empty, selected_event, series, series_labels, charts[8], datasets[7]`). Un locale, un organizzatore o un evento che non c'è: 403 dal servizio del pannello; un canale sconosciuto o date fuori tetto: 422.
- `GET E/analytics/report/table?dataset=&page=`: una tabella da 20 righe (`dataset, label, columns[], rows[{cells[], links{}}], unavailable, meta{page, next_page, total}`).
- `GET E/analytics/report/export?format=csv|xlsx&dataset=`: il file del pannello (CSV della tabella, Excel con tutti i fogli e il riepilogo dei filtri), `Cache-Control: private, no-store`.
- `GET E/analytics/subjects/{type}/{id}` (`type` `event|venue|organizer`), `/table`, `/export`: la pagina di un soggetto con `subject{type, id, name, title, event, related[], information[], description, profile_report[]}`; filtri solo periodo e canale (gli altri si ignorano, come `AnalyticsDetailPage::reportFilters`). Il report del profilo c'è per ogni locale e organizzatore. Soggetto che non c'è: 404.
- `GET E/analytics/events?q=&venue=&organizer=&exclude=`: gli eventi del filtro «Evento» e di «Guarda un altro evento», `{upcoming[], past[]}` come la gestione. `GET E/analytics/venues?q=` e `GET E/analytics/organizers?q=`: `[{id, name}]`, al massimo 50 per nome.

### SEO e qualità del catalogo (admin e super admin, `analytics_view`)

- `GET E/seo`: `title`, `search_console{title, help, city_label, code_label, code_help, save_label, max (255), pattern, default_city_id (la prima città, come il pannello), cities[{id, slug, name, is_active, verification}]}`, `steps{heading, items[{text, code}]}` (indirizzo del sito e della sitemap), `tools{heading, description, links[{key, label, url}]}`, `audit{heading, help, empty, complete, tabs[{key, label}]}`. Tutti i testi sono quelli del pannello.
- `GET E/seo/audit`: il controllo degli ultimi 200 eventi pubblici, `tab` `issues` (predefinita) o `all`, `per_page` 20 come il pannello (fino a 50). Righe `{key, title, url, issues[], complete}`, `meta.tabs{issues, all}`. Tutte le città, come il pannello.
- `PUT E/seo/verification` `{city_id, verification}`: il codice di Search Console della città (al massimo 255 caratteri, solo lettere, cifre, `_` e `-`; vuoto lo toglie), senza toccare le altre impostazioni SEO. Risponde con la città e `meta.message`. Non scrive nel registro, come il pannello. Non è pericolosa: è un valore pubblico nella pagina.

### Ricerche senza risultati (moderatore, admin, super admin: `SearchMissPolicy`)

- `GET E/search-misses`: righe `{id, term, count, last_searched_at, last_searched_label (gg/mm/aaaa hh:mm, fuso di Roma come la colonna), first_searched_at, first_searched_label, city{id, name}, abilities{remove}}`; `q` sulla parola, `filter[city]` (id), `sort` `count|last_searched_at|first_searched_at` con `-` (predefinito `-count`), `choices.cities[{value, label}]`.
- `DELETE E/search-misses/{miss}` e `POST E/search-misses/bulk-delete` `{ids: [1…200 id]}` (rotte `search-misses.remove` e `search-misses.bulk-remove`): tolgono le righe, con `delete` per ciascuna e `deleteAny` per il gruppo; gli id che non ci sono più si ignorano (`data.removed` dice quante). Non sono pericolose (decisione 4): niente password recente.

Limiti per persona: statistiche 60/min (`editorial-analytics`), file 10/min (`editorial-analytics-export`), SEO 60/min (`editorial-seo`) e 20/min per il salvataggio (`editorial-seo-verification`), ricerche 60/min (`editorial-search-misses`, `editorial-search-misses-remove`; il gruppo 20/min).

## Redazione: catalogo (R11, 26 novembre 2026)

Il gruppo «Contenuti» del pannello `/admin` nell'app (categorie, tag, caratteristiche degli eventi, città, pagine): **24 operazioni** sotto `E` = `/v1/editorial` (rotte in `routes/editorial/catalog.php`, controller `CatalogController`). Tutte chiedono lo sblocco delle 12 ore; quelle segnate «password» anche la password scritta negli ultimi 15 minuti (423 `PASSWORD_REQUIRED`). `{id}` è sempre l'id numerico, mai lo slug. La città di lavoro non filtra: come il pannello, si vede tutto il catalogo. Gli elenchi seguono la convenzione della redazione (`page`, `per_page` fino a 50, `q`, `filter[…]` con `1`/`0`, `sort` con `-` per il verso opposto) e ogni riga porta `abilities{update, delete_on_web}` (i tag anche `merge`), calcolate dalla policy del modello, e `web_path`, la pagina di modifica del pannello (per esempio `admin/tags/jazz/edit`): l'eliminazione è definitiva e resta solo sul sito (decisione 4), l'app apre quella pagina con «Elimina sul sito».

La logica condivisa con il pannello sta in `app/Services/Editorial/Catalog/` (una classe per voce, con le colonne, i filtri, l'ordine e le regole del modulo della risorsa) e in `App\Support\CitySettings` (gli interruttori della città fra righe e mappa, prima in `StructuredFields`; le pagine della città del pannello ora chiamano questa). `EventFeature::GROUPS` e `City::LOCALES` sono le scelte dei moduli, lette da pannello e API. L'unione dei tag è `MergeTagsAction`, la stessa del pannello.

- `GET E/catalog`: `sections[]{key, count, can_create}` delle voci che la persona vede (`viewAny` della policy): categorie, tag e città a tutta la redazione, `event_features` e `pages` ad admin e super admin.
- `GET E/catalog/choices` (chi può creare almeno una voce; il moderatore riceve 403): `categories[]`, `cities[]`, `feature_groups[]`, `feature_icons[]` (`{value, label}`), `timezones[]`, `locales[]` (`it`, `en`, `de`), `seo_indexing[]`, `limits{text (5000), faqs (30), seo_title (180), seo_description (400)}`.
- Per ogni voce `<voce>` = `categories`, `tags`, `event-features`, `cities`, `pages`: `GET E/<voce>` (`viewAny`; 403 altrimenti), `POST E/<voce>` (`create`, 201 con il modulo), `GET E/<voce>/{id}` (il modulo, `update`: il moderatore legge l'elenco ma non apre il modulo, come nel pannello), `PATCH E/<voce>/{id}` (`update`, il modulo intero; le chiavi di `content_details` e `seo` non mandate restano). Nessuna rotta `DELETE`: dal 26/11 (seconda revisione di sicurezza) si elimina solo nel pannello, e ogni eliminazione di una di queste voci va nel registro di sicurezza (`catalogo_voce_eliminata`, con gli eventi toccati per i tag).
- Categorie: colonne `name, slug, parent{id,name}, icon, color, sort_order, default_duration_minutes, supports_ongoing, is_nightlife, is_active, events_count`; `filter[is_active]`, `filter[is_nightlife]`; `q` sul nome; `sort` `sort_order` (predefinito) o `name`. Modulo con `parent_id` (non sé stessa), durata 5–10080 minuti, `sort_order` intero da 0.
- Tag: `name, slug, category{id,name}, events_count, usage_count, is_approved`; `filter[is_approved]`; `sort` `name` (predefinito), `events_count`, `usage_count`. Modulo con `category_id`, `is_approved`, `synonyms[]`. `POST E/tags/{id}/merge` `{absorbed_id}` (password, `TagPolicy::delete` sul tag che resta e su quello assorbito, che viene eliminato: `MergeTagsAction::authorize`, la stessa regola dell'azione del pannello; 403 `FORBIDDEN` altrimenti): gli eventi del doppione passano qui, il suo nome e i suoi sinonimi diventano sinonimi, il doppione sparisce; `meta{message, moved}`. Le due righe si bloccano durante l'unione: se nel frattempo una delle due è sparita, 422 su `error.fields.absorbed_id`. Ogni unione va nel registro di sicurezza (`tag_uniti`). Il moderatore non approva i tag: `TagPolicy::approve` non ha un pulsante nemmeno nel pannello.
- Caratteristiche (`event-features`, solo admin e super admin): `name, group, icon, description, sort_order, is_active, is_system`; `sort` `group` (predefinito) o `name`. `group` fra i sette del pannello, `icon` fra quelle di `PracticalIcons`. Le voci di sistema non si eliminano (403) e il loro `is_active` si ignora.
- Città: `name, slug, province_name, timezone, night_cutoff_time (HH:MM), starting_soon_minutes, venues_count, events_count, is_active`; `filter[is_active]`; `q` su nome e provincia. Modulo con identità, `center_lat`/`center_lng`, `default_zoom` 1–20, `radius_km` 1–500, `bounds{north,south,east,west}`, `timezone` (identificatori PHP), `night_cutoff_time`, `starting_soon_minutes` 5–1440, `locale`, `settings[]{key, enabled}`. `POST E/cities` e `PATCH E/cities/{id}` chiedono la password (decisione 4: una città nuova attiva compare subito nel sito, fuso e chiusura della notte spostano il motore temporale). Ogni modifica va nel registro di sicurezza (`catalogo_citta_modificata`, campi con valore di prima e nuovo), anche dal pannello. Il cambio di indirizzo lascia il reindirizzamento con il jolly (`CityObserver`).
- Pagine (solo `pages.manage`): `title, slug, is_published, sort_order, updated_at`; `filter[is_published]`; `q` su titolo e indirizzo; `sort` `sort_order` (predefinito), `title`, `updated_at`. Il modulo porta `excerpt`, `body` (Markdown, al massimo 200000 caratteri come l'anteprima; annidamento al massimo 50 livelli), `seo_title`, `seo_description`, `url` e `revision`: `PATCH E/pages/{id}` vuole la `revision` letta (422 senza) e risponde 409 `CONFLICT` se nel frattempo la pagina è cambiata (decisione 7; il pannello non ha questo controllo). `POST E/pages/preview` `{body}` → `{html}`: la conversione della pagina pubblicata (`Page::renderedBody`, marcatura grezza scartata).
- Categorie, tag, città e pagine hanno anche le due sezioni comuni del pannello: `content_details{introduction, faqs[]{question, answer}, city_introductions[]{city_id, text}}` (le presentazioni per città solo per categorie e tag; testi formattati con il filtro dell'editor del sito, al massimo 30 domande) e `seo{title (180), description (400), image (url), indexing}` con `is_demo`; `indexing` e `is_demo` contano solo per admin e super admin, come nel pannello.

## Redazione: modulo evento e date (R3, 26 novembre 2026)

«Nuovo evento», «Modifica evento» e la sezione «Date» di `/admin/events`, sotto `E` = `/v1/editorial` (porta, sblocco e `canale: app` come nelle fondamenta). 24 operazioni in `routes/editorial/event-editor.php`. Pannello e controller usano le stesse classi: `App\Services\Events\EditorialEventEditor` (regole dei campi del modulo come regole di validazione, salvataggio, autore della scheda per `CreateEvent`, «Prima di andare» ereditato dal locale), `App\Services\Events\EditorialEventDates` (modulo della data, «Sposta», «Annulla la data», «Tutto esaurito», «Genera date»: li chiamano anche `OccurrencesRelationManager` ed `EditEvent`), `App\Services\Editorial\EditorialEventForm` (scelte e valori del modulo) e `App\Services\Editorial\EditorialEventMedia` (locandina e galleria). Creare e modificare sono di `EventPolicy::create` e `update` (admin e super admin): il moderatore riceve 403, come nel pannello dove non vede quelle pagine.

### Modulo evento

- `GET E/events/form`: `{options, event: null}` per «Nuovo evento» (`EventPolicy::create`). `options`: `categories[{id,name}]`, `cities[{id,name,timezone}]` (anche non attive), `statuses`, `sources`, `verification_statuses`, `price_types`, `ticket_tier_statuses`, `occurrence_statuses`, `lineup_roles`, `scopes[{value,label,help}]`, `attendance_modes`, `parking_types`, `organizer_types`, `seo_indexing` (tutte `{value,label}` con le etichette del pannello), `defaults{status (draft), city_id (la città di lavoro, `?city=`, altrimenti la prima), price_type (unknown), currency (EUR), source (manual), verification_status (unverified), editorial_score (0)}`, `limits{ticket_tiers, tags, agenda, faqs, facts, external_links, new_dates, short_description}`, `can{feature, admin, create_feature}` (`create_feature`: `EventFeaturePolicy::create`, il «Crea caratteristica» del campo delle caratteristiche) e `catalog` (il catalogo di «Prima di andare», la forma di `GET /management/options`). Limite 60 al minuto (`editorial-event-editor`).
- `GET E/events/{event}/form`: `{options, event}` (`EventPolicy::update`; un evento cestinato risponde 404). `event` ha la forma della scheda della gestione per i campi comuni (`title`, `description`, `category`, `tags[{id,name}]`, `price_type{value,label}`, `price_min`, `price_max`, `ticket_url`, `booking_url`, `custom_location{name,address,lat,lng}`, `content_details` con «Prima di andare» già unito ai valori del locale, `facts[]`, `external_links[]`, `ticket_tiers[]` del listino dell'evento, `organizer_name`, `organizer_url`, `city{id,name,timezone}`, `venue{id,name}`) più `subtitle`, `slug`, `short_description`, `age_restriction`, `language`, `video_url`, `is_outdoor`, `organizer{id,name}`, `organizers[]` (co-organizzatori), `price_notes`, `currency`, `booking_required`, `booking_phone`, `seo{title,description,image,indexing}`, `is_demo`, `source{value,label}`, `verification_status`, `editorial_score`, `is_featured`, `featured_until`, `rejection_reason`, `scheduled_publish_at`, `published_at`, `recurrences[{id, rrule, until, exdates[], generated_until}]`, `poster`, `gallery[{id, thumb, card, full, …}]`, `date_refs[{id, starts_at, ticketing_path}]` (per «Data collegata» del programma e «Gestisci biglietti»: `gestione-biglietti/{data}` a chi gestisce la biglietteria della data), `ticketing_enabled` del locale, `abilities{update, feature, admin, schedule, unschedule, generate, dates}`. Istanti in ISO 8601 UTC.
- `POST E/events`: crea l'evento con le sue date. Obbligatori come nel pannello `status`, `title`, `category_id`, `city_id`, `price_type`, `source`, `verification_status`, `currency` (solo con biglietto o tessera) e `occurrences[]` (almeno una, fino a 100: `{starts_at, ends_at, doors_at, is_all_day}`; orari con il fuso, o senza fuso nell'ora della città). Gli altri campi come in modifica. `created_by` è chi crea. Risposta 201 `{data: <event del modulo>, message}`. Limite 30 al minuto (`editorial-event-editor-save`).
- `PATCH E/events/{event}`: le chiavi ricevute (una sezione o il modulo intero); `occurrences` si ignora (le date si governano da `E/events/{event}/dates`). Le regole sono quelle dei componenti del pannello: titolo e sottotitolo 255, indirizzo nel sito 255, descrizione breve 500, lingua 5, limite di età 40, video un indirizzo che il sito sa incorporare, locale della città scelta, organizzazioni attive, prezzi da 0, note sul prezzo 255, valuta 3, collegamenti `url` 255, telefono 40, SEO (titolo 180, descrizione 400, immagine `url` 2048, indicizzazione `automatic`/`excluded`), punteggio da 0 a 100, scheda tecnica e collegamenti esterni con le regole di sempre (`FactRows`, `ExternalLinks`), listino (12 voci), «Prima di andare» e informazioni utili come la gestione (`EventFormRules`), ricorrenze `{id?, rrule (500), until, exdates[]}` (le righe con `id` si aggiornano, le altre nascono, le mancanti si tolgono). Come nel pannello non si convalidano e non si salvano i campi nascosti: importi, valuta e listino senza biglietto o tessera, link e telefono di prenotazione senza `booking_required`, il luogo libero quando c'è un locale, la scadenza dell'evidenza senza evidenza. Evidenza (`is_featured`, `featured_until`) solo con `EventPolicy::feature`; `seo.indexing` e `is_demo` solo da admin e super admin (per gli altri si ignorano). «Prima di andare»: i valori uguali a quelli del locale non si copiano sull'evento. Risposta `{data: <event del modulo>, message}`. Limite 60 al minuto.
- `POST E/events/before-going` con `previous_venue_id`, `venue_id`, `content_details`: ricalcola «Prima di andare» quando cambia il locale, come il campo «Locale» del pannello. Risposta `{data{content_details}}`.
- `GET E/events/lookup/venues?q=&city_id=&approved=1`: i locali per «Locale» (della città scelta) e per «Locale di questa data» e «Organizzatore registrato» (solo approvati), cinquanta al massimo, `{id, name, city, address, ticketing_enabled}`. `GET E/events/lookup/organizers?q=`: le organizzazioni attive `{id, name}`. `GET E/events/lookup/tags?q=`: i tag `{id, name}` con `meta.can_create`; `POST E/events/lookup/tags` con `name` (80) ed `event_id` facoltativo crea il tag condiviso (`CreateSharedEventTag`, 201). Limite 120 al minuto per le ricerche (`editorial-event-editor-lookup`), 30 per i tag nuovi.
- Locandina e galleria (`EventPolicy::update`, campo multipart `image` con le regole di `RealImage`): `POST`/`DELETE E/events/{event}/poster`, `POST E/events/{event}/gallery` (201), `DELETE E/events/{event}/gallery/{media}`, `PUT E/events/{event}/gallery/order` con `ids[]` (tutte e sole le foto). Risposta `{data{poster, gallery[]}}`. Nessuna revisione: è la redazione. Limite 20 (locandina) e 30 (galleria) al minuto.
- `PUT E/events/{event}/schedule` con `publish_at` futuro («Programma pubblicazione», `ScheduleEventPublication`: una bozza o un evento in attesa con almeno una data, altrimenti 422) e `DELETE E/events/{event}/schedule` («Annulla programmazione»). `POST E/events/{event}/occurrences/generate` («Genera date», 404 senza ricorrenze): `meta.created` e il messaggio del pannello. Tutte `EventPolicy::update`.

### Date

- `GET E/events/{event}/dates`: cinquanta per pagina in ordine di inizio, `{id, starts_at, ends_at, doors_at, effective_ends_at, business_date, timezone, is_all_day, status{value,label}, status_note, highlight, capacity, capacity_left, venue{id,name}, practical_details{}, cost_breakdown{}, currency, series, is_exception, lineups_count, lineup[{id, name, role, starts_at, url}], ticket_tiers[] (il listino della sola data), ticketing_path, abilities{update, shift, cancel, sold_out, destroy, ticketing}}`; `meta` con il paginatore, `event{id, title, status, city, timezone, currency}`, `abilities{create, generate}` e `choices{occurrence_statuses, lineup_roles, ticket_tier_statuses, memberships, scopes}`. Permesso `EventPolicy::view`: il moderatore la legge senza gesti, come la pagina dell'evento nel pannello. `GET E/events/{event}/dates/{occurrence}`: una data con la stessa `meta` (404 per una data di un altro evento).
- `POST E/events/{event}/dates` (`EventOccurrencePolicy::create`, 201) e `PATCH E/events/{event}/dates/{occurrence}` (`update`; le chiavi assenti restano): `venue_id` (approvato), `starts_at` (obbligatorio alla creazione), `ends_at` (dopo l'inizio), `doors_at`, `is_all_day`, `status` fra i cinque, `capacity` e `capacity_left` (0–1.000.000), `highlight` (40), `status_note` (2.000), `practical_details{accessibility (yes/no/unknown), membership, entrance_notes, membership_notes, parking_notes, transit_notes, food_notes, start_notes}`, `cost_breakdown{admission, drink, membership, other}` (0–100.000, due decimali), `ticket_tiers[]` (12 voci; sostituisce il listino della data) e `lineup[{id?, name, role, starts_at, url}]` (100; sostituisce la lineup). Orari con il fuso o nell'ora della città.
- `POST E/events/{event}/dates/{occurrence}/shift` con `shift_minutes` (anche negativi) e `scope` (`single`, `series`: per una data di una ricorrenza, la serie da lì in avanti senza le date modificate a mano). `POST E/events/{event}/dates/{occurrence}/status` con `action` `cancel` (con `status_note` e `scope`; 422 su una data già annullata) o `sold_out` («Tutto esaurito», o «Rimetti in programma» se lo è già; sempre la sola data). Risposta `{data, meta{changed}, message}`. `EventOccurrencePolicy::update`.
- Pericolosa, con la password recente (423 `PASSWORD_REQUIRED`): `DELETE E/events/{event}/dates/{occurrence}` (`delete`, nel cestino), 204.

## Redazione: calendario, campanella, chiusura (R14, 26 novembre 2026)

La pagina «Calendario» del pannello `/admin` (R-EV-27), la campanella (R-DB-11) e la traduzione degli indirizzi del pannello con uno slug, sotto `E` = `/v1/editorial` con la porta e lo sblocco delle 12 ore delle fondamenta. **Otto operazioni** in `routes/editorial/calendar.php`, controller `CalendarController`, `EditorialNotificationController`, `EditorialLinkController`. Tutta la redazione (moderatore, admin, super admin), come la pagina e la campanella; nessuna è pericolosa.

Una regola sola per pannello e app: le date del calendario, il titolo «Evento · Locale» e il colore dello stato sono in `App\Services\Editorial\EditorialCalendar`, che usa anche `EventsCalendarWidget`; la campanella è `App\Services\Editorial\EditorialBell`, con la stessa domanda di `DatabaseNotifications` di Filament (le notifiche della persona con `data.format = filament`).

| Metodo e percorso | Note |
|---|---|
| `GET E/calendar?from=&to=` | i giorni `AAAA-MM-GG` compresi, nel fuso della città di lavoro (`?city=`), al massimo 62 (422 su `to` oltre, rovesciati o scritti male). Le date di **tutte le città**, come il pannello, che non filtra per città; gli eventi nel cestino no. Riga `{id (la data), event_id, title, event_title, venue, city{id, name, timezone}, date (il giorno nel fuso della città dell'evento), starts_at, ends_at, all_day, event_status{value,label}, status{value,label} (della data), color (lo stato dell'evento, come la casella del pannello), abilities{open, edit}}`; `meta{from, to, timezone, total, truncated (oltre 1.500 date), legend[]{value, label, color}}`. L'app apre la scheda dell'evento (R2) con `event_id`, dove il pannello apre la modifica |
| `GET E/notifications` | la campanella: le notifiche della persona nella forma di Filament, dalla più recente, 50 per pagina come la tendina (`page`). Oggi è l'avviso del mattino `sponsorships:pacing-check` (`SponsorshipPacingAlert`), che arriva a chi ha `sponsorships.manage` (admin e super admin): il moderatore la trova vuota, come nel pannello. Riga `{id, title, body, status (warning…), category, url (l'indirizzo del pannello, `/admin/sponsorships`), actions[]{label, url}, read, read_at, created_at}`; `meta{current_page, last_page, per_page, total, unread}` |
| `POST E/notifications/{notification}/read`, `…/unread` | «Segna come letta», «Segna come da leggere»: la riga aggiornata, `meta{message, unread}`. Una notifica di un altro, o che non è della campanella, 404 |
| `POST E/notifications/{notification}/remove` | la «x» della notifica: la toglie, come il pannello |
| `POST E/notifications/read-all` | «Segna tutte come lette» (solo quelle della campanella) |
| `POST E/notifications/clear` | «Svuota»: toglie le notifiche della campanella; gli altri avvisi dell'account restano |
| `GET E/links/{type}/{slug}` | `type` `events` o `venues`: lo slug di un indirizzo del pannello (`/admin/events/{slug}/edit`) tradotto in `{type, id, title}` per aprire la scheda nativa. Il record si cerca come il pannello (per slug, il primo, anche nel cestino) e risponde solo a chi lo può vedere (`view`); 404 se non c'è |

Limiti per persona: `editorial-calendar` 60/min, `editorial-notifications` 60/min, `editorial-notifications-write` 60/min, `editorial-links` 60/min.

Nell'app (`EditorialDestination`) gli indirizzi `/admin/…` che arrivano da dentro l'app (la campanella, la casella degli avvisi, i collegamenti delle altre schermate, «Impostazioni newsletter» del profilo) o da una notifica dell'app con la prova dell'installazione si aprono nella schermata nativa con lo stesso permesso; da un'altra app vanno nel browser.

## Redazione: seconda revisione di sicurezza (26 novembre 2026)

Correzioni della revisione della seconda ondata. Le regole stanno nei servizi e nelle azioni che chiamano sia il pannello sia l'app; le righe del registro di sicurezza scritte da una richiesta `E` hanno da sole `canale: app`.

- **Password recente (423 `PASSWORD_REQUIRED`)** anche su: `PATCH E/events/{event}` quando `status` passa a `cancelled`, `archived` o `rejected` (lo stesso effetto di `events.cancel`); `POST E/events/{event}/dates/{occurrence}/status` con `action=cancel` quando `scope=series` o quando la data ha prenotazioni non annullate; `PATCH E/events/{event}/dates/{occurrence}` che porta a `cancelled` una data con prenotazioni non annullate; `PUT E/seo/verification` (chi scrive il codice diventa proprietario verificato su Search Console); `POST E/cities`.
- **Catalogo**: tolti i cinque `DELETE E/{categories,tags,event-features,cities,pages}/{id}`; l'eliminazione definitiva resta nel pannello (decisione 4), con il registro `catalogo_voce_eliminata`.
- **Locali**: `venue_id` dell'evento e della data rifiuta i locali nel cestino; quello della data vuole un locale approvato della città dell'evento (anche il campo del pannello offre solo quelli).
- **Tetti**: la galleria dell'evento si ferma a `ManagementOptions::GALLERY_MAX` (30) foto, 422 su `error.fields.image` (anche il campo del pannello); `date_refs` del modulo porta solo le date non ancora finite, al massimo 100, con `date_refs_meta{truncated, limit}`.
- **Ricorrenze**: `recurrences.*.rrule` rifiuta le regole illeggibili, quelle più fitte di una volta al giorno (`HOURLY`, `MINUTELY`, `SECONDLY`) e quelle con più di 1000 date in un anno, nel pannello e nell'app (422 con il motivo). «Genera date» con una regola salvata prima di questi controlli risponde 422 su `error.fields.recurrences` invece di 500 (nel pannello un avviso), e il comando `occurrences:generate` salta la regola e prosegue con le altre.
- **Passaggi**: tutte le rotte `E/carpool/…` rispondono 404 con `carpool.enabled` spento (`CarpoolPrivacy`, come le pagine del sito); `last_error` delle consegne ha i numeri di telefono coperti (`PhoneMask`, restano le ultime due cifre); la ricerca per email della sezione «Persone» trova solo chi ha una presenza nei passaggi o nella community e solo con l'email intera, quindi non dice se un'email è registrata; ogni lettura o esportazione delle evidenze va anche nel registro di sicurezza (`evidenze_passaggio_lette`, `evidenze_passaggio_esportate`).
- **Sospensione da tutta la community**: una regola sola (`CommunityModeration::requireGlobalSuspension`) per `community/users/{id}/suspend|unsuspend`, `carpool/users/{id}/suspend|restore` con `scope=global`, `users/{id}/community/…` e il pannello: `community.manage` più `users.manage` e rango più alto di chi la subisce, per sospendere e per togliere (403 altrimenti).
- **Promozione**: le modifiche di una campagna si convalidano unite ai valori salvati (inizio dopo la fine, obiettivo su una collocazione solo a periodo: 422); `amount` con `decimal:0,2` e al massimo 1.000.000 €, `impressions_cap` e `clicks_cap` interi fino a 1.000.000.000, anche nel pannello; una decisione su un ordine già deciso da un'altra persona risponde 409 `CONFLICT` invece di 500; si elimina solo una campagna senza visualizzazioni né clic (`SponsorshipPolicy::delete`, come dice il suo commento). Registro: `ordine_sponsorizzazione_approvato`/`_rifiutato`, `listino_sponsorizzazioni_modificato`, `banner_sponsorizzazioni_modificato`.
- **SEO**: ogni cambio del codice di Search Console va nel registro di sicurezza, `search_console_codice_cambiato` (codice di prima e nuovo, anche dal pannello).

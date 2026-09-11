# API v1 — stato reale

Aggiornato con il ticketing del 2026-09-05. Il contratto è verificato con
test di integrazione e consumato dall'app Android in `android/` contro dati reali.

## Coordinate

| Voce | Valore |
|---|---|
| Base | `/api/v1` |
| Documentazione | `/docs/api` (Scramble) · JSON versionato in `docs/openapi.json` — OpenAPI **3.1.0**, **66 operazioni** |
| Autenticazione | Bearer token (Sanctum) per `/me`, `auth/logout` e scritture ticketing; policy aggiuntive per il locale |
| Formato risposte | `{data, meta{next_cursor, has_more}}` · errori `{error{code, message, fields}}` |
| Paginazione | catalogo a cursore (`limit` 1–50); cursore illeggibile → **400**. Biglietti: `page`, 30 prenotazioni, `meta.next_page` nullable |
| Cache | catalogo: `ETag` + `Cache-Control: max-age=60, public, stale-while-revalidate=300` (private se autenticato), **304**. Ticketing: `no-store`, senza ETag |
| Rate limit | 60 req/min anonimi, 120 autenticati, con header `X-RateLimit-Limit/-Remaining/-Reset` e `Retry-After` sul 429 |

## Rotte (66)

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
| POST | `/reports/sponsorships/{id}/metrics/{impression|click}` |

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
invalida quelli precedenti dello stesso account.

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
(`dark` oppure `light`). `PATCH /api/v1/me` accetta lo stesso campo e modifica
solo il profilo autenticato. Il sito e l'app Android condividono la preferenza;
le installazioni precedenti restano compatibili grazie al valore `dark` predefinito.
I visitatori del sito partono dal tema di sistema, memorizzato per un anno nel
cookie necessario `incitta_appearance`, e possono cambiarlo da Aspetto.


### Tessera dell’evento e alternative geografiche

`GET /api/v1/events`, le relative mappe e facets accettano `membership=required|not_required`. Nel dettaglio `content_details.membership` riporta il requisito dell’evento oppure null. Non viene dedotto dal locale: null non equivale a “non richiesta”. Le facets geografiche consentono il cambio diretto di comune, quartiere e locale ignorando la selezione dello stesso campo e quelle dipendenti, mantenendo gli altri filtri.

`content_details.practical_items` restituisce le informazioni confermate di «Prima di andare» come elenco di `{label, icon, text}`. Include caratteristiche selezionate dal catalogo, requisiti strutturati e voci libere. Le icone sono identificatori della lista consentita; non contengono SVG o HTML. Android usa la stessa lista e icone native corrispondenti. Dettagli in [BEFORE-GOING.md](BEFORE-GOING.md).

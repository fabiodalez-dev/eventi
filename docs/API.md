# API v1 — stato reale

Aggiornato dopo la verifica finale del 2026-08-24, eseguita con server attivo
(`php artisan serve`) su database seedato. Ogni numero e codice HTTP riportato
qui è stato misurato, non dedotto.

## Coordinate

| Voce | Valore |
|---|---|
| Base | `/api/v1` |
| Documentazione | `/docs/api` (Scramble) · JSON in `/docs/api.json` — OpenAPI **3.1.0**, **39 operazioni** |
| Autenticazione | Bearer token (Sanctum) per le rotte `/me` e `auth/logout` |
| Formato risposte | `{data, meta{next_cursor, has_more}}` · errori `{error{code, message, fields}}` |
| Paginazione | a cursore ovunque (`limit` 1–50, default da `config('api.limits')`); cursore illeggibile → **400** |
| Cache | `ETag` + `Cache-Control: max-age=60, public, stale-while-revalidate=300` (private se autenticato); `If-None-Match` → **304** a corpo vuoto |
| Rate limit | 60 req/min anonimi, 120 autenticati, con header `X-RateLimit-Limit/-Remaining/-Reset` e `Retry-After` sul 429 |

## Rotte (39)

### Scoperta e contenuti (pubbliche)

| Metodo | Rotta |
|---|---|
| GET | `/config` |
| GET | `/cities` · `/cities/{slug}` |
| GET | `/events` · `/events/{slug}` · `/events/{slug}/similar` |
| GET | `/occurrences/{id}` |
| GET | `/calendar` |
| GET | `/venues` · `/venues/{slug}` · `/venues/{slug}/events` |
| GET | `/map/occurrences` (payload minimale, 7 campi) |
| GET | `/search` |
| POST | `/submissions` · `/reports` |

I preset temporali di `/events` (`?preset=ongoing`, `?preset=starting_soon`,
ecc.) passano da `DatePreset` → `EventOccurrenceQuery`: sito e API condividono
una sola definizione di "adesso" (scenario G, verificato sotto).

### Autenticazione

`POST /auth/register` · `login` · `logout` · `magic-link` · `verify-email` ·
`password/forgot` · `password/reset`.

Il magic link apre una sessione del sito, non consegna un token API (un token
in un URL finirebbe in cronologia e log dei proxy); per la futura app servirà
un deep link (`API_PASSWORD_RESET_URL`).

### Account (`/me`, Bearer token; senza token → 401)

`GET|PATCH|DELETE /me` · `GET /me/export` · `GET /me/feed` ·
`GET|POST /me/saved` · `POST /me/saved/merge` · `DELETE /me/saved/{occurrence}` ·
`GET|POST /me/follows` · `DELETE /me/follows/{type}/{id}` ·
`GET /me/notifications` · `GET|PATCH /me/notification-preferences` ·
`POST /me/devices` · `DELETE /me/devices/{device}`.

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
| `GET /docs/api` · `/docs/api.json` | 200, JSON valido, 39 operazioni |
| config, events, venues, map, calendar, search, cities | tutti 200 |
| `GET /me` senza token | **401** |
| `If-None-Match` con l'ETag della prima risposta | **304** |
| Cursore: pagina 1 `3,4,5,29,28` → pagina 2 `27,32,34,33,17` | nessuna sovrapposizione |
| Cursore illeggibile (`?cursor=zzzz`) | **400** `VALIDATION_FAILED` |
| `limit=100` (oltre il massimo 50) | **400** `VALIDATION_FAILED` |
| 70 richieste rapide a `/events` | 60×200 + **10×429** con `X-RateLimit-*` e `Retry-After` |
| **Scenario G** `starting_soon`: sito `3,4,5` vs API `3,4,5` | **identici, stesso ordine** |
| **Scenario G** `ongoing`: sito `1,2` vs API `1,2` | **identici, stesso ordine** |

## Questioni aperte

1. `/docs/api` è protetto da `RestrictedDocsAccess`: visibile solo in ambiente
   `local`. Per esporlo in produzione va definito il gate `viewApiDocs`.
2. `API_PASSWORD_RESET_URL` e `API_LEGAL_*` non sono valorizzate: il link di
   reset punta a `/reimposta-password` del sito e `legal` in `/config` è `null`.
3. `media.cdn_url` è vuota: il meccanismo esiste ed è coperto da un test, il
   fornitore non è stato scelto.
4. §13.5 "devices" registra i dispositivi, e dal 2026-09-04 il canale push
   esiste davvero (D54: Web Push riaperto). I canali reali sono push, email e
   archivio in-app.

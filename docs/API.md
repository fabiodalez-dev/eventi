# API v1 — stato reale

Aggiornato con il ticketing del 2026-09-05. Il contratto è verificato con
test di integrazione e consumato dall'app Android in `android/` contro dati reali.

## Coordinate

| Voce | Valore |
|---|---|
| Base | `/api/v1` |
| Documentazione | `/docs/api` (Scramble) · JSON versionato in `docs/openapi.json` — OpenAPI **3.1.0**, **143 operazioni** |
| Autenticazione | Bearer token (Sanctum) per `/me`, `auth/logout` e scritture ticketing; policy aggiuntive per il locale |
| Formato risposte | `{data, meta{next_cursor, has_more}}` · errori `{error{code, message, fields}}` |
| Paginazione | catalogo a cursore (`limit` 1–50); cursore illeggibile → **400**. Biglietti: `page`, 30 prenotazioni, `meta.next_page` nullable |
| Cache | catalogo: `ETag` + `Cache-Control: max-age=60, public, stale-while-revalidate=300` (private se autenticato), **304**. Ticketing: `no-store`, senza ETag |
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
invalida quelli precedenti dello stesso account. Il client genera un verifier casuale
(43–128 caratteri PKCE), conserva il segreto sul dispositivo e invia
`code_challenge = BASE64URL(SHA256(code_verifier))` nella richiesta `magic-link`.
Lo scambio richiede `token` e `code_verifier`. Una prova errata non consuma il link;
un cambio password lo invalida. I vecchi client senza PKCE devono essere aggiornati.
Il collegamento HTTPS `/app/auth/magic` usa gli Android App Links verificati;
la pagina di ripiego non riflette il token e imposta `no-store` e `no-referrer`.

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

L'export automatico Scramble non è stato sostituito durante questa review:
la rigenerazione segnala `GEN001 Scope is not initialized for route` sul
controller della singola occorrenza. Questa sezione descrive le aggiunte
verificate dai test; il JSON storico richiede una revisione separata del generatore.

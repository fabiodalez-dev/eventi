# Stack tecnologico — elenco completo

Documento di supporto a `piano-piattaforma-eventi-v2.md`. Contiene ogni tecnologia, libreria e servizio previsto, con il ruolo che ricopre, le alternative valutate e le trappole note.

**Verificato al 23 agosto 2026.**

---


> ## Stato del documento
>
> **Verificato al 23 agosto 2026, rivisto il 1 settembre.** Le versioni qui
> sotto erano previsioni: costruendo il sistema alcune si sono rivelate
> impraticabili sul bersaglio reale — shared hosting cPanel, senza root e senza
> demoni propri.
>
> Le voci **barrate** non sono più in uso, e accanto c'è cosa le ha sostituite e
> perché. Il registro completo è in [`DECISIONS.md`](DECISIONS.md).
>
> Il §0 di questo documento chiedeva di verificare la compatibilità prima di
> scrivere codice. È stato fatto, e ha pagato: il rischio temuto — «i plugin
> Filament non sono pronti per la v5» — **non si è presentato** (Filament 5.7 e
> Livewire 4.4 sono maturi, D2). Le rotture vere erano altrove, tutte causate
> da `guzzlehttp/guzzle` 8 che Laravel 13 porta con sé: Pulse, Socialite e
> Web Push non erano installabili.
>
> È la ragione per cui quella verifica va rifatta a ogni ripresa del progetto:
> non conferma ciò che ci si aspetta, trova ciò che non ci si aspettava. E va
> rifatta anche **in senso inverso**, sulle esclusioni: Web Push è rientrato
> il 2026-09-04 (D54) perché il canale ha smesso di dipendere da Guzzle, e
> nessuno se ne sarebbe accorto rileggendo la decisione invece dei pacchetti.

---

## 0. Come leggere questo file

Le versioni sono espresse come **vincoli semantici** (`^13.0`), non come numeri esatti: i numeri invecchiano in settimane. Le major sono verificate alla data di stesura; le minor no.

Primo task di F0, prima di scrivere qualunque riga di codice:

```bash
composer outdated --direct
npm outdated
composer why-not filament/filament 5.0    # per ogni pacchetto sospetto
```

Se un pacchetto non è compatibile con Filament 5 / Livewire 4, la scelta non è "aspettiamo": è **registrare la decisione in `docs/DECISIONS.md`** e ripiegare su Filament 4 + Livewire 3, che non porta perdita di funzionalità (§4 del piano).

Legenda: ✅ major verificata · ⚠️ trappola nota · 🔁 sostituibile senza toccare il core.

---

## 1. Runtime e infrastruttura

| Componente | Versione | Ruolo | Note |
|---|---|---|---|
| **PHP** | **8.4 obbligatorio** | Runtime | Non 8.3: `spatie/laravel-activitylog` 5.1, `spatie/laravel-sitemap` 8.2 e `symfony/*` 8.1 richiedono `^8.4` (D1). Dichiarato in `composer.json` insieme a dodici estensioni |
| ~~PostgreSQL~~ **MariaDB** | 10.11+ | Database | D3: nessun demone PostgreSQL sulla shared hosting. Tipi spaziali `POINT` + `SPATIAL INDEX`, sempre `POINT(lng, lat)` con SRID 0 (D4) |
| ~~PostGIS~~ | — | Query geospaziali | Sostituito da `MBRContains` (usa l'indice) più `ST_Distance_Sphere` (raffina il quadrato in cerchio), dietro `GeoQueryInterface` |
| ~~Redis~~ | — | Cache, sessioni, code, lock | D5: non installabile. Cache su file, code e sessioni su database. Il worker delle notifiche preleva con `SELECT … FOR UPDATE SKIP LOCKED`, e la garanzia contro il doppio invio resta `dedupe_key UNIQUE` |
| ~~Meilisearch~~ | — | Ricerca full-text | D5: non installabile. Scout con driver `database` e indici FULLTEXT MariaDB |
| **Node.js** | 22 LTS | Build asset | Solo build-time, non a runtime |
| **Nginx** o **Caddy** | — | Web server | Caddy se si vuole HTTPS automatico senza pensieri |
| ~~Docker + Compose~~ | — | Ambiente locale | Ambiente nativo: PHP 8.4 e MariaDB già sulla macchina. Il bersaglio è shared hosting senza root, dove Docker non esiste |

⚠️ **Imagick con libheif e libavif.** Le locandine arrivano da iPhone in HEIC e vanno servite in AVIF. Se l'immagine PHP dell'ambiente non ha queste librerie compilate, metà degli upload dei gestori fallirà silenziosamente. Verificare in F0 con `php -r 'print_r(Imagick::queryFormats());'` e includere le librerie nel Dockerfile.

---

## 2. Framework e pannelli

| Pacchetto | Vincolo | Ruolo | Note |
|---|---|---|---|
| `laravel/framework` | `^13.0` | Framework | ✅ release del 17 marzo 2026, PHP 8.3+ |
| `filament/filament` | `^5.0` | Pannelli `/admin` e `/gestione` | ✅ 16 gennaio 2026, richiede Livewire 4. Nessuna funzionalità nuova rispetto a v4 |
| `livewire/livewire` | `^4.0` | Interattività server-driven | ⚠️ i tag componente vanno auto-chiusi (`<livewire:x />`); `wire:transition` usa le View Transitions API |
| ~~`laravel/horizon`~~ | — | Supervisione code | Richiede Redis (D5). Al suo posto `spatie/laravel-health` e `spatie/laravel-schedule-monitor`, che avvisano se il worker smette di girare — il guasto silenzioso da cui Horizon doveva proteggere |
| `laravel/pulse` | `^1.0` | Metriche applicative | 🔁 opzionale |
| `laravel/pennant` | `^1.0` | Feature flag | Serve per accendere funzioni per singola città |
| `laravel/sanctum` | `^4.0` | Token API per le app | |
| ~~`laravel/socialite`~~ | — | Login Google e Apple | D7: incompatibile con Guzzle 8, che Laravel 13 porta con sé. Rimandato |

---

## 3. Dominio: date, ricorrenze, calendari

Il cuore del prodotto. Questa sezione merita più attenzione di tutte le altre.

| Pacchetto | Vincolo | Ruolo | Note |
|---|---|---|---|
| `nesbot/carbon` | incluso in Laravel | Manipolazione date | Già presente. **Non installare altre librerie di date** |
| `rlanvin/php-rrule` | `^2.0` | Parsing e generazione RRULE (RFC 5545) | Preferito a `simshaun/recurr`: più manutenuto, API più pulita, gestisce EXDATE correttamente |
| `sabre/vobject` | `^4.5` | **Lettura** file ICS in import | Standard de facto per iCalendar in PHP |
| `spatie/icalendar-generator` | `^2.0` | **Scrittura** feed `.ics` pubblici | Due librerie diverse per le due direzioni: è normale, non è ridondanza |

⚠️ **Attenzione ai fusi orari nell'import ICS.** Un file ICS può contenere date fluttuanti (senza fuso), date UTC (`Z`) o date con `TZID`. Il driver di import deve gestire tutti e tre i casi e normalizzare a UTC applicando il fuso della città come default per le fluttuanti. È la prima fonte di bug in ogni sistema di calendario.

⚠️ **Ora legale.** `Europe/Rome` ha due giorni all'anno in cui un'ora non esiste e un'ora esiste due volte. Le occorrenze ricorrenti generate a cavallo di quelle date vanno testate (§18 del piano).

### Calendario nell'interfaccia
Per la griglia mensile pubblica **non serve una libreria**: è una tabella di 42 celle con conteggi aggregati, e va scritta a mano in Blade con Alpine. FullCalendar sarebbe 250 KB di JavaScript per un problema che non hai.

| Libreria | Quando usarla | Note |
|---|---|---|
| **Nessuna** (Blade + Alpine) | Calendario mensile pubblico | Raccomandato |
| `@fullcalendar/core` | Solo se serve una vista risorse/timeline nell'admin | ⚠️ le viste premium (resource timeline) sono a **licenza commerciale a pagamento**: verificare prima di adottarle |
| `flatpickr` | Selettore data/ora nel wizard evento | Leggero, buona UX mobile, localizzabile in italiano |

---

## 4. Geospaziale e mappe

| Pacchetto | Vincolo | Ruolo | Note |
|---|---|---|---|
| ~~`clickbar/laravel-magellan`~~ **`matanyadaev/laravel-eloquent-spatial`** | `^4.8` | Tipi spaziali in Eloquent | Magellan è solo per PostGIS |
| — alternativa — `matanyadaev/laravel-eloquent-spatial` | `^4.0` | Idem, orientato a MySQL | Usare solo se si ripiega su MySQL |
| ~~`maplibre-gl`~~ **`leaflet`** | `^1.9` | Mappa nel browser | Il disegno adottato inverte le tile in scala di grigi con un filtro CSS: MapLibre le disegna in WebGL, dove i filtri CSS non arrivano. Leaflet le dispone come normali elementi del documento — e pesa molto meno |
| `@turf/turf` (npm) | `^7.0` | Calcoli geometrici lato client | 🔁 solo se serve clustering custom o buffer; con Leaflet il clustering si aggiunge con `leaflet.markercluster` |
| `pmtiles` (npm) | `^4.0` | Lettura tile da singolo file statico | Solo se si sceglie l'hosting tile self-serve |

### Tile server — tre strade
| Opzione | Costo | Note |
|---|---|---|
| **OpenFreeMap** | gratuito | Nessuna chiave, nessun limite dichiarato. Ottimo per partire |
| **Protomaps + PMTiles su R2** | costo storage ≈ nullo | Un file `.pmtiles` dell'Italia servito da CDN. Controllo totale, zero dipendenze da terzi |
| **MapTiler / Stadia** | a consumo | Se serve supporto commerciale e stili pronti |

### Geocoding
⚠️ **Trappola grossa.** L'API pubblica di Nominatim di OpenStreetMap **vieta esplicitamente l'uso massivo e automatizzato**. Geocodificare centinaia di locali contro l'endpoint pubblico porta al ban dell'IP. Le strade praticabili:

| Opzione | Note |
|---|---|
| **Photon** (Komoot) self-hosted | Consigliata: veloce, autocomplete nativo, dataset OSM |
| **Nominatim self-hosted** | Pesante da mantenere (import planet o estratto Italia) ma senza limiti |
| **Geoapify / LocationIQ** | Piani gratuiti generosi, API key, buona qualità sugli indirizzi italiani |
| Nominatim pubblico | **Solo per test manuali**, mai in produzione |

Tutto dietro `GeocodingServiceInterface`: cambiare provider deve costare una riga nel service container.

**Piano B sempre attivo:** se il geocoding fallisce, il form mostra un marker trascinabile sulla mappa e il gestore posiziona il locale a mano. Non bloccare mai un'iscrizione perché un servizio esterno non ha riconosciuto un indirizzo.

---

## 5. Media e immagini

| Pacchetto | Vincolo | Ruolo | Note |
|---|---|---|---|
| `spatie/laravel-medialibrary` | `^11.0` | Gestione allegati e conversioni | Collezioni `poster`, `gallery`, `logo`, `cover` |
| `spatie/image` | `^3.0` | Manipolazione immagini | Supporta **libvips**: molto più veloce e leggero di GD su immagini grandi |
| `spatie/laravel-image-optimizer` | `^1.7` | Ottimizzazione post-conversione | Richiede i binari `jpegoptim`, `optipng`, `cwebp`, `avifenc` nel container |
| `kornrunner/blurhash` | `^1.2` | Placeholder sfocati | 🔁 alternativa: ThumbHash, più compatto |
| `league/flysystem-aws-s3-v3` | `^3.0` | Storage S3-compatible | Cloudflare R2 o Hetzner Object Storage |

### Immagini Open Graph generate
| Opzione | Note |
|---|---|
| **Composizione con `spatie/image`** | Raccomandata: locandina + titolo + data su canvas, in coda, nessun browser headless |
| `spatie/browsershot` | Permette layout HTML complessi ma richiede Chrome nel container: +300 MB e una fonte di fragilità in più |

---

## 6. Ricerca, tassonomie, contenuti

| Pacchetto | Vincolo | Ruolo |
|---|---|---|
| `laravel/scout` | `^10.0` | Astrazione ricerca |
| `meilisearch/meilisearch-php` | `^1.0` | Driver Meilisearch |
| `spatie/laravel-sluggable` | `^3.0` | Slug unici per città |
| `spatie/laravel-tags` | `^4.0` | 🔁 opzionale: i tag sono già modellati a mano nello schema. Usare solo se si vuole il plugin Filament pronto |
| `spatie/laravel-query-builder` | `^6.0` | Filtri e sort dai parametri URL/API in modo dichiarativo |
| `spatie/laravel-activitylog` | `^4.0` | Audit trail su cambi di stato di eventi e locali |
| `spatie/laravel-permission` | `^6.0` | Ruoli e permessi |
| `spatie/laravel-sitemap` | `^7.0` | Sitemap dinamica |

---

## 7. Notifiche

| Pacchetto | Vincolo | Ruolo | Note |
|---|---|---|---|
| `minishlink/web-push` | `^11.0` | Web Push (VAPID) | Arriva come dipendenza del canale. D8 lo escludeva, D54 lo riapre: la catena `web-token` → `brick/math` è installabile |
| `laravel-notification-channels/webpush` | `^12.1` | Canale Laravel per Web Push | Le iscrizioni stanno in `devices`, non nella tabella del pacchetto: vedi `App\Models\WebPushSubscription` |
| `laravel-notification-channels/fcm` | `^5.0` | Canale FCM | Serve in F11 con le app native |
| `spatie/laravel-schedule-monitor` | `^3.0` | Allarme se uno scheduled task smette di girare | Il worker delle notifiche è critico: se muore in silenzio nessuno riceve più promemoria |

⚠️ **Web Push su iOS** funziona solo se l'utente ha installato il sito come PWA dalla schermata home. Su Android e desktop funziona dal browser. L'email resta il fallback obbligatorio (§15.6 del piano).

⚠️ **Service worker scritto a mano.** Non serve un framework PWA: un `sw.js` di un centinaio di righe (cache dello shell + gestione `push` e `notificationclick`) è più leggibile e più facile da debuggare di `vite-plugin-pwa` con la sua configurazione generata.

---

## 8. Email

| Componente | Ruolo | Note |
|---|---|---|
| **Postmark** o **Brevo** | Invio transazionale | Postmark ha la deliverability migliore; Brevo include la newsletter nello stesso piano |
| `mailpit` | Cattura email in locale | Nel Compose, mai in produzione |
| Template email | Componenti Blade | ⚠️ i client email non supportano CSS moderno: tabelle e stili inline. Testare su Gmail, Apple Mail, Outlook |

Configurare **SPF, DKIM e DMARC** sul dominio prima del primo invio massivo: senza, la newsletter finisce in spam e la reputazione del dominio si brucia una volta sola.

---

## 9. API e documentazione

| Pacchetto | Vincolo | Ruolo |
|---|---|---|
| `dedoc/scramble` | `^0.12` | OpenAPI 3.1 generato dal codice, esposto su `/docs/api` |
| Laravel API Resources | nativo | Serializzazione. Laravel 13 include risorse JSON:API native: valutarle, ma **la forma delle risposte resta quella definita in §13.6 del piano** |

---

## 10. Qualità, test, CI

| Pacchetto | Vincolo | Ruolo | Note |
|---|---|---|---|
| `pestphp/pest` | `^4.0` | Test | ✅ v4 include il **browser testing** |
| `pestphp/pest-plugin-laravel` | `^4.0` | Helper Laravel | |
| `pestphp/pest-plugin-browser` | `^4.0` | Test end-to-end su browser reale | Basato su Playwright: `npx playwright install` |
| `larastan/larastan` | `^3.0` | Analisi statica, livello 6+ | |
| `laravel/pint` | `^1.0` | Formattazione, preset `laravel` | |
| `@lhci/cli` (npm) | `^0.14` | Lighthouse CI con soglie bloccanti | |

⚠️ **Laravel Dusk non va usato.** Il browser testing di Pest 4 lo sostituisce integralmente. Attenzione se l'agente genera codice misto: `visit('/')` è Pest, `$this->browse()` è Dusk, e in Pest `wait()` prende **secondi**, non millisecondi. Metterlo esplicitamente nelle istruzioni dell'agente.

---

## 11. Frontend

| Pacchetto | Vincolo | Ruolo | Note |
|---|---|---|---|
| `tailwindcss` | `^4.2` | CSS | ✅ configurazione **CSS-first**: niente `tailwind.config.js`, tutto in `@theme` |
| `@tailwindcss/vite` | `^4.2` | Integrazione build | |
| `alpinejs` | `^3.0` | Interattività locale | Già incluso da Livewire |
| `vite` | `^7.0` | Build | |
| `leaflet` | `^1.9` | Mappe | vedi §4 |
| `flatpickr` | `^4.6` | Date picker | |
| `@fontsource/*` | — | Font self-hosted | Niente Google Fonts da CDN: è un problema GDPR risolvibile in dieci minuti |

Nessun framework SPA. Nessun jQuery. Nessuna libreria di date lato client: le date arrivano dal server già formattate per l'utente.

---

## 12. Operatività e monitoraggio

| Componente | Ruolo | Note |
|---|---|---|
| `spatie/laravel-backup` `^9.0` | Backup DB e storage | ⚠️ configurare anche la **notifica di backup fallito**: un backup che non gira e non avvisa è peggio di nessun backup |
| `spatie/laravel-health` `^1.0` | Health check (DB, Redis, code, storage, spazio disco) | Esposto su endpoint protetto per l'uptime monitor |
| **Sentry** | Error tracking | 🔁 alternativa self-hosted e completamente open source: **GlitchTip**, compatibile con gli SDK Sentry |
| **Plausible** o **Umami** | Analytics privacy-first | Self-hosted: nessun trasferimento extra-UE, banner cookie molto più semplice |
| **Uptime Kuma** | Monitor uptime self-hosted | 🔁 |
| **Cloudflare Turnstile** | Captcha su form pubblici | Gratuito, meno invasivo di reCAPTCHA, nessun cookie di profilazione |

---

## 13. Deploy

| Opzione | Costo indicativo | Note |
|---|---|---|
| **VPS Hetzner** (CX/CPX) + Docker | 5–20 €/mese | Massimo controllo, richiede gestione manuale |
| **Laravel Forge** + VPS | 12 $/mese + VPS | Deploy, code, certificati e backup gestiti: la scelta pragmatica per un solo sviluppatore |
| **Laravel Cloud** | a consumo | Zero gestione infrastruttura, costo meno prevedibile |
| **Coolify** self-hosted | costo del VPS | Alternativa open source a Forge |

Storage oggetti: **Cloudflare R2** (nessun costo di egress, punto decisivo per un sito pieno di immagini) oppure Hetzner Object Storage.

CI: GitHub Actions — `pint --test` → `larastan` → `pest` → `lighthouse-ci`.

---

## 14. Fase mobile (F11)

Da installare solo quando sito, API e contenuti sono stabili.

| Pacchetto | Ruolo |
|---|---|
| `expo` (SDK corrente) + `react-native` | Base, codebase unico Android/iOS |
| `expo-router` | Navigazione file-based |
| `typescript` | Tipi condivisi con l'API generati da OpenAPI |
| `@tanstack/react-query` | Fetch, cache, sincronizzazione con `updated_since` |
| `react-native-mmkv` | Storage locale veloce per la cache offline 7 giorni |
| `react-native-maps` oppure `@maplibre/maplibre-react-native` | Mappa nativa. Nota: sul sito si è passati a Leaflet, che non ha un corrispettivo nativo — la mappa dell'app userà una libreria diversa da quella del web, e i due disegni andranno tenuti allineati a mano |
| `expo-notifications` | Push |
| `expo-location` | "Vicino a me" |
| `expo-calendar` | "Aggiungi al calendario" di sistema |
| `expo-sharing` | Condivisione nativa |
| `expo-image` | Immagini con cache e blurhash |
| **EAS Build / Submit / Update** | Build, pubblicazione e aggiornamenti OTA |

⚠️ Se si attiva un login social su iOS, **Sign in with Apple diventa obbligatorio** per la pubblicazione su App Store. Ed è richiesta la **cancellazione account dentro l'app**, non solo sul sito.

---

## 15. Licenze e attribuzioni obbligatorie

Da sistemare prima del lancio, non dopo.

| Elemento | Obbligo |
|---|---|
| Dati OpenStreetMap | Attribuzione **ODbL** visibile sulla mappa: "© OpenStreetMap contributors" |
| Leaflet | BSD-2, attribuzione nei crediti |
| Font | Verificare la licenza di ogni famiglia self-hosted (OFL nella maggior parte dei casi) |
| Locandine caricate dai locali | Dichiarazione di titolarità in fase di iscrizione + procedura di rimozione (§16 del piano) |
| Pacchetti GPL/AGPL | Nessuno di quelli elencati qui lo è. **Verificare prima di aggiungerne di nuovi**: un pacchetto AGPL in un progetto che offrirà servizi a pagamento è un problema legale, non un dettaglio |

---

## 16. Cosa non usare, e perché

| Da evitare | Motivo |
|---|---|
| Google Maps JS API come dipendenza obbligatoria | Costi imprevedibili a volume, API key, vendor lock-in. Un deep link a Google Maps per le indicazioni è un'altra cosa: quello va bene e non costa niente |
| Laravel Dusk | Sostituito dal browser testing di Pest 4 |
| FullCalendar per il calendario pubblico | 250 KB per una griglia di 42 celle; e le viste avanzate sono a pagamento |
| Moment.js, Luxon lato client | Le date le formatta il server, che conosce il fuso della città |
| localStorage per dati che devono sopravvivere | Solo per i salvataggi guest pre-registrazione, che sono per definizione temporanei |
| Google Fonts da CDN | Trasferimento dati verso terzi senza consenso |
| Google Analytics | Rende il cookie banner più invasivo e la conformità più fragile, per dati che qui non servono |
| Un framework SPA per il sito pubblico | Distrugge il canale di acquisizione principale, che è la ricerca organica |
| API pubblica di Nominatim in produzione | Uso vietato dalla policy, ban dell'IP |

### Pacchetti installati, valutati e rimossi (2026-09-04)

Erano in `composer.json` senza una sola riga di codice che li usasse. Una
dipendenza dichiarata e mai usata sembra una funzione esistente a chi legge il
file, ed è l'ambiguità che questo progetto evita altrove.

| Rimosso | Motivo |
|---|---|
| `spatie/laravel-webhook-server` | Nessun destinatario in uscita, né oggi né nel piano: le uniche integrazioni sono **in entrata** (import iCal di §14.2, segnale di rilascio di `DeployController`), e in `app/` non esiste una sola chiamata HTTP verso l'esterno che non sia geocoding o import. Costruirlo ora significherebbe inventare il consumatore per giustificare la dipendenza, e ciò che nascerebbe è una superficie in uscita — URL scelti da terzi, segreti di firma, tentativi in coda — che nessuno usa. Si reinstalla in un minuto quando un locale o un portale chiederà di essere avvisato |
| `spatie/laravel-translatable` | Il multilingua qui non passa da colonne traducibili. Renderle traducibili significa trasformarle in JSON, e tre cose ci sbattono contro: gli indici `FULLTEXT` su `events.description` e `venues.description`, che senza Scout su Typesense sono ciò che fa funzionare la ricerca, indicizzerebbero le chiavi di lingua e tutte le lingue insieme; le query scritte a mano (`EventOccurrenceQuery`, `EditorialDashboardQuery`, che confronta i titoli per trovare i doppioni di §14.4) tornerebbero a confrontare JSON; e `HasSlug` su titoli e nomi diventerebbe uno slug per lingua, cioè indirizzi pubblici diversi, che §11 dichiara immutabili. In più i contenuti li scrivono i gestori dei locali o li porta l'import iCal: nessuno dei due produrrà mai una versione inglese, e `events.language` dice già in che lingua si **svolge** un evento, che è il dato utile allo studente o al turista. Quando la lingua servirà, la strada è quella già predisposta: `lang/en` da riempire, la lingua in `config/seo.php`, il prefisso di rotta. Traduce l'interfaccia, che il progetto controlla, e lascia stare i contenuti, che non controlla |
| `bezhansalleh/filament-shield` | `DeployCommand` esegue `db:seed --class=RolesAndPermissionsSeeder` a **ogni** rilascio, e quel seeder chiama `syncPermissions()` su tutti e sei i ruoli: qualunque spunta messa dall'interfaccia verrebbe azzerata al rilascio successivo, senza un errore e senza un avviso. Un pannello che accetta le modifiche e le perde in silenzio è peggio di nessun pannello. Toglierlo, quel difetto, vorrebbe dire smontare l'impianto scelto — `App\Enums\Permission` è il catalogo, il seeder assegna, le diciassette Policy leggono — e ritrovarsi due sorgenti di verità che divergono senza che si veda da nessuna parte. In più il generatore riscriverebbe le Policy (che `ScopesToVenueMembership` costruisce sul `venue_id`), `panel_user` creerebbe un ruolo che `UserRole` non conosce, e la `RoleResource` senza una `RolePolicy` sarebbe aperta a chiunque entri in `/admin` — Filament, quando una Policy non esiste, risponde `allow`. Il bisogno però è reale: nessuna schermata mostra oggi cosa comporti un ruolo. La risposta giusta è una pagina di sola lettura che disegni la matrice ruoli × permessi leggendo gli enum e le assegnazioni effettive, e che mostri gli scostamenti fra ciò che l'enum prescrive e ciò che c'è nel database — l'unico controllo che oggi non esiste in nessuna forma |

Una nota che vale oltre questi tre casi: **la sorgente di verità dei permessi è
il seeder**. Finché è così, nessuna interfaccia può modificarli senza mentire.

---

## 17. Comandi di installazione

```bash
# core
composer require filament/filament laravel/horizon laravel/pennant \
  laravel/sanctum laravel/socialite laravel/scout meilisearch/meilisearch-php

# dominio
composer require rlanvin/php-rrule sabre/vobject spatie/icalendar-generator

# geo
composer require clickbar/laravel-magellan

# media
composer require spatie/laravel-medialibrary spatie/image \
  spatie/laravel-image-optimizer kornrunner/blurhash league/flysystem-aws-s3-v3

# contenuti e permessi
composer require spatie/laravel-permission spatie/laravel-sluggable \
  spatie/laravel-activitylog spatie/laravel-query-builder spatie/laravel-sitemap

# notifiche
# Web Push e attivo (D54): le chiavi VAPID stanno in .env, vedi .env.example.
composer require \
  spatie/laravel-schedule-monitor

# operatività
composer require spatie/laravel-backup spatie/laravel-health sentry/sentry-laravel

# API docs
composer require dedoc/scramble

# dev
composer require --dev pestphp/pest pestphp/pest-plugin-laravel \
  pestphp/pest-plugin-browser larastan/larastan laravel/pint
npx playwright install

# frontend
npm i -D tailwindcss @tailwindcss/vite vite
npm i leaflet flatpickr
```

---

## 18. Riepilogo delle decisioni da confermare in F0

1. Filament 5 + Livewire 4, oppure Filament 4 + Livewire 3 se un plugin indispensabile non è pronto.
2. Tile server: OpenFreeMap (subito) o Protomaps su R2 (controllo totale).
3. Geocoding: Photon self-hosted o Geoapify/LocationIQ.
4. Error tracking: Sentry o GlitchTip.
5. Hosting: Forge + Hetzner, oppure Laravel Cloud, oppure Coolify.
6. Provider email: Postmark (deliverability) o Brevo (newsletter inclusa).
7. ~~Web Push al lancio o solo email~~ — deciso: entrambi (D54). Push a chi ha un browser iscritto, email a tutti gli altri (§15.6). Su iOS serve il sito installato sulla schermata Home, e la pagina delle preferenze lo dice.

Ogni voce va chiusa con una riga in `docs/DECISIONS.md`: cosa, perché, quando ricontrollare.

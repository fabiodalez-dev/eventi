# Piattaforma Eventi — Design della demo funzionante

**Data:** 23 agosto 2026
**Fonti:** `docs/piano-piattaforma-eventi-v2.md` (§ citati), `docs/stack-tecnologico.md`
**Obiettivo:** demo funzionante e seedata, pubblicata su `eventi.fabiodalez.it`, che copra la Fase 1 del piano.
**Stato:** approvato dal committente il 23/08/2026.

---

## 1. Scope

Il committente ha selezionato l'intera Fase 1 del piano: F1 (dominio), F2 (admin),
F3 (pannello locali), F4 (motore temporale), F5 (sito pubblico), F6 (media/SEO/cache),
F7 (API v1), F7b (account, salvataggi, notifiche), più i **11 scenari end-to-end di §18**
con browser testing.

Lo scope non viene ridotto. Viene ordinato in checkpoint dove ogni tappa è già
dimostrabile, così che un'interruzione lasci un prodotto intero e non un cantiere.

| # | Checkpoint | Dimostrabile |
|---|---|---|
| C1 | Dominio, schema, seed, motore temporale | test verdi, 25 locali e ~150 occorrenze in DB |
| C2 | Admin `/admin` | città → locale → evento → 3 occorrenze → pubblicazione |
| C3 | Sito pubblico | **prima demo visitabile**: home popolata, filtri, mappa, scheda evento |
| C4 | API v1 + OpenAPI | `/docs/api`, scenario G (sito e API coerenti nello stesso istante) |
| C5 | `/gestione` locali | wizard ≤ 90 secondi da smartphone, duplica, ricorrenze |
| C6 | Account e notifiche | salvataggi guest, merge, follow, feed, `scheduled_notifications` |
| C7 | Media, SEO, cache, deploy | pipeline immagini, JSON-LD, sitemap, cache §12.3, Lighthouse CI |

Il controllo torna al committente alla fine di **C1** e di **C3**: sono i due punti
in cui un errore si propagherebbe a tutto ciò che segue.

## 2. Criteri di accettazione

Concordati esplicitamente. La demo è finita quando tutti e quattro sono verdi.

1. **Apro il sito e trovo qualcosa da fare.** Home con sezioni popolate, "in corso adesso"
   non vuota, ≥ 3 eventi in quasi tutti i prossimi 14 giorni, percorso
   *home → Musica + Stasera + Gratis* in massimo 3 interazioni, URL condivisibile
   che rende la stessa pagina.
2. **L'API si regge da sola come contratto.** OpenAPI su `/docs/api`, `preset=ongoing`
   e `preset=starting_soon` identici al sito nello stesso istante, cursori ed ETag.
3. **Pubblico un evento come farebbe un locale.** Login in `/gestione` da smartphone,
   wizard cronometrato ≤ 90 secondi, evento visibile sul sito pubblico.
4. **Il ciclo di moderazione funziona.** Da `/admin`: creo città, approvo un locale,
   pubblico un evento in coda, annullo un'occorrenza e partono le notifiche di
   annullamento a chi l'aveva salvata.

## 3. Ambiente di destinazione — vincoli rilevati

`fabiodalez.it` è cPanel shared hosting su CloudLinux (`cpanel10.vhosting-it.com`),
senza root, senza Docker, senza demoni propri.

| Presente | Assente |
|---|---|
| PHP 8.3.33 / **8.4.24** / 8.5.9 (ea-php) | PostgreSQL server |
| Imagick **con HEIC e AVIF** | Redis server |
| MariaDB 10.11 | Meilisearch |
| Composer 2.10, git, node 24, cron | Docker |
| `proc_open`, `memory_limit` 512M | `avifenc`, `jpegoptim`, `optipng` |

Nota: la trappola ⚠️ segnalata a §1 dello stack tecnologico (Imagick privo di
libheif/libavif, che farebbe fallire in silenzio metà degli upload dei gestori)
**non si presenta**: entrambi i formati sono compilati.

### Verifica del task bloccante F0 (§4 del piano)

Eseguita su Packagist il 23/08/2026. Esito: **nessun ripiego necessario.**

| Pacchetto | Versione reale | PHP richiesto |
|---|---|---|
| `laravel/framework` | 13.26.1 | ^8.3 |
| `filament/filament` | 5.7.6 | ^8.2 |
| `livewire/livewire` | 4.4.1 | ^8.1 |
| `spatie/laravel-activitylog` | 5.1.0 | **^8.4** |
| `spatie/laravel-sitemap` | 8.2.0 | **^8.4** |
| `pestphp/pest` | 5.1.1 | **^8.4** |

Filament 5 + Livewire 4 sono maturi: si adotta lo stack previsto dal piano senza
ripiegare su Filament 4.

## 4. Deviazioni dal piano

Ogni riga è replicata in `docs/DECISIONS.md` con data e motivo.

| Il piano prescrive | Si adotta | Motivo | Costo del ritorno |
|---|---|---|---|
| PHP 8.3 minimo | **PHP 8.4** | tre pacchetti richiedono `^8.4`; server e macchina di sviluppo lo hanno | nullo |
| PostgreSQL + PostGIS | **MariaDB** + `POINT` + `SPATIAL INDEX` | nessun demone Postgres installabile sul server | una classe + una migration |
| Redis | `database` (queue), `file`/`apcu` (cache) | nessun demone Redis | tre righe di `.env` |
| Meilisearch | Scout driver `database` + FULLTEXT | nessun demone Meilisearch | una riga di `.env` |
| `clickbar/laravel-magellan` | `matanyadaev/laravel-eloquent-spatial` | Magellan è PostGIS-only | sostituzione dietro l'interfaccia |
| Docker Compose | ambiente locale nativo (PHP 8.4 + MySQL 3306) | shared hosting senza root | — |
| Laravel Horizon | `queue:work` via cron + Laravel Pulse | Horizon richiede Redis | ritorna con Redis |

**Vincolo che rende reversibili tutte queste scelte:** nessuna può essere spalmata nel
codice applicativo. Vivono dietro `GeoQueryInterface` e `SearchServiceInterface`, e nel
`.env`. Migrare a un VPS significa registrare implementazioni diverse nel service
container, non riscrivere query. È l'applicazione diretta del criterio §21 del piano.

## 5. Architettura

Struttura di §6 del piano, invariata. Domini: Cities · Venues · Events · Occurrences ·
Taxonomies · Users · Moderation · Imports · Notifications · Analytics.

### 5.1 `EventOccurrenceQuery` — cuore logico

Contratto di §8 invariato. È l'**unico** posto del codice dove esistono i concetti di
"oggi", "stasera", "in corso", "inizia tra poco". Una seconda implementazione di uno di
questi concetti, ovunque essa compaia, è un bug da correggere subito (§5.6).

Due punti in cui MariaDB cambia l'implementazione ma non il contratto:

- **`->near($lat,$lng,$km)`** delega a `GeoQueryInterface`. `MariaDbGeoQuery` filtra con
  `MBRContains(envelope, location)` per usare lo `SPATIAL INDEX`, poi raffina con
  `ST_Distance_Sphere`. Il solo bounding box restituirebbe un quadrato, non un cerchio:
  servono entrambi i passaggi.
- **worker notifiche**: preleva le righe `pending` con `SELECT … FOR UPDATE SKIP LOCKED`
  in transazione (MariaDB 10.6+). Senza lock Redis, il vincolo `dedupe_key UNIQUE` a
  livello di database è l'unica garanzia reale contro il doppio invio — che è già ciò
  che §7.10 prescrive, quindi non è un compromesso introdotto qui.

### 5.2 Colonne calcolate

`business_date` ed `effective_ends_at` restano colonne **persistite e indicizzate**,
calcolate da observer su `EventOccurrence`, ricalcolate a ogni modifica di `starts_at`
o della categoria dell'evento padre (§8.2, §8.3).

## 6. Schema

Schema di §7 integralmente, con due aggiunte derivanti dalla decisione sulla
monetizzazione (gratuito per no-profit, a pagamento per commerciali):

```
venues      + is_nonprofit (bool, default false)
            + plan (enum free|premium, default free)
promotions  (tabella creata, vuota, nessuna interfaccia in v1)
```

Nessun pagamento viene implementato. I Termini si scrivono già prevedendo la
possibilità, come richiede §20.4.

## 7. Frontend

Blade + Livewire 4 + Tailwind 4 (configurazione CSS-first, nessun `tailwind.config.js`)
+ Alpine. **Nessuna SPA** (§4, §11).

Moderno nell'aspetto, non nell'architettura: dark mode via `prefers-color-scheme`,
View Transitions sui cambi di filtro, skeleton di caricamento, bottom sheet su mobile
per la mappa, card evento come componente unico riusato ovunque (§11.4).

Vincoli non negoziabili: tutti i filtri in query string, URL condivisibile e
indicizzabile, lista navigabile **anche senza JavaScript** (§11.3), zero contenitori
vuoti (§8.6).

Mappa: MapLibre GL + tile OpenFreeMap, attribuzione OSM (§15 dello stack). Le coordinate
delle 25 sedi sono seedate a mano: nessuna chiamata a Nominatim, che ne vieta l'uso
automatizzato.

Nome del prodotto: `config('app.name')` più una stringa in `lang/it`, segnaposto
`inCittà`. Il committente sceglierà il nome definitivo; cambiarlo costerà una riga.

Lingua: italiano completo in `lang/it`, **nessuna stringa hardcoded** (§5.4);
`lang/en` e `lang/de` predisposti e vuoti.

## 8. Dati di seed

Provincia di Padova. 25 locali con nomi e indirizzi plausibili e **coordinate reali**,
~150 occorrenze future distribuite sui prossimi 30 giorni, con ≥ 3 eventi nella maggior
parte dei prossimi 14 giorni e alcune occorrenze **in corso nell'istante di apertura**,
perché le sezioni "in corso adesso" e "inizia tra poco" siano visibili davvero (§2.2).

Locandine: 5-6 fotografie libere diverse per categoria, così che due eventi della stessa
serata non mostrino la stessa immagine. Passano per la pipeline media reale
(WebP/AVIF, varianti, blurhash, OG 1200×630). Licenze in `docs/CREDITS.md`.

## 9. Account e notifiche (F7b)

Implementato per intero, incluso il motore di invio.

- Salvataggio **guest** in `localStorage`, banner al terzo salvataggio, migrazione
  all'account via `POST /v1/me/saved/merge` (§15.1).
- Si salva l'**occorrenza**, non l'evento; selettore date se le occorrenze future sono
  più d'una (§15.3).
- `scheduled_notifications` con `dedupe_key UNIQUE`, observer di riprogrammazione e
  annullamento, quiet hours, cap di 2 push al giorno, canale con fallback
  push → email → archivio in-app (§15.5, §15.6).
- Email reali via SMTP del dominio.

## 10. Test

Tutti gli 11 scenari di §18, con Pest 5 e browser testing su Playwright (locale e CI,
non sulla shared hosting). Copertura obbligatoria e non negoziabile su:

- casi limite temporali: `23:59`, `00:00`, `05:59`, `06:00`, nightlife sì/no,
  ora legale e ora solare di `Europe/Rome`;
- scenario F: isolamento fra locali, anche manipolando URL e ID direttamente;
- scenario G: sito e API coerenti nello stesso istante;
- scenari I/J/K: riprogrammazione, annullamento di massa, cap di volume.

Qualità: Pint (preset `laravel`), Larastan livello 6+, Lighthouse CI con soglie
bloccanti (≥ 90 su Performance, SEO, Accessibility; LCP < 2s).

## 11. Deploy

```
push su main
  → GitHub Actions: pint --test → larastan → pest → vite build
  → rsync via SSH (deploy key dedicata) su ~/eventi
  → artisan migrate --force && optimize && queue:restart
```

Il server non compila nulla: nessun `node_modules`, nessuna build sulla shared hosting.

Sottodominio `eventi.fabiodalez.it` con document root su `~/eventi/public`, creato via
UAPI cPanel. **Il `.htaccess` di root non viene toccato**, il WordPress esistente non
viene sfiorato, i cinque cron già presenti restano invariati; se ne aggiungono due
(scheduler ogni minuto, worker code).

Repository GitHub **privato**. `CLAUDE.md` e `.claude/` non raggiungono mai il remote.

## 12. Rischi noti

| Rischio | Mitigazione |
|---|---|
| Scope pari all'intera Fase 1 | checkpoint C1…C7, ognuno già dimostrabile |
| Nessun lock distribuito per le notifiche | `SKIP LOCKED` + `dedupe_key UNIQUE`, con test dedicato |
| Quota disco della shared hosting | nessun `node_modules` sul server; solo `vendor` e asset compilati |
| MariaDB spatial ≠ PostGIS | `GeoQueryInterface` con test che valgono per entrambe le implementazioni |
| `.htaccess` di root condiviso con altri siti | sottodominio con docroot proprio, root mai modificato |

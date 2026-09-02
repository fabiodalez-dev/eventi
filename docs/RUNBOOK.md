# Runbook operativo

## Coordinate

| Voce | Valore |
|---|---|
| Sito | https://eventi.fabiodalez.it |
| Server | `fabiodalez.it` (alias SSH) = `185.116.60.3`, cPanel `cpanel10.vhosting-it.com` |
| Path app | `/home/fabiodal/eventi` |
| Document root | `/home/fabiodal/eventi/public` |
| PHP | `ea-php84` → `/opt/cpanel/ea-php84/root/usr/bin/php` |
| Database | MariaDB 10.11.18, `fabiodal_eventi`, utente `fabiodal_eventi` |
| Repo | `fabiodalez-dev/eventi` (privato) |

Le credenziali del database vivono **solo** in `/home/fabiodal/eventi/.env` (chmod 600)
e nei secret GitHub. Non sono nel repository.

## Prima installazione su un server nuovo (D42, D44)

Il wizard sta su `/installazione` e va aperto **a quell'indirizzo**: le altre
pagine, prima dell'installazione, rispondono con un errore — la homepage prova
ad aprire la sessione su un database che ancora non esiste (misurato: `GET /`
→ 500 finché la checklist non è conclusa). Sul server servono soltanto i file
e `vendor/`:

```bash
# sul server, nella cartella dell'applicazione
composer install --no-dev --optimize-autoloader
```

Poi si apre `https://<dominio>/installazione` con un browser. Il `.env` e
`APP_KEY` li crea il punto d'ingresso da sé; i sette passi chiedono database,
nome e indirizzo del sito, posta, città, amministratore, ed eseguono la
checklist. **Senza `vendor/` la pagina spiega quale comando dare** invece di
restare bianca.

Alla fine restano **due cose da fare a mano**, e sono nella schermata finale:

1. le due righe di cron della sezione «Cron attivi» più sotto — senza, non
   partono scheduler, code, notifiche, import e backup;
2. le integrazioni che nascono spente (Turnstile, Sentry, analitica, S3), che
   si accendono riempiendo il `.env` e rieseguendo `php artisan config:cache`.

### Perché l'installer non risponde più

Il marcatore è `storage/app/private/install.lock` (JSON: data, nome
dell'applicazione, migrazione più recente). Finché c'è, `/installazione`
risponde **404**. Il deploy non lo tocca mai: `ci.yml` esclude
`storage/app/private/*` dal `rsync --delete`, la stessa riga che protegge i
backup.

Due comportamenti che valgono più del file:

- **Marcatore assente, database vivo** → l'installer lo scrive da sé e risponde
  404. È quello che è successo a `eventi.fabiodalez.it`, in produzione da prima
  che l'installer esistesse: il rilascio che lo ha portato non ha mostrato alcun
  wizard.
- **Trappola sulla macchina di sviluppo:** il `.env` creato da `.env.example`
  punta a `eventi_local`; se quel database esiste ed è popolato, il primo
  accesso a `/installazione` lo scambia per un'installazione preesistente,
  scrive il marcatore e risponde 404. Per provare il wizard in locale si punta
  `DB_DATABASE` a un nome inesistente o a un database vuoto **prima** della
  prima richiesta (è ciò che fa il banco di prova di D44 e della verifica
  finale).
- **Marcatore presente, database rotto** → non risponde «già installato»:
  riprova la connessione e mostra una **diagnosi** con `503` — connessione che
  non si apre, database vuoto, oppure tabelle mancanti elencate — e i comandi da
  dare. Il dettaglio tecnico (PDO, codice errore) sta in
  `storage/logs/laravel.log` e mai in pagina.

### Reinstallare davvero

Non c'è alcun percorso web: reinstallare sopra un sistema vivo cancella dati, ed
è un gesto da SSH fatto da chi sa cosa sta cancellando.

```bash
ssh fabiodalez.it 'rm ~/eventi/storage/app/private/install.lock'
# e, se si vuole davvero ripartire da zero, prima un backup e poi:
# ... artisan db:wipe --force
```

### Dati di base senza faker

`ProductionSeeder` chiama i soli quattro seeder che vivono senza `fakerphp/faker`
— ruoli e permessi, categorie, tag, pagine legali — perché in produzione il
pacchetto non esiste (`composer install --no-dev`). È idempotente:

```bash
ssh fabiodalez.it 'cd ~/eventi && /opt/cpanel/ea-php84/root/usr/bin/php artisan db:seed --class=ProductionSeeder --force'
```

I dati dimostrativi restano fuori: la demo è `migrate:fresh --seed`, in
sviluppo, dove faker c'è.

## Deploy

Automatico: ogni push su `main` fa partire `.github/workflows/ci.yml`.

```
quality (pint + phpstan)  ─┐
                           ├─→ deploy (rsync + migrate + cache + verifica HTTP 200)
tests (pest su MariaDB)   ─┘
```

Il deploy parte **solo se lint, analisi statica e test sono verdi**. Gli asset sono
compilati dal runner: sul server non esiste Node e non viene eseguita alcuna build.

Deploy manuale (emergenza):

```bash
ssh fabiodalez.it
cd ~/eventi
PHP=/opt/cpanel/ea-php84/root/usr/bin/php
$PHP artisan down
git -C /percorso/locale ... # oppure rsync dal proprio Mac
$PHP artisan migrate --force
$PHP artisan optimize
$PHP artisan up
```

## Rollback

```bash
# 1. rimetti il codice alla revisione precedente
gh run list --repo fabiodalez-dev/eventi --limit 5
git revert <sha>            # poi push: la CI ridispiega da sola

# 2. se il problema è una migration
ssh fabiodalez.it 'cd ~/eventi && /opt/cpanel/ea-php84/root/usr/bin/php artisan migrate:rollback --step=1 --force'
```

## Cron attivi

Due voci, aggiunte alle cinque preesistenti dell'account (che non sono state toccate):

```
* * * * * cd ~/eventi && ea-php84 artisan schedule:run
* * * * * cd ~/eventi && ea-php84 artisan queue:work --stop-when-empty --max-time=55 --tries=3
```

Il worker si spegne a coda vuota e viene rilanciato ogni minuto: è il sostituto di un
demone supervisord, non disponibile sulla shared hosting.

## Quando l'integrazione continua fallisce e in locale è tutto verde

Tre differenze fra le due macchine, e ognuna ha già nascosto un difetto vero.

**1. La CI parte da `.env.example`, lo sviluppo dal proprio `.env`.** Una
variabile che in locale non esiste prende il valore predefinito del file di
configurazione — quello giusto, di solito già del tipo giusto. In CI la stessa
variabile esiste ed è una **stringa**, perché `env()` restituisce sempre
stringhe: `TURNSTILE_TIMEOUT=5` diventa `"5"`, e `config()->integer()` la
rifiuta sollevando. La regola: **ogni valore letto con un accessor tipizzato va
convertito nel file di configurazione**, dove `env()` è l'unica cosa che si usa.
E una riga lasciata vuota vale stringa vuota, non «assente»: `BACKUP_ARCHIVE_PASSWORD=`
faceva cifrare l'archivio con password vuota, e i backup non venivano creati.

Per riprodurre l'ambiente della CI in locale:

```bash
cp .env .env.mio && cp .env.example .env && php artisan key:generate
./vendor/bin/pest --ci
mv .env.mio .env && php artisan config:clear
```

**2. Il job di analisi statica non ha `.env` affatto.** Larastan avvia
l'applicazione per risolvere i tipi, e senza configurazione risolve alcune cose
in modo più conservativo — per esempio `Password::broker()` come contratto
`PasswordBroker` invece che come implementazione concreta. Per riprodurre:

```bash
mv .env .env.mio
./vendor/bin/phpstan clear-result-cache && ./vendor/bin/phpstan analyse --memory-limit=1G
mv .env.mio .env
```

**3. PHPStan tiene una cache dei risultati.** Un file non toccato di recente non
viene rianalizzato, e un errore introdotto da un aggiornamento di dipendenze
resta invisibile finché non si tocca quel file. Prima di dare per buono un
verde locale su un fallimento in CI: `./vendor/bin/phpstan clear-result-cache`.

## Dopo una modifica ai ruoli o ai permessi

**In produzione ci pensa il rilascio**: dal 2026-09-01 la pipeline riesegue
`RolesAndPermissionsSeeder` subito dopo le migrazioni. Non c'è più niente da
fare a mano, ed è per questo che il passo esiste — il guasto che evitava si è
presentato due volte.

`RolesAndPermissionsSeeder` **non gira con le migrazioni**. Aggiungere un
permesso all'enum non lo crea nel database finché il seeder non viene
rieseguito, e finché non esiste, la sezione che protegge risponde 403 **anche
all'amministratore**. Il sintomo somiglia a un errore di autorizzazione ed è
invece un dato mancante; in locale i test passano, perché ogni test semina i
ruoli da capo.

In sviluppo, dopo aver aggiunto un permesso:

```bash
php artisan db:seed --class=RolesAndPermissionsSeeder --force
```

E se serve rifarlo a mano in produzione, fuori da un rilascio:

```bash
ssh fabiodalez.it 'cd ~/eventi && /opt/cpanel/ea-php84/root/usr/bin/php artisan db:seed --class=RolesAndPermissionsSeeder --force'
```

Il seeder è idempotente: crea i mancanti con `firstOrCreate` e riporta ogni
ruolo esattamente ai permessi dichiarati in codice. Non esiste un'interfaccia
per personalizzarli, quindi non c'è niente da sovrascrivere.

## Import dei calendari (§14.2)

Le sorgenti si leggono ogni ora, dallo scheduler già attivo. Verifiche utili:

```bash
# stato delle sorgenti
ssh fabiodalez.it "mysql ... -e 'SELECT id, url, is_active, last_status, last_run_at FROM import_sources'"

# storico delle esecuzioni
ssh fabiodalez.it "mysql ... -e 'SELECT source_id, status, created, updated, excluded, errors, created_at FROM import_runs ORDER BY id DESC LIMIT 10'"

# forzare una lettura
ssh fabiodalez.it 'cd ~/eventi && /opt/cpanel/ea-php84/root/usr/bin/php artisan import:run --source=<id>'
```

Gli eventi importati nascono `published` ma `verification_status = unverified`
(D32): si distinguono in pannello e in API da quelli confermati dal locale.

## Archiviazione degli eventi scaduti (§14.5)

Gira da sola ogni notte alle 04:10. Archiviare **toglie dalle liste, non dal
sito**: la scheda continua a rispondere 200, resta nell'archivio del locale e
nella `sitemap.xml` (D38).

```bash
# quanti sparirebbero dalle liste, senza toccare nulla
ssh fabiodalez.it 'cd ~/eventi && /opt/cpanel/ea-php84/root/usr/bin/php artisan events:archive --dry-run'

# adesso
ssh fabiodalez.it 'cd ~/eventi && /opt/cpanel/ea-php84/root/usr/bin/php artisan events:archive'

# con una soglia diversa da quella di EVENTS_ARCHIVE_AFTER_DAYS (90 giorni)
... artisan events:archive --days=180
```

Un evento archiviato per sbaglio si recupera dal pannello rimettendolo a
`published`: nessun dato viene cancellato.

## Turnstile sui moduli pubblici (§14.7)

**Vuoto = spento.** Senza le due chiavi in `.env` il riquadro non viene
disegnato e la validazione non lo chiede: e' cosi' che sviluppo, test e
integrazione continua non dipendono da Cloudflare. Vanno riempite **entrambe**:
con una sola, la protezione resta spenta e non lo dice nessuno.

```bash
ssh fabiodalez.it 'cd ~/eventi && grep TURNSTILE .env'
# TURNSTILE_SITE_KEY=...   (pubblica, finisce nell'HTML)
# TURNSTILE_SECRET_KEY=... (segreta, sta solo qui)
ssh fabiodalez.it 'cd ~/eventi && /opt/cpanel/ea-php84/root/usr/bin/php artisan config:cache'
```

Le chiavi si generano su dash.cloudflare.com → Turnstile, con il dominio
`eventi.fabiodalez.it`. Verifica che sia acceso: `curl -s https://eventi.fabiodalez.it/proponi-evento | grep cf-turnstile`.

Se Cloudflare e' irraggiungibile o risponde con un errore proprio, **il modulo
resta aperto** e l'episodio finisce in `storage/logs/laravel.log`: campo esca e
limite di frequenza reggono da soli. E' voluto — un guasto di un terzo non deve
diventare un guasto nostro.

## Manutenzione ordinaria: i tre guasti che non si annunciano

Tutti e tre sono successi. Nessuno fa cadere il sito nel momento in cui accade,
e tutti si presentano più tardi come qualcos'altro — che è il motivo per cui
costano un'indagine invece di cinque minuti.

Il primo posto dove guardare è sempre lo stesso:

```bash
php artisan health:check          # in locale
ssh fabiodalez.it "cd ~/eventi && /opt/cpanel/ea-php84/root/usr/bin/php artisan health:check"
```

### «La pagina iniziale dà 500, le altre no»

**Quasi certamente il disco è pieno**, non il codice. Le altre pagine reggono
perché hanno già la loro cache; la home no, e Laravel non riesce a scrivere né
sessioni né log — per questo `laravel.log` è muto e inganna.

```bash
ssh fabiodalez.it "uapi --output=simple Quota get_quota_info | grep -E 'megabytes_(used|remain)'"
```

Se `megabytes_remain` è `0.00`, il colpevole più probabile è un backup morto a
metà. Si tolgono i suoi resti — **solo quelli**, dopo aver verificato che il
backup precedente sia integro:

```bash
ssh fabiodalez.it "cd ~/eventi
  unzip -t storage/app/private/eventi/<il-penultimo>.zip | tail -2   # deve dire 'No errors'
  rm -rf storage/app/backup-temp
  rm -f  storage/app/private/eventi/<quello-troncato>.zip"
```

Da settembre 2026 non dovrebbe più capitare: `SpazioSufficiente` impedisce al
backup di partire se non c'è spazio per finirlo — e non lo stima, prova a
scrivere 64 MB, perché su hosting condiviso `disk_free_space()` riporta il
volume di tutti e non la quota dell'account.

**Il contatore di cPanel è in cache**: dopo aver liberato può restare fermo per
un po'. La prova vera è scrivere un file.

### «Il sito è diventato lento e non ho toccato niente»

Guardare **il peso delle locandine** prima del codice:

```bash
php artisan health:check | grep -A 2 'Peso delle locandine'
php artisan media:compress-oversized --dry-run    # quali e quanto pesano
php artisan media:compress-oversized              # le ricomprime
```

Le conversioni nuove restano nel tetto da sole (`CompressOversizedConversion`);
il comando serve per lo storico, o dopo un import massiccio. Quelle che non
rientrano nemmeno alla qualità minima vengono lasciate com'erano e nominate
nell'esito: lì il problema è l'originale, e va risolto da chi l'ha caricato.

Se le immagini sono a posto, il sospetto successivo è che manchino le varianti
**AVIF**: senza, la stessa locandina pesa da tre a cinque volte tanto.

```bash
find storage/app/public -name '*-card.webp' | wc -l
find storage/app/public -name '*-card-avif.avif' | wc -l   # devono somigliarsi
php artisan tinker --execute="var_dump(App\Support\Media\AvifSupport::available());"
```

### «Nessuno si lamenta, quindi va tutto bene»

È il guasto peggiore perché non ha sintomi: **il calendario si svuota**. Chi
apre il sito non trova niente da fare e non torna, e nessuno scrive per dirlo.

```bash
php artisan health:check | grep -A 2 'Copertura del calendario'
```

Avvisa sotto il 70% dei prossimi quattordici giorni con almeno tre date,
fallisce sotto il 40%. Non è un problema tecnico e non si risolve con il
codice: si risolve chiamando i locali. Il piano lo dice già in §2.2 — *«una
piattaforma di eventi che mostra "non ci sono eventi" è morta»* — ed è
l'indicatore che va guardato per primo, prima di qualunque metrica di velocità.

## Vedere le email in sviluppo

**Non serve una libreria PHP.** Laravel ha già due modi di non spedire davvero,
e per guardare i messaggi c'è Mailpit — che su questa macchina è già installato
(`brew install mailpit`).

| come | cosa fa | quando conviene |
|---|---|---|
| `MAIL_MAILER=log` | scrive il messaggio in `storage/logs/laravel.log` | zero dipendenze, ma leggere un HTML dentro un log è penoso |
| `MAIL_MAILER=array` | tiene i messaggi in memoria e non li scrive | nei test: è quello che usa `Mail::fake()` |
| **Mailpit** | server SMTP finto con una casella nel browser | **il modo normale di lavorare**: si vede il messaggio come lo vedrà chi lo riceve |

Mailpit si avvia così:

```bash
mailpit --smtp 127.0.0.1:1025 --listen 127.0.0.1:8025
# oppure, per averlo sempre: brew services start mailpit
```

e in `.env`:

```
MAIL_MAILER=smtp
MAIL_HOST=127.0.0.1
MAIL_PORT=1025
```

La casella è su **http://127.0.0.1:8025** e ha anche un'API, comoda per
verificare un invio da riga di comando senza aprire il browser:

```bash
curl -s http://127.0.0.1:8025/api/v1/messages | jq '.messages[0].Subject'
```

**Niente cifratura verso Mailpit**: il campo va lasciato vuoto. Con `tls`
selezionato la connessione fallisce, ed è l'errore più facile da fare provando
la pagina di configurazione della posta (D50) contro la casella locale.

**Non spedirà mai a un indirizzo vero**, ed è il punto: qualunque destinatario
si scriva, il messaggio resta lì. È la sola configurazione con cui si può
provare in pace un invio massivo di promemoria.

## Misurare Lighthouse a mano

La pipeline lo fa da sola (lavoro `lighthouse`, D37). Per rifare la stessa
misura in locale servono database popolato, coda smaltita e nginx davanti:

```bash
mysql -h 127.0.0.1 -P 3307 -u root --skip-password -e "CREATE DATABASE IF NOT EXISTS eventi_lh"
DB_DATABASE=eventi_lh php artisan migrate:fresh --seed --force
DB_DATABASE=eventi_lh php artisan queue:work --stop-when-empty   # senza, niente WebP/AVIF

TMP=$(mktemp -d)
sed -e "s|__ROOT__|$PWD/public|g" -e "s|__PORT__|8080|g" -e "s|__APP_PORT__|8000|g"     -e "s|__MIME__|/opt/homebrew/etc/nginx/mime.types|g" -e "s|__TMP__|$TMP|g"     .github/lighthouse/nginx.conf.template > "$TMP/nginx.conf"

( cd public && DB_DATABASE=eventi_lh APP_URL=http://127.0.0.1:8080 APP_DEBUG=false   nohup php -S 127.0.0.1:8000 ../vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php > "$TMP/php.log" 2>&1 & )
nginx -c "$TMP/nginx.conf" -p "$TMP" &

export CHROME_PATH="/Applications/Google Chrome.app/Contents/MacOS/Google Chrome"
export LHCI_BASE_URL=http://127.0.0.1:8080
export LHCI_EVENT_URL=$(curl -s http://127.0.0.1:8080/feed.rss | grep -oE '<link>[^<]+/eventi/[^<]+</link>' | head -1 | sed -E 's|</?link>||g')
npx lhci autorun --config=lighthouserc.cjs
```

Tre cose che sembrano dettagli e falsano tutto:

- **`php -S` e non `php artisan serve`.** Il secondo legge lo standard output
  del figlio da una pipe; appena la shell che l'ha lanciato esce, ogni risposta
  esce con un `Notice: Broken pipe` prima del `<!doctype>`. Il sintomo e' un
  SEO a 0.83 con `robots.txt` invalido e «pagina senza meta description», e non
  c'entra niente col sito.
- **la coda va smaltita**: senza le varianti WebP/AVIF la Performance perde
  undici punti.
- **il banco resta piu' lento della produzione** di circa un secondo e mezzo di
  LCP, perche' parla HTTP/1.1. La misura che conta e' quella sul sito vero:
  `npx lighthouse https://eventi.fabiodalez.it/ --only-categories=performance,accessibility,seo`.

## File che non vanno mai sincronizzati

Alcuni percorsi esistono su entrambe le macchine ma il loro contenuto corretto
dipende da **dove** si trovano. Il deploy li esclude e li rigenera sul server;
copiarli rompe la produzione in modi che non assomigliano alla causa.

| File | Cosa succede se lo si copia |
|---|---|
| `public/storage` | Symlink assoluto: punta a un percorso del Mac. Nessuna immagine si carica, restano i segnaposto sfocati |
| `bootstrap/cache/packages.php` | Elenca i provider delle dipendenze di sviluppo, assenti in produzione: HTTP 500 su tutto, `Class "Laravel\Pail\PailServiceProvider" not found` |
| `public/.htaccess` | Al contrario: questo **deve** essere versionato, perche contiene la direttiva che forza PHP 8.4 (vedi sopra) |
| `storage/app/private/` | E dove vivono i backup (§16) **e il marcatore di installazione** (`install.lock`, D42). Il deploy la esclude dal `rsync --delete`: senza l'esclusione, **ogni pubblicazione cancellerebbe ogni copia** — proprio il gesto dopo il quale un backup serve di piu — e riaprirebbe il wizard di installazione su un sito vivo |

Se la produzione risponde 500 subito dopo un rilascio, il primo posto da
guardare e questo elenco.

## Verifiche rapide

```bash
# il sito risponde
curl -sS -o /dev/null -w '%{http_code}\n' https://eventi.fabiodalez.it/

# code in attesa e fallite
ssh fabiodalez.it 'cd ~/eventi && ea-php84 artisan queue:monitor default'
ssh fabiodalez.it "mysql -u fabiodal_eventi -p'<pass>' fabiodal_eventi -e 'SELECT COUNT(*) FROM failed_jobs'"

# notifiche programmate in attesa
ssh fabiodalez.it "mysql ... -e \"SELECT status, COUNT(*) FROM scheduled_notifications GROUP BY status\""

# log
ssh fabiodalez.it 'tail -50 ~/eventi/storage/logs/laravel.log'
ssh fabiodalez.it 'tail -20 ~/eventi/storage/logs/queue.log'
```

## Backup (§16)

Il backup gira **da solo**, dallo scheduler: `backup:run` alle 03:40,
`backup:clean` alle 04:40 (applica la conservazione di 30 giorni),
`backup:monitor` alle 09:00. Le copie stanno in
`/home/fabiodal/eventi/storage/app/private/eventi/`.

```bash
# copia a mano, adesso
ssh fabiodalez.it 'cd ~/eventi && /opt/cpanel/ea-php84/root/usr/bin/php artisan backup:run'

# che cosa c'e, e da quanto
ssh fabiodalez.it 'ls -lh ~/eventi/storage/app/private/eventi/'
ssh fabiodalez.it 'cd ~/eventi && /opt/cpanel/ea-php84/root/usr/bin/php artisan backup:list'
```

**Quando qualcosa non va, l'email arriva a `OPS_ALERT_EMAIL`** — backup
fallito, pulizia fallita, e soprattutto *backup piu vecchio di un giorno*, che
e il caso in cui il comando non e partito affatto e quindi non ha fallito
niente. Se quella variabile e vuota in `.env`, **nessun allarme parte**: e la
prima cosa da controllare quando si sospetta che il sistema stia tacendo.

Portare le copie fuori dal server (un backup che vive solo sulla macchina che
protegge non protegge da un guasto di quella macchina): riempire le credenziali
`AWS_*` in `.env` e mettere `BACKUP_DISKS=local,s3`.

## Stato del sistema (§16)

Endpoint **protetto**, per il monitor di uptime. La chiave e `OPS_HEALTH_TOKEN`
in `.env`; **senza chiave l'endpoint risponde 404 a chiunque**, di proposito.

```bash
# vivo o degradato: 200 oppure 503
curl -sS -o /dev/null -w '%{http_code}\n' \
  -H "X-Secret-Token: <token>" https://eventi.fabiodalez.it/stato

# quale controllo e rosso (stessa chiave, anche in query string)
curl -sS "https://eventi.fabiodalez.it/stato/completo?token=<token>" | python3 -m json.tool

# rieseguire i controlli adesso invece di leggere l'ultimo esito
ssh fabiodalez.it 'cd ~/eventi && /opt/cpanel/ea-php84/root/usr/bin/php artisan health:check'
```

I sette controlli: database, cache, spazio su disco (giallo al 70%, rosso
all'85%), coda dei lavori, ultima esecuzione dello scheduler, sorgenti di
import (§14.2: rosso se sono **tutte** in errore, giallo se ne e caduta
qualcuna), comandi schedulati. Lo stato si **rilegge**: lo salva `health:check`
ogni cinque minuti, l'endpoint non lo ricalcola.

## Comandi schedulati: accorgersi che uno ha smesso di girare

`spatie/laravel-schedule-monitor` tiene il registro, il controllo di stato
"Comandi schedulati" lo traduce in un allarme via email.

```bash
ssh fabiodalez.it 'cd ~/eventi && /opt/cpanel/ea-php84/root/usr/bin/php artisan schedule-monitor:list'
```

**Dopo ogni modifica a `routes/console.php`** il registro va riallineato, o il
comando nuovo resta fuori dalla sorveglianza:

```bash
ssh fabiodalez.it 'cd ~/eventi && /opt/cpanel/ea-php84/root/usr/bin/php artisan schedule-monitor:sync'
```

Lo fa gia il deploy a ogni rilascio, e lo scheduler alle 03:05: questa riga
serve per una modifica applicata a mano. Se il controllo di stato dice
«fuori sorveglianza», e questo il comando da dare.

## Sponsorizzazioni

Le campagne si aprono da `/admin/sponsorships`, e le vede solo chi ha il
permesso `sponsorships.manage`: amministratore e amministratore di sistema. Il
moderatore no — decidere cosa compare a pagamento è una scelta commerciale, non
editoriale — e nemmeno il referente del locale, che però le trova in sola
lettura sui propri eventi in `/gestione/sponsorizzazioni`.

**Per aprirne una** servono cinque cose: l'evento, la collocazione, la finestra,
il nome del committente e lo stato `Attiva`. La città non si chiede: viene
dall'evento. Il committente compare al pubblico accanto alla scritta
«Sponsorizzato», quindi va scritto come deve leggersi.

**Se una campagna non compare**, si controlla in quest'ordine:

1. lo stato è `Attiva` (una bozza non compare mai, qualunque cosa dica la finestra);
2. `adesso` sta dentro la finestra — le date sono in UTC nel database e nel fuso
   della città nel modulo;
3. **l'evento è ancora pubblicato**: è la condizione che si dimentica. Una
   campagna viva su un evento annullato o ritirato non compare, di proposito;
4. l'evento ha ancora almeno una data futura: una campagna su una serata già
   passata non si disegna;
5. per il foglio della mappa soltanto, la campagna dev'essere su un evento **di
   quel locale**.

**Se ce ne sono più d'una per la stessa collocazione**, ne compare comunque una
sola: le altre entrano in rotazione, che avanza a ogni cambio di minuto. Chi ha
la priorità più alta sta davanti. Il tetto è nel codice
(`SponsorshipPlacement::limit()`), non in configurazione: alzarlo è una
decisione di prodotto e si prende leggendo quel metodo, dove è spiegata.

**Le misure sono una stima al ribasso.** Si contano dal browser — le pagine
stanno in cache un minuto, e un contatore lato server direbbe una
visualizzazione al minuto invece di una per visitatore — quindi chi blocca gli
script non viene contato. Va detto nel contratto, non lasciato intendere. Una
visualizzazione si conta quando la card è entrata davvero nello schermo, non
quando è stata spedita.

**Cosa non si può spegnere**, e non per scelta di stile: la fascia
«Sponsorizzato» col nome del committente, il `rel="sponsored"` sul collegamento
e il campo `sponsored` nell'API. La pubblicità dev'essere riconoscibile come
tale (Codice del Consumo, art. 22-23) e un link pagato va marcato per i motori
di ricerca. Se qualcuno chiede di toglierla, la risposta è no.

## Interruttori di funzione (Pennant)

```bash
PHP=/opt/cpanel/ea-php84/root/usr/bin/php

# spegnere l'import dei calendari di una citta (§14.2)
ssh fabiodalez.it "cd ~/eventi && $PHP artisan feature:set city-import --city=padova --off"
ssh fabiodalez.it "cd ~/eventi && $PHP artisan feature:set city-import --city=padova"

# spegnere la newsletter del weekend per tutti (§15.9)
ssh fabiodalez.it "cd ~/eventi && $PHP artisan feature:set newsletter --off"
```

La decisione e una riga nella tabella `features`, e **vince sui valori di
`config/pennant.php`**: quelli valgono solo per un ambito su cui nessuno ha
ancora deciso — una citta appena creata. Per rimettere tutto ai valori
predefiniti: `php artisan pennant:purge`.

Spegnere la newsletter toglie il consenso dai moduli e svuota la
programmazione del giovedi, ma **non revoca i consensi gia dati**: sono un atto
delle persone, non una funzione del sistema.

## Tracciamento degli errori (Sentry)

`SENTRY_LARAVEL_DSN` vuoto in `.env` significa **spento**: il pacchetto non si
avvia, non apre connessioni e non rallenta niente. Per accenderlo basta il DSN
(vale anche per GlitchTip, che parla lo stesso protocollo). Dopo averlo scritto:
`php artisan config:cache`.

Non escono dati personali: `send_default_pii` e `false` e i parametri delle
interrogazioni SQL sono esclusi (§16).

## Restore del database

Un backup non è valido finché non è stato testato un restore reale (§16 del piano).

```bash
# dump
ssh fabiodalez.it "mysqldump -u fabiodal_eventi -p'<pass>' fabiodal_eventi | gzip > ~/backup-eventi-\$(date +%F).sql.gz"

# restore su un database di prova, MAI direttamente su quello vivo
ssh fabiodalez.it "uapi Mysql create_database name=fabiodal_evtest"
ssh fabiodalez.it "zcat ~/backup-eventi-<data>.sql.gz | mysql -u fabiodal_eventi -p'<pass>' fabiodal_evtest"
# verifica i conteggi, poi elimina il database di prova
```

Il dump prodotto da `backup:run` si ripristina allo stesso modo: dentro lo zip
di `storage/app/private/eventi/` c'è `db-dumps/mariadb-<db>.sql.gz`, che va
passato a `gunzip -c | mysql` su un database di prova, confrontando poi i
conteggi (tabelle, locali, eventi, occorrenze, utenti) con l'originale.

**Restore provato davvero il 2026-09-01** (verifica finale F10, in locale):
archivio di `backup:run --only-db` estratto e ripristinato su un database di
prova → 47 tabelle su 47, conteggi identici all'originale (locali 25, eventi
138, occorrenze 145, utenti 5, pagine 5), database di prova poi eliminato.
Da ripetere sul server dopo il primo `backup:run` di produzione.

## Rotazione dei segreti

```bash
# password del database
ssh fabiodalez.it "uapi Mysql set_password user=fabiodal_eventi password='<nuova>'"
ssh fabiodalez.it "sed -i 's|^DB_PASSWORD=.*|DB_PASSWORD=<nuova>|' ~/eventi/.env"
gh secret set PROD_DB_PASSWORD --repo fabiodalez-dev/eventi --body '<nuova>'
ssh fabiodalez.it 'cd ~/eventi && ea-php84 artisan config:cache'

# chiave di deploy: rigenerala, sostituiscila in ~/.ssh/authorized_keys sul server
# e aggiorna il secret DEPLOY_SSH_KEY
```

## Ambiente di sviluppo

```bash
cd ~/Documents/GitHub/eventi
php artisan migrate:fresh --seed     # MariaDB locale su 127.0.0.1:3307
php artisan serve
npm run dev
./vendor/bin/pest
```

Il database di sviluppo è **MariaDB sulla porta 3307**, non MySQL sulla 3306:
vedi la decisione D4 in `docs/DECISIONS.md`.

## Pagine legali, consenso e analitica (§16)

### I testi si cambiano dal pannello, non con un rilascio

Privacy, cookie policy, termini, chi siamo e contatti vivono nella tabella
`pages` e si modificano da **`/admin` → Pagine informative**. Il corpo e
Markdown: qualunque marcatura HTML scritta nel campo viene scartata alla
lettura, quindi non c'e modo di rompere la pagina (ne di iniettarci qualcosa).

`PageSeeder` scrive i testi iniziali ed e **idempotente in senso stretto**: usa
`firstOrCreate` sullo slug, quindi rieseguirlo **non tocca** le correzioni fatte
dalla redazione. Per far ripartire una pagina dai testi di serie va prima
cancellata la riga.

```bash
# prima installazione (o pagina nuova aggiunta al seeder)
ssh fabiodalez.it 'cd ~/eventi && /opt/cpanel/ea-php84/root/usr/bin/php artisan db:seed --class=PageSeeder --force'
```

**Il permesso `pages.manage` e nuovo**: al primo rilascio di questa funzione va
rieseguito anche `RolesAndPermissionsSeeder` (vedi la sezione «Dopo una modifica
ai ruoli o ai permessi»), altrimenti `/admin/pages` risponde **403** a un
amministratore che dovrebbe vederla.

### Consenso

`CONSENT_VERSION` in `.env` e la versione dell'informativa a cui si riferisce
ogni scelta registrata. **Cambiarla fa ricomparire il banner a tutti**: e il
gesto da fare quando cambiano le finalita, e l'unico modo perche le scelte
vecchie non valgano per un trattamento nuovo. Non va cambiata per una correzione
di refuso.

```bash
# quante scelte, e quali
ssh fabiodalez.it "mysql ... -e \"SELECT policy_version, action, COUNT(*) FROM consent_logs GROUP BY policy_version, action\""
```

Le righe non si aggiornano mai: chi cambia idea ne aggiunge una con lo stesso
`consent_id`. Nessun indirizzo IP viene conservato.

### Analitica

Tre variabili, **tutte e tre obbligatorie** perche qualcosa parta:

```
ANALYTICS_PROVIDER=plausible   # oppure umami; qualunque altro valore = spento
ANALYTICS_DOMAIN=eventi.fabiodalez.it
ANALYTICS_SRC=https://statistiche.esempio.it/script.js
```

Vuote (**stato predefinito**) il sito non emette alcuno script e non contatta
alcun dominio esterno. Riempite, lo script viene servito **solo** a chi ha
acconsentito alle statistiche: chi non ha ancora scelto vale come chi ha
rifiutato.

Dopo aver riempito le variabili:

```bash
ssh fabiodalez.it 'cd ~/eventi && /opt/cpanel/ea-php84/root/usr/bin/php artisan config:cache'
# verifica: da anonimo senza consenso lo script NON deve comparire
curl -s https://eventi.fabiodalez.it/ | grep -c 'ANALYTICS_SRC-host'
```

`ANALYTICS_SRC` deve essere `https`: uno script in chiaro dentro una pagina
cifrata verrebbe bloccato dal browser, e l'unico segnale sarebbe una riga in
console che nessuno guarda. Per questo, se non lo e, la funzione resta spenta
invece di emettere un tag che non funziona.

**La Cookie Policy legge la configurazione, non un testo fisso**: accendendo o
spegnendo l'analitica, la pagina dice comunque la verita su cosa e attivo.

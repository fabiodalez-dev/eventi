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

## Dopo una modifica ai ruoli o ai permessi

`RolesAndPermissionsSeeder` **non gira con le migrazioni**. Aggiungere un
permesso al seeder non lo assegna a nessuno finché il seeder non viene
rieseguito, e il sintomo è un 403 su una pagina nuova per un utente che
dovrebbe vederla — mentre in locale i test passano, perché ogni test semina i
ruoli da capo.

```bash
# sviluppo
php artisan db:seed --class=RolesAndPermissionsSeeder --force

# produzione
ssh fabiodalez.it 'cd ~/eventi && /opt/cpanel/ea-php84/root/usr/bin/php artisan db:seed --class=RolesAndPermissionsSeeder --force'
```

Il seeder è idempotente: rieseguirlo non duplica ruoli né permessi.

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

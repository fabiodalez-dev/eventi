# Sviluppo locale e rilascio continuo

Il repository privato esistente è `fabiodalez-dev/eventi`: sito Laravel e sorgenti
Android restano insieme, così le modifiche API e client si verificano nello stesso
commit. Non occorre creare un secondo repository né rendere pubblico questo.

## Flusso quotidiano

1. Crea un ramo `fix/nome-intervento` (o un ramo per la nuova funzionalità).
2. Sviluppa e verifica in locale; non committare `.env`, password, backup,
   `vendor`, `public/build` o chiavi Android.
3. Apri una pull request verso `main`: GitHub esegue Pint, PHPStan, tutta la
   suite Pest con MariaDB, build Vite, Lighthouse, test unitari e compilazione
   Android. Non viene generato un APK.
   Pest è suddiviso in tre runner con database indipendenti: nessun test viene
   saltato e le tre parti devono riuscire tutte. Gli audit delle dipendenze
   PHP e JavaScript, inclusi gli strumenti di sviluppo, sono anch'essi bloccanti.
4. Unisci solo con controlli verdi. Il push su `main` esegue nuovamente i
   controlli e pubblica automaticamente. Puoi anche usare Actions → CI → Run workflow.
5. Il job Deploy deve essere verde: verifica il commit effettivamente online e
   home, eventi, locali, sitemap, API e login amministrativo.

## Garanzie e limiti

- Action fissate a SHA, permessi in sola lettura tranne pubblicazione degli asset.
- L'ambiente `production` accetta esclusivamente il ramo `main`.
- Nessun segreto viene esposto alle pull request; niente `pull_request_target`.
- Il deploy usa gli stessi asset compilati e conservati dal job dei test,
   senza ricompilare una seconda versione dopo i controlli.
- Manifesto con SHA sorgente e ID della CI; server e asset sono vincolati allo
   stesso commit. Il server acquisisce una revisione precisa, non un ramo mobile.
- Il lucchetto server serializza sia richieste HTTP sia cron/CLI; GitHub non
   cancella un rilascio già in esecuzione per un nuovo push.
- Backup database obbligatorio più copie private di codice e asset prima di
   aggiornare; modifiche tracciate sul server fanno fermare il rilascio.
- Breve manutenzione durante installazione dipendenze, migrazioni e cache.
   Dopo verifica di database, migrazioni, rotte e asset, il sito riapre.
- Le immagini social di anteprima hanno un URL versionato sul renderer e sui
   dati: un aggiornamento non riusa il vecchio file nella cache del browser.
- I pacchetti ZIP già generati sono documenti storici: per un nuovo layout usa
   “Prepara le grafiche selezionate”; non si modificano file già pubblicati.

L'hosting non è raggiungibile in modo affidabile dai runner GitHub: il webhook
è un acceleratore, il cron Laravel controlla ogni cinque minuti. Il job non
dichiara successo finché `/release-status` non conferma lo SHA e le pagine
pubbliche non rispondono correttamente. Un timeout è un fallimento visibile.

**Limite del piano GitHub attuale:** l'API rifiuta la protezione dei rami su
questo repository privato (richiede Pro). Il gate di deploy funziona, ma non
possiamo imporre tramite GitHub il divieto di push diretto o merge con test rossi.
Non rendere pubblico il repository per aggirare questo limite. Dopo l'upgrade,
attivare su `main` pull request obbligatoria, controlli richiesti, divieto di
force-push/eliminazione e applicazione anche agli amministratori.

## Migrazioni e recupero

Usare migrazioni additive compatibili con la versione precedente: aggiungere
prima le nuove colonne, distribuire il codice, rimuovere le vecchie in un
rilascio successivo. La suite prova installazione, rollback e nuova migrazione.
Non usare `migrate:fresh`, il seeder generale o rollback automatici in produzione.

In caso di errore dopo l'inizio delle modifiche il sito **resta in manutenzione**:
non viene esposto uno schema parzialmente aggiornato. Il cron non riprova a
modificare un sito già in manutenzione. Esaminare prima i log di GitHub e
`storage/logs`, poi `artisan migrate:status`; non eseguire `up` alla cieca.

Backup di codice/asset: `storage/app/private/releases/<data>-<sha>/`.
Il database usa il backup Spatie configurato per l'installazione. Questi archivi
non sono pubblici e non viaggiano su GitHub. Verificare e gestire periodicamente
spazio e conservazione dei backup.

Per un difetto applicativo con schema compatibile: creare un commit `git revert`
in un nuovo ramo, testarlo e rilasciarlo tramite la stessa pipeline. Non riscrivere
la storia di produzione. Se il sito è in manutenzione dopo una migrazione fallita,
serve prima una verifica operativa via SSH e una correzione guidata: ripristinare
automaticamente il database potrebbe cancellare prenotazioni arrivate nel frattempo.

Comandi sul server (PHP 8.4):

```sh
cd /home/fabiodal/eventi
/opt/cpanel/ea-php84/root/usr/bin/php artisan migrate:status
/opt/cpanel/ea-php84/root/usr/bin/php artisan deploy:verify
```

Solo dopo avere riallineato e verificato codice, dipendenze, schema e asset,
riaprire con `artisan up`. Conservare il backup e documentare l'incidente.

## Segreti

`DEPLOY_HOOK_URL` e `DEPLOY_HOOK_SECRET` sono GitHub Secrets già configurati.
Il database e `.env` rimangono sul server, mai copiati nei runner. I vecchi
segreti SSH/database non sono usati da questa pipeline. Rotazione dei segreti
e upgrade del piano GitHub sono operazioni dell'account, non nuovi pacchetti Laravel.

Le dipendenze transitive di Lighthouse (`tmp`, `uuid`, `qs`, `puppeteer-core`)
sono vincolate tramite `overrides` alle versioni corrette per gli avvisi noti.
Non rimuovere questi vincoli senza ripetere `npm audit` e una raccolta Lighthouse
reale. Non usare `npm audit fix --force`: propone anche downgrade incompatibili.

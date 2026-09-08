# Sviluppo locale e rilascio continuo

Il repository principale è `fabiodalez-dev/eventi`: sito Laravel e sorgenti
Android restano insieme, così le modifiche API e client si verificano nello stesso
commit. Il proprietario ha autorizzato la visibilità pubblica il 7 settembre 2026.
La CI torna sui runner standard GitHub, gratuiti per repository pubblici;
il mirror GitLab rimane privato. Vedere `docs/PUBLIC-REPOSITORY.md`.

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
Le richieste del runner usano IPv4 esplicito, verificato con un controllo TLS
separato: il percorso IPv6 non è disponibile su tutti i runner. La verifica
del certificato non viene mai disabilitata. Se l'hosting restituisce ancora
timeout o un certificato di un altro dominio, serve verificare il percorso
di rete e la configurazione TLS con il provider; non usare `curl -k`.

La protezione dei rami è disponibile sui repository pubblici del piano Free.
Verificare la configurazione effettiva su GitHub: la sola visibilità pubblica
non attiva automaticamente regole di protezione. Il gate di deploy continua
a richiedere tutti i controlli previsti, anche per i push diretti su `main`.

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

Il job di deploy usa `ubuntu-24.04-arm`: il 7 settembre 2026 il runner x86 del
rilascio riceveva certificati estranei e timeout dall'hosting, mentre una prova
indipendente ARM verificava hostname, certificato e SHA corretti. Test e build
restano sui runner originali, gli asset vengono sempre dallo stesso commit.
La verifica TLS resta obbligatoria: mai usare `curl -k`. Se il problema ricompare,
confrontare DNS, certificato e raggiungibilità da un secondo runner prima di
intervenire. Il controllo SHA finale non viene saltato.

### Verifica del rilascio su runner nuovi

Il 7 settembre 2026 la diagnosi comparativa ha confermato che DNS di sistema
e DNS pubblico restituiscono entrambi `185.116.60.3`. Un runner riceveva
timeout o il certificato predefinito `*.vhosting-it.com`, mentre un altro
otteneva HTTP 200 e il certificato corretto di `eventi.fabiodalez.it`.
Forzare lo stesso IP con `--resolve` non correggeva il percorso guasto:
non è quindi giustificato fissare l'IP o disabilitare TLS.

La pubblicazione degli asset avviene una sola volta. La verifica pubblica
usa un job separato e, solo se non riesce, riprova fino a due volte su nuovi
runner GitHub ARM. Ogni tentativo deve verificare **insieme** certificato,
SHA atteso, tutte e sei le pagine pubbliche e di nuovo lo SHA. Non si sommano
successi parziali fra tentativi. Il gate finale «Deploy su eventi.fabiodalez.it»
fallisce se nessun tentativo ha completato la verifica o se la pubblicazione
è fallita. I tentativi non riusciti rimangono visibili nei log e nel riepilogo.
`PUBLIC_SITE_URL` è una variabile repository facoltativa per il futuro cambio
dominio (solo origine HTTPS, nessuna credenziale); il webhook conserva la sua
configurazione separata nei Secrets.

Il workflow manuale «Hosting network diagnostics» confronta i percorsi x86 e
ARM, senza segreti né modifiche al server. È una raccolta diagnostica, non
un gate di rilascio: i risultati delle singole connessioni sono nei log.
Questa mitigazione non ripara il percorso dell'hosting: se ricorre, fornire
al provider i log di DNS e certificato per verificare routing/vhost/filtri.

`DEPLOY_HOOK_URL` e `DEPLOY_HOOK_SECRET` sono GitHub Secrets già configurati.
Il database e `.env` rimangono sul server, mai copiati nei runner. I vecchi
segreti SSH/database non sono usati da questa pipeline. Rotazione dei segreti
e upgrade del piano GitHub sono operazioni dell'account, non nuovi pacchetti Laravel.

Le dipendenze transitive di Lighthouse (`tmp`, `uuid`, `qs`, `puppeteer-core`)
sono vincolate tramite `overrides` alle versioni corrette per gli avvisi noti.
Non rimuovere questi vincoli senza ripetere `npm audit` e una raccolta Lighthouse
reale. Non usare `npm audit fix --force`: propone anche downgrade incompatibili.
# Pausa temporanea Lighthouse

Su richiesta esplicita del proprietario (8 settembre 2026), `CI_LIGHTHOUSE_PAUSED=true` nelle variabili del repository salta temporaneamente **solo Lighthouse**. Per riattivarlo, eliminare la variabile o impostarla a `false`. Analisi statica, vulnerabilità, test PHP/Android e verifica del deploy restano obbligatori. Il gate accetta soltanto lo stato `skipped` intenzionale di Lighthouse, mai un fallimento o una cancellazione. Le esecuzioni già avviate con il vecchio workflow non cambiano retroattivamente.

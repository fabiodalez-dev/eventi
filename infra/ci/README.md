# Fabio CI: server di esecuzione multi-repository

## Decisione corrente: tutta la CI su GitHub

Il proprietario ha ritirato tutte le migrazioni self-hosted il 7 settembre 2026.
I workflow attivi e le PR aperte sono stati controllati su 37 repository:
solo Pinakes #417 e FAZ #274 richiedevano ancora la VM, ora ripristinati su
`ubuntu-latest`, comprese le release. Eventi usa già runner standard GitHub.
`fabio-ci-controller` è fermo e disabilitato all'avvio: non riattivarlo.
Le istruzioni self-hosted seguenti sono solo documentazione storica.
Restano attivi entrambi i timer GitLab e il servizio di backup su push di eventi.
PR, issue e release rimangono su GitHub; i repository privati restano privati.
Vedere `docs/PUBLIC-REPOSITORY.md` per la configurazione corrente.

## Aggiornamento del 7 settembre 2026: eventi pubblico

Per `eventi` la configurazione corrente è descritta in
[`docs/PUBLIC-REPOSITORY.md`](../../docs/PUBLIC-REPOSITORY.md): CI sui runner
standard GitHub, mirror GitLab privato attivato dai push e dalla fine della CI.
Il timer orario resta un recupero. Il server non deve registrare runner per
`eventi` pubblico. Pinakes e FAZ sono stati rimossi dall'allowlist pubblica:
le loro PR self-hosted restano sospese, non vanno considerate integrate.
Le sezioni seguenti documentano anche la precedente installazione e non
autorizzano a riattivare le allowlist dei repository pubblici.

## Stato dell'installazione

Host SSH: `fabiodalez` (YunoHost/Debian, separato dall'hosting eventi).
KVM/QEMU installati da Debian. Nessun Docker sull'host. VM Ubuntu 24.04 con
4 vCPU, 8 GiB RAM e disco virtuale massimo 80 GiB; allocazione reale dinamica.
Docker, Java 21, Android SDK 36 e runner GitHub 2.337.0 sono dentro la VM.
Le licenze Android già accettate nell'SDK del proprietario sono state riutilizzate.
Non è stato generato alcun APK.

Prova reale completata con successo il 7 settembre 2026:
https://github.com/fabiodalez-dev/eventi/actions/runs/34096671973.
Verificati PHP 8.4, Node 22, Java 21, SDK, MariaDB in container, connessione al
sito pubblico, isolamento LAN/host e tutti i 29 test della logica CI. Confermata
la deregistrazione automatica del runner e la rimozione del disco del job.
Non equivale ancora alla migrazione di tutti i workflow o a un deploy dal server.

L'immagine Ubuntu e l'archivio ufficiale del runner sono verificati SHA-256.
L'immagine base non contiene credenziali di repository. Per ogni esecuzione:

1. Si crea un overlay da una base verificata, senza cache scrivibili condivise.
2. Si avvia QEMU come utente dedicato, con filesystem host protetto e solo KVM.
3. Il runner riceve via SSH soltanto la configurazione JIT del singolo job.
4. Dopo il job, la VM si ferma e il suo disco temporaneo viene eliminato.

Una coda globale esegue un job alla volta. È una scelta iniziale conservativa:
la capacità può aumentare dopo benchmark, senza cambiare i servizi personali.
La porta SSH della VM è esposta solo su `127.0.0.1:22220`, non sulla LAN.
Il firewall aggiunge una tabella separata `inet fabio_ci` per il solo utente CI:
blocca LAN, loopback (salvo DNS), link-local e IPv6, e consente web pubblico/DNS.
Non sostituisce le regole YunoHost. Una modifica della rete/firewall host richiede
di ripetere i test d'isolamento prima di eseguire job.

## Credenziali dedicate richieste per l'autonomia

Il token personale del Mac **non viene copiato sul server**. La GitHub App privata
`fabiodalez-ci-server` (ID 4858177, installazione 159702150) è ora collegata:
vede i 36 repository autorizzati. Il controller è attivo e abilitato all'avvio.
La prova autonoma, senza token del Mac per registrare il runner, è passata:
https://github.com/fabiodalez-dev/eventi/actions/runs/34098015324.
I workflow di `eventi` sono configurati per la coda locale, compreso il deploy;
quelli degli altri repository richiedono ancora verifica e migrazione individuale.

Pinakes e FAZ Cookie sono stati revisionati e aggiunti all'allowlist pubblica.
Le migrazioni sono nelle PR [Pinakes #417](https://github.com/fabiodalez-dev/Pinakes/pull/417)
e [FAZ #274](https://github.com/fabiodalez-dev/FAZ-Cookie-Manager/pull/274): controllare
i check reali prima di considerarle integrate. Tutti i job Linux selezionano la
VM per proprietario/Dependabot e PR dello stesso repository; gli altri attori e
fork mantengono runner GitHub. GitHub resta centrale per PR, issue, discussion,
check/log/artefatti Actions e release: si sposta l'esecuzione, non la piattaforma.
La release Pinakes continua a essere pubblicata su GitHub dai job esistenti;
la migrazione non crea tag o release. GitLab rimane una copia del solo Git.

Il supervisore consente i job Pinakes da 120 minuti, con margine separato per
avvio e pulizia (SSH 7800 s, VM 8000 s, controller 8100 s). Le allowlist vengono
rilette tra i job, senza interrompere quelli attivi. La protezione dei repository
pubblici dipende dall'isolamento VM/host: espressioni e label di workflow non
costituiscono da sole una barriera contro workflow modificati intenzionalmente.

Permessi minimi della App:

- Repository Administration: write (richiesto da GitHub per creare runner JIT).
- Actions: read (lettura coda e stato dei job).
- Metadata: read (implicito).
- Contents: read soltanto se la stessa App deve alimentare il mirror Git.
- Nessun permesso account, nessun secret di produzione, nessun Contents write.

La chiave privata va in `/etc/fabio-ci/github-app.pem`, root:root, modo 0600;
la configurazione in `/etc/fabio-ci/github-app.json`, stesso proprietario/modo.
Il controller genera token d'installazione brevi e non li passa alle VM.
App ID, installation ID e chiave sono obbligatori: il file example non va usato
come configurazione attiva. Non conservare chiavi nel repository.

GitHub con account personale registra i runner per repository. La coda li crea
automaticamente sulle installazioni della App, senza trasferire i repository in
una organizzazione. Per i repository pubblici occorre prima verificare workflow,
policy delle PR esterne e segreti, poi aggiungerli all'elenco esplicito
`reviewed_public_repositories`. Non basta controllare l'autore del job: un runner
registrato può ricevere altri job compatibili nella stessa coda.

## Attivazione e operazioni

Dopo la verifica di credenziali e immagine, eseguire un job di prova e poi:

```sh
sudo systemctl enable --now fabio-ci-controller
sudo systemctl status fabio-ci-controller
sudo journalctl -u fabio-ci-controller --since today
```

Un workflow da migrare usa `runs-on: [self-hosted, Linux, X64, fabio-ci]`.
Non migrare indiscriminatamente job macOS/Windows: questo host esegue Linux.
Le PR di fork pubblici devono restare su runner GitHub o avere una procedura
dedicata esplicitamente approvata. Prima di cambiare un repository, verificarne
le dipendenze di runtime e un'esecuzione completa, compreso l'eventuale deploy.

Per fermare nuovi job: `sudo systemctl stop fabio-ci-controller`.
Non fermare una VM mentre esegue un deploy. Il firewall resta attivo quando
il controller è fermo. L'immagine base va aggiornata periodicamente per le patch
di sicurezza e la scadenza supportata del runner; non modificarla mentre viene
usata da overlay. Il provisioning rimane distinto dalle VM dei job.

## GitLab esterno

Il proprietario ha scelto GitLab esterno, non GitLab installato su YunoHost.
Account GitLab `fabiodalezbackup`, gruppo privato `fabiodalez-dev-group`
(ID 141574454). Le 36 destinazioni dei repository sono state create vuote,
private e con lo stesso nome della sorgente. Il progetto iniziale
`fabiodalez-dev-project` del proprietario non viene toccato.

Il token `fabiodalez-server-git-mirrors` scade il **6 settembre 2027**.
È un token fine-grained gratuito, limitato al solo gruppo: Project Read/Update,
Code Download/Push; nessun permesso utente/globale, eliminazione, condivisione
o trasferimento. I token di gruppo richiedono un abbonamento e non sono
disponibili nel trial. Nessun abbonamento è stato acquistato.
La creazione API di progetti richiede invece un permesso utente: il servizio
non lo possiede. Per un nuovo repository creare prima nel pannello GitLab la
destinazione vuota privata, con nome identico, nel gruppo dedicato.

`/etc/fabio-ci/gitlab-mirror.token` e `gitlab-mirror.json` sono root:root 0600.
`fabio-git-mirror.timer` esegue la copia ogni ora, anche senza il Mac.
`git_mirror.py` usa la GitHub App per leggere e il token granulare per scrivere;
le credenziali non sono salvate negli URL Git o nei repository.
Disabilita CI/CD, shared runners e Auto DevOps prima del primo push.
La prima adozione richiede una destinazione vuota. Un cambio esterno di
destinazione/visibilità/riferimenti interrompe la sincronizzazione.
I push sono atomici, senza force e senza cancellazioni sul remoto:
riscritture della storia richiedono verifica manuale, non cancellano i backup.

Stato verificato e repository bare sono in `/var/lib/fabio-git-mirrors`.
Le copie storiche giornaliere `.bundle` sono nella sottocartella `history`;
non vengono eliminate automaticamente. Con meno di 20 GiB liberi il servizio
si ferma prima del prossimo repository. Non modificare direttamente le copie.

```sh
sudo systemctl start fabio-git-mirror.service
sudo journalctl -u fabio-git-mirror.service --since today
sudo systemctl list-timers fabio-git-mirror.timer
```

Riferimenti: [token granulari](https://docs.gitlab.com/auth/tokens/fine_grained_access_tokens/),
[permessi Git](https://docs.gitlab.com/auth/tokens/fine_grained_access_tokens_other/).

Un mirror Git copia commit/rami/tag, non issue, PR, Actions secrets, database,
upload del sito o automaticamente gli oggetti Git LFS. Non è il backup completo
dell'applicazione. Non attivare CI GitLab in parallelo al mirror: eviterebbe
duplicazioni di job e costi. Snapshot/backup separati devono conservare revisioni
anche dopo cancellazioni o force-push alla sorgente.

## Verifiche locali

```sh
python3 -m unittest discover -s infra/ci -p 'test_*.py'
python3 -m py_compile infra/ci/controller.py infra/ci/github_app.py infra/ci/run_vm.py
bash -n infra/ci/host-prepare.sh infra/ci/guest-tools.sh infra/ci/guest-finalize.sh
```

## Rimozione recuperabile

Fermare controller/job e rimuovere le registrazioni runner dalla App; revocare
l'installazione App solo dopo aver verificato che nessun job sia in corso.
Conservare l'immagine base e gli script finché si desidera poter ripristinare
il servizio. Non rimuovere o riconfigurare Docker/database dei servizi YunoHost:
non fanno parte di questa installazione.

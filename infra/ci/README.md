# Fabio CI: server di esecuzione multi-repository

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

Il token personale del Mac **non viene copiato sul server**. Il controller resta
disattivato finché il proprietario non completa l'autenticazione GitHub e approva
una GitHub App privata, installata sul proprio account/repository.

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
L'accesso GitLab deve essere completato dal proprietario; nessuna destinazione
o credenziale viene inventata. Creare copie **private**, con sincronizzazione
monodirezionale GitHub → GitLab. Prima della prima sincronizzazione verificare
che la destinazione sia vuota e destinata esclusivamente al mirror.

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

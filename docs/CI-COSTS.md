# Costi CI e server dedicato

## Budget verificato il 7 settembre 2026

Nella pagina GitHub personale `Settings > Billing and licensing > Budgets and alerts`
il prodotto Actions ha già budget **$0** e **Stop usage: Yes**. Nessuna modifica
necessaria. È un limite dell'intero account, non del solo repository eventi.
La quota inclusa resta utilizzabile; esaurita, non si autorizzano consumi extra.
Il sito già pubblicato resta online, ma i nuovi rilasci attendono i controlli.

## Controlli selettivi

- PR solo frontend (Blade, CSS, JS, traduzioni, asset): suite web, senza Android.
- PR solo Android: test e compilazione Android, senza PHP/Lighthouse.
- Backend, API, configurazione, workflow o percorsi sconosciuti: entrambe le suite.
- PR solo documentazione: selezione e verifica finale, senza suite applicative.
- Push su main e avvio manuale: suite completa, senza riutilizzare risultati
  di un altro commit. Il deploy richiede anche la riuscita esplicita di ogni job.

Il confronto PR usa il commit base e il merge commit verificato, comprende file
eliminati e rinominati, senza limite di 300 file delle API di listing. Un errore
di selezione blocca i controlli anziché concedere un deploy.

Le esecuzioni superate della stessa PR sono già annullate tramite concurrency.
Non si annulla un rilascio attivo. PHPStan/Pint precedono Pest e Lighthouse:
un errore di qualità evita i job costosi. L'audit npm gira una volta anziché tre.
Gli aggiornamenti minor/patch sono raggruppati per ecosistema; le major restano
separate, salvo la toolchain Kotlin che va aggiornata insieme. ZXing 0.x resta
separato perché un minor può cambiare il contratto. Nessun merge automatico.
La configurazione non unisce automaticamente le PR già aperte.

Verifica locale: `node --test .github/scripts/*.test.cjs` e
`actionlint .github/workflows/ci.yml`.

## Valutazione del server SSH fabiodalez

Ispezione di sola lettura del 7 settembre 2026:

- Alias `fabiodalez`, Debian x86_64 con YunoHost; distinto dall'hosting eventi.
- Intel i5-9500, 6 core; RAM 23 GiB visibili, circa 17 GiB disponibili.
- Disco root 467 GiB, circa 313 GiB disponibili.
- Virtualizzazione VT-x e `/dev/kvm` presenti; Docker, Podman, virsh e QEMU
  non risultano disponibili nel PATH dell'utente.
- Load medio basso; swap da circa 1 GiB interamente occupata, ma nei tre campioni
  di vmstat non ci sono operazioni swap-in/swap-out. Da solo non prova saturazione.
- Molti servizi personali attivi, inclusi posta, archivi, foto, database e media.

**Aggiornamento successivo del 7 settembre:** il proprietario ha richiesto
l'installazione per tutti i repository, precisando che i servizi personali vengono
usati in alternanza allo sviluppo. Installati KVM/QEMU e una VM Ubuntu isolata
con 4 vCPU, 8 GiB RAM e disco massimo 80 GiB (allocazione dinamica).

Una prova GitHub Actions reale sul server è passata, incluso PHP 8.4, Node 22,
Java 21, MariaDB in Docker, presenza SDK Android 36, rete isolata e 29 test CI.
Run: https://github.com/fabiodalez-dev/eventi/actions/runs/34096671973.
Il runner monouso si è deregistrato e il disco temporaneo è stato eliminato.

Il controller multi-repository è attivo e abilitato all'avvio con la GitHub App
dedicata, chiave root-only. La prova autonoma 34098015324 è passata. Nessun token
personale è stato salvato sul server. Il workflow di eventi ora usa il server
per tutti i job, incluso il deploy; quelli degli altri repository non sono ancora
migrati in massa e richiedono verifiche dei runtime e delle policy delle PR.
Il proprietario ha scelto GitLab **esterno** per le copie Git; anche qui manca
l'accesso autenticato. I dettagli operativi sono in `infra/ci/README.md`.

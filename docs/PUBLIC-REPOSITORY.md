# Repository pubblico e CI GitHub

GitHub rimane la fonte principale per codice, PR, issue, discussion, release,
check, log e artefatti. I job CI usano runner standard GitHub Ubuntu e i tre
shard Laravel tornano a essere paralleli. Il deploy usa Ubuntu ARM per il
percorso TLS verso l'hosting già verificato. Nessun job pubblico richiede un
runner del server personale. Tutti i gate esistenti restano obbligatori.

Il server `fabiodalez` conserva i mirror GitLab privati. Il workflow
`Private GitLab backup`, attivato a ogni push di rami o tag, usa una chiave SSH
dedicata con comando forzato: può soltanto avviare il backup di eventi, non
eseguire comandi arbitrari, aprire tunnel o leggere le credenziali del server.
Il job attende l'esito del servizio. Le credenziali GitLab restano sul server.
La chiave host SSH è verificata, non acquisita alla cieca dal runner.
Si attiva anche alla conclusione di CI, per includere il ramo `assets` generato
con `GITHUB_TOKEN` (che non attiva a sua volta workflow su push). Questo trigger
non scarica né esegue codice o artefatti delle PR.

Le esecuzioni ravvicinate vengono serializzate; GitHub può accorpare quelle
ancora in attesa. Ogni copia recupera l'intera cronologia raggiungibile dai
rami/tag correnti, non soltanto il commit che ha attivato il workflow. Il timer
orario globale rimane un recupero indipendente dal Mac e da GitHub Actions.
Un commit mai inviato a GitHub non può essere copiato dal server.

Per tutti i repository autorizzati nella GitHub App, il timer
`fabio-git-mirror-poll.timer` controlla inoltre ogni minuto, a coda libera, la
data dell'ultimo push. Copia solo le sorgenti cambiate, senza consumare minuti
GitHub/GitLab. Dopo un fermo recupera la cronologia corrente al riavvio. Il timer
orario verifica nuovamente anche le destinazioni senza nuovi push. Tutti i job
mirror condividono un lock: nessuna scrittura concorrente. Non è una garanzia
di latenza di 60 secondi durante trasferimenti lunghi o interruzioni di rete.
Un repository appena creato e ancora vuoto attende il primo push; prima della
prima copia di codice deve esistere la destinazione privata GitLab. Il piccolo
repository `fabio-ci-executor` è ancora vuoto e non viene considerato un errore.

Il mirror verifica identità e visibilità privata della destinazione. Prima di
aggiornare un riferimento già copiato, conserva il vecchio oggetto nel tag
`mirror-history-<sha>` e usa un lease sul valore remoto verificato. Questo
permette i rebase di Dependabot e il ramo generato `assets` senza perdere la
vecchia cronologia. Modifiche esterne alla destinazione bloccano la copia;
non vengono cancellati riferimenti remoti. I tag di archivio non sono release.

La copia riguarda Git, non database, upload, issue/PR o oggetti Git LFS.
Non attivare GitLab CI nel gruppo mirror. Errori del mirror sono visibili nel
workflow dedicato e nei log di `fabio-eventi-mirror.service`; non vanno ignorati.

Prima della pubblicazione sono stati recuperati tutti i rami e la cronologia
completa, scansionati con Gitleaks in modalità redatta. Il controllo include
anche PR/commenti, log e artefatti Actions. Nessuna scansione automatica può
garantire l'assenza assoluta di segreti: usare sempre credenziali esterne a Git,
secret scanning e revisione dei cambiamenti. Gli account demo del seeder sono
intenzionalmente dimostrativi; non usarli per contenuti riservati.

# Review e completamento — 24 settembre 2026

Perimetro: documento «inCittà — cosa c'è, cosa manca, cosa resta fuori» fornito
nella task. Wallet è stato successivamente indicato dal proprietario come meno
prioritario. Le modifiche sono nella worktree isolata sul branch `fix/review-completamento`; questo referto non certifica un
rilascio pubblico, un APK distribuito o prove su dispositivi non collegati.

## Risultato per funzione

| Punto | Implementazione e limite |
|---|---|
| 1. Prima di andare | Campi della data nei tre pannelli, eredità locale/evento/data, cibo e orario effettivo, indicazioni esplicite quando accessibilità, tessera, trasporti, parcheggio, ristorazione e ingresso non sono dichiarati. Web e app mostrano le informazioni della singola data. |
| 2. Prezzo trasparente | Quattro voci facoltative, somma in centesimi, distinzione zero/mancante, subtotale dichiarato come parziale. I filtri di budget usano il costo completo della data. I dati strutturati non pubblicano come prezzo finale un subtotale. |
| 3. Prossime ore | Preset condiviso web/API/Android, finestra della città, ordine cronologico, posti reali della biglietteria; niente numero inventato quando la capienza manca. Pagina e API senza cache per questa vista. |
| 4. Quasi esaurito | Soglia positiva ≤ 10% della capienza, almeno un posto, solo con totale noto e posti già occupati. Deduplica per destinatario/data, preferenza esaurimento, silenzio e tetto giornaliero esistenti. Ricontrollo di disponibilità e salvataggio alla consegna. |
| 5. Calendario personale | Selezione «date salvate» sul calendario nativo, snapshot aggiornato che esclude annullamenti/rinvii, indipendente dai filtri di scoperta. La certificazione su telefono reale resta da eseguire. |
| 6. Ingressi | Assegnazione/revoca staff per data, scanner limitato, richieste idempotenti, esclusione di doppi ingressi concorrenti. Le letture senza risposta restano in memoria nella pagina e si ritentano: non autorizzano ingressi offline; chiudere la pagina perde le letture in attesa, con avviso prima dell'uscita. |
| 7. Follow | Tutto / solo novità / nessuna notifica per locale, su web e app. I riepiloghi escludono «solo novità»; il feed continua a includere il locale. |
| 8. Feed | Motivi espliciti, accesso a tutto il catalogo, impostazioni richiudibili sul web e poche proposte nell'app. Nessun interesse dedotto introdotto. |
| 9. Rapporti economici | Campagna selezionata: spesa registrata / prenotazioni confermate o ingressi nel periodo. Campioni con almeno cinque account distinti; spesa mancante o concessione cumulativa senza attribuzione restano non disponibili. Nessuna promessa di ricavi, margine o causalità pubblicitaria. |
| 10. Wallet | Implementata revoca asincrona con ritentativi per pass emessi, cancellazioni e ingressi; mantenuta configurazione disattivata senza credenziali. Attivazione e prova Google non completate, secondo la priorità aggiornata. |
| 11. Widget | Nessuna prova su produttori reali certificata in questa task. I test automatici non sostituiscono il comportamento del launcher e del risparmio energetico del telefono. |

## Difetti corretti durante la review

- La migrazione della posizione precisa falliva sui database con le colonne
  della precedente versione arrotondata: ora aggiorna precisione e indice e
  ricopia i valori dalla fonte cifrata. Verificato anche sul database locale.
- Il contatore manuale poteva contraddire le prenotazioni reali; una capienza
  zero causava una divisione per zero nella percentuale.
- Il calendario salvati poteva mantenere date annullate o dipendere dai filtri
  di scoperta del catalogo.
- Una risposta persa dopo il check-in poteva sembrare un secondo tentativo:
  ora la chiave della lettura consente il recupero del risultato originale.
- Il costo della data poteva essere presentato diversamente nel filtro,
  nella scheda e nei dati strutturati; nell'app i dati generali potevano
  nascondere l'eccezione della data.
- L'app arrotondava la posizione prima di salvarla: eliminato l'arrotondamento
  che rendeva impreciso il raggio scelto per le proposte vicine.
- Un gruppo numeroso non deve da solo superare la soglia di riservatezza
  dei rapporti economici: il controllo conta gli account distinti.

## Verifiche e passaggi operativi

Le due migrazioni additive del 24 settembre creano i campi pratici/costi,
le preferenze di follow, l'assegnazione staff e i riferimenti di idempotenza/pass.
Sono state esercitate su database dedicati con segmento `test` nel nome.
Le migrazioni pendenti sono state applicate anche a `eventi_local`, dopo la
correzione della compatibilità con lo schema della posizione arrotondata.
Prima del rilascio applicare le migrazioni con la procedura ordinaria e
ricostruire gli asset. La revoca Wallet, se attivata in futuro, richiede anche
il worker della coda e il controllo dei job falliti.

Il contratto API aggiuntivo è documentato in `docs/API.md`. Corretto anche
l’errore Scramble `GEN001`: i due endpoint della singola occorrenza ora
condividono un metodo privato, evitando che l’analisi di una rotta consumi
quella dell’altra. Export rigenerato senza diagnostiche in `docs/openapi.json`
(119 percorsi, 143 operazioni), con riferimenti interni verificati. Aggiunto
un test sul comando `scramble:analyze`, che fallisce in presenza di errori
del generatore anche quando viene comunque prodotto un JSON.

### Collaudo fisico ancora necessario

Su un account di prova e una data dedicata, salvare una data e collegare
«date salvate» al calendario del telefono. Controllare titolo e orario,
spostare la data dal pannello, sincronizzare e verificare una sola voce con
il nuovo orario. Annullarla e controllarne la rimozione. Ripetere dopo il
riavvio e dopo revoca/ripristino del permesso calendario. Verificare che
scollegare inCittà lasci intatti gli altri calendari.

Per il widget annotare modello, versione Android e launcher; installarlo,
verificare apertura della data, assenza di rete, cambio città, risparmio
energetico e aggiornamenti dopo almeno due finestre di dodici ore. Registrare
orario previsto ed effettivo. Senza queste osservazioni il punto 11 resta aperto.

### Risultati automatici

La suite generale nella worktree isolata ha eseguito **3.003 test: 3.002 superati, uno fallito**. Il fallimento confrontava la pagina prima/dopo la cache: lo stato statico Livewire di un test precedente aggiungeva asset solo alla prima risposta. Il file della cache eseguito da solo supera **26 test e 102 asserzioni**. Aggiunto il ripristino dello stato Livewire prima di ciascun test, come già avviene per Pennant. Il ricontrollo combinato dopo la correzione (wizard, cache, sicurezza della cache e pannello prestazioni) supera **81 test e 442 asserzioni**. La suite generale non è stata rieseguita integralmente dopo questa sola modifica di isolamento dei test: il risultato iniziale resta 3.002/3.003, seguito dal ricontrollo mirato verde.

- Suite mirata nuove funzioni: **19 test, 79 asserzioni, tutti superati**.
- Concorrenza ingressi: **1 test su due processi/connessioni reali, 5 asserzioni**, un ingresso accettato e uno respinto.
- JavaScript coda letture: **4 test superati**, inclusi risposta persa, doppia lettura, richieste in corso e nuove letture accodate.
- Browser preesistenti profilo/biglietteria: **8 test superati**, desktop e mobile, inclusa decodifica QR reale con fotocamera simulata.
- Android: **150 test unitari, zero errori, un test di compatibilità con API live saltato**; compilazione dei test strumentali riuscita. Non sono stati eseguiti test su dispositivo fisico.
- PHPStan livello del progetto: **zero errori**. Deptrac: **zero violazioni**, restano le due eccezioni preesistenti dichiarate.
- Build frontend riuscita; resta l'avviso sulle dimensioni di alcuni bundle.

- Ricontrollo delle regressioni della prima suite generale: **106 test e 1.225 asserzioni superati**.

- Compatibilità migrazione posizione e rollback: **1 test su schema precedente, 6 asserzioni superate**.

- Nuovi flussi browser: **4 test, 34 asserzioni, tutti superati**, su desktop e mobile. Verificati costi/informazioni mancanti, preferenze persistenti e scansioni senza rete/con risposta persa.


### Isolamento della verifica finale

Un'altra attività ha salvato il workspace condiviso nello stash
`258406d55cc9769b93862d46fedeb4dbeafe6270` mentre la suite generale era in corso.
Il referto di quella corsa (3.001/3.003) non è una certificazione del codice
finale: i file delle ultime verifiche erano cambiati durante l'esecuzione.
Il lavoro è stato recuperato integralmente, senza eliminare lo stash, in una
worktree dedicata che include anche `ddc31e4`. La verifica conclusiva usa questa
copia isolata e database distinti.

Percorso della consegna: `/Users/fabio/.codex/worktrees/review-completamento/eventi`.
Le modifiche restano non committate, pronte per la revisione; nessun push o
rilascio pubblico è stato eseguito. Lo stash originale è conservato.


### Correzione export OpenAPI

Export completo riuscito senza diagnostiche. Verifiche dedicate: **19 test,
843 asserzioni superate**, incluse diagnostica Scramble, accesso alla
documentazione, dettaglio API e URL delle date. Il controllo PHPStan globale
rieseguito in questa worktree segnala due proprietà non riconosciute
(`User::$location_lat` e `User::$location_lng` in `MessageFactory.php:451`),
esterne alla correzione del controller OpenAPI; questo risultato aggiorna
il precedente esito globale riportato sopra.

### Preparazione PR e rilascio

Risolti anche i due rilievi PHPStan sulle coordinate: la migrazione ora
dichiara esplicitamente entrambe le colonne, mantenendo aggiornamento e
compatibilità con lo schema precedente. Analisi globale, a cache ricostruita:
**zero errori**. Il rilascio segue la CI della PR #116 e la pipeline di `main`,
con backup remoto e verifica del commit effettivamente pubblicato.

### Estensione Android richiesta dopo la review

La versione **1.17.0 (39)** aggiunge l’area nativa Gestione eventi: staff,
scanner QR, coda cifrata persistente, informazioni/costi della data e rapporti
economici. Non è necessario aprire i pannelli web per queste funzioni. Il
collaudo dei due ruoli su emulatore è riuscito; restano le prove dei produttori
reali per calendario/widget. Dettagli e artefatti in `docs/RELEASE-1.17.0.md`.

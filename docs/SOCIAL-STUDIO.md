# Social: uso quotidiano e configurazione

## Per gli amministratori

Nella dashboard iniziale si vedono le grafiche degli eventi di oggi. **Scarica
le grafiche di oggi (.zip)** prepara il pacchetto direttamente. **Social**
permette di cambiare giorno, città, selezione e formato.

1. Scegli il giorno e seleziona gli eventi desiderati.
2. Apri «Personalizza le grafiche» solo se vuoi cambiare bianco e nero,
   inquadratura, indirizzo, prezzo o il titolo di una singola grafica.
3. Premi «Prepara le grafiche selezionate». Le immagini preparate sono una
   copia dei dati di quel momento: cambiare le opzioni richiede di prepararle
   nuovamente.
4. Scarica lo ZIP. I nomi sono numerati nell'ordine cronologico. Oltre dieci
   immagini, lo ZIP contiene cartelle separate per ogni carosello, ciascuna
   con la propria didascalia. Le didascalie riportano luogo, orario e
   organizzatore quando disponibile, senza inventare un organizzatore.
5. Le ultime preparazioni restano recuperabili in «Grafiche già preparate».

La locandina viene mostrata intera per default, senza ritagli. Lo sfondo è
una copia ingrandita e sfocata della stessa immagine, fino ai bordi: nessuna
banda nera per compensare i diversi rapporti d'aspetto. Una sfumatura
dal nero al trasparente sostiene i testi sovrapposti nella parte inferiore.
Il ritaglio è disponibile soltanto come scelta esplicita.

I formati sono JPEG verticale 1080×1350, quadrato 1080×1080 e storia
1080×1920. L'autopost riguarda i post, non le storie. I font sono gli stessi
file già usati dal sito per le anteprime, con nero, carta e lime inCittà.
I titoli vanno a capo e si ridimensionano entro un limite leggibile. Se un
testo non entra, la generazione segnala l'errore e non consegna immagini
con parole tagliate. Si può impostare un titolo alternativo senza cambiare
l'evento pubblico.

## Per i locali

La voce «Social» è presente nel pannello del locale. Il pulsante «Anteprima
grafica» nella modifica dell'evento apre direttamente quell'evento.
Sono disponibili anteprime e download delle proprie grafiche, anche durante
la preparazione di un evento non ancora pubblicato. I locali non possono
configurare i token della piattaforma o pubblicare sui suoi account.
I controlli lato server verificano il locale anche modificando gli ID.

## Collegare Facebook e Instagram

Da Social aprire «Collega account e programma». La configurazione riguarda
la Pagina della piattaforma e un account Instagram professionale collegato.

- Il download funziona senza token.
- Inserire ID della Pagina, ID Instagram e token di accesso della Pagina,
  ottenuti tramite la propria app Meta autorizzata. Non usare password di
  Facebook o Instagram.
- Autorizzazioni necessarie: `pages_manage_posts`, `pages_read_engagement`;
  per Instagram anche `instagram_basic` e `instagram_content_publish`.
  L'uso con account esterni ai ruoli dell'app può richiedere revisione Meta.
- Salvare e premere «Verifica collegamento». La verifica legge i dati degli
  account, non pubblica un post di prova. L'effettivo permesso di pubblicazione
  e l'esito finale vengono verificati all'invio e mostrati nello storico.
- Il token è cifrato nel database, non viene rimostrato e non viene inserito
  negli URL. Lasciare il campo vuoto conserva il token esistente.
- Una modifica delle credenziali o dei canali invalida la verifica.

## Didascalie e automatismo

Il testo è configurabile e i chip sopra il campo inseriscono:

| Segnaposto | Contenuto |
| --- | --- |
| `:data` | Data degli eventi, per esempio 6 settembre 2026 |
| `:giorno` | Giorno della settimana |
| `:citta` | Città selezionata |
| `:eventi` | Elenco della singola parte, con locali, orari e organizzatori |
| `:numero` | Numero di eventi della singola parte |
| `:link` | Pagina eventi della città già filtrata per quella data |
| `:parte` | Numero della parte corrente |
| `:parti` | Numero totale delle parti |

I segnaposti sconosciuti sono respinti al salvataggio. La didascalia compilata
deve restare entro 2.200 caratteri (1.024 con Telegram abilitato); in caso contrario l'invio viene fermato.
Prima dell'invio manuale si vedono le didascalie effettive.

L'automatismo è **spento per default**. Dopo il collegamento verificato si
può accendere, scegliere l'ora nel fuso della città e personalizzare le opzioni
grafiche. `social:daily`, eseguito ogni minuto dallo scheduler esistente,
prepara gli eventi del giorno e li divide in parti da massimo dieci.
I giorni vuoti sono saltati. Le date sono selezionate dal motore temporale
condiviso col sito, comprese le regole degli eventi notturni.

## Affidabilità

- `PublishSocial` lavora nella coda Laravel esistente. Ogni caricamento viene
  registrato; Instagram aspetta lo stato `FINISHED` dei contenitori.
- Un doppio clic sullo stesso pacchetto non crea due invii. Lo scheduler
  deduplica anche per canale e giornata.
- I dati vengono ricontrollati prima dell'invio. Una modifica, un annullamento
  o la sospensione del locale richiedono di preparare nuove grafiche.
- Prima della chiamata irreversibile lo stato diventa «Da verificare». Se
  manca la risposta del social, **non parte un secondo invio automatico**.
  Il pulsante di nuovo tentativo richiede di aver controllato sul social che
  il post non esista già.
- Gli URL temporanei delle immagini durano sette giorni e possono essere
  letti da Meta e Telegram; i file e gli ZIP non sono in una directory pubblica.
- Non c'è pubblicazione reale finché non vengono forniti token validi e
  attivati i canali. I test automatici usano risposte Meta e Telegram simulate.

## Dipendenze e rilascio

Il generatore usa `intervention/image` 3.11, driver GD (disponibile anche sul
server pubblico). Non richiede browser, Node o un servizio di grafica esterno.
Sono necessarie le migrazioni `2026_09_06_120000` e `2026_09_06_130000` e
`composer install` dal lock aggiornato. Scheduler e worker già presenti
restano i medesimi. Nessun APK viene generato da questo rilascio.

## Autenticazione Meta dal backend

In Social → Impostazioni inserire App ID e App Secret dell’app Meta.
Registrare in Facebook Login l’URL di callback mostrato nella pagina, identico
al dominio HTTPS pubblico. «Collega con Meta» apre l’autorizzazione; dopo
il consenso si sceglie una delle Pagine amministrate. Il sistema recupera
il token della Pagina e l’account Instagram professionale collegato.
Il flusso richiede anche `pages_show_list` e usa uno stato monouso valido
dieci minuti. Rimane disponibile l’inserimento manuale degli ID e del token.
App Secret e token sono cifrati e non vengono rimostrati nel modulo.
La pubblicazione automatica resta spenta dopo il collegamento.

## Collegare Telegram

1. Creare un bot tramite BotFather e inserirne il token in Social → Impostazioni.
2. Aggiungere il bot come amministratore del canale con il permesso di pubblicare.
3. Inserire `@nomecanale` oppure l’ID numerico del canale, abilitare Telegram,
   salvare e premere «Verifica Telegram». La verifica non invia messaggi.

Una sola immagine viene inviata come foto, più immagini come album in parti
fino a dieci, con la didascalia sulla prima immagine. La didascalia deve
rientrare in 1.024 caratteri: se necessario accorciare il modello prima di
preparare il contenuto. Il token del bot è cifrato e nascosto; errori e
diagnostica non registrano gli URL Telegram contenenti la credenziale.

## Programmare, spostare o annullare un post

In Social preparare le grafiche, scegliere data e ora nella sezione
«Programma la pubblicazione» e premere «Programma» sul contenuto preparato.
L’ora segue il fuso della città, indicato accanto al campo. Le pubblicazioni
programmate compaiono nello storico per ciascun canale e parte.
Per spostarle impostare la nuova data e premere «Usa la nuova data e ora»
sulla riga desiderata; «Annulla programmazione» annulla quella riga finché
non è già entrata in esecuzione. Le storie restano scaricabili manualmente.

`social:publish-due` controlla ogni minuto e accoda una sola volta i post
scaduti. L’orario effettivo dipende anche dal worker e dai tempi delle API.
I contenuti vengono ricontrollati all’invio: eventi modificati richiedono
nuove grafiche. Gli URL firmati delle immagini vengono creati al momento
dell’invio, quindi una programmazione lontana non li fa scadere in anticipo.

## Cron e monitoraggio

Sistema → Cron e programmazioni mostra tutte le attività registrate nello
scheduler, con spiegazione, frequenza, prossima esecuzione e storico del
monitor. La pagina genera i due comandi cron per il percorso e PHP del
server: scheduler e worker ogni minuto, protetti da `flock`. Tutte le
programmazioni applicative passano dallo scheduler centrale; non aggiungere
un cron separato per ciascuna attività. Sostituire eventuali righe precedenti,
senza duplicarle. Se il processo web non può leggere il crontab, la pagina
lo indica senza dichiarare i cron assenti.

Sul server eventi sono installati entrambi i cron. Il percorso PHP può
essere configurato con `SCHEDULER_PHP_BINARY`. Dopo il rilascio delle tre
migrazioni `2026_09_10_150000`, `150100` e `150200`, eseguire
`php artisan schedule-monitor:sync` per registrare subito la nuova attività.

L’integrazione usa il publisher applicativo esistente, esteso con Telegram
e OAuth Meta. La libreria laravel-social-auto-post è stata valutata ma non
aggiunta: il flusso esistente conserva stato dei contenitori Instagram,
deduplicazione e gestione esplicita degli invii dall’esito incerto.

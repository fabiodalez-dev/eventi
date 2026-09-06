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
deve restare entro 2.200 caratteri; in caso contrario l'invio viene fermato.
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
  letti da Meta; i file e gli ZIP non sono in una directory pubblica.
- Non c'è pubblicazione reale finché non vengono forniti token validi e
  attivati i canali. I test automatici usano risposte Meta simulate.

## Dipendenze e rilascio

Il generatore usa `intervention/image` 3.11, driver GD (disponibile anche sul
server pubblico). Non richiede browser, Node o un servizio di grafica esterno.
Sono necessarie le migrazioni `2026_09_06_120000` e `2026_09_06_130000` e
`composer install` dal lock aggiornato. Scheduler e worker già presenti
restano i medesimi. Nessun APK viene generato da questo rilascio.

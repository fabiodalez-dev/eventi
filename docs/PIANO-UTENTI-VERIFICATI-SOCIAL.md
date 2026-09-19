# Utenti verificati e consigli sugli eventi

Data: 18 settembre 2026. Branch: `fix/verified-users-social`, creato dal commit `9f03f53`.

Stato: **implementazione web, API, Android e backend amministrativo in main e pubblicata il 18 settembre 2026**. Prima integrata con PR #93 (`08018ea`), poi annullata con #95 perché entrata senza review, e reintegrata dopo review completa e correzioni con #98 (server, `59ff0ef`) e #99 (Android, `27f16ef`). Le decisioni qui sotto recepiscono le risposte del proprietario. Le sezioni architetturali successive conservano il ragionamento iniziale; in caso di differenza prevale questo registro di implementazione.

## Decisioni approvate e realizzazione

- Sito e Android nativo condividono i servizi Laravel. Conferma email obbligatoria; invito gratuito e facoltativo alla verifica WhatsApp dopo la conferma, rinviabile.
- Account preesistenti: nessuna conferma email scritta dalla migrazione. La prima versione confermava in blocco gli account non confermati; il riempimento è stato tolto e in produzione gli account interessati sono stati rivisti a mano il 18 settembre (dettagli in `docs/DECISIONS.md`). Il badge WhatsApp richiede la prova del numero; nessun telefono inventato viene certificato in produzione.
- Follow libero con contatori e notifica nel sito/app, senza richieste di amicizia separate. Gli account ordinari possono seguire e leggere, soltanto i verificati pubblicano e commentano.
- Nome pubblico e handle univoco; bio, foto, città facoltativa, profilo pubblico/solo iscritti/privato e consenso separato all'indicizzazione.
- Salvataggio privato predefinito, privacy modificabile per singola data. La pubblicazione crea un solo post con testo fino a 500 caratteri, intenzione «Lo consiglio»/«Parteciperò» e card completa del catalogo. Privatizzare o rimuovere il salvataggio ritira post e commenti.
- Locali scelti esplicitamente tra quelli seguiti e approvati. Nessuna esposizione automatica dei preferiti precedenti.
- Bacheca persone seguite, scoperta globale, ordinamento recente/data evento, archivio e paginazione esplicita. Ricerca e selezione redazionale, pulsante Segui anche nell'elenco persone.
- Commenti fino a 1.000 caratteri, una sola profondità di risposta, cancellazione da autore/proprietario del post/staff. Segnalazioni, blocchi reciproci e moderazione successiva alla pubblicazione.
- Revoca, perdita della conferma email, sospensione o cancellazione nascondono immediatamente i contenuti. La moderazione di una data non si aggira ritirando e ripubblicando; lo staff può ripristinare la pubblicazione dal registro blocchi.
- Admin: directory utenti, filtri email/WhatsApp/sospensione, storico OTP con numeri mascherati, revoca, sospensione, selezione redazionale, gestione post/commenti e collegamento dalle segnalazioni. Nessuna assegnazione arbitraria del badge WhatsApp.
- `overtrue/laravel-follow` **6.0.0** installato, `followables` distinto dai follow del catalogo. `spatie/laravel-activitylog` per audit. Reverb e fan-out Redis rinviati: non necessari per feed paginato e notifiche in-app.
- Prenotazioni esclusive: sviluppo successivo, come richiesto. Nessuna chat, messaggistica privata, like o feed infinito aggiunti.

## Protezioni e verifica

OTP crittografico a sei cifre, hash del codice, telefono cifrato e fingerprint HMAC separato, unicità del numero, durata cinque minuti, cinque tentativi, cooldown e limiti per utente/telefono/IP/globale. Consumo atomico, invalidazione del codice precedente solo dopo un invio partito, audit senza codici, eliminazione dei challenge dopo trenta giorni. Credenziali solo in ambiente server; Telescope esclude le chiamate contenenti OTP.

Foto decodificate e ricodificate JPEG, SVG escluso, limite 2 MB/4096 px, storage privato e accesso autorizzato a ogni richiesta. Risposte personali `no-store`, avatar Android senza cache persistente. Export e cancellazione includono dati social.

**61 nuovi test** di sicurezza/comportamento in `tests/Feature/Community/CommunitySecurityTest.php`: i cinquanta richiesti più undici su avatar, retention, backend, informativa privacy e selezione sicura del template Android. Tutti superati: 267 asserzioni. La regressione PHP completa integrata precedente ha superato 2.632 test / 11.264 asserzioni; i successivi cambiamenti sono coperti dalla suite mirata e dalla CI del rilascio.

Android: otto nuovi test JVM su sessione, nonce, formato e scadenza; dodici test strumentali con il parser SDK e Android Keystore reali; tre prove Compose per compilazione senza invio automatico, isolamento della challenge e inserimento manuale. Eseguiti sull'emulatore Android 15 insieme alle tre regressioni SafeScreens: **18/18 superati**. I test non inviano messaggi a numeri reali. Il primo controllo CI aveva individuato un parametro mancante nel test ProfileScreen, corretto e ricompilato.

Il lint della release ha individuato e corretto l'uso di `InputStream.readNBytes` non disponibile sotto API 33: lettura avatar limitata a 2 MB più un byte sentinella, verificata con tre test JVM aggiuntivi (stream illimitato, soglia esatta, letture parziali). Corretti anche i messaggi Compose per reagire alla configurazione linguistica. Suite JVM complessiva: 87 superati, un test preesistente ignorato, zero errori; lintRelease senza errori. APK 1.14.0 (36).

## Correzioni dopo la review della PR #98 (18 settembre 2026)

La review ha prodotto cinque gruppi di correzioni, ciascuno con i propri test in `tests/Feature/Community/CommunitySecurityTest.php` (dal numero 62 in poi). Qui è registrato che cosa è cambiato rispetto alla descrizione precedente e che cosa è rinviato a issue separate.

**Esiti dell'invio WhatsApp.** L'invio del codice ha tre esiti (`App\Enums\KapsoOutcome`). *Consegnato*: Kapso ha accettato il messaggio e ne ha restituito l'identificativo; il codice nuovo sostituisce quelli precedenti. *Incerto*: la connessione è caduta dopo la partenza della richiesta (per esempio un timeout); il codice resta confermabile perché potrebbe essere arrivato, sostituisce i precedenti, conta nei limiti e l'API risponde `delivery: "uncertain"`, mentre il sito avvisa che il messaggio potrebbe non essere arrivato. *Rifiutato*: errore del fornitore, risposta senza identificativo o connessione mai stabilita; la richiesta è segnata come fallita, il codice precedente resta valido e i contatori per IP e globale vengono restituiti. Le richieste fallite non contano nei tetti orario e giornaliero ma contano nell'attesa di sessanta secondi fra un invio e l'altro. L'API aggiunge `delivery: "sent"` negli altri casi, senza togliere né rinominare chiavi.

**Revoca e limiti.** La revoca non cancella più le richieste: segna come usati i codici in attesa, così non azzera i limiti d'invio né lo storico, che resta fino alla pulizia dopo trenta giorni; il numero viene cancellato dall'account. L'informativa è stata corretta di conseguenza, anche nelle pagine già pubblicate, con la migrazione `2026_09_18_120000_community_privacy_retention`. Il controllo sul numero già usato da un altro account consuma il limite per IP prima di rispondere, per non trasformarlo in un modo gratuito di sondare i numeri. Ogni azione della community ha il proprio limite di frequenza, identico fra sito e API. I limiti d'invio sono separati per account e per numero (issue #101): ogni account ha tre invii l'ora e cinque al giorno, che le richieste fatte da altri sullo stesso numero non consumano, così un estraneo non può più bloccare la verifica del titolare; il numero, nelle ultime 24 ore, riceve codici al massimo per tre account diversi (`number_foreign_daily_limit`), quindi nel caso peggiore quindici messaggi al giorno, comunque sotto i limiti per IP e il tetto globale. Si contano gli account e non gli invii perché un tetto sugli invii lo riempirebbe un solo estraneo, e il titolare, indistinguibile da lui, resterebbe di nuovo chiuso fuori. Le richieste rifiutate da Kapso contano solo nell'attesa di sessanta secondi, che vale sia per l'account sia per il numero.

**Chiave dell'impronta.** L'impronta del numero usa soltanto `WHATSAPP_PHONE_HASH_KEY`, senza ripiego su `APP_KEY`: senza la chiave la verifica WhatsApp risulta non disponibile. L'installer la genera per le installazioni nuove; per i server esistenti la procedura è nel runbook. Una rotazione della chiave cambierebbe tutte le impronte e riaprirebbe i numeri già usati: il comando di rotazione con riverifica è rinviato alla issue #103.

**Sospensione e cancellazione.** Se un account sospeso viene cancellato restano l'impronta del numero e la data della sospensione, così lo stesso numero non torna con una nuova iscrizione; il numero invece viene cancellato. L'informativa lo dice. Il periodo dopo il quale anche l'impronta trattenuta va eliminata è rinviato alla issue #104. Chi è sospeso non può revocare da sé la verifica, perché libererebbe il numero; lo staff sì.

**Conferma email.** Nessun riempimento di `email_verified_at` nella migrazione (vedi sopra). Chi non ha confermato l'email può comunque spegnere ciò che già riceve e rivedere le preferenze: togliere push, dispositivi e Google Calendar, leggere e modificare preferenze e interessi, dal sito e dall'API; resta bloccato ciò che apre un canale nuovo. Il link di conferma distingue ospite, stesso utente e utente diverso.

**Community.** Chi ha il profilo privato vede i propri post, il loro permalink e può commentarli. L'archivio del profilo mostra solo le date concluse. I contatori di follower e seguiti contano solo relazioni ammesse (email confermata, account né sospeso né cancellato). Nei commenti chi ha un profilo non visibile a chi legge compare come «Utente». Bacheca, persone, post e follower non fanno più una query per riga. Dopo un blocco si può ancora segnalare in entrambe le direzioni. La cancellazione dei commenti segue una sola policy (autore del commento, autore del post, staff). Seguire di nuovo la stessa persona entro un giorno non genera un secondo avviso. Chi è verificato ma non ha un profilo riceve l'invito a crearlo.

**Rifiniture.** Con `COMMUNITY_ENABLED=false` i collegamenti alla community spariscono da menu e pagine dell'account. L'export comprende anche chi segue l'account. Sul sito ogni azione conferma che cosa è successo (seguito, bloccato, commento rimosso, segnalazione inviata). Nel pannello, sospendere e nascondere chiedono conferma e le pubblicazioni bloccate mostrano titolo e data dell'evento e si cercano per nome.

## Attivazione esterna e dominio

Il proprietario ha completato la verifica dell'attività Meta il 18 settembre 2026. Portfolio verificato, account WhatsApp approvato e limite di messaggistica pari a 2.000. Creato e verificato tramite API Kapso il template italiano `incitta_verifica_whatsapp`, ID `1106275741742965`, stato **APPROVED**, categoria AUTHENTICATION, pulsante COPY_CODE e durata cinque minuti. Il precedente rifiuto Meta 10 / 2388185 è risolto. Credenziali esclusivamente nell'ambiente server; l'attivazione usa `WHATSAPP_VERIFICATION_ENABLED=true`.

`fabiodalez.it` aggiunto ai domini autorizzati del portfolio Meta. L'autorizzazione del dominio e la verifica dell'attività restano distinte dalla verifica DNS della proprietà. Dati e documenti dell'attività sono stati completati dal proprietario.

La verifica separata della proprietà di `fabiodalez.it` è completata: Meta mostra **Verified**, risorsa `950522017480727`, dopo il caricamento del file HTML fornito da Meta nella radice del dominio e il controllo della sua raggiungibilità HTTPS. Il progetto Kapso Eventi è stato escluso dall'uso dei messaggi per miglioramento/addestramento modelli attraverso l'opzione dedicata, salvata e verificata nel pannello.

### Autocompilazione WhatsApp su Android

Integrato l'SDK ufficiale `com.whatsapp.otp:whatsapp-otp-android-sdk:1.0.0`. Prima dell'invio l'app verifica il supporto di WhatsApp/WhatsApp Business e salva un nonce UUID cifrato, legato alla sessione e valido cinque minuti. L'Activity ricevente accetta solo azione, nonce e codice a sei cifre corrispondenti; il codice rimane in memoria, viene associato alla challenge del server e consumato una sola volta. Logout, cambio sessione e reinvio lo invalidano. L'utente tocca il pulsante WhatsApp, trova il campo compilato e conferma nell'app. Nessun accesso alle notifiche di altre app o agli SMS.

Il template italiano `incitta_verifica_android` (ID `1756743655374332`) è **APPROVED**, AUTHENTICATION ONE_TAP, durata cinque minuti; configurato tramite `KAPSO_ANDROID_AUTH_TEMPLATE_NAME` negli ambienti locale e remoto. Le richieste senza `delivery=one_tap`, i client precedenti e i dispositivi senza supporto continuano a usare COPY_CODE. L'assenza del template Android disabilita l'autocompilazione senza impedire la verifica manuale.

I `supported_apps` attuali corrispondono alla firma storica degli APK locali: `it.fabiodalez.incitta` → `Z5ELh4mhkZY`, `it.fabiodalez.incitta.debug` → `PUFKVFAGpse`. Sono hash pubblici, non credenziali. Per la distribuzione Google Play serve aggiungere l'hash del **certificato App signing di Play**, distinto dal certificato di upload. La sessione Google disponibile in Chrome non dà accesso all'account Play del progetto; nessuna chiave o identità alternativa è stata inventata. Senza questa configurazione la versione firmata da Play conserva l'alternativa Copia codice. Nessun caricamento su Play eseguito.

Calcolo dell'hash secondo il [campione ufficiale WhatsApp](https://github.com/WhatsApp/WhatsApp-OTP-Sample-App): SHA-256 della stringa UTF-8 `package_name + " " + certificato_DER_in_esadecimale_minuscolo`, primi nove byte, Base64 senza padding, primi undici caratteri. Non usare l'impronta SHA-256 del solo certificato. Resta da provare la consegna reale e il passaggio da WhatsApp su un telefono autorizzato; emulatore e mock HTTP non attestano quel passaggio esterno.

Memoria permanente del cambio dominio: [DOMINIO-DEFINITIVO.md](DOMINIO-DEFINITIVO.md), richiamato dalle convenzioni vincolanti. Include Meta, Kapso, OAuth, email, Android, push, mappe, pagamenti e deployment.

Il proprietario ha precisato che il trasferimento avverrà anche su un nuovo account: tutte le integrazioni andranno ricollegate e collaudate lì. Non limitarsi a sostituire gli URL e non presumere che credenziali, risorse, firme o verifiche del vecchio account restino applicabili.

### Collaudo del rilascio

Dati del primo rilascio (#93); il rilascio corrente è quello di #98 e #99, con le correzioni della review. CI sul commit main: **2.628 test PHP / 11.233 asserzioni**, **95 test browser desktop/mobile / 749 asserzioni**, compilazione e test Android, Pint/PHPStan e controlli architetturali superati. La prova API Android facoltativa è stata poi attivata contro l'API locale: tre test superati, compreso quello escluso nella normale suite, per 88 casi JVM/API distinti complessivamente esercitati. Confermati anche i 18 test strumentali su Android 15.

Rilascio verificato via HTTPS e SHA esatto, `deploy:verify` superato. Schema social e configurazione COPY_CODE/ONE_TAP presenti, informativa aggiornata, nessun account QA trasferito, salvataggi preesistenti ancora privati. Backup database e copia di rollback creati prima del rilascio e conservati sul server.

La pipeline separata Play bundle ha superato test release e lint, poi si è fermata per assenza di `PLAY_UPLOAD_KEY_BASE64`, `PLAY_UPLOAD_PASSWORD` e `ANDROID_GOOGLE_SERVICES_JSON` nell'ambiente CI. L'APK locale è compilato; nessuna pubblicazione Play effettuata. Restano necessarie la configurazione del certificato App signing di Play e una prova WhatsApp completa su un numero/dispositivo autorizzato.

## 1. Obiettivo e requisiti acquisiti

La persona registrata conferma sempre la propria email. Subito dopo riceve l'invito facoltativo a verificare WhatsApp: l'attivazione è gratuita e abilita servizi esclusivi. Esistono utenti ordinari e utenti con WhatsApp verificato; questo stato riguarda la persona e resta distinto dalla verifica dei locali, dai loro piani commerciali e dai ruoli amministrativi.

Gli utenti verificati hanno una pagina pubblica, possono pubblicare eventi salvati con un breve testo e commentare. Gli utenti ordinari possono leggere i contenuti degli utenti verificati e il feed delle persone seguite, ma non commentare. I salvataggi privati sono consultabili soltanto dal proprietario. La pagina può mostrare anche locali scelti dall'utente. Vanno definite le relazioni fra persone: seguire qualcuno e diventarne amico non sono necessariamente la stessa operazione.

Il prodotto resta centrato sulla scelta degli eventi: card, data, luogo e azione di salvataggio hanno priorità. Le precedenti indicazioni della roadmap che rinviavano la partecipazione pubblica sono superate dalla richiesta attuale; durante l'implementazione aggiornare `PRODUCT.md`, roadmap e decisioni per evitare due contratti funzionali contraddittori.

La verifica WhatsApp prova il controllo di un numero in quel momento. Il badge non deve promettere verifica anagrafica, presenza effettiva a un evento o affidabilità dei consigli. Testo proposto: «WhatsApp verificato».

## 2. Stato del progetto verificato

| Area | Implementazione esistente | Conseguenza |
| --- | --- | --- |
| Stack | Laravel 13, PHP 8.4, MariaDB, Blade/Alpine, Filament, Sanctum, Android nativo | Regole condivise fra web e API; verificare anche i client Android esistenti |
| Account | `User` implementa `MustVerifyEmail`; `RegisterUser` invia la conferma | Estendere il percorso esistente |
| Restrizioni email | Account web e API possono ancora salvare e seguire senza conferma; la verifica limita principalmente le notifiche | Rendere effettivo il nuovo requisito su tutte le entrate pertinenti |
| Link email | `SignedEmailVerification`, firma e scadenza; apertura anche senza sessione | Conservare il flusso fra dispositivi, senza attribuire la verifica a un altro utente collegato |
| Salvataggi | `saved_events`, univocità utente/occorrenza; `SaveOccurrences` condivisa | L'unità del post deve essere una data precisa |
| Salvataggi automatici | `SaveOccurrenceForFollowers`; import dai salvataggi anonimi; selezione multipla | Nessuno di questi deve pubblicare automaticamente |
| Follow attuali | `follows` polimorfici verso locali, organizzatori, categorie, tag e serie | Conservare questi significati e aggiungere il grafo delle persone |
| Feed attuale | `PersonalFeed`, date future delle sorgenti seguite | Affiancare il feed delle persone; evitare cambiamenti incompatibili al feed API v1 |
| Registro attività | `spatie/laravel-activitylog` già richiesto da Composer | Riutilizzarlo per audit selettivo |
| Social Studio | Pubblicazione redazionale verso Facebook, Instagram e Telegram | Usare un dominio `Community`, distinto dai modelli `Social*` esistenti |
| Privacy | Export, cancellazione account, preferenze notifiche e cache personali | Estenderli a telefono, profilo, post, relazioni e commenti |
| Funzioni progressive | Laravel Pennant e code già presenti | Riutilizzare gli strumenti di attivazione e distribuzione esistenti |

Le modifiche locali preesistenti su mappe, filtri, CSS, cache e relativo piano trasporti sono rimaste intatte. La creazione del branch non le ha trasformate in modifiche di questa funzionalità.

## 3. Decisioni architetturali proposte

### Relazioni fra utenti

Preferenza: **`overtrue/laravel-follow:^6.0` per le relazioni tra persone**, con i trait `Follower` e `Followable` su `User`. La versione stabile 6.0.0 è presente nei metadati Composer ufficiali e richiede PHP `^8.3` e Laravel `^13.0`, compatibili con i requisiti del progetto. Prima dell'installazione confermare la risoluzione rispetto al lock completo, poi fissare la versione nel lock. [Manifest ufficiale 6.x](https://github.com/overtrue/laravel-follow/blob/6.x/composer.json), [metadati Composer](https://repo.packagist.org/p2/overtrue/laravel-follow.json).

Il pacchetto usa di default `followables`, mentre il progetto usa già `follows`: mantenerle separate. `User::follows()` continua a rappresentare locali, organizzatori, categorie, tag e serie; `followings()`/`followers()` del pacchetto rappresentano le persone. Usare l'alias `user` già registrato nella morph map. Verificare e completare indici, univocità e rimozione dei riferimenti in entrambe le direzioni senza migrare i follow esistenti. [Configurazione 6.x](https://github.com/overtrue/laravel-follow/blob/6.x/config/follow.php).

La migrazione 6.x consultata non definisce il vincolo univoco della relazione: aggiungere una migrazione applicativa con unicità `(user_id, followable_type, followable_id)` e foreign key del follower. Il destinatario è polimorfico: gestirne esplicitamente la rimozione. Serializzare le mutazioni concorrenti della stessa coppia e preservare l'idempotenza delle richieste. [Migrazione ufficiale](https://github.com/overtrue/laravel-follow/blob/6.x/migrations/2022_05_02_000000_create_followables_table.php).

Le Action applicative aggiungono le regole che il pacchetto non decide: email/WhatsApp, blocchi, idoneità del profilo, richieste ripetute e sospensioni. Il feed deve selezionare i `followable_id` delle relazioni approvate di tipo `user`: `followings()->pluck('id')` restituisce ID delle relazioni, non degli utenti. Anche i conteggi pubblici devono includere soltanto relazioni ammesse. [Implementazione `Follower`](https://github.com/overtrue/laravel-follow/blob/6.x/src/Traits/Follower.php).

Proposta funzionale: qualunque utente con email confermata può seguire un verificato; «amici» indica due verificati che si seguono a vicenda. Il pacchetto supporta anche richieste di follow con approvazione, utili se si sceglie quel modello. Se la risposta 3 richiede un'amicizia distinta dal follow, aggiungere un'entità esplicita con stati richiesta/accettata/rifiutata/annullata e coppia univoca: approvare un follow unidirezionale non equivale da solo a un'amicizia bilaterale. [API 6.x](https://github.com/overtrue/laravel-follow/tree/6.x).

### Post e audit

`CommunityPost` è la fonte dei contenuti mostrati: autore, salvataggio collegato, testo, data di pubblicazione e stato di moderazione. `CommunityComment` contiene i commenti. Il registro attività documenta soltanto operazioni selezionate: creazione/rimozione, cambio di visibilità, verifica e moderazione, con dati minimi. Non trasformare il log amministrativo in una timeline pubblica e non registrarvi OTP, numero completo o copie di testi diventati privati.

### Feed e Reverb

Prima versione: lettura SQL dei post pubblicabili delle persone seguite, con `EXISTS`/subquery indicizzate, caricamento anticipato delle relazioni e paginazione a cursore su `(published_at, id)`. Evitare di caricare tutti gli ID seguiti in PHP con `pluck()` a ogni pagina. Profilo, feed e permalink devono riutilizzare gli stessi controlli di visibilità.

Reverb è rinviato: non serve a pubblicare, leggere o commentare con normali richieste HTTP. Richiede un processo persistente e una configurazione operativa dedicata; introdurlo solo se l'aggiornamento istantaneo diventa un requisito misurabile. [Documentazione ufficiale Reverb](https://laravel.com/framework/docs/13.x/reverb).

La propagazione anticipata dei post nei feed tramite code e Redis è un'evoluzione successiva, guidata da volume e misure. In tal caso propagare identificativi, ricontrollare la visibilità alla lettura e gestire revoche, blocchi, unfollow e autori con molti follower. La prima versione non richiede Redis.

## 4. Stati e autorizzazioni

Lo stato verificato è una capacità calcolata: email confermata, numero confermato, account attivo e assenza di sospensione delle funzioni social. Nessun nuovo ruolo globale «influencer» è necessario. I privilegi di un gestore o di un amministratore non costituiscono una verifica WhatsApp.

Matrice proposta, da adattare alle risposte 2, 4 e 10:

| Operazione | Anonimo | Email da confermare | Email confermata | Email + WhatsApp |
| --- | --- | --- | --- | --- |
| Leggere catalogo | Sì | Sì | Sì | Sì |
| Salvare nel browser | Comportamento esistente | Dati locali preservati | Sincronizzazione account | Sincronizzazione account |
| Usare salvataggi server e seguire | No | Dopo la conferma | Sì | Sì |
| Leggere profili/post | Risposta 4 | Come un visitatore | Sì | Sì |
| Feed delle persone seguite | No | Dopo la conferma | Sì | Sì |
| Pubblicare un salvataggio | No | No | Proposta: no, risposta 10 | Sì, scelta esplicita |
| Commentare | No | No | No | Sì |
| Gestire dati, uscire, completare verifica | Accesso secondo il flusso | Sempre raggiungibile | Sì | Sì |

Applicare policy alle risorse e controlli nelle Action comuni; nascondere un pulsante non è una protezione. Prevedere codici API stabili come `EMAIL_VERIFICATION_REQUIRED` e `WHATSAPP_VERIFICATION_REQUIRED`, coerenti con l'envelope attuale.

Una revisione delle entrate deve includere registrazione web/API, magic link, token mobili già emessi, inviti ai gestori, pannelli, recensioni, prenotazioni e scritture su profilo. Distinguere funzioni da bloccare da operazioni che devono rimanere disponibili, come logout, recupero accesso, reinvio conferma, esportazione e cancellazione. Non introdurre un middleware indiscriminato che crei redirect circolari o impedisca di gestire prenotazioni già esistenti.

## 5. Iscrizione e verifica email

1. Registrazione con i controlli esistenti, creazione account in attesa e invio email.
2. Pagina «Conferma la tua email», reinvio limitato e stato recuperabile anche dopo chiusura del browser. I salvataggi anonimi rimangono locali finché la sincronizzazione non riesce.
3. Link firmato valido: conferma idempotente dell'utente cui appartiene il link.
4. Se la sessione è dello stesso utente, apertura dell'invito WhatsApp; senza sessione, accesso con ritorno al percorso. Se è collegato un altro account, nessuna verifica o associazione telefonica su quell'account.
5. Invito: «Verifica WhatsApp gratuitamente per pubblicare i tuoi eventi, avere una pagina personale e commentare». Pulsanti «Verifica WhatsApp» e «Più tardi»; i servizi ulteriori dipendono dalla risposta 9.
6. Un rinvio non disabilita l'account ordinario; l'azione resta nel profilo e nei punti di accesso alle funzioni riservate.

Memorizzare il completamento/rinvio dell'onboarding sul server, senza ripresentare il blocco a ogni login. Non considerare automaticamente la password reimpostata o un semplice login prova della verifica richiesta. Un eventuale cambio email deve azzerarne la conferma e sospendere le capacità che la richiedono fino alla nuova verifica.

Per gli account esistenti preservare i dati e non inventare `email_verified_at`. Raccomandazione: requisito immediato sui nuovi account; finestra di transizione di 14 giorni per gli account già creati, se scelta nella risposta 2. Le nuove funzioni social richiedono comunque email confermata fin dal loro avvio. Verificare preventivamente gli accessi operativi degli amministratori senza modificarne le password.

## 6. Integrazione Kapso e verifica WhatsApp

### Prerequisiti esterni

La guida Kapso documenta template `AUTHENTICATION`, con pulsante `COPY_CODE` utilizzabile sui dispositivi supportati. Riporta come requisiti un Business Portfolio Meta verificato e un limite di messaggistica WABA almeno 2.000. Prima dell'attivazione vanno verificati mittente operativo, `phone_number_id`, template approvato e lingua. Questi dati non sono provati dalla sola disponibilità della chiave API. [Guida OTP Kapso](https://docs.kapso.ai/docs/whatsapp/templates/authentication).

Integrare il client HTTP Laravel direttamente, senza introdurre un servizio Node per inviare i messaggi. La documentazione corrente usa `POST https://api.kapso.ai/meta/whatsapp/v24.0/{phone_number_id}/messages` e header `X-API-Key`; configurare versione e mittente lato server. Il backend genera e verifica il codice: l'esito di invio di un messaggio non dimostra che il destinatario abbia completato la verifica. [API Kapso](https://docs.kapso.ai/api/introduction).

La chiave ricevuta non è riportata in questo piano e non va inclusa in Git, browser o Android. Configurazione proposta: `KAPSO_API_KEY`, `KAPSO_PHONE_NUMBER_ID`, `KAPSO_API_VERSION`, `KAPSO_AUTH_TEMPLATE_NAME`, `KAPSO_AUTH_TEMPLATE_LANGUAGE` e, se attivato, `KAPSO_WEBHOOK_SECRET`; `.env.example` con valori vuoti per i segreti. La gratuità è per l'utente: quote e costi del fornitore vanno gestiti dall'operatore, senza promettere invii gratuiti illimitati.

### Flusso proposto

- Email già confermata e sessione autenticata; inserimento volontario del numero e informazione sul suo impiego per la verifica.
- Normalizzazione E.164 usando gli strumenti telefonici già presenti; numero mai visibile agli altri utenti.
- Una challenge attiva per account/scopo, codice casuale di sei cifre, durata iniziale cinque minuti, massimo cinque tentativi. Valori configurabili e coerenti con il template.
- Reinvio dopo almeno 60 secondi, per account e per numero; tre invii/ora e cinque/giorno per account, non consumati dalle richieste di altri account sullo stesso numero; per numero, codici ad al massimo tre account diversi nelle 24 ore; oltre a limiti per IP e tetto globale. Il rinnovo del codice non azzera i limiti complessivi.
- Challenge legata a utente, numero normalizzato e versione corrente della verifica. Conservare un verificatore protetto del codice, non il codice in chiaro.
- Conferma in transazione con lock, consumo monouso e vincolo univoco del numero verificato; incremento tentativi persistente anche quando la verifica fallisce. Due richieste simultanee non devono ottenere due conferme.
- Un codice precedente, scaduto, già usato o relativo a un numero sostituito è invalido. Una challenge di un altro account non è utilizzabile.
- Numero cifrato per l'invio; indice di ricerca/univocità tramite HMAC con chiave server dedicata, non un semplice hash enumerabile. Gestire esplicitamente la rotazione di tale chiave.
- Invio con timeout breve, eseguito fuori dai lock DB, e gestione esplicita di richiesta rifiutata, rate limit ed esito incerto. Non ritentare alla cieca dopo un timeout che potrebbe aver già prodotto il messaggio.
- Se l'invio passa in coda, cifrare il payload del job e ricontrollare scadenza e versione prima di inviarlo; non lasciare OTP nei payload serializzati o nei job falliti.
- Escludere credenziali, telefono e OTP da Telescope, Sentry, log HTTP e risposte di errore. L'audit conserva ID e risultati minimi.

Il servizio indisponibile non verifica nessuno per errore: l'utente resta ordinario e può riprovare. Non usare template di marketing o messaggi impropri per aggirare l'eventuale indisponibilità del template di autenticazione.

I webhook di consegna sono opzionali per la conferma OTP e utili per la diagnostica. Se previsti, validare `X-Webhook-Signature` con HMAC SHA256 sul corpo originale e confronto sicuro, deduplicare in database ed elaborare solo gli eventi pertinenti. Un evento «consegnato» o «letto» non abilita il badge. [Sicurezza webhook Kapso](https://docs.kapso.ai/docs/platform/webhooks/security).

### Cambio, perdita e rimozione del numero

Proposta: un numero verificato per account, con recupero tramite sessione e riconferma email/accesso recente, senza trasferire automaticamente il numero da un altro account. Durante un cambio conservare il vecchio stato finché il nuovo numero non è provato; nella revoca volontaria toglierlo immediatamente. Trattare separatamente una sospensione amministrativa e una semplice sostituzione del numero. La visibilità dei contenuti dopo la perdita del requisito è la decisione 8.

## 7. Modello dati proposto

I nomi sono indicativi e verranno consolidati dopo le risposte; migrazioni additive, foreign key esplicite, enum PHP e policy secondo le convenzioni del repository.

| Entità | Campi/relazioni principali | Vincoli |
| --- | --- | --- |
| `users` | Numero cifrato, impronta telefono, `whatsapp_verified_at`, stato onboarding, eventuale sospensione social | Impronta verificata univoca; dati telefonici nascosti nelle risorse ordinarie |
| `whatsapp_verification_challenges` | ID opaco, utente, candidato cifrato/impronta, verificatore OTP, scadenza, tentativi, stato, ID messaggio | Challenge corrente definita sotto lock; consumo atomico; pulizia periodica |
| `user_profiles` | Utente, handle, nome pubblico, bio, avatar, città facoltativa, pubblicazione | Un profilo per utente; handle univoco normalizzato e nomi riservati |
| `followables` (pacchetto 6.x) | `user_id`, `followable_type = user`, `followable_id`, `accepted_at`, date | Relazione univoca, no self-follow; indici inversi; distinta dai follow del catalogo |
| `user_blocks` | Bloccante, bloccato | Coppia univoca; esclusione coerente dalle interazioni |
| `saved_events` | Aggiunta `visibility`, inizialmente `private` | Tutto lo storico, i merge e gli automatismi restano privati |
| `community_posts` | Autore, salvataggio, testo, `published_at`, stato moderazione, date modifica/rimozione | Un post associato a un salvataggio; fonte unica della visibilità in `saved_events` |
| `community_comments` | Post, autore, testo, eventuale risposta, stato moderazione | Commento sempre subordinato alla visibilità del post; risposta nello stesso post |
| `profile_venues` | Profilo, locale, ordine | Solo selezioni esplicite; coppia univoca |
| `reports` | Estensione dei tipi segnalabili a profilo/post/commento | Riutilizzo della coda e dei controlli admin esistenti |

La visibilità effettiva di un post richiede contemporaneamente: salvataggio pubblico, post pubblicato/non rimosso, profilo esposto, autore idoneo, evento ancora pubblicabile e permesso del visitatore. Il campo di moderazione non deve duplicare il significato di `saved_events.visibility`.

Se si usano rimozioni logiche, il vincolo univoco deve prevedere la riattivazione della medesima riga o una strategia esplicita di versionamento. Un nuovo salvataggio privato non ripristina automaticamente una pubblicazione cancellata.

## 8. Salvataggi, pubblicazione e ciclo degli eventi

Interfaccia proposta: «Salva per me» e «Pubblica nella mia bacheca». La seconda azione presenta scelta della data, testo facoltativo, anteprima della card e indicazione chiara del pubblico. Il testo iniziale può avere massimo 500 caratteri, senza HTML o allegati; decisione 13.

La pubblicazione è un'azione esplicita e transazionale. L'account ordinario riceve l'invito alla verifica se prova una funzione riservata, mantenendo il salvataggio privato. L'abilitazione di WhatsApp non converte i salvataggi esistenti in post.

Regole proposte:

- Pubblicazione legata alla specifica occorrenza, con URL pubblico stabile della data e card condivisa con il catalogo.
- Le selezioni multiple restano private; l'utente sceglie quali date pubblicare. Le future date salvate dal follow di una serie non vengono pubblicate automaticamente.
- Modificare il testo non riporta il post in testa al feed e non invia una seconda notifica di pubblicazione.
- Rendere privato il salvataggio nasconde subito post, commenti e contatori collegati, anche da permalink, API e risultati di ricerca; eventuale ripubblicazione resta esplicita e soggetta ai limiti.
- Rimuovere il salvataggio ritira anche il post nella proposta iniziale, oltre a cancellare i promemoria come avviene già oggi. Decisione 15.
- Un evento concluso va nell'archivio del profilo; la bacheca può distinguere prossime date e archivio. Non eliminare automaticamente il testo dell'autore solo perché la data è trascorsa.
- Orario, luogo, disponibilità e stato annullato sono letti dal catalogo aggiornato. Un evento rimosso, oscurato o divenuto non pubblico non deve riemergere tramite card, anteprime o copie nel post.
- Modifiche editoriali, fusione di duplicati, cancellazione di una data e sospensione di un locale devono aggiornare o ritirare i contenuti dipendenti senza riferimenti orfani.
- «Salvato», «Consigliato» e «Parteciperò» hanno significati distinti. Nessuna prenotazione/check-in viene dedotta dal salvataggio pubblico; decisione 12.
- Nessun elenco dei partecipanti o dei salvataggi privati deve essere ottenibile tramite contatori, API o query amministrative riutilizzate sul sito pubblico.

Un contenuto ritirato non può essere richiamato da email già consegnate: le notifiche social dovrebbero inizialmente contenere soprattutto un collegamento e informazioni minime, ricontrollando i permessi prima dell'invio.

## 9. Profilo, locali e scoperta

Percorso proposto `/persone/{handle}`, con nome pubblico, badge, bio breve, avatar e città facoltativi. Niente email, telefono, coordinate o altri dati account. I profili dei normali non diventano pubblici per effetto di un follow. L'onboarding deve spiegare cosa verrà mostrato prima di pubblicare il profilo.

Riutilizzare la media library per avatar con validazione, limiti, conversione e rimozione metadati. Nessun uso automatico della foto WhatsApp. Gestire handle riservati, omonimie, modifica dell'handle e link condivisi.

I «locali salvati» possono essere una selezione esplicita dei locali già seguiti: nessuna esposizione retroattiva dell'elenco esistente. Nascondere locali rimossi o non pubblici. Se si desiderano locali consigliati indipendenti dai follow, mantenere quella selezione in `profile_venues` senza attivare notifiche per effetto collaterale.

Feed proposto «Persone che segui», cronologico per pubblicazione, distinto dall'attuale selezione degli eventi per interessi. Filtri per prossime date e città tramite le query comuni. La scoperta delle persone può offrire ricerca per handle/nome e selezioni redazionali/città; nessun follow automatico, rubrica caricata o ordinamento premiale dei più commentati come default.

L'indicizzazione dei profili è separata dalla loro accessibilità: `noindex` non costituisce controllo di accesso. Se si scelgono profili pubblici, un blocco fra utenti limita le interazioni e la visibilità autenticata, ma non può impedire a qualcuno di leggere una pagina pubblica da visitatore.

## 10. Commenti, moderazione e notifiche

Solo account email + WhatsApp idonei possono creare commenti. Verificare nuovamente l'idoneità quando si invia il modulo, senza fidarsi dello stato mostrato al caricamento della pagina. Testo semplice, limite proposto 1.000 caratteri, gestione accessibile di errori e limiti di frequenza; eventuali risposte con profondità massima uno da definire nella risposta 19.

Prevedere modifica e cancellazione del proprio commento, segnalazione e blocco. Proposta: moderazione successiva alla pubblicazione, con azioni Filament per nascondere/ripristinare e sospendere le capacità social. L'autore del post può segnalare o nascondere commenti sul proprio spazio solo se previsto dalla scelta di prodotto. Un amministratore non modifica il testo attribuito a un altro autore.

Il blocco vieta nuovi follow/interazioni fra la coppia, rimuove le relazioni attive secondo la regola scelta e filtra feed/commenti senza rivelare dati privati. Revoca della verifica, sospensione, chiusura del profilo e cancellazione account devono produrre effetti coerenti su tutte le superfici.

Prima versione proposta senza like, classifiche, messaggi privati, menzioni massive o post liberi senza evento. Sono estensioni possibili, non dipendenze del progetto.

Notifiche iniziali proposte: archivio interno per commenti e richieste di amicizia se presenti; email/push su preferenza esplicita. Nuovi post delle persone seguite opzionali, con deduplica e limite agli invii. WhatsApp rimane dedicato alla verifica, salvo scelta esplicita di un altro servizio. Verifica del numero e consenso a ricevere comunicazioni promozionali restano scelte separate.

## 11. Privacy, cache e rimozione dati

- Risorse pubbliche a whitelist, separate da `UserResource` dell'account; nessuna serializzazione diretta del modello.
- Feed personale e risposte che dipendono da follow/blocchi con `private, no-store`; anche le pagine social inizialmente fuori dalla cache HTML condivisa. Introdurre cache successivamente solo con invalidazione e partizionamento provati.
- Ricontrollo autorizzazioni su ogni pagina, permalink, query commenti, anteprima condivisa e notifica in coda. Conoscere un ID non concede accesso.
- Aggiornare export e cancellazione account per profilo, numero, challenge, follow in entrambe le direzioni, post, commenti, blocchi, locali pubblicati e media.
- La cancellazione account esistente anonimizza e usa soft delete: non basta affidarsi a `ON DELETE CASCADE`. Ritiro e rimozione dei nuovi dati devono essere espliciti e transazionali dove possibile.
- Definire durata breve per challenge scadute e diagnostica, e durata dell'audit di moderazione in base alla politica effettivamente adottata. Non conservare indefinitamente codici o numeri candidati.
- Aggiornare informazioni privacy e testi del percorso per descrivere numero, fornitore e pubblicazione volontaria, senza inventare consensi o condizioni già approvate.

## 12. API, Android e compatibilità

Azioni condivise proposte: `RequestWhatsappVerification`, `ConfirmWhatsappVerification`, `RevokeWhatsappVerification`, `PublishSavedOccurrence`, `MakeSavedOccurrencePrivate`, `FollowUser`, `BlockUser`, `CreateCommunityComment`. Servizi per `KapsoClient`, `CommunityFeed`, visibilità e moderazione.

Contratti API additivi indicativi:

- `GET /v1/me/verification` e campi di capacità nel profilo account.
- `POST /v1/me/whatsapp/challenges`, `POST /v1/me/whatsapp/challenges/{id}/confirm`, rimozione della verifica.
- Gestione del proprio profilo pubblico; lettura profilo/post con permessi coerenti con il web.
- Follow/unfollow persone e blocchi con azioni esplicite e idempotenti.
- Feed persone dedicato, post collegati ai salvataggi, commenti e segnalazioni.
- Parametri di pubblicazione espliciti; assenza del parametro continua a significare salvataggio privato per vecchi client.

Non cambiare il significato di `GET /v1/me/feed` in modo da rompere la versione Android installata. Esportare OpenAPI e aggiornare `docs/API.md`. Il controllo email più severo è comunque una modifica di comportamento: il rilascio deve includere risposte gestibili dal client, feature flag e percorso di conferma funzionante. La risposta 1 decide se anche tutte le schermate social Android sono incluse nella prima consegna.

## 13. Realizzazione per fasi

| Fase | Lavoro | Condizione di completamento |
| --- | --- | --- |
| 0 — Specifica | Integrare le 20 risposte, matrice permessi definitiva, scope Android, stato Kapso | Nessuna ambiguità su amicizie, pubblicazione dei normali e pubblico dei profili |
| 1 — Identità | Migrazioni utenti/challenge, restrizioni email, onboarding e recovery | Account in attesa, ordinario e verificato distinguibili e testati su web/API |
| 2 — WhatsApp | Client Kapso, OTP, limiti, errori, revoca/cambio, diagnostica | Test HTTP simulati completi; prova reale separata quando mittente/template sono disponibili |
| 3 — Profilo e relazioni | Installazione e verifica laravel-follow 6, profili pubblici, handle/avatar, follow/amicizie e blocchi | URL e policy coerenti, nessuna fuga dei dati account; follow del catalogo invariati |
| 4 — Pubblicazione | Visibilità salvataggi, composer, post con card, ciclo di vita | Privato di default; ritiro coerente; automatismi e calendario preservati |
| 5 — Feed e locali | Query paginata, scoperta persone, locali pubblicati | Feed e profilo utilizzabili anche con pochi autori; prestazioni misurate |
| 6 — Commenti e gestione | Commenti, segnalazioni, pannello moderazione, notifiche selezionate | Normali sempre esclusi dalla scrittura; sospensioni e blocchi effettivi |
| 7 — Integrazione | Android secondo scope, export/cancellazione, testi e documentazione | Nessuna regressione nei flussi consumer condivisi |
| 8 — Verifica e rilascio | Controlli automatici, browser, prova esterna, attivazione graduale | Evidenze distinte di codice testato, invio Kapso riuscito e funzionalità attive |

Dipendenze principali: 1 precede 2–3; 4 dipende da autorizzazioni e profilo; 5–6 dipendono da 3–4; rilascio dipende dai controlli di privacy e compatibilità, non soltanto dalla riuscita dell'invio WhatsApp. Le attese Meta/Kapso sono distinte dal lavoro di implementazione e non giustificano attivare un percorso incompleto.

Punti di modifica principali: `app/Models/User.php`, `app/Actions/Account`, `app/Services/Account`, `routes/account.php`, `routes/api.php`, `app/Http/Resources/V1`, nuovi namespace `Community`, migrazioni, viste account/componenti card, `lang/it`, risorse Filament e test. Le nuove superfici devono seguire i temi chiaro/scuro e le convenzioni accessibili già presenti.

## 14. Criteri di accettazione e test

1. Registrazione e vecchio account non confermato seguono la politica scelta; link scaduti, ripetuti e aperti con account diverso non abilitano persone sbagliate.
2. Login ordinario non aggira i limiti email; recupero, reinvio, uscita e gestione dati rimangono raggiungibili.
3. Verifica WhatsApp riuscita, codice errato/scaduto/usato, massimo tentativi, invii concorrenti, numero duplicato e cambio numero sono coperti, compresi rollback e contatori persistenti.
4. HTTP Kapso simulato: body/template/lingua/header corretti, 4xx, 429, 5xx, timeout ed esito incerto; nessun test invia OTP reali.
5. Un ordinario può leggere e seguire ma non commentare; richieste manuali non possono falsificare autore, badge o timestamp di verifica.
6. Nessun salvataggio preesistente, importato o automatico diventa pubblico. Pubblicazione duplicata produce un solo post.
7. Passaggio a privato, rimozione e revoca nascondono il contenuto ovunque secondo la politica scelta, inclusi commenti, contatori, permalink, cache e notifiche pendenti.
8. Follow/amicizie, unfollow, blocco reciproco e richieste simultanee conservano i vincoli; nessun self-follow o accesso fra risorse altrui. I test di integrazione del pacchetto coprono relazioni approvate/pending, conteggi, ID della destinazione, richieste ripetute e coesistenza con `User::follows()`.
9. Eventi annullati, passati, rimossi, spostati, ricorrenti e uniti mantengono card e URL coerenti; date e finestre temporali passano da `EventOccurrenceQuery`.
10. Test XSS, upload avatar, identificativi manipolati, serializzazione pubblica e risposte API verificano i confini dei dati.
11. Export/cancellazione includono tutti i nuovi dati e tolgono i contenuti dipendenti nonostante la soft delete dell'account.
12. Test di regressione: promemoria, calendar/ICS/Google Calendar, merge ospite, feed esistente, verifiche dei locali e ticketing.
13. Browser desktop/mobile: email → WhatsApp → profilo → salvataggio privato/pubblico → lettura da altro utente → commento/ritiro; tastiera, focus, errori e temi.
14. Misurare query per pagina, assenza di N+1, indici con dati realistici e stabilità dei cursori con inserimenti simultanei.

Eseguire le suite PHP su un database dedicato `eventi_test_community`, senza alterare i blocchi di sicurezza dei test. Controlli coerenti con `docs/TESTING.md`: Pest mirati, suite di regressione, Pint, Larastan livello 6, Deptrac, build frontend e flussi browser pertinenti. Per Android, test Kotlin e verifiche dell'app secondo il perimetro concordato. Stato al 18 settembre 2026: test PHP e Android eseguiti come riportato nella sezione «Protezioni e verifica» in testa al documento; template Kapso creati e approvati tramite API, mentre la consegna reale su un telefono autorizzato resta da provare.

## 15. Attivazione e operatività

Introdurre interruttori distinti per nuovo percorso WhatsApp e funzionalità community. Le migrazioni iniziali lasciano tutti i salvataggi privati; l'attivazione social non modifica in massa le preferenze. Rilasciare prima la compatibilità del client interessata dalla conferma email, poi il requisito più restrittivo secondo la finestra scelta.

Prima dell'attivazione WhatsApp: configurazione di ambiente, mittente e template operativi, queue/scheduler se utilizzati, prova con un destinatario di test autorizzato e verifica che i log non raccolgano segreti. Il backend pronto ma privo del template deve presentare uno stato coerente e non una promessa di invio funzionante.

Metriche operative minime: conferme email e WhatsApp riuscite/fallite, quota invii, errori fornitore, latenza feed, post pubblicati, segnalazioni aperte e salvataggi degli eventi dal feed. Evitare di usare il tempo passato nel social come obiettivo primario.

Per un rollback: sospendere nuove pubblicazioni/verifiche tramite interruttori, preservare i dati e mantenere in funzione i controlli di visibilità. Non eseguire automaticamente migrazioni distruttive; nessun ritorno a salvataggi pubblici per effetto della disattivazione di una funzione. Aggiornare runbook e decisioni con gli esiti reali.

## 16. Le 20 domande da chiudere

1. **Perimetro del primo rilascio:** sito e API con compatibilità Android, oppure anche tutte le nuove schermate nell'app Android? Consiglio sito/API prima, mantenendo funzionante l'app.
2. **Email e account esistenti:** conferma obbligatoria subito per tutti, oppure subito per i nuovi e 14 giorni di transizione per gli esistenti? Consiglio la transizione, senza abilitare funzioni social prima della conferma.
3. **Amicizia:** basta il follow reciproco tra verificati, oppure vuoi richieste da accettare e amicizia distinta dal seguire? Consiglio follow libero e amicizia reciproca.
4. **Pubblico dei profili:** chiunque può leggere profili/post anche senza account, oppure soltanto gli iscritti; nel primo caso vuoi anche indicizzazione Google? Consiglio pagine pubbliche inizialmente non indicizzate e feed personale riservato agli iscritti.
5. **Identità pubblica:** nome e cognome reali, oppure nickname e nome pubblico scelto, con foto/bio/città facoltative? Consiglio la seconda opzione, senza mostrare contatti.
6. **Numeri ammessi:** un numero WhatsApp per un solo account, anche internazionale, oppure vuoi limitazioni diverse? Consiglio un numero univoco e nessuna limitazione alla sola Italia.
7. **Configurazione Kapso:** hai già un numero mittente collegato e un template Authentication approvato; quali sono `phone_number_id`, nome e lingua del template e stato di verifica Business Meta? Non serve reinviare la chiave API.
8. **Perdita della verifica:** se un utente rimuove WhatsApp o perde l'abilitazione, nascondiamo profilo/post/commenti oppure manteniamo lo storico leggibile senza nuove pubblicazioni? Consiglio nascondere i contenuti fino a nuova verifica, senza cancellarli automaticamente.
9. **Servizi esclusivi:** al lancio sono profilo pubblico, pubblicazioni e commenti, oppure vuoi anche benefici concreti aggiuntivi come prenotazioni o iniziative riservate? Consiglio definire soltanto benefici effettivamente disponibili.
10. **Salvataggi pubblici degli utenti ordinari:** possono pubblicarli anche senza WhatsApp oppure la pubblicazione è riservata ai verificati? Consiglio riservarla ai verificati; tutti possono salvare privatamente e seguire.
11. **Scelta della visibilità:** ogni salvataggio parte privato con pubblicazione esplicita, oppure un verificato può impostare pubblico come preferenza abituale? Consiglio privato di default, con storico sempre privato.
12. **Significato del post:** indica «Lo consiglio», «Mi interessa», «Parteciperò», oppure una scelta fra queste intenzioni? Consiglio distinguere consiglio e intenzione di partecipare, senza attestare la presenza.
13. **Formato:** un solo evento/data con testo facoltativo fino a 500 caratteri è sufficiente, oppure vuoi foto aggiuntive, link o post senza evento? Consiglio testo e card dell'evento.
14. **Eventi ricorrenti:** ogni post riguarda una singola data oppure vuoi pubblicare anche un'intera rassegna? Consiglio singola data; i salvataggi automatici delle successive restano privati.
15. **Durata dei post:** va bene ritirare il post quando il salvataggio diventa privato o viene rimosso, mantenendo invece un archivio pubblico degli eventi conclusi? Consiglio questo comportamento.
16. **Locali nella pagina:** mostrare soltanto locali scelti esplicitamente dall'utente, tutti quelli seguiti o una sezione pubblica attivabile in blocco? Consiglio selezione esplicita, senza rendere pubblici i follow già esistenti.
17. **Ordine del feed:** prima i post più recenti oppure gli eventi più vicini nel tempo; deve comprendere tutte le città seguite o solo la città selezionata? Consiglio post recenti con filtro città e prossime date.
18. **Scoperta delle persone:** ricerca per nome/username, suggerimenti della città, selezione redazionale iniziale o una combinazione? Consiglio ricerca e selezione redazionale per avviare la rete.
19. **Commenti e moderazione:** commenti semplici oppure anche risposte; preferisci pubblicazione immediata con segnalazione/blocco o approvazione preventiva? Consiglio un livello di risposta e moderazione successiva, con controlli admin.
20. **Notifiche social:** solo interne al sito/app oppure anche email e push per nuovi post, commenti e amicizie? Consiglio interne inizialmente, canali aggiuntivi su scelta dell'utente e WhatsApp solo per verifica.

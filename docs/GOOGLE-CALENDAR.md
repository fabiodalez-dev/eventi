# Calendario Google personale

## Scelta approvata, 9 settembre 2026

Un calendario secondario `inCittà · <città>` nell'account Google dell'utente,
comune a sito e Android. Il precedente CalendarContract Android crea invece
un calendario **locale**: Google Calendar può non mostrarlo. Rimane tra le
opzioni secondarie, distinto dal download ICS e dal vero collegamento Google.

Il pulsante di personalizzazione è pieno nel colore principale del progetto.
Nessun nuovo APK va confezionato senza richiesta esplicita.

## Configurazione necessaria

Nel progetto Google Cloud del proprietario:

1. Abilitare Google Calendar API (`calendar-json.googleapis.com`).
2. Configurare branding, email di assistenza, dominio e informativa della
   schermata di consenso. Registrare un client OAuth **Applicazione web**:
   l'autorizzazione si conclude sul server Laravel, non nell'APK.
3. Registrare esattamente il callback pubblico
   `https://eventi.fabiodalez.it/il-mio-calendario/google/callback`.
   Per sviluppo aggiungere separatamente
   `http://127.0.0.1:8170/il-mio-calendario/google/callback`.
4. Impostare solo negli ambienti privati server:
   `GOOGLE_CALENDAR_CLIENT_ID`, `GOOGLE_CALENDAR_CLIENT_SECRET` e, se necessario,
   `GOOGLE_CALENDAR_REDIRECT_URI`. Ricostruire la cache configurazione.
5. Configurare pubblico/utenti di prova e completare l'eventuale verifica Google.
   Lo stato OAuth **Testing** non è un rilascio definitivo: Google limita i
   refresh token a sette giorni per questi scope. Non dichiarare il servizio
   pronto per tutti finché non è stata verificata l'effettiva pubblicazione.

La chiave JSON del servizio Firebase **non è** un client OAuth e non permette
di creare calendari negli account personali degli utenti.

Scope: `openid` (identificativo stabile, senza richiedere email/profilo) e
`https://www.googleapis.com/auth/calendar.app.created`. Non usare accesso a
tutti i calendari. La creazione del client e l'ampliamento di accesso nel
browser richiedono conferma del proprietario al momento dell'azione.

## Flusso e protezioni

- Accesso inCittà obbligatorio; selezione categorie dinamiche, locale, giorni,
  ingresso gratuito. La pagina prima del consenso riepiloga l'account inCittà.
- Android richiede un URL firmato e temporaneo al server. Quel link **non**
  autentica: nel browser serve lo stesso account inCittà dell'app. Un account
  diverso viene respinto. Nessun token Sanctum o segreto Google nell'URL/APK.
- OAuth code con state monouso, scadenza dieci minuti, sessione vincolata
  all'utente e PKCE S256. Il callback richiede la sessione autenticata.
- Token cifrati a riposo con APP_KEY e nascosti dalla serializzazione.
  Chiamate OAuth/Calendar escluse dalla registrazione Telescope, come l'URL
  del callback. Le rotte personali non sono memorizzabili in cache.
- Nessun calendario primario viene letto o scritto. ID evento deterministico
  per singola occorrenza, luogo della singola replica, fine esclusiva all-day,
  orari nel fuso della città. Si eliminano solo record marcati con l'ID della
  connessione. Il confronto hash evita riscritture identiche.
- Nuove date, modifiche, cancellazioni e cambi filtri passano dal motore
  temporale esistente. Limite uguale ai feed (attualmente 200 date).
  Le date uscite dall'orizzonte o annullate vengono rimosse dal calendario.
- Logout non interrompe la sincronizzazione Google. **Scollega Google** la
  ferma e revoca il token; il calendario resta una copia nell'account Google.
  L'eliminazione dell'account inCittà elimina anche le credenziali conservate.
- Alla revoca/scadenza Google si richiede un nuovo consenso; niente successo
  fittizio se Google fallisce o se le credenziali applicative non ci sono.

## Esercizio

Migrazione additiva `2026_09_09_120000_create_google_calendar_connections_table`.
Scheduler `google-calendar:sync` ogni 15 minuti. Coda database dedicata
`google-calendar`, connessione `google_calendar`, retry_after 660 secondi,
job timeout 540 secondi, blocco per utente 600 secondi. Lo scheduler esegue
anche il worker dedicato ogni minuto, con mutex di 12 minuti: non occupa la
coda delle email e non richiede un demone aggiuntivo sull'hosting condiviso.
Verificare scheduler e job falliti prima di dichiarare la sincronizzazione attiva.

In caso di cambio dominio aggiornare APP_URL, callback registrato in Google e
GOOGLE_CALENDAR_REDIRECT_URI, quindi configurazione server/API_BASE_URL Android.
I token e il calendario esistente rimangono associati allo stesso client
OAuth: non creare un nuovo client senza necessità.

## Verifiche

Stato al 9 settembre 2026: codice implementato su
`fix/calendar-connection-clarity`, migrazione applicata **solo in locale**.
Configurazione OAuth nel progetto `incitta-11b5b`, con l'account proprietario
nel profilo Chrome **Fabio** (non **Lavoro**): il proprietario ha accettato la
Google API Services User Data Policy e creato il client web. Credenziali salvate
esclusivamente negli `.env` privati locale e remoto; callback distinti per i due
ambienti. API Calendar abilitata e verificata, branding con homepage/privacy/
termini del sito e dominio autorizzato `fabiodalez.it`. Salvati soltanto gli scope
`openid` e `calendar.app.created`, classificati non sensibili dalla console.
Lo stato rimane **Testing**; aggiunto e verificato nell'elenco l'utente di prova
`fabiodalez@gmail.com`, su autorizzazione del proprietario. L'indirizzo inCittà
`admin@incitta.test` non compare nell'elenco Google dopo il tentativo di aggiunta:
non confondere l'account applicativo con un account Google idoneo. L'admin può
collegare il proprio Gmail senza cambiare email/password dell'account inCittà.
Il proprietario ha completato personalmente l'avviso Google «app non verificata»
e il consenso per la prova locale, accedendo a inCittà come `admin@incitta.test`
e a Google con il proprio Gmail. Il worker ha creato il calendario separato e
sincronizzato **63 date**, confermate da una lettura reale Calendar API (HTTP 200).
Una seconda sincronizzazione ha effettuato soltanto la lettura, senza riscrivere
eventi o creare duplicati. Stato aggiornato e 63 date verificati anche nella
pagina di gestione in Chrome. La visibilità nel Google Calendar del telefono
deve ancora essere confermata dal proprietario.

La connessione di prova appartiene al database **locale**, non al remoto; anche
i collegamenti agli eventi dentro questo calendario puntano all'ambiente locale.
Non copiare credenziali/connessioni personali tra database o presumere equivalenti
gli ID utente. Dopo il deploy effettuare il consenso con l'account inCittà remoto.
Il calendario di prova può essere scollegato e rimosso dal proprietario; non
eliminarlo automaticamente e non confonderlo con quello definitivo.
Verifica locale: 141 test PHP della regressione iniziale; dopo l'ulteriore
protezione contro callback concorrente alla cancellazione account, 41 test
calendario/privacy superati. 18 test degli endpoint personali (anche etichetta
del ruolo, isolamento e nessuna elevazione dei permessi), 11 test canale push.
50 unit test Android superati
(un test di contratto remoto saltato), PHPStan senza errori e lint Android
senza errori. Interfaccia del wizard controllata visivamente in Chrome.
Rilascio web e APK **1.8.4 (19)** autorizzati dal proprietario il 9 settembre
2026, dopo la prova locale. Consultare `docs/RELEASE-1.8.4.md` per il referto
di distribuzione: questi controlli locali non attestano da soli il deploy.
La configurazione remota verrà letta dopo il deploy e la ricostruzione della
cache; aver inserito le credenziali da solo non attiva le nuove rotte.

Prima dell'apertura generale va aggiornata e approvata l'informativa pubblica:
la versione remota del 1 settembre contiene ancora affermazioni precedenti alle
funzioni pubblicitarie, Firebase e Google Calendar. Non attestare conformità
partendo da quel testo. Descrivere identificativo Google, token cifrati, filtri,
contenuti sincronizzati, fornitori e revoca, senza dichiarare accesso ai calendari
personali che il codice non richiede.

Test PHP isolati: `tests/Feature/Account/GoogleCalendarTest.php`, più regressioni
su account, feed, wizard, esportazioni e cancellazione account. HTTP Google
simulato con blocco delle richieste non previste, mai scritture reali nei test.
Android: `GoogleCalendarTest`, compilazione Kotlin, unit test e lint release.
Dopo l'autorizzazione al nuovo APK sono stati eseguiti e superati **3 test
strumentali su emulatore Android 15**: `AccountIdentityUiTest` e
`CalendarConfirmationUiTest`. Verificati email lunga, nome assente, font
ingranditi, cambio account, rimozione del vecchio ruolo e annullamento della
conferma calendario. Il test attende il layout della finestra nativa del dialogo.

Profilo Android: identità prima di biglietti e preferenze, email ad alto contrasto
copiabile, etichetta del ruolo proveniente dall'API e azione «Esci / cambia
account». All'apertura del Profilo si rileggono i dati dal server, senza applicare
risposte tardive a una sessione diversa. Le vecchie sessioni senza etichetta del
ruolo rimangono leggibili. Nessun ruolo viene dedotto dall'indirizzo email.

Prova push richiesta dal proprietario: Firebase risulta configurato in remoto,
ma al controllo non esistono dispositivi Android attivi con token. Richiesto al
proprietario di attivare «Notifiche su questo dispositivo» dall'app e indicare
l'account destinatario. Nessun invio reale eseguito, nessun broadcast agli utenti.

Prima della consegna effettiva resta indispensabile completare la prova remota
e sul dispositivo: replica distinta, cambio orario/luogo, annullamento, modifica
filtri e scollegamento. La creazione e l'idempotenza reali locali non sostituiscono
queste verifiche; i test HTTP simulati non dimostrano la visibilità sul telefono.

## Fonti

- [Scope Calendar API](https://developers.google.com/workspace/calendar/api/auth)
- [OAuth web server](https://developers.google.com/identity/protocols/oauth2/web-server)
- [Scadenze OAuth e modalità Testing](https://developers.google.com/identity/protocols/oauth2#expiration)
- [Sincronizzazione Google Calendar Android](https://support.google.com/calendar/answer/6261951?hl=it)

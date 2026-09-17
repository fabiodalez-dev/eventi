# Analytics di locali e organizzatori

La voce **Analytics** è disponibile nei pannelli `/gestione/{locale}/statistiche`
e `/organizza/{organizzatore}/statistiche`. Ogni lettura verifica nuovamente
l'accesso al tenant corrente, comprese le richieste Livewire e la paginazione.
Per i locali gli eventi sono attribuiti tramite `events.venue_id`; per gli
organizzatori tramite `events.organizer_id`, anche quando si svolgono in locali
gestiti da altri. Lo spostamento di una singola replica non cambia la proprietà
delle statistiche storiche dell'evento.

## Misure disponibili

- Aperture delle schede web di eventi, locali e organizzatori.
- Click su indicazioni, biglietti, prenotazioni, telefono, email, link esterni,
  calendario e download della locandina.
- Condivisioni completate tramite il browser, copie del link e avvii dai pulsanti social/email.
- Salvataggi autenticati e nuovi follower creati nel periodo e ancora presenti;
  follower attuali del profilo.
- Impressioni e click delle sponsorizzazioni, con ripartizione dei click per
  canale, posizione e pagina di provenienza già registrati.

I periodi sono 7, 30 e 90 giorni inclusi, con andamento giornaliero e tabella
degli eventi paginata. I click non indicano acquisti o prenotazioni completate.
Le aperture ripetute sono conteggiate; non viene raccolto un identificativo per
calcolare visitatori unici. `unique_views` resta un campo storico non alimentato
dal nuovo contatore. I salvataggi anonimi restano nel browser e non entrano nei
totali dei gestori. Gli analytics esterni Plausible/Umami non vengono importati.

## Raccolta e consenso

Il browser invia le misure a un endpoint interno con URL firmato, protezione
CSRF e limite di frequenza. Ogni richiesta ricontrolla il consenso alla categoria
Statistiche sul server. Senza consenso non vengono scritti contatori; accettando
dal banner la raccolta può iniziare già nella pagina aperta.

La registrazione avviene dal browser per funzionare anche sulle pagine in cache.
Le anteprime editoriali non hanno il contatore. Le tabelle conservano solo somme
giornaliere, senza IP, identificativi dei visitatori o URL di provenienza.
I click pubblicitari continuano a seguire la raccolta già esistente.

Le visite web vengono aggiunte a `event_views_daily` e `profile_views_daily`
dal momento dell'attivazione. Non è possibile ricostruire lo storico non
registrato. Il nuovo conteggio delle aperture non include l'app Android;
i suoi click sponsorizzati già registrati restano visibili.

## Installazione

Eseguire la migrazione `2026_09_15_120000_add_content_analytics` e ricompilare
gli asset con `npm run build`. Il tema Filament include esplicitamente le classi
della vista analytics. Invalidare la cache delle pagine pubbliche dopo il rilascio.

Il backup programmato resta spento per impostazione predefinita
(`BACKUP_SCHEDULED=false`). Un ambiente che lo imposta esplicitamente a `true`
deve correggere anche la propria variabile per spegnerlo. Riattivandolo, lo
scheduler esegue davvero il controllo dello spazio; il dump DB del deploy
resta indipendente da questo interruttore.
# Metriche per replica

La tabella delle date nei pannelli locale e organizzatore mostra visualizzazioni,
clic e salvataggi per replica. L’azione Analytics apre tutte le metriche e
l’andamento degli ultimi 30 giorni. I totali della riga coprono l’intera raccolta.
`occurrence_views_daily` mantiene la data della visita separata dall’ID della
replica; il parametro `occurrence` è firmato insieme al link di tracciamento.
La scrittura aggiorna replica e aggregato evento nella stessa transazione.
Nessun backfill dei dati reali privi di replica; visite alla pagina generale e
sponsorizzazioni restano attribuite all’evento. Il seed dedicato genera anche
le metriche delle proprie repliche, senza duplicare gli aggregati evento.

## Link brevi degli eventi

Le due barre di condivisione nella scheda evento utilizzano link `/s/XXXXXXX`
(7 caratteri alfanumerici), distinti per evento, eventuale replica e canale:
Condividi/copia, WhatsApp, Telegram, email. I link sono stabili e vengono riusati;
non viene creato un record per ogni visita o per ogni persona. Il reindirizzamento
302 non è memorizzabile e risolve la destinazione dagli ID: un cambio di slug non
rompe i collegamenti. Bozze, eventi rimossi e città disattivate non sono accessibili.
La pagina finale conserva canonical, Open Graph, immagini social e dati strutturati;
non esiste una pagina intermedia. I link nelle anteprime editoriali restano normali.

Il riepilogo **Analytics condivisioni** compare negli Analytics del locale e
organizzatore, e nella pagina omonima del pannello admin. Mostra link, evento,
numero di replica, canale, condivisioni avviate e aperture nel periodo 7/30/90 giorni.
La paginazione ricontrolla lo stesso isolamento delle altre statistiche. Il canale
indica il pulsante di origine: se il link viene inoltrato altrove l'attribuzione
rimane quella originale. Le aperture sono passaggi, non utenti unici.

La raccolta rispetta il consenso Statistiche sia per chi condivide sia per chi
apre: senza consenso il link continua a funzionare ma non incrementa i contatori.
Le anteprime/bot riconosciuti dallo user-agent, HEAD e richieste prefetch/prerender
non incrementano le aperture. Il filtro è euristico, non una certificazione di
visitatore umano. Le somme giornaliere non conservano IP, referrer o identificativi
personali. Il redirect non apre una sessione né imposta cookie nuovi.

WhatsApp/Telegram/email contano l'avvio dal pulsante con JavaScript attivo, non la
consegna del messaggio. La condivisione di sistema e la copia contano solo dopo il
completamento senza errore. Gli annullamenti non contano. I link dei servizi
funzionano anche senza JavaScript; in quel caso l'avvio non viene misurato.
Le condivisioni confluiscono una sola volta nei totali evento/replica; i pulsanti
social non vengono più classificati anche come clic a siti esterni o all'email.

Installazione: migrazione `2026_09_17_150000_create_event_share_links`, build Vite e
invalidazione cache pagine. Nessuna dipendenza esterna né servizio di short URL.
L'integrazione riguarda i pulsanti web della scheda evento; gli URL API canonici
restano invariati. Le metriche non ricostruiscono condivisioni precedenti al rilascio.

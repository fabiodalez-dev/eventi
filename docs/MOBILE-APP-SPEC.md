# inCittà Android

## Stack e build

- Kotlin 2.2.21, Jetpack Compose, min SDK 26, target/compile SDK 36.
- Package: `it.fabiodalez.incitta`; debug: `it.fabiodalez.incitta.debug`.
- API predefinita: `https://eventi.fabiodalez.it/api/v1/`.
- Build riproducibile: `cd android && ./gradlew test assembleDebug`.
- Override locale: `./gradlew assembleDebug -PapiBaseUrl=http://10.0.2.2:8000/api/v1/`.

## Navigazione

Cinque destinazioni persistenti: Eventi, Mappa, Cerca, Salvati, Profilo. Dalla
home si aprono anche Calendario e l'indice Locali. Il dettaglio evento include
locandina integrale, tutte le date, calendario di sistema, descrizione, listino,
prenotazione, locale collegato, mappa interattiva, accessibilità, tag, link,
condivisione ed eventi simili. Il dettaglio locale include contatti, orari,
accessibilità, indicazioni e tutti gli eventi prossimi e passati disponibili.

Sono accettati deep link HTTPS per eventi, locali, mappa, calendario, categorie,
tag e preset, oltre a `incitta://auth/magic?token=...`.

## Autenticazione e wishlist

Il bearer è cifrato AES-GCM con una chiave non esportabile di Android Keystore.
L'installation ID non è un'identità utente: serve a limiti, idempotenza e
device binding. Senza sessione la wishlist è un set locale. Dopo il login:

1. lo stato dell'account precedente viene rimosso dalla memoria;
2. si salva il nuovo bearer cifrato;
3. si invia `POST /me/saved/merge` con i soli `occurrence_id` ospite;
4. gli ID locali vengono cancellati solo dopo il successo;
5. si rilegge `/me/saved` dal server.

Un 401 elimina la sessione locale e impedisce che dati privati restino visibili.
La cancellazione account è disponibile nell'area critica e richiede la password
corrente; il client invia anche la conferma esplicita richiesta dal backend.

## UI

La sorgente è `DESIGN.md`: fondo `#0B0B0B`, carta `#F5F5F0`, lime `#CCFF00`,
Archivo SemiBold, fotografie in bianco e nero, divisori da 2 dp e raggio zero.
I target interattivi sono almeno 48 dp e hanno etichette per TalkBack.
Le mappe sono native MapLibre/OpenFreeMap e non richiedono una chiave Google.
Al primo avvio compare la scelta privacy con accetta/rifiuta e collegamenti alle
informative; non sono attivi cookie pubblicitari o profilazione.

## Allineamento D60 (ottobre 2026)

Versione 1.19.0 (code 41). L'app porta le funzioni rilasciate sul server con [D60](DECISIONS.md) il 23 ottobre 2026, con le stesse rotte del sito e i testi in `res/values/strings.xml`.

- Scheda evento: foto in griglia a due colonne con l'ingrandimento a schermo intero; il video è un riquadro dell'app che al tocco apre `watch_url` fuori dall'app, senza WebView e senza richieste al fornitore prima del tocco; i co-organizzatori compaiono come «con Nome» e portano alla loro pagina. Ogni data ha «Aggiungi a una raccolta».
- Raccolte (`ui/CollectionsScreen.kt`, `data/CollectionsApi.kt`): elenco e creazione, dettaglio con rinomina, interruttore «condivisa con un link», link da copiare o condividere, «Togli» ed eliminazione; si raggiungono dal profilo e dai salvati. I link `/raccolte/{token}` aprono la raccolta condivisa in sola lettura, anche senza account.
- Ricerche salvate (`data/SavedSearchesApi.kt`): nella pagina Eventi e nella ricerca «Avvisami per nuovi eventi come questi» compare quando c'è almeno un criterio che il server conserva; in Profilo › Notifiche e newsletter la sezione «Le mie ricerche salvate» ha «Apri» e «Rimuovi». I filtri dell'app usano i nomi dell'API (`categories`, `tags`, `time_of_day`), le ricerche salvate quelli di `/eventi` (`category`, `tag`, `time`): `SavedSearchFilters` traduce nei due sensi.
- Biglietti: stato «Non presentato» e, con `meta.no_shows` maggiore di zero, l'avviso sulle assenze recenti (tre in novanta giorni bloccano le prenotazioni gratuite nello stesso locale; su tutta la piattaforma ogni locale ne conta al massimo due, D64).
- Gestione di una data: «Scrivi a chi ha prenotato» (con conferma e tetto giornaliero del server) e «Aggiungi una prenotazione» dal banco, un nome per riga.
- Controllo ingressi senza rete: all'apertura della data l'app scarica il manifest (impronte SHA-256 dei codici e nomi) e lo tiene cifrato per 48 ore. Senza rete respinge subito i QR estranei e quelli già entrati; gli altri restano in coda con il nome accanto e l'ingresso è registrato solo quando il server conferma. Ogni lettura invia `scanned_at`.
- Scheda del locale: l'avviso in vista oggi (pausa, chiusura, riapertura, comunicazione) in alto, e «Calendario pubblico (ICS)» che apre `/locali/{slug}/calendario.ics`. Il campo `notice` di `GET /v1/venues/{slug}` è stato aggiunto per questo.

Restano fuori, e l'app rimanda al sito: Apple Wallet, cessione del biglietto, login social, rivendicazione di un locale e registrazione degli organizzatori. Gli strumenti del pannello web e della redazione (import CSV, fusione dei doppioni, ripristino dalla cronologia, registro delle ricerche senza risultati) non entrano nell'app.

## Allineamento D61 (1.20.0, 23 ottobre 2026)

Le dieci funzioni di [PIANO-UTENTE-2026-10-23](PIANO-UTENTE-2026-10-23.md) sono nell'app: avviso di sovrapposizione al salvataggio, link del riepilogo del lunedì verso i salvati, «aperto adesso» nella scheda e nell'elenco dei locali con il filtro, nascondere un locale e gestire i nascosti negli interessi, esigenze di accessibilità precompilate nei filtri con «Mostra tutto», biglietti salvati cifrati e mostrati senza rete, «Invia a questa persona» e invio per email con consenso, avviso di disponibilità sulle date esaurite, «Le mie serate», «sono qui» con il foglio di condivisione. Lo scanner manda al server anche i codici assenti dall'elenco quando la rete c'è (validata da Android) e mostra comunque l'avviso dell'elenco. Regola: ogni modifica per l'utente sul sito si porta nell'app nello stesso lavoro.

## Allineamento D70 (1.23.0, versionCode 50, 20 novembre 2026)

- **Gettone usa e getta del banner.** `SponsoredBanner` legge `impression_token` (facoltativo: con un server vecchio manca). Visualizzazioni e clic mandano `X-Impression-Token` accanto a `X-Metric-Token` di sempre (`ApiClient.sponsorshipMetricRequest`); il server preferisce il gettone nuovo e conta una volta per gettone.
- **Un secondo a metà banner.** La visualizzazione parte solo dopo un secondo di fila con almeno metà banner sullo schermo e l'app in primo piano (`RESUMED`), come sul sito; se la condizione cade il conto riparte. Prima partiva appena il banner veniva composto.
- **Nessun doppione dal client.** Il ViewModel manda una visualizzazione per gettone, e con un server senza gettone una per campagna come prima.
- **Avanzamento nella gestione.** `ManagedCampaign` legge `goal`, `delivered`, `pacing_state` da `GET /v1/management/campaigns` e `/{id}`: la scheda mostra barra, «1.240 di 3.000 visualizzazioni» e lo stato, oppure solo «87 visualizzazioni» per il prodotto a periodo, con la riga che spiega che è una stima al ribasso e che le visite di chi gestisce non contano. Senza i campi mostra la scheda di prima.
- Test di unità: `SponsoredBannerTest`, `SponsoredViewabilityTest`, `CampaignProgressTest`; il test strumentale `SponsoredBannerUiTest.countsTheViewOnlyAfterOneSecondOnScreen` è compilato e va eseguito su emulatore. Nessun APK distribuito.

## Gestione di locali e organizzazioni: fondamenta (1.24.0, versionCode 51, 25 novembre 2026)

Prima PR del piano `piano-android-gestione.md` (§3). Non aggiunge funzioni nuove di gestione: prepara l'area in cui arrivano i pacchetti P1–P13 e ci trasloca la biglietteria senza cambiarne il comportamento.

- **Struttura.** API e modelli in `data/management/`, schermate e ViewModel in `ui/management/<area>/`, componenti comuni in `ui/management/common/`, stringhe in `res/values/management_common.xml`, `management_home.xml`, `management_ticketing.xml` (le `management_*` di `strings.xml` sono state spostate lì con gli stessi nomi). Test in `app/src/test/.../data/management/` e `.../ui/management/`.
- **Navigazione.** Navigation Compose solo dentro la gestione (`ui/management/ManagementNavHost.kt`, rotte tipizzate `@Serializable`). Ogni area ha il suo file con `fun NavGraphBuilder.<area>Graph(context)`, già registrato e per ora vuoto: riepilogo, eventi, date, biglietteria, profilo, statistiche, sponsorizzazioni, commenti e recensioni, collaboratori, import del calendario (pacchetto `calendarimport`), social, notifiche, registrazione. Un'area compare nel menu quando `ManagementArea.<area>.entry` punta alla sua prima rotta e i `permissions` del soggetto la consentono. Il resto dell'app non usa Navigation: `InCittaApp` apre la gestione con `managementOpen`, ora `rememberSaveable`.
- **Stato e dati.** Un ViewModel per schermata con `StateFlow`; id e filtri nel `SavedStateHandle` della voce di navigazione, i campi brevi dei moduli in `rememberSaveable`. `ManagementRepository` (uno per sessione, tenuto da `ManagementGraph` e chiuso al cambio di token) espone `ticketing` (l'interfaccia `TicketingApi`, implementata da `ManagementApi`), `identity` e `media`, e ricorda i soggetti e quello scelto. `ManagementScreen(session, padding, client, onBack)` resta l'ingresso, con `client` per l'OkHttp finto dei test.
- **Identità.** `GET /v1/management/subjects` (`ManagementSubjects`: locali e organizzazioni con città e fuso, ruolo, stato, logo, `permissions` come mappa di booleani tollerante a `[]` e a `1`/`"1"`) e `GET /v1/management/options` (`ManagementOptions`: elenchi in tre forme e tetti in cima o sotto `limits`; oggi l’app ne legge solo `limits.attendee_messages_per_day`, con 3 se manca; i sottoelenchi per tipo di soggetto, come `occurrence_statuses.venue`, si leggono con `choices(chiave, gruppo)`). `GET /me` porta `management{venues, organizers, checkin}`: la voce «Gestione eventi» del profilo compare solo a chi ha almeno un conteggio, e a tutti se il campo manca (server vecchio).
- **Interruttore.** L'area nuova si accende solo con `features.management` vero in `GET /config`. Con l'interruttore spento, un server vecchio, la rete assente o una risposta che non si legge, l'app atterra sull'elenco delle date come nella 1.23.0. Con l'interruttore acceso chi gestisce almeno un locale o un'organizzazione atterra sul primo locale (poi sulla prima organizzazione), con il selettore se ne ha più di uno e il menu delle aree; chi è solo personale d'ingresso va all'elenco delle date. Il menu mostra solo «Biglietteria» e il pulsante «Apri il pannello web» per tutto il resto.
- **Biglietteria.** `ui/management/ticketing/`: elenco delle date e delle campagne, scheda della data (controllo ingressi con scanner e elenco senza rete, staff, messaggio, prenotazione al banco, informazioni e costi), scheda della campagna. Stesse chiamate, stessi limiti, stessi testi. La coda cifrata delle letture (`CheckinOutbox`, invariata) ora la gestisce il repository: il ritentativo ogni 15 secondi continua finché la sessione è aperta anche a schermata chiusa, parte subito all'apertura se ci sono letture rimaste e si ferma a coda vuota o al cambio di account. Gli esiti delle ultime letture restano per tutta la sessione.
- **Componenti comuni.** `ManagementScaffold` (indietro con etichetta per TalkBack, titolo, selettore del soggetto), `LoadState` con `Loadable` (caricamento, contenuto, vuoto, errore con «Riprova»), `FormScaffold` e `FormTextField` con `FormErrors` (gli errori di `error.fields` sotto il campo, quelli di campi non mostrati in cima), `ConfirmDialog`, `CityDateTimeField` (data e ora nel fuso della città del soggetto, mai in quello del telefono), `rememberImagePicker` (`PickVisualMedia`, nessun permesso). `RichTextField` arriva con P1, `EventPickerSheet` con P5.
- **Immagini.** `ApiClient.upload` fa il multipart per tutti (il profilo della community ora lo usa). `data/management/ImageUpload.kt` riduce il lato lungo a 2400 px, applica la rotazione EXIF, mette lo sfondo bianco alle trasparenze e ricomprime in JPEG qualità 85; rifiuta file illeggibili, non immagini, oltre 50 milioni di pixel o oltre 12 MB dopo la riduzione. `ManagementMediaApi` copre `GET V/media`, `POST/DELETE S/events/{event}/poster`, `POST/DELETE V/media/{logo|cover}`, `POST V/media/gallery`, `DELETE V/media/gallery/{media}`, `PUT V/media/gallery/order`, campo `image`. Nessuna schermata li usa ancora (P1, P4).
- **Collegamenti.** `ManagementDestination` traduce `/gestione-biglietti` e `/gestione-biglietti/{data}` (anche `/scanner`) nelle schermate native, per gli avvisi e i collegamenti aperti dentro l'app; tutto il resto della gestione si apre nel browser come prima. Il manifest non dichiara `/gestione`, `/organizza` né `/gestione-biglietti` fra gli App Links.
- **Test.** Unitari: `ManagementModelsTest`, `ManagementDestinationTest`, `ImageUploadTest`, `ManagementRepositoryTest`, `ManagementFoundationsTest`, `TicketingViewModelsTest`. `ManagementUiTest` legge i testi da `R.string` (prima cercava «Email di un account esistente» e «Assegna allo staff», che non esistevano più) e segue la navigazione nuova; è compilato, va eseguito su emulatore.

## Gestione: commenti e recensioni (P7)

- **Area.** `ui/management/feedback/`: la voce «Commenti e recensioni» del menu del soggetto apre un menu con le due pagine del gruppo «Community» del pannello, ciascuna solo se i `permissions` la consentono (`comments_moderate`, `reviews_reply`). API e modelli in `data/management/ManagementFeedbackApi.kt` (`ManagementRepository.feedback`), stringhe in `res/values/management_feedback.xml`.
- **Commenti agli eventi.** Elenco con filtro di stato (tutti, pubblicati, nascosti), ricerca su evento, autore e testo, paginazione. Ogni commento mostra evento, autore, data, testo, mi piace, stato e chi l’ha moderato. «Nascondi» chiede conferma e accetta una nota facoltativa (al massimo 1.000 caratteri); «Rimetti in vista» chiede conferma. Un commento nascosto dalla redazione non offre «Rimetti in vista» e lo dice. Se nel frattempo qualcun altro ha deciso (revisione cambiata) l’app chiude la finestra, mostra il messaggio del server e rilegge l’elenco.
- **Recensioni ricevute.** Solo quelle pubblicate: autore, stelle, testo, data di pubblicazione, risposta. «Rispondi» o «Modifica la risposta» (da 2 a 2.000 caratteri, errore sotto il campo), «Togli la risposta» con conferma, «Segnala alla redazione» (da 10 a 3.000 caratteri) a chi può partecipare alla community, come sulla scheda pubblica.
- **Dalle schede pubbliche.** La scheda di un evento mostra «Modera i commenti» e quella di un locale o di un organizzatore «Rispondi alle recensioni» solo quando il server manda `manage_url` (cioè a chi gestisce): il pulsante apre la schermata nativa, sui commenti già ristretta a quell’evento. Il pulsante «Elimina» sui commenti resta quello di prima, per l’autore e la redazione: chi gestisce non cancella, nasconde.
- **Collegamenti.** `ManagementDestination` traduce anche `/gestione/{slug}/event-comments` (con `?event=`), `/gestione/{slug}/recensioni` e gli stessi indirizzi sotto `/organizza/` nelle schermate native; il soggetto si cerca per tipo e slug fra quelli gestiti.
- **Test.** Unitari: `ManagementFeedbackTest` (forma delle risposte, filtri, collegamenti), `FeedbackViewModelsTest` (soggetto dalla rotta, filtri salvati, nota, conflitto di revisione, risposta, segnalazione). Strumentato: `ManagementFeedbackUiTest`, con i testi letti da `R.string`.

## Gestione: collaboratori, accesso e inviti (P8, 25 novembre 2026)

- **Area «Collaboratori e accesso».** `ui/management/collaborators/`, voce del menu per ogni persona di un locale (non per le organizzazioni, che non hanno collaboratori). Il referente vi trova l'elenco delle persone (prima chi ha accettato, poi gli inviti in attesa senza nome e con l'email mascherata, come nel pannello), la ricerca, «Mostra altre persone», il foglio «Invita un collaboratore» (nome ed email, errori del server sotto il campo, il tetto di 10 l'ora e 30 al giorno spiegato sotto il modulo e condiviso con il sito) e, sulla riga, «Reinvia l'invito», «Ritira l'invito» e «Rimuovi», ognuno con la sua conferma. Il collaboratore vede solo la voce «Il tuo accesso a questo locale» e la riga che spiega che i collaboratori li gestisce il referente.
- **Il tuo accesso.** Ruolo, cosa permette, da quando, e «Lascia il locale» nella zona pericolosa, con conferma. All'ultimo referente il pulsante è spento, con la spiegazione e «Vai ai collaboratori». Dopo l'uscita l'app rilegge i soggetti: se ne restano torna alla pagina del primo, altrimenti esce dalla gestione.
- **I tuoi inviti.** Voce del profilo «I tuoi inviti» quando `GET /me` porta `management.invitations` maggiore di zero; ci portano anche i collegamenti `/inviti-locali/…` e `/inviti-ingressi/…` aperti dentro l'app (`ManagementDestination.Invitations`), e allora «indietro» esce dalla gestione. Ogni invito mostra locale o data, che cosa cambia accettando e la scadenza, con «Accetta l'invito» e «Rifiuta» (con conferma). Un invito al locale accettato apre la pagina di quel locale nella gestione; uno al controllo ingressi apre la scheda della data. Chiudendo la gestione l'app rilegge il profilo, così la voce sparisce.
- **Perché nell'app e non dal collegamento.** Il collegamento dell'email resta del browser (non è un App Link): lì la firma prova la casella e la sessione l'account. Nell'app la risposta passa da `/me/invitations`, che chiede il token dell'account invitato e l'indirizzo confermato: un account con l'indirizzo non confermato riceve il messaggio del server che chiede di confermarlo. L'accettazione scegliendo la password (account nato dall'invito) resta del sito.
- **Dati.** `ManagementPeopleApi` (`ManagementPeopleSource`, nel repository come `people`), modelli `VenueMember`, `VenueAccess`, `ReceivedInvitations`, `InvitationAnswer` con valori predefiniti su ogni campo; testi in `res/values/management_collaborators.xml`.
- **Test.** Unitari: `ManagementPeopleTest` (forme, corpi, conteggio di `/me`, collegamenti d'invito), `CollaboratorsViewModelsTest` (chi vede l'elenco, errori dell'invito sotto il campo e tetto in cima, azioni sulle righe, uscita, risposte agli inviti).

## Gestione: biglietteria completa (P3, 25 novembre 2026)

Tutto `/gestione-biglietti` nativo, in `ui/management/ticketing/`; l'API e i modelli stanno in `data/ManagementApi.kt` (`TicketingApi`) e `data/management/ManagementTicketingModels.kt`. Il collaboratore del locale resta fuori dalla biglietteria (decisione 6); il controllo ingressi senza rete (coda cifrata e manifest) non cambia.

- **Elenco delle date.** Ricerca nel titolo e nel nome del locale (salvata con `SavedStateHandle`), locandina, stato delle prenotazioni, posti occupati e lista d'attesa, ruolo.
- **Scheda della data.** Chi controlla solo gli ingressi vede lo scanner come prima. Chi gestisce vede locandina, indirizzo, i numeri del sito (posti, rimasti, confermati, entrati, in lista d'attesa, annullati, non presentati e quota di assenze), l'avviso «Evento concluso» o «Biglietteria non abilitata», e sette schede, la scelta salvata: Ingressi (lo scanner di sempre), Prenotazioni, Impostazioni, Messaggi, Staff, Informazioni e costi, Esporta. A data finita spariscono i moduli, come sul sito.
- **Prenotazioni** (`BookingsViewModel`): filtri per stato con i conteggi, ricerca per nome o email, trenta per pagina, dati di chi prenota e risposte alle domande, «Registra ingresso» dalla riga (passa dalla stessa coda cifrata dello scanner e segna il biglietto come usato nell'elenco senza rete), «Annulla prenotazione» e «Annulla questo biglietto» con motivo facoltativo; sotto, la prenotazione al banco. `FLAG_SECURE` sulla scheda.
- **Impostazioni**: prenotazioni accese, lista d'attesa, posti, massimo per account, apertura, chiusura e termine di annullamento con `CityDateTimeField` nel fuso della città, istruzioni, dati da chiedere a chi prenota, fino a cinque domande (testo, scelta, sì o no). Errori del server sotto il campo; con il locale non abilitato il modulo si legge ma non si salva. Le istruzioni scritte sul sito con la formattazione si mandano solo se cambiate, e l'app lo dice.
- **Messaggi**: il modulo di prima e lo storico degli ultimi dieci, con chi li ha mandati, i destinatari e il conto delle ultime 24 ore.
- **Esporta**: CSV dei partecipanti e lista d'ingresso in PDF prodotti dal server, scaricati con il token nella cache dell'app (`management-exports/`) e passati con «Apri con…» o «Condividi» tramite `ManagementFileProvider` (autorità `<applicationId>.management.files`, nessun permesso). I file si cancellano dopo un'ora, al cambio di account e all'uscita.
- **Riquadro dell'evento** (`EventTicketingRoute(venueId, eventId)`): «Ticketing inCittà» della modifica evento del locale, con l'avviso se il locale non è abilitato e le date che si gestiscono; ogni data apre la scheda sulle Impostazioni («Configura ticketing»). Lo apre la modifica dell'evento (P1).
- **Test.** `TicketingCompleteTest` (ricerca, filtri, annullamento, ingresso dalla riga, impostazioni con errori sotto il campo, storico dei messaggi, file nella cache, riquadro dell'evento, corpo delle impostazioni, modelli con un server vecchio).

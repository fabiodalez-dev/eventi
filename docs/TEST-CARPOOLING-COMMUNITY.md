# Matrice dei test: car pooling, chat, badge e amministrazione

Data: 18 settembre 2026. **Specifiche di test da implementare**, non risultati di test eseguiti. Le suite social già verdi non dimostrano il funzionamento di queste nuove funzioni. Riferimento: [piano](PIANO-CARPOOLING-COMMUNITY.md) e [confronto funzionale](CONFRONTO-FUNZIONI-CARPOOLING.md).

Ogni ID è uno scenario di accettazione da collegare a uno o più test reali. I controlli condivisi vanno parametrizzati sui percorsi web/API, senza verificare soltanto la UI. Il numero di metodi finali dipenderà dai dataset; non dividere una singola asserzione in molti test per raggiungere una quota.

Totale attuale: **233 scenari**, suddivisi nelle 13 sezioni A–M. Non sono test già eseguiti.

## Metodo

- Pest unit/feature per regole, HTTP, policy, SQL, scheduler e notifiche; MariaDB reale in database dedicato con segmento `test` nel nome.
- Test concorrenti con connessioni/processi indipendenti e barriere esplicite: niente simulazione dell'overbooking con sole chiamate sequenziali o SQLite. Non eseguire `migrate:fresh` su un database condiviso con un'altra suite.
- Tempo controllato; nessun `sleep()` per simulare scadenze. Riferimenti UTC e fuso Europe/Rome, con DST e ritorno oltre mezzanotte.
- Test property-based/sequenze generate su accettazione/ritiro/cancellazione per conservare l'invariante posti. Mutation testing mirato su eligibility, assegnazione e deduplica, non su tutta la UI.
- Test Musonza tramite l'adattatore reale e database, non solo mock di una facciata; cifratura e autorizzazioni anche sulla serializzazione e sugli eventi.
- Browser desktop/mobile per flussi, accessibilità, più schede e due sessioni; Kotlin unit test, test contratti API e Compose/device per Android.
- Provider push finti nei test automatici ordinari. Prove esterne distinte in staging su dispositivi/account autorizzati, senza numeri o dati reali inventati.
- Le regressioni preesistenti social, ticketing, accessi, privacy e notifiche rimangono obbligatorie. Test appropriati a ogni fase; regressione completa al rilascio.

## A. Identità, età e autorizzazione

- A01 — Ospite vede i due pulsanti ma non può leggere elenchi personali o creare offerte/richieste via API.
- A02 — Account con sola email confermata viene indirizzato alla verifica WhatsApp e riceve rifiuto server sulle mutazioni riservate.
- A03 — WhatsApp presente ma email non confermata non abilita nessuna operazione carpool.
- A04 — Timestamp WhatsApp senza impronta valida del numero non soddisfa il requisito.
- A05 — Account idoneo senza dichiarazione 18+ non può offrire/richiedere, anche falsificando lo stato client.
- A06 — Valore booleano false, dichiarazione assente o tipo non ammesso è respinto; casella iniziale vuota su web/Android. Il test non pretende di accertare l'età reale di chi dichiara true.
- A07 — Accettazione 18+ registra attore autenticato, versione e orario server; l'utente non può impostare questi campi.
- A08 — Dichiarazione età non attribuisce badge «età verificata» o verifica documentale.
- A09 — Richiesta multiposto senza dichiarazione sugli accompagnatori maggiorenni è respinta.
- A10 — Accompagnatori non verificati sono ammessi e contati, senza obbligo di creare account o inventare identità.
- A11 — Account cancellato, sospeso globalmente o sospeso carpool è respinto; sola sospensione social non disabilita i passaggi. Migrazione delle sospensioni pregresse conserva il divieto generale community.
- A12 — Owner/editor del locale non diventa idoneo al carpool grazie al ruolo commerciale.
- A13 — Blocco reciproco impedisce visibilità/azioni in entrambe le direzioni e non rivela chi ha bloccato tramite dettagli eccessivi.
- A14 — Profilo social privato conserva la privacy pur permettendo la scheda minima carpool e il suo avatar autorizzato.
- A15 — Token scaduto/revocato e sessione scaduta non consentono azioni o lettura chat.
- A16 — Mass assignment non modifica autore, verifica, età, prezzo, destinatari, posti assegnati o stato.
- A17 — L'admin impersonificato non invia messaggi, prenota o legge chat/IP aggirando il percorso amministrativo.
- A18 — Ogni mutazione web richiede CSRF, ogni mutazione API autenticazione corretta; nessuna azione di stato avviene con GET.
- A19 — Quote di invio/creazione/segnalazione sono condivise tra web e API; alternare canale non aggira il limite e 429 espone Retry-After.
- A20 — Quote di ricerca/polling non impediscono rinuncia o segnalazione urgente; limiti di payload/paginazione/bozze/alert impediscono crescita arbitraria.

## B. Offerte, tappe, veicoli e modelli

- B01 — Offerta valida associata alla singola occurrence e non all'intera serie.
- B02 — Occurrence inesistente, non pubblica/non accessibile, annullata o temporalmente non ammessa è rifiutata.
- B03 — Posti minimi/massimi, zero, negativi, decimali, stringhe e overflow sono validati.
- B04 — Capacità esclude il conducente; prima pubblicazione mostra tutti i posti dichiarati disponibili.
- B05 — Seconda offerta attiva dello stesso conducente/data/tratta è rifiutata anche via API.
- B06 — Andata e ritorno sono indipendenti; creare il ritorno non duplica passeggeri o conferme.
- B07 — Orario include data/fuso e gestisce DST, mezzanotte e evento su più giorni.
- B08 — Limiti lunghezza, enum preferenze, testo XSS e URL nelle note non producono script o link pericolosi.
- B09 — Prezzi/contributi nascosti nel payload non attivano pagamenti o cambiano la gratuità.
- B10 — Riduzione capacità sotto posti confermati è respinta; aumento consentito senza duplicare conteggi.
- B11 — Chiusura alle richieste mantiene le prenotazioni confermate e la chat autorizzata.
- B12 — Modifiche sostanziali con confermati richiedono il percorso di annullamento/nuova offerta; patch diretta respinta.
- B13 — Massimo tre tappe, ordine univoco, coordinate/etichette valide; nessuna tappa di un altro viaggio selezionabile.
- B14 — Andata consente salita a una tappa e arrivo all'evento; ritorno applica l'inverso, senza prenotazione segmenti arbitrari.
- B15 — Punto privato e dettagli identificativi veicolo non escono nelle risorse destinate a non partecipanti.
- B16 — Garage personale: CRUD di altri utenti negato; cambiare/eliminare un'auto non riscrive uno snapshot di viaggio confermato.
- B17 — Modello duplicato conserva solo campi ammessi; elimina data vecchia, identità, chat, accettazioni, stato e riferimenti di altri proprietari.
- B18 — Bozza generata per altra data richiede nuova pubblicazione e nuova validazione delle regole correnti.
- B19 — Bozza non compare in elenchi, mappe o suggerimenti e non genera occupazioni/notifiche; condivisione del link non apre dati privati.
- B20 — Chiusura blocca nuove richieste ma permette di decidere le pendenti; riapertura ricontrolla requisiti e scadenza senza recuperare richieste terminate.

## C. Richieste e assegnazione dei posti

- C01 — Richiesta di un posto con nota facoltativa vuota viene creata in attesa.
- C02 — Richiesta multiposto conta il richiedente e gli accompagnatori correttamente.
- C03 — Richieste in attesa non riducono disponibilità.
- C04 — Richiesta per più posti dei residui è respinta; nessun intero negativo o overflow entra nel database.
- C05 — Seconda richiesta attiva dello stesso utente/offerta non crea duplicati.
- C06 — Richiesta alla propria offerta è respinta.
- C07 — Limite di tre alternative pendenti per data/tratta; l'altra tratta rimane distinta.
- C08 — Solo il conducente proprietario può accettare o rifiutare; ID di un'altra offerta non cambia il controllo.
- C09 — Accettazione assegna esattamente i posti richiesti, produce una chat e una notifica.
- C10 — Rifiuto non occupa posti e non crea chat.
- C11 — Ritiro pendente non altera capacità; ritiro accettato prima della partenza libera i posti una sola volta.
- C12 — Accettazione impossibile su richiesta ritirata/rifiutata/scaduta o offerta bozza/sospesa/annullata/conclusa; «chiusa alle nuove richieste» permette soltanto le pendenti già esistenti.
- C13 — Una seconda prenotazione accettata sulla stessa data/tratta è impedita.
- C14 — Conducente attivo e passeggero accettato sulla stessa data/tratta sono incompatibili.
- C15 — Prima accettazione ritira alternative, chiude annuncio di ricerca e sospende alert correlati, senza toccare ritorno/altri eventi.
- C16 — Riduzione posti dopo accettazione libera soltanto la differenza e avvisa; non può eliminare il posto del richiedente.
- C17 — Aumento posti dopo accettazione non modifica una conferma esistente tramite PATCH non autorizzata.
- C18 — Idempotenza: stesso payload restituisce stesso risultato; stessa chiave con payload diverso è respinta senza effetti.

## D. Concorrenza e transazioni reali

- D01 — Due azioni concorrenti del conducente accettano richieste diverse per l'ultimo posto della stessa offerta: una sola assegnazione riesce.
- D02 — Due richieste multiposto concorrenti non superano mai la capacità totale.
- D03 — Stesso richiedente accettato contemporaneamente su due offerte: resta una sola occupazione per data/tratta.
- D04 — Due accettazioni simultanee della stessa richiesta creano una sola conversazione e un solo evento di conferma.
- D05 — Accettazione contro ritiro concorrente produce uno stato serializzabile e conteggi coerenti.
- D06 — Accettazione contro annullamento offerta non lascia conferme attive in una tratta annullata.
- D07 — Accettazione contro riduzione capacità non produce posti negativi o sovrallocazione.
- D08 — Accettazione contro revoca WhatsApp/email ricontrolla eligibility sotto il protocollo di lock.
- D09 — Accettazione contro blocco tra utenti non mantiene una relazione vietata.
- D10 — Accettazione contro cancellazione account non lascia occupazioni o chat attive dell'account.
- D11 — Creazioni concorrenti rispettano unicità dell'offerta e della richiesta attiva.
- D12 — Retry di un deadlock è limitato e non moltiplica side effect/avvisi.
- D13 — Errore nella creazione chat o audit prima del commit annulla anche l'assegnazione.
- D14 — Rollback della transazione non invia push né lascia outbox/notifica orfana.
- D15 — Doppia esecuzione scheduler/revoca/cancellazione libera posti e notifica una volta sola.
- D16 — Sequenze generate di operazioni preservano sempre capacità, unicità e corrispondenza occupazioni/richieste.

## E. Ciclo di vita, eventi e revoche

- E01 — Alla partenza le pendenti scadono anche se lo scheduler è in ritardo: endpoint le respingono subito.
- E02 — Annullamento offerta notifica tutti e soli i richiedenti coinvolti con destinazione ancora leggibile.
- E03 — Evento annullato invalida offerte/pendenti/confermate secondo il piano, preservando snapshot e caso aperto.
- E04 — Evento spostato non cambia silenziosamente luogo/ora di una conferma; tutti ricevono stato corretto.
- E05 — Cambio di solo testo editoriale non annulla i passaggi.
- E06 — Ripristino evento non ripristina automaticamente prenotazioni annullate.
- E07 — Revoca email del conducente chiude operazioni e avvisa richiedenti; variante WhatsApp e sospensione.
- E08 — Revoca del richiedente cancella la sua richiesta senza danneggiare gli altri gruppi.
- E09 — Ripristino delle verifiche non riapre automaticamente offerte/chat/prenotazioni precedenti.
- E10 — Blocco bilaterale con prenotazione confermata libera posti prima della partenza e chiude chat, preservando altri passeggeri.
- E11 — Blocco dopo partenza non rimette posti sul mercato e mantiene il canale di segnalazione.
- E12 — Cancellazione account distingue dati da rimuovere e dati sotto preservazione, senza cascade distruttivi impropri.
- E13 — Archiviazione a 24 ore non dichiara che il viaggio sia realmente avvenuto.
- E14 — Chat passa a sola lettura alla scadenza o cancellazione; lettura storica rispetta comunque sicurezza e retention.
- E15 — Flag di emergenza blocca nuove operazioni mantenendo rinunce, storico e assistenza previsti.
- E16 — Interfacce vecchie ricevono errore di stato comprensibile e recuperano il riepilogo aggiornato.
- E17 — Rettifica/revoca 18+ chiude operazioni e accordi futuri senza cancellare audit o impedire assistenza; nuova dichiarazione non riapre automaticamente i passaggi.
- E18 — Modifica sostanziale senza confermati invalida pendenti e suggerimenti della revisione precedente; cambio di sola capacità non modifica condizioni accettate.
- E19 — Fine dell'evento non impedisce un ritorno futuro ancora nella finestra consentita; oltre tale finestra nuove operazioni sono respinte.

## F. Chat e contenuti privati

- F01 — Nessuna chat prima dell'accettazione, neppure conoscendo ID o manipolando le API del pacchetto.
- F02 — Un solo legame conversazione/richiesta; retry o nuova apertura non crea doppioni.
- F03 — Stessa coppia in due eventi diversi ha conversazioni distinte.
- F04 — Accompagnatore/terzo/follower/gestore locale non legge né invia messaggi.
- F05 — Partecipanti e mittente sono stabiliti dal server; modello polimorfico arbitrario respinto.
- F06 — Nessuna rotta generica Musonza esposta aggira policy carpool.
- F07 — Un messaggio valido è cifrato nel database e correttamente decifrato al destinatario autorizzato.
- F08 — Segreti, IP, campi utente privati e corpi cifrati non trapelano in risorse API o eventi.
- F09 — Testo vuoto, troppo lungo, XSS, unicode limite e payload non testuale sono gestiti correttamente.
- F10 — Nessun allegato o anteprima remota attivabile tramite campi extra.
- F11 — Stessa chiave client ritentata dopo timeout produce un solo messaggio.
- F12 — Destinazione/conversazione falsificata nella stessa chiave non riutilizza un messaggio di un altro contesto.
- F13 — Paginazione stabile con arrivo di nuovi messaggi non salta o duplica la cronologia.
- F14 — Watermark di lettura non può superare l'ultimo messaggio autorizzato esistente.
- F15 — Messaggio arrivato dopo il watermark resta non letto.
- F16 — Archiviazione/silenziamento di un utente non modifica l'archivio dell'altro.
- F17 — Cancellazione/ritiro non permette invio successivo anche con UI o socket già aperti.
- F18 — Revoca/blocco durante connessione attiva non consegna ulteriori testi sensibili.
- F19 — Broadcast contiene solo metadati minimi; recupero API riesegue policy e revoca.
- F20 — Logout/cambio account azzera cache locale, badge, listener e contenuti dell'account precedente.
- F21 — Messaggio segnalato rimane collegato al caso con provenienza corretta; nessuna modifica retroattiva del partecipante.
- F22 — Contenuto chat non appare in Sentry/Telescope, log di debug, notifiche lock screen o ricerche admin generiche.

## G. Notifiche, badge e consegna

- G01 — Nuova richiesta notifica solo il conducente; l'avviso porta alla richiesta precisa.
- G02 — Accettazione/rifiuto notifica solo il richiedente corretto con stato corrente.
- G03 — Rinuncia e riduzione posti aggiornano conducente, disponibilità e badge una sola volta.
- G04 — Nuovo follower/commento/risposta social usa archivio e badge unificati.
- G05 — Nessuna auto-notifica per azioni sul proprio contenuto.
- G06 — Più messaggi nella stessa chat contano una conversazione nel riepilogo, non anche ogni notifica duplicata.
- G07 — Leggere una richiesta non azzera «Da gestire»; decidere/scadere sì.
- G08 — Aprire la lista Avvisi non segna automaticamente tutte le chat come lette.
- G09 — «Segna avvisi come letti» rispetta watermark, ownership e nuovi avvisi arrivati durante l'azione.
- G10 — Lettura su web si riflette in Android e viceversa al successivo aggiornamento.
- G11 — Risposta di rete vecchia non sovrascrive contatori più recenti.
- G12 — Eventi/FCM duplicati, persi o fuori ordine convergono allo stato server.
- G13 — Worker fermo lascia notifica interna/outbox recuperabili; riavvio non duplica consegne logiche.
- G14 — Revoca/blocco/cancellazione fra enqueue e invio impedisce anteprime o conferme ormai errate.
- G15 — Permesso notifiche negato mantiene badge, cronologia e funzionalità principali.
- G16 — Silenziare push chat non cancella messaggi o avvisi operativi nell'app.
- G17 — Preferenze e quiet hours non sopprimono l'archivio transazionale; marketing separato.
- G18 — Link vecchi sul dominio precedente non diventano open redirect; nuove destinazioni tipizzate usano il dominio corrente.
- G19 — Deep link ad app chiusa preserva destinazione durante login e verifica ownership dell'account.
- G20 — Badge 0/1/99/100 e stringhe italiane accessibili sono corretti, senza affidarsi al solo colore.
- G21 — Scheduler promemoria 24h/2h e richieste pendenti deduplica, salta scaduti e non recupera invii superati.
- G22 — FCM/Web Push con token morto non rompe la transazione; pulizia token e stato consegna verificabili.

## H. Admin, social e assistenza

- H01 — Owner/editor del locale non accede a risorse carpool, chat, audit, badge admin o export anche sul proprio evento.
- H02 — Moderator/admin/superadmin rispettano i permessi granulari assegnati, non un controllo solo di visibilità menu.
- H03 — Consultazione sensibile richiede caso/motivo e permesso, e registra operatore reale.
- H04 — IP e telefoni mascherati per default; rivelazione originale riservata e tracciata.
- H05 — Dashboard e ricerca ordinaria non mostrano corpi di chat o dati di accompagnatori non raccolti.
- H06 — Nascondere/ripristinare social richiede motivazione e mantiene restrizioni contro ritiro/ripubblicazione.
- H07 — Sospensione per ambito produce gli effetti previsti senza attribuire arbitrariamente verifiche WhatsApp.
- H08 — Lo staff non può accettare posti al posto del conducente o scrivere come partecipante.
- H09 — Annullamento amministrativo del passaggio è motivato, notificato e conserva traccia.
- H10 — Assegnazioni simultanee di un caso non perdono assegnatario/stato; conflitto spiegato all'operatore.
- H11 — Note interne non vengono inviate al segnalante o all'altro partecipante.
- H12 — Risposta di assistenza è distinta dalla chat passaggio, con autore staff e destinatario corretti.
- H13 — Modelli di risposta non espongono variabili private né inviano senza azione dell'operatore.
- H14 — Utente sospeso può presentare ricorso/assistenza senza riottenere il permesso di chattare con passeggeri.
- H15 — Segnalazione «richiesta di denaro» entra nella coda pertinente senza sanzione automatica basata sulla sola accusa.
- H16 — Esportazioni sono autorizzate anche al download, scadono, sono fuori webroot e neutralizzano formule CSV.
- H17 — Contatori/filtri/paginazione admin rispettano i limiti della query e non generano N+1 o fughe tra casi.
- H18 — Ripristino o riapertura caso conserva la cronologia e non cancella la decisione precedente.
- H19 — Segnalante e segnalato leggono solo il proprio filo con l'assistenza; manipolare caso/destinatario/paginazione non espone risposte o note dell'altra parte.

## I. IP, audit, conservazione e richieste dell'autorità

- I01 — IPv4/IPv6 canonici vengono associati all'azione e cifrati; l'orario proviene dal server.
- I02 — `X-Forwarded-For` da sorgente non fidata non falsifica l'IP; proxy configurato viene gestito correttamente.
- I03 — Job/outbox usano il contesto della richiesta originaria, non l'IP del worker.
- I04 — User-Agent e campi contesto sono limitati e non permettono log injection.
- I05 — Token, password, OTP, cookie e header arbitrari non finiscono nell'audit.
- I06 — Polling badge/chat non crea una crescita illimitata del registro delle azioni.
- I07 — Scadenza IP cancella anche copie/impronte previste, senza lasciare lo stesso valore nei registri generici.
- I08 — Scadenza contenuti elimina note/chat e copie derivate alla durata configurata.
- I09 — Preservazione su caso sospende solo il purge dei dati pertinenti, non dell'intero account/sistema.
- I10 — Scadenza/revisione/rilascio di una preservazione è autorizzata e tracciata.
- I11 — Export evidenze contiene solo soggetti/intervallo richiesti, manifest degli ID/hash e identità dell'operatore.
- I12 — Nessun export per autorità è accessibile come URL pubblico o inviato automaticamente a destinatari non verificati.
- I13 — Export personale non rivela IP o note interne di terzi; diritti di accesso ed evidenze amministrative restano distinti.
- I14 — Cancellazione account mantiene solo ciò che ha ragione di conservazione documentata e limita visibilità/accessi.
- I15 — Ripristino backup riapplica tombstone/scadenze, evitando ricomparsa di messaggi eliminati.
- I16 — Chiavi stabili trasferite in ambiente di prova consentono lettura dei dati dopo migrazione account/dominio; chiavi errate falliscono senza esporre dati grezzi.
- I17 — Scadenza del caso elimina risposte/evidenze secondo le proprie durate; la segnalazione non copia automaticamente l'intera chat né ne prolunga globalmente la conservazione.

## J. Termini e dichiarazione 18+

- J01 — Documento carpool versionato è leggibile prima dell'accettazione da web e Android.
- J02 — Accettazione regole ed età sono distinte, non preselezionate e non implicano marketing.
- J03 — Versione/hash arbitrario o superato non viene accettato come versione corrente.
- J04 — Retry della stessa accettazione non falsifica data originale né crea audit duplicato improprio.
- J05 — Cambi sostanziali richiedono nuova adesione per nuove offerte/richieste.
- J06 — Rifiuto dei nuovi termini permette comunque rinuncia, storico, segnalazione e gestione necessaria di accordi preesistenti.
- J07 — Profilo mostra stato «Maggiore età dichiarata» e relativa data senza inventare verifica documentale.
- J08 — Dichiarazione accompagnatori viene richiesta per ogni gruppo e riferita al numero di posti di quella richiesta.
- J09 — Migrazione dei testi è idempotente, conserva personalizzazioni/versioni e non resetta pagine o utenti.
- J10 — Termini, privacy e UI descrivono coerentemente gratuità, accompagnatori, chat consultabili dallo staff e retention.
- J11 — Nessuna promessa testuale di prenotazione prima dell'accettazione o identità/patente certificate tramite WhatsApp.
- J12 — Pagine pubbliche non affermano più che il servizio non gestisce alcuna prenotazione quando carpool/ticketing sono attivi.

## K. Web, mobile e Android

- K01 — Dalla scheda data i due pulsanti aprono il percorso corretto per ospite/email/WhatsApp/età/sospensione.
- K02 — Onboarding conserva solo destinazione interna e torna alla data corretta, senza inviare azioni automaticamente.
- K03 — Modale gestisce focus, Escape/indietro, tastiera e ritorno al controllo originario.
- K04 — Offerta completa con ritorno, tappe e preferenze funziona su viewport mobile senza overflow.
- K05 — Richiesta multiposto mostra chiaramente totale e accompagnatori prima dell'invio.
- K06 — Doppio tap e timeout non creano richieste/messaggi duplicati; feedback di caricamento e retry leggibili.
- K07 — Due utenti completano richiesta/accettazione/chat da browser distinti con badge corretti.
- K08 — «I miei passaggi» e «Messaggi» raggiungibili da evento, menu, profilo e notifica.
- K09 — Header chat mantiene data/tratta/interlocutore/stato e collegamento al riepilogo.
- K10 — Offline non mostra una prenotazione come confermata; riconnessione risincronizza prima di consentire azioni definitive.
- K11 — Android foreground/background/riavvio processo conserva navigazione utile e non cache privata non autorizzata.
- K12 — Cambio account sullo stesso dispositivo cancella notifiche locali e contenuti della sessione precedente.
- K13 — Deep link invalido, risorsa cancellata, token scaduto e account diverso sono gestiti con schermata comprensibile.
- K14 — TalkBack, font grandi, contrasto e aree 48 dp; ordine logico nelle richieste e nei messaggi.
- K15 — Temi chiaro/scuro e badge non coprono azioni o contenuto sulle dimensioni supportate.
- K16 — Polling unico, stop quando nascosto e backoff impediscono duplicazione timer e consumo inutile.
- K17 — App con permesso push negato continua a ricevere aggiornamenti quando aperta e a mostrare gli avvisi.
- K18 — Client Android precedente ignora nuovi tipi in modo sicuro e mantiene i flussi già esistenti.

## L. Ricerche, annunci, promemoria e feedback

- L01 — Filtri zona/tappa/orario/posti/preferenze selezionano solo offerte pertinenti alla data/tratta.
- L02 — Annuncio «cerco» richiede autore idoneo/18+, posti validi e controllo dei blocchi.
- L03 — Un solo annuncio attivo per utente/data/tratta, con scadenza e ritiro idempotenti.
- L04 — Conducente propone soltanto offerte proprie, attive, pertinenti e capaci di soddisfare i posti richiesti.
- L05 — Suggerimento duplicato o inviato a utente bloccato viene respinto; nessuna chat anticipata.
- L06 — Apertura di un suggerimento non genera richiesta o accettazione automatica.
- L07 — Ricerca salvata e alert sono privati e modificabili soltanto dal proprietario.
- L08 — Nuova offerta corrispondente genera un solo avviso per destinatario/offerta; incompatibile non lo genera.
- L09 — Posti tornati disponibili avvisano solo interessati idonei senza assegnare posti.
- L10 — Scadenza/disiscrizione/revoca/accettazione altrove ferma gli alert anche se già accodati.
- L11 — Variazioni ripetute di capacità non producono una raffica di avvisi uguali.
- L12 — Nuove offerte non mandano messaggi automatici a follower che non hanno aderito alla ricerca.
- L13 — Promemoria rispetta quiet hours e stato; non inoltra indirizzi o testo chat nel push.
- L14 — Feedback consentito solo ai due interlocutori di una richiesta già accettata, dopo l'orario previsto della tratta, anche se annullata; il feedback non la presenta automaticamente come svolta.
- L15 — Un feedback per autore/richiesta, nessuna auto-recensione o feedback degli accompagnatori senza account.
- L16 — Feedback privato non compare su profilo pubblico, ranking o risposte API di terzi.
- L17 — Dichiarazione di assenza/problema non viene trasformata automaticamente in fatto provato o ban.
- L18 — Caso nato da feedback conserva il legame a richiesta/offerta/evento e consente replica assistita secondo policy.

## M. Integrazione, carico e rilascio

- M01 — Migrazioni additive su copia di schema con dati esistenti, senza alterare salvataggi privati, follow o prenotazioni ticketing.
- M02 — Compatibilità Musonza stabile risolta senza abbassare Laravel/PHP o usare release di sviluppo; audit dipendenze registrato.
- M03 — Query liste/offerte/chat/avvisi/admin restano paginate e con limiti di complessità; nessun N+1 sui dataset realistici.
- M04 — Cache condivisa/HTML evento/service worker non memorizzano dati, badge o chat di un utente per altri.
- M05 — Carico e accettazioni concorrenti in staging rispettano invarianti anche quando gli obiettivi di latenza non sono raggiunti.
- M06 — Reverb: origine vietata, canale non autorizzato, logout, revoca e riconnessione sono provati prima dell'attivazione.
- M07 — Reverb indisponibile passa al recupero API/polling senza perdere messaggi persistiti.
- M08 — Worker e scheduler con retry/riavvio recuperano attività e mostrano anomalie nello stato operativo admin.
- M09 — Feature flag impedisce esposizione parziale e mantiene le operazioni necessarie ai viaggi già concordati.
- M10 — Deploy con backup e asset dello stesso SHA; verifica HTTPS e smoke su dati dedicati, mai reset DB reale.
- M11 — APK compilato/testato e prova FCM su dispositivo autorizzato; pubblicazione Play distinta, con firma e account corretti.
- M12 — Cambio dominio/account: deep link, notifiche storiche, WSS, CORS, FCM/Web Push, OAuth, chiavi e callback ricollegati e verificati.

## Evidenze da consegnare al termine dell'implementazione

Report con associazione ID→test, comando/ambiente/commit, esito e problemi aperti; report CI e browser; test Android JVM/Compose/device; prova concorrenza MariaDB; prova reale di push e navigazione; validazione documenti/retention; verifica post-deploy. Distinguere sempre test automatizzati, prove su dispositivo e verifiche di servizi esterni.

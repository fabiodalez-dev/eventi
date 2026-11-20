# inCittà — Funzionalità della piattaforma

*Panoramica per ruoli e opportunità commerciali. Aggiornata al 20 novembre 2026.*

<!--
COME SI AGGIORNA QUESTO FILE
È la fonte unica dell'elenco delle funzionalità. Ogni PR che aggiunge, cambia o toglie qualcosa che un utente o un operatore può fare aggiorna questo file nella stessa PR:
- una voce per funzione, nella sezione del ruolo che la usa: `- **Nome breve** — Cosa permette di fare, in una frase per operatori e investitori. *Dove: sito e app / solo web / Android.*`
- se la funzione era in «Pianificato, non ancora realizzato», si sposta nella sezione del ruolo;
- si aggiorna la data qui sopra e si aggiunge una riga al registro in fondo.
Il documento Word si rigenera con: php scripts/funzionalita-docx.php
-->

inCittà mette in relazione chi cerca qualcosa da fare con locali e organizzatori. Questo documento riepiloga gli strumenti della piattaforma per presentarla a operatori e potenziali investitori, distinguendo le responsabilità di ciascun ruolo.

Le funzionalità dipendono dai permessi assegnati e, dove indicato, dall’abilitazione delle integrazioni. Dove non è specificato, la funzione è disponibile sul sito e, per le persone, anche nell’app Android.

## Amministratore e redazione

Controlla il catalogo, abilita gli operatori e gestisce promozione, comunicazioni e qualità della piattaforma.

- **Catalogo centralizzato** — Crea e aggiorna eventi, locali e organizzatori da un unico pannello.
- **Revisione delle pubblicazioni** — Approva o respinge proposte e modifiche prima della pubblicazione.
- **Bozze e uscite programmate** — Prepara contenuti in anticipo e imposta quando renderli pubblici.
- **Pannelli leggibili** — Date scritte per esteso, eventi riconoscibili da locandina, titolo, data e locale invece che da numeri, un selettore degli eventi con ricerca in statistiche e grafiche social, riquadri della redazione che dicono cosa aspetta una decisione, menu uguale nei tre pannelli e cambio del tema dal menu della persona. *Solo web.*
- **Calendario della programmazione** — Consulta le date degli eventi per pianificare e controllare il palinsesto.
- **Eventi con più date** — Gestisce appuntamenti, orari e variazioni senza duplicare la scheda principale.
- **Ricorrenze automatiche** — Genera serie di appuntamenti con frequenza e termine configurabili.
- **Annullamenti e rinvii** — Aggiorna lo stato delle singole date e attiva gli avvisi previsti agli interessati.
- **Importazione da Facebook** — Recupera dati e copertina dagli eventi pubblici accessibili, da verificare prima di pubblicare.
- **Importazione di calendari** — Acquisisce eventi da fonti ICS e controlla gli esiti delle importazioni.
- **Fonti aggiornate automaticamente** — Configura sorgenti e frequenze di importazione per alimentare il catalogo nel tempo.
- **Categorie e tag** — Organizza i contenuti e rende coerenti ricerca, filtri e interessi degli utenti.
- **Gestione di più città** — Configura città, fuso orario e riferimenti geografici per i rispettivi cataloghi.
- **Accreditamento dei locali** — Valuta richieste di gestione, dati del locale e accesso al pannello dedicato.
- **Account e autorizzazioni** — Assegna ruoli e responsabilità mantenendo separati gli ambiti di gestione.
- **Nuove iscrizioni in evidenza** — L'elenco degli utenti parte dagli ultimi iscritti, con data d'iscrizione, stato della conferma e il filtro «In attesa di conferma»; il riepilogo mostra le nuove iscrizioni. *Solo web.*
- **Richieste di sponsorizzazione da approvare** — Le richieste mandate dai locali senza pagamento online arrivano in redazione, che concorda il pagamento e le approva. *Solo web.*
- **Organizzatori indipendenti** — Gestisce identità e contenuti di realtà che organizzano eventi anche in sedi diverse.
- **Evidenza editoriale** — Seleziona appuntamenti da valorizzare nelle aree di scoperta della piattaforma.
- **Segnalazioni e recensioni** — Esamina contenuti segnalati e modera le recensioni dei locali.
- **Studio grafico automatico** — Trasforma dati e locandine degli eventi in grafiche pronte per i canali social.
- **Formati per post e storie** — Produce immagini quadrate, verticali e per storie con titoli e informazioni dell’evento.
- **Personalizzazione delle grafiche** — Regola titolo, prezzo, indirizzo, resa delle immagini e opzioni visive disponibili.
- **Testi social riutilizzabili** — Configura modelli di didascalia con città, data, elenco eventi e collegamenti.
- **Pubblicazione su Facebook** — Invia i contenuti predisposti alla pagina collegata e autorizzata.
- **Pubblicazione su Instagram** — Pubblica i contenuti supportati sull’account professionale correttamente collegato.
- **Pubblicazione su Telegram** — Distribuisce le selezioni di eventi nel canale Telegram configurato.
- **Programmazione dei post** — Pianifica invii futuri e controlla stato, errori e nuovi tentativi di pubblicazione.
- **Selezioni social giornaliere** — Automatizza la preparazione e la pubblicazione quotidiana sui canali abilitati.
- **Storie da scaricare** — Esporta le grafiche per le storie da pubblicare manualmente sui social.
- **Gestione delle sponsorizzazioni** — Configura campagne, periodi, posizionamenti e autorizzazioni promozionali.
- **Nuova sponsorizzazione in quattro passi** — Sceglie il committente (un locale, un organizzatore o un committente esterno), poi un evento da un selettore con locandina, data e locale che si cerca anche per nome del locale, oppure «tutti gli eventi» di quel locale o organizzatore; poi posizione, periodo, prodotto e pagamento, con un riepilogo prima di salvare. *Solo web.*
- **Sponsorizzazioni a visualizzazioni** — Oltre al periodo si vende un numero di visualizzazioni, distribuito nel periodo o consegnato il prima possibile; il modulo stima quante ne può dare lo spazio fino all'ultima data dell'evento, avvisa sopra il 60% e blocca oltre l'80% (il massimo che una campagna riceve), e dice chi occupa già quella posizione. La campagna smette di comparire quando ha consegnato tutto; un organizzatore con eventi in più città ha un obiettivo solo, distribuito sul traffico di tutte. *Solo web; l'apertura della home e il foglio della mappa si vendono solo a periodo.*
- **Avanzamento delle campagne** — Elenchi e schede mostrano per ogni campagna o abilitazione barra, «N di M visualizzazioni», stato («in linea», «in ritardo», «a rischio»…), consegnate oggi, quota richiesta, giorni rimasti e la consegna giorno per giorno contro l'attesa, con un filtro per stato. *Solo web.*
- **Avviso del mattino sulle campagne in ritardo** — Ogni giorno alle 7 la campanella del pannello segnala le campagne a visualizzazioni in ritardo o a rischio, una volta al giorno. *Solo web.*
- **Promozione degli eventi** — Assegna visibilità sponsorizzata agli eventi nei punti previsti di sito e app.
- **Banner promozionali** — Gestisce creatività e destinazioni dei banner nelle aree supportate.
- **Misurazione delle campagne** — Consulta impression, click e percentuale di click sulle impression registrate. Una visualizzazione conta dopo un secondo con metà della card sullo schermo, una volta sola; programmi automatici, redazione e committente non contano. La percentuale di click compare da 100 visualizzazioni.
- **Rotazione equa fra committenti** — Chi sponsorizza «tutti i propri eventi» occupa un posto solo, come chi ne sponsorizza uno; card e cima dell'elenco cambiano a ogni visita anche con la pagina in cache, e una campagna in pausa o conclusa sparisce entro un minuto.
- **Analisi dei posizionamenti** — Confronta i risultati per canale, pagina e posizione della sponsorizzazione.
- **Report periodici agli sponsor** — Invia riepiloghi automatici delle campagne attraverso il sistema email configurato; per le campagne a visualizzazioni il riepilogo del lunedì riporta obiettivo, consegnate e stato, un messaggio solo per abilitazione; quello di un'abilitazione arriva anche a chi gestisce il locale o l'organizzazione.
- **Newsletter e riepiloghi** — Imposta contenuti e parametri delle comunicazioni periodiche agli iscritti.
- **Testi delle notifiche** — Personalizza i messaggi utilizzati nei diversi flussi della piattaforma.
- **Controllo degli invii** — Consulta notifiche pianificate e risultati per verificare le comunicazioni automatiche.
- **Abilitazione delle prenotazioni** — Autorizza i locali al ticketing gratuito o con pagamento all’ingresso.
- **Contenuti istituzionali** — Gestisce pagine informative, dichiarazioni cookie e contenuti di servizio.
- **Visibilità sui motori di ricerca** — Controlla impostazioni SEO, metadati e reindirizzamenti delle pagine.
- **Consensi e strumenti esterni** — Configura gli script soggetti a consenso e le relative dichiarazioni.
- **Controllo operativo** — Consulta operazioni pianificate, cache e configurazione email dal pannello amministrativo.
- **Acquisto delle sponsorizzazioni con approvazione** — La redazione gestisce il listino (posizione × durata), riceve gli ordini pagati con carta tramite Stripe e li approva o li respinge; il rifiuto rimborsa e spiega il motivo. *Solo web; attivo quando sono configurate le chiavi Stripe.*
- **Sponsorizzazioni per organizzatori** — Un organizzatore può essere committente di una sponsorizzazione che copre i suoi eventi in qualunque locale. *Solo web.*
- **Autorizzazioni sponsor gratuite** — Concede un'abilitazione promozionale senza dati di pagamento, lasciandone traccia nella cronologia. *Solo web.*
- **Analytics di eventi e pubblico** — Dieci grafici e sette tabelle filtrabili per locale, organizzatore, evento, canale e periodo: visite, salvataggi, commenti, prenotazioni, ingressi, sponsorizzazioni e follower, con esportazione CSV e Excel. *Solo web.*
- **Link brevi tracciati** — Ogni condivisione usa un link breve distinto per canale, con condivisioni e aperture giorno per giorno. *Solo web.*
- **Moderazione dei commenti** — Nasconde e ripristina i commenti agli eventi, con filtri automatici contro dati personali, duplicati e spam. *Solo web.*
- **Console della community** — Modera profili, pubblicazioni e commenti, sospende o revoca, mette in evidenza contenuti e consulta lo storico delle verifiche WhatsApp con i numeri mascherati. *Solo web.*
- **Console dei passaggi in auto** — Gestisce segnalazioni, sospensioni, prove e registro delle azioni sul car pooling. *Solo web.*
- **Recensioni degli organizzatori** — Modera anche le recensioni agli organizzatori e può rimuovere le risposte dei gestori, senza poterle scrivere al loro posto. *Solo web.*
- **Unione dei doppioni** — Fonde due schede dello stesso evento: date, prenotazioni, salvataggi, follow, commenti e statistiche passano alla scheda che resta, e il vecchio indirizzo vi rimanda. *Solo web.*
- **Ripristino dalla cronologia** — Rimette i valori precedenti di titolo, testi, prezzi e luogo. *Solo web.*
- **Ricerche senza risultati** — Mostra, città per città, cosa cercano le persone senza trovarlo, senza dire chi ha cercato. *Solo web.*
- **Candidature e rivendicazioni** — Un'unica coda per chi rivendica un locale già a catalogo e per gli organizzatori senza sede; l'approvazione consegna la scheda senza creare doppioni. *Solo web.*
- **Staff esonerato dalla verifica WhatsApp** — Chi amministra partecipa alla community senza un falso badge di numero verificato. *Sito e app.*
- **Rinomina dei nomi utente** — Cambia un nome utente con un motivo, senza l'attesa di trenta giorni prevista per gli utenti. *Solo web.*
- **Pannelli rinnovati** — Nuova grafica e navigazione in sei gruppi, riepilogo che parte dalla coda di lavoro e scorciatoie coerenti con i permessi di ciascuno. *Solo web.*

## Locale e gestore

Promuove la propria attività, mantiene aggiornato il programma e misura l’interesse generato dai propri contenuti.

- **Richiesta di gestione** — Richiede l’accesso al locale e opera nel pannello dopo l’approvazione prevista.
- **Scheda pubblica del locale** — Aggiorna presentazione, recapiti, sito e informazioni utili ai visitatori.
- **Posizione e informazioni pratiche** — Mantiene indirizzo e dati geografici per facilitare scoperta e indicazioni stradali.
- **Collaboratori** — Coinvolge collaboratori con i permessi previsti per gestire il proprio locale.
- **Creazione guidata degli eventi** — Compila contenuti, categoria, prezzi, immagine e dettagli dell’appuntamento.
- **Locandine personalizzate** — Carica la grafica dell’evento, con presentazione adatta anche alle immagini verticali 3 a 4.
- **Importazione da Facebook** — Incolla il collegamento a un evento pubblico accessibile e verifica i dati recuperati.
- **Importazione da calendario ICS** — Importa un calendario, controlla l’anteprima e seleziona i contenuti da acquisire.
- **Programmazione ricorrente** — Crea serie di date per appuntamenti periodici senza reinserire ogni evento.
- **Gestione delle singole date** — Modifica orari e dettagli delle occorrenze, compresi rinvii e annullamenti.
- **Bozze e anteprima** — Prepara l’evento e controlla come apparirà prima di inviarlo alla pubblicazione.
- **Pubblicazione controllata** — Invia alla redazione oppure pubblica secondo le autorizzazioni assegnate al locale.
- **Uscita programmata** — Imposta la pubblicazione futura quando prevista dal flusso autorizzato.
- **Programma del locale** — Consulta gli appuntamenti gestiti e mantiene aggiornata la propria offerta.
- **Grafiche social automatiche** — Genera immagini a partire dai propri eventi senza impaginare ogni post da zero.
- **Materiali per Instagram e Facebook** — Scarica formati per post e storie da usare nei propri canali social.
- **Statistiche del locale** — Consulta visite alla scheda e interazioni con i propri contenuti nel periodo scelto.
- **Statistiche degli eventi** — Legge visualizzazioni e azioni registrate per capire quali appuntamenti attirano interesse.
- **Analytics per data** — Distingue i risultati di ogni appuntamento, anche quando appartiene a una serie.
- **Click di interesse concreto** — Misura azioni tracciate come indicazioni stradali, biglietti, contatti e calendario.
- **Salvataggi e persone interessate** — Osserva l’interesse espresso tramite i salvataggi delle singole date.
- **Attivazione delle promozioni abilitate** — Seleziona gli eventi da promuovere nell’ambito delle concessioni attive assegnate.
- **Analytics delle sponsorizzazioni** — Confronta impression, click e rendimento dei posizionamenti delle proprie campagne.
- **Avanzamento delle proprie sponsorizzazioni** — Una scheda per abilitazione con barra e frase («1.240 di 3.000 visualizzazioni, in linea: al ritmo attuale arrivi all'obiettivo il 28 novembre»), «tutti i tuoi eventi» o gli eventi scelti con i loro numeri, e la spiegazione che le misure sono una stima al ribasso e che le proprie visite non contano. *Solo web; nell'app Android barra e stato nella gestione delle campagne.*
- **Riepilogo del lunedì delle sponsorizzazioni** — Chi gestisce un locale o un'organizzazione con un'abilitazione riceve ogni lunedì per email le visualizzazioni e le aperture della settimana, con obiettivo, consegnate e stato. *Email.*
- **Prenotazioni per appuntamento** — Se abilitato, configura posti, limite per account e apertura delle prenotazioni.
- **Lista di attesa** — Gestisce la disponibilità con una lista di attesa quando i posti risultano esauriti.
- **Dati e istruzioni di prenotazione** — Definisce i campi richiesti e le informazioni da comunicare a chi prenota.
- **Biglietti e controllo degli ingressi** — Gestisce le prenotazioni e verifica i biglietti con QR per le date autorizzate.
- **Esportazione degli iscritti** — Scarica gli elenchi delle prenotazioni in CSV per le attività operative autorizzate.
- **Collegamenti alla biglietteria esterna** — Indirizza verso servizi di vendita esterni e misura i click sul collegamento.
- **Analytics completi del locale** — Dashboard con grafici ed esportazioni CSV ed Excel, pagine dedicate per evento e scheda evento divisa in tre viste: Evento con tutte le serate, Modifica e Statistiche. *Solo web.*
- **Rapporto mensile via email** — Il primo del mese i referenti ricevono visite, salvataggi, prenotazioni e presenze confrontati con il mese precedente.
- **QR e canali personalizzati** — Crea link tracciati con nomi propri («volantino», «radio», «partner») e un QR vettoriale da stampare per ciascuno. *Solo web.*
- **Costo per prenotazione e per presenza** — Mostra quanto è costata ogni prenotazione o ingresso ottenuto con una sponsorizzazione, solo con almeno cinque account distinti. *Sito e app.*
- **Pagina del locale più ricca** — Galleria, caratteristiche, servizi, fasce d'età e note pratiche visibili al pubblico. *Sito e app.*
- **«Prima di andare» per singola data** — Accessibilità, tessera, trasporti, parcheggio, cibo e ingresso indicati per ogni data, ereditando quanto già scritto per locale ed evento. *Sito e app.*
- **Costi dichiarati** — Ingresso, consumazione obbligatoria, tessera e altri costi indicati separatamente, distinguendo un totale completo da uno parziale. *Sito e app.*
- **Lista d'attesa con conferma a tempo** — Chi viene promosso conferma entro una scadenza, poi il posto passa al successivo; chi aspetta vede la propria posizione. *Sito e app.*
- **Acquisto di sponsorizzazioni dal pannello** — Il proprietario compra un periodo dal listino e paga con carta; i collaboratori vedono gli ordini ma non ordinano. *Solo web.*
- **Moderazione dei commenti sui propri eventi** — Nasconde o ripristina i commenti alle proprie date. *Solo web.*
- **Motivo del rifiuto nel pannello** — Quando la redazione rifiuta un evento, il locale legge il motivo nella scheda dell’evento, oltre che nell’email, e può correggere e reinviare. *Solo web.*
- **Risposta alle recensioni** — Risponde in pubblico con il nome della scheda; la prima risposta avvisa l'autore. *Risposta dal pannello web, visibile anche nell'app.*
- **Rivendicazione di un locale a catalogo** — Il titolare chiede la gestione della scheda direttamente dalla pagina pubblica. *Solo web.*
- **Registrazione del locale con account** — Chi registra o rivendica un locale senza avere un account lo crea nello stesso modulo, scegliendo la password; la richiesta entra in coda dopo la conferma dell'email. *Solo web.*
- **Pannello semplificato e collegato** — Dalla scheda di un evento si passa a modifica, statistiche, grafiche social, sponsorizzazione e biglietteria; gli elenchi mostrano le locandine. *Solo web.*
- **Pagina Social con filtri e calendario** — Si scelgono le serate da un calendario o da un elenco filtrabile, senza conoscere le date a memoria. *Solo web.*
- **Sponsorizzazione in quattro passi** — Evento con locandina, posizione spiegata, durata con prezzo, riepilogo; senza pagamento online la richiesta va alla redazione. *Solo web.*
- **Descrizioni con gli a capo** — I testi incollati (anche da Facebook) mantengono paragrafi e a capo, nel pannello e sul sito; nell’app restano anche la riga vuota fra i paragrafi e i punti e numeri degli elenchi. Le indicazioni per l’ingresso vanno a capo anche nel PDF del biglietto. *Sito e app.*
- **Messaggio ai prenotati** — Scrive a chi ha un posto e, se vuole, a chi è in lista d'attesa, fino a tre messaggi al giorno per data. *Sito e app.*
- **Prenotazioni al banco o al telefono** — Registra prenotazioni di persone senza account; con un'email, i biglietti partono in allegato. *Sito e app.*
- **Domande a chi prenota** — Fino a cinque domande per data (testo, scelta, sì/no), con risposte in elenco, CSV e lista stampabile, cancellate dopo trenta giorni. *Sito e app.*
- **Lista d'ingresso stampabile** — PDF in ordine di cognome con caselle da spuntare, risposte e note. *Solo web.*
- **Gestione delle assenze** — Segna chi non si presenta; dopo tre assenze le prenotazioni gratuite si bloccano, con un conteggio che non permette a un solo locale di chiuderle ovunque. *Sito e app.*
- **Controllo ingressi senza rete** — Lo scanner riconosce i QR anche offline e invia le letture appena torna la connessione. *Sito e app.*
- **Personale di ingresso per singola data** — Assegna lo staff a una data con uno scanner limitato a quella sola data; si entra solo accettando l'invito. *Sito e app.*
- **Gestione eventi nell'app** — Staff, scanner, informazioni pratiche, costi e rapporti economici senza aprire il pannello web. *Android.*
- **Avviso «stessa sera in zona»** — Mentre sceglie l'orario, vede fino a sei eventi di altri entro 2 km alla stessa ora. *Solo web.*
- **Chiusure straordinarie e ferie** — Giorni di chiusura che prevalgono sugli orari settimanali, con «Chiuso fino al…» sul sito e un avviso a chi crea date in quei giorni. *Sito e app.*
- **Avvisi a chi segue il locale** — Pausa, chiusura o riapertura sulla scheda e come notifica ai follower, al massimo una volta a settimana. *Sito e app.*
- **Programma settimanale in PDF** — Scaricabile dal pannello e dalla scheda pubblica. *Solo web.*
- **Calendario ICS pubblico del locale** — Chiunque si iscrive al calendario del locale senza account. *Sito e app.*
- **Importazione da fogli CSV** — Carica date da un file o da un indirizzo, per esempio un Google Sheet pubblicato. *Solo web.*
- **Collaboratori su invito** — L'invito si accetta entro sette giorni; il referente lo reinvia o lo ritira e il collaboratore può lasciare il locale da solo. *Solo web.*
- **Modulo contatti con recapito confermato** — L'email di contatto del locale va confermata prima che il modulo compaia. *Sito e app.*

## Organizzatore

Gestisce la propria identità e gli eventi di competenza, anche quando si svolgono in locali o sedi differenti.

- **Profilo pubblico** — Presenta l’organizzazione e aggiorna descrizione, sito e recapiti pubblici.
- **Pannello dedicato** — Accede ai propri eventi e alle statistiche senza gestire contenuti di altri operatori.
- **Creazione degli eventi** — Inserisce titolo, descrizione, categoria, immagine e informazioni dell’iniziativa.
- **Importazione da Facebook** — Recupera i dati disponibili da un evento pubblico e li rivede prima dell’invio.
- **Eventi in sedi differenti** — Associa gli appuntamenti ai luoghi previsti mantenendo la propria identità di organizzatore.
- **Più date per lo stesso evento** — Aggiunge appuntamenti con orari e dettagli specifici alla medesima scheda.
- **Variazioni di data e luogo** — Aggiorna le singole occorrenze e gestisce lo stato degli appuntamenti.
- **Bozza e anteprima pubblica** — Prepara il contenuto e ne verifica la presentazione prima della revisione.
- **Invio alla redazione** — Richiede la pubblicazione dell’evento e sottopone a controllo le modifiche previste.
- **Collegamento ai biglietti** — Inserisce la destinazione esterna per informazioni e acquisto dei biglietti.
- **Statistiche del profilo** — Consulta le visite alla propria pagina pubblica e le interazioni registrate.
- **Statistiche dei singoli eventi** — Misura visualizzazioni, salvataggi e click sui contenuti di competenza.
- **Risultati distinti per appuntamento** — Analizza ogni data separatamente per confrontare iniziative e repliche.
- **Risultati della promozione** — Consulta i dati sponsorizzati disponibili per le campagne associate ai propri contenuti, con la stessa scheda di avanzamento dei locali per ogni abilitazione.
- **Gestione del ticketing autorizzato** — Accede agli strumenti di prenotazione delle date per cui possiede i permessi previsti.
- **Ingressi ed elenchi dei partecipanti** — Controlla i biglietti e scarica gli elenchi CSV degli appuntamenti che può gestire.
- **Visibilità attraverso la piattaforma** — Rende gli eventi disponibili a ricerca, mappa, calendari e persone che seguono l’organizzatore.
- **Promozione coordinata con la redazione** — Può far valorizzare i contenuti nei canali centrali e nelle iniziative sponsorizzate abilitate.
- **Registrazione senza sede** — Chi organizza senza un locale proprio si iscrive e diventa subito organizzatore attivo. *Solo web.*
- **Co-organizzatori** — Più organizzatori sullo stesso evento: tutti compaiono sulla scheda, lo modificano dal proprio pannello e lo trovano sulla propria pagina. *Gestione web, visibili nell'app.*
- **Acquisto di sponsorizzazioni** — Come per i locali, con copertura di tutti i propri eventi in qualunque locale. *Solo web.*
- **Analytics dedicati con esportazione** — La dashboard dei locali, limitata ai propri eventi. *Solo web.*
- **Recensioni e risposta pubblica** — Riceve recensioni moderate e può rispondere. *Risposta dal pannello web.*
- **Moderazione dei commenti sui propri eventi** — Come per i locali. *Solo web.*
- **Strumenti delle date** — Avviso «stessa sera in zona», domande a chi prenota, messaggi ai prenotati, prenotazioni al banco, lista stampabile e personale di ingresso su invito, come per i locali.
- **Calendario ICS pubblico** — Iscrizione al calendario dell'organizzatore senza account. *Sito e app.*
- **Pannello allineato ai locali** — Barra di scorciatoie e nome dell'organizzatore come titolo. *Solo web.*
- **Pagine aperte nell'app** — I link alle pagine degli organizzatori si aprono direttamente nell'app. *Android.*

## Personale agli ingressi

Controlla i biglietti all’ingresso delle date a cui è assegnato, con permessi limitati a quelle sole date.

- **Invito con accettazione** — Riceve un'email e accetta con il proprio account; fino ad allora la data lo mostra «in attesa». *Sito e app.*
- **Scanner limitato alla propria data** — Legge solo i biglietti di quella data, gestisce i doppi ingressi e riprova le letture rimaste senza risposta. *Sito e app.*
- **Ingressi senza rete** — L'elenco dei biglietti validi si scarica prima della serata; le letture restano cifrate sul telefono e si registrano con l'ora reale, anche se inviate entro 24 ore dalla fine. *Sito e app.*

## Utente e visitatore

Scopre cosa fare, organizza il proprio calendario e riceve aggiornamenti sugli appuntamenti di interesse.

- **Consultazione senza iscrizione** — Esplora il catalogo pubblico e le schede degli eventi anche senza un account.
- **Calendario in home** — Il mese in corso subito sotto l'apertura, accanto al prossimo evento e a quelli a seguire; per chi è collegato segue i suoi interessi. *Sito e app.*
- **Tre viste degli elenchi da telefono** — Card grandi, griglia a due colonne o elenco con la locandina a sinistra, sul sito e nell'app Android. Da ospite la scelta resta sul dispositivo; con un accesso sta nel profilo, come il tema, e vale sul sito, nell'app e sugli altri dispositivi.
- **Accesso unico** — Una sola pagina di accesso per visitatori, gestori, organizzatori e redazione; ognuno arriva nella sua area. Le pagine riservate mandano all'accesso o spiegano in tema perché non si possono aprire. *Solo web.*
- **«Stasera» passo per passo** — Una domanda per schermata, con avanzamento, risposte grandi e i risultati che si aggiornano mentre si sceglie. *Solo web.*
- **Oggi e questo fine settimana** — Trova selezioni temporali rapide per decidere cosa fare senza impostare ricerche complesse.
- **Eventi di stasera e in corso** — Accede agli appuntamenti rilevanti per il momento della consultazione.
- **Ricerca nel catalogo** — Cerca eventi e riferimenti di interesse attraverso i contenuti disponibili.
- **Filtri di scoperta** — Restringe i risultati per data, categoria, prezzo e caratteristiche previste dal catalogo.
- **Mappa interattiva** — Esplora eventi e luoghi geograficamente, aprendo i dettagli direttamente dalla mappa.
- **Vicino a te** — Scopre appuntamenti entro 5 o 10 chilometri dalla posizione utilizzata.
- **Posizione attuale con permesso** — Autorizza la geolocalizzazione per ottenere suggerimenti vicini al punto in cui si trova.
- **Ultima posizione ricordata** — Con scelta esplicita, conserva per sei mesi l’ultima posizione approssimata nel dispositivo e nell’account.
- **Continuità senza geolocalizzazione** — Usa la posizione ricordata quando quella attuale non è disponibile, altrimenti il riferimento abituale.
- **Controllo sulla posizione salvata** — Può dimenticare la posizione; viene conservato solo l’ultimo punto, non una cronologia degli spostamenti.
- **Scheda completa dell’evento** — Consulta descrizione, locandina, date, prezzi, luogo e informazioni pratiche.
- **Contatti e indicazioni** — Apre sito, telefono, email, biglietti e navigazione quando presenti nella scheda.
- **Condivisione degli eventi** — Condivide il collegamento all’appuntamento attraverso le opzioni del proprio dispositivo.
- **Previsioni per l’appuntamento** — Consulta le informazioni meteo quando disponibili per luogo e data dell’evento.
- **Eventi salvati** — Raccoglie le date interessanti in un elenco personale da ritrovare facilmente.
- **Salvataggi prima dell’accesso** — Conserva salvataggi locali e può ritrovarli nell’account dopo l’accesso.
- **Locali e organizzatori seguiti** — Segue le realtà preferite per ritrovare più facilmente la loro programmazione.
- **Categorie e tag seguiti** — Definisce gli argomenti che interessano e personalizza la scoperta dei contenuti.
- **Nuove date degli eventi seguiti** — Segue un evento per includere nel proprio percorso anche gli appuntamenti futuri.
- **Calendario personale** — Consulta gli appuntamenti salvati in una vista utile a organizzare le proprie uscite.
- **Esportazione in calendario** — Aggiunge appuntamenti attraverso file e collegamenti calendario supportati.
- **Abbonamento al calendario ICS** — Usa il feed personale nei calendari compatibili, come Apple Calendar e Outlook.
- **Collegamento a Google Calendar** — Sincronizza il calendario attraverso l’integrazione Google, dopo l’autorizzazione richiesta.
- **Calendario del telefono Android** — Aggiunge gli appuntamenti tramite le funzioni native e i permessi del dispositivo.
- **Promemoria degli eventi** — Riceve gli avvisi previsti prima degli appuntamenti di interesse.
- **Avvisi di annullamento e rinvio** — Viene informato delle variazioni importanti relative agli eventi interessati.
- **Riepiloghi personalizzati** — Riceve comunicazioni aggregate sugli interessi seguiti, secondo le preferenze impostate.
- **Newsletter del weekend** — Può aderire alle comunicazioni dedicate alle proposte del fine settimana.
- **Preferenze delle notifiche** — Configura le comunicazioni facoltative, con limiti e orari di quiete previsti.
- **Notifiche push ed email** — Riceve messaggi sui canali disponibili e autorizzati, con integrazione web e Android.
- **Prenotazione di un posto** — Prenota le date abilitate, gratuite o con pagamento all’ingresso, nei limiti disponibili.
- **Prenotazione senza ripetere i dati** — Chi prenota trova nome e cognome già compilati e con «Partecipo anch'io» è già fra i partecipanti. *Solo web.*
- **Biglietti con QR** — Riceve conferma e biglietti utilizzabili per il controllo dell’ingresso. Ogni biglietto ha la forma di un biglietto vero: locandina e titolo della serata, data in grande, luogo, nome di chi entra e una matrice staccata col QR e il numero; uguale sulla scheda della prenotazione, sul link condiviso, nel PDF e nell’app. *Sito e app.*
- **Lista di attesa e annullamento** — Si mette in attesa e gestisce la cancellazione entro i termini dell’appuntamento.
- **Recensioni dei locali** — Condivide una valutazione secondo le regole di accesso e moderazione previste.
- **Segnalazioni alla redazione** — Segnala problemi o informazioni da verificare attraverso i canali della piattaforma.
- **Account e dati personali** — Gestisce il profilo e dispone delle funzioni previste per esportare o cancellare i propri dati.
- **App Android** — Accede a scoperta, mappa, salvati e servizi personali da un’app nativa dedicata.
- **Sito adattabile e web app** — Usa il servizio da computer e smartphone con interfacce responsive e funzioni PWA.
- **Tema chiaro e scuro** — Sceglie una preferenza di aspetto e può cambiarla rapidamente durante la consultazione.
- **Promozioni riconoscibili** — Distingue gli spazi sponsorizzati dai contenuti editoriali grazie alle indicazioni dedicate.
- **Commenti agli eventi** — Risposte, reazioni e avvisi di risposta, per chi ha l'email confermata o il numero WhatsApp verificato dove la community lo richiede. *Sito e app.*
- **Commenti in stile social** — Avatar, fumetto, tempo relativo, reazioni e risposte rientrate sotto ogni commento. *Solo web.*
- **Community di persone verificate** — Profilo pubblico con avatar e nome utente, persone da seguire, bacheca e pubblicazione volontaria delle date salvate con un consiglio; blocchi e segnalazioni. *Sito e app.*
- **Verifica WhatsApp gratuita** — Con un codice o, nell'app, con un tocco; apre la partecipazione alla community. *Sito e app.*
- **«Ci vado» e chi partecipa** — Dichiara pubblicamente che andrà a una data e vede chi altro ci va, fra le persone verificate; resta privato finché non lo sceglie. *Sito e app.*
- **Sondaggi fra amici** — Propone più date di un evento a un gruppo fino a dieci persone, che votano entro una scadenza tramite link. *Solo web.*
- **Passaggi in auto gratuiti** — Offre o chiede un passaggio per una data, con chat privata, conferma del viaggio, recensioni del conducente e segnalazioni; riservato a maggiorenni con email e WhatsApp verificati. *Sito e app.*
- **Avvisi in tendina** — La campanella apre gli ultimi avvisi con «Segna tutti come letti». *Web; l'app ha il suo centro avvisi.*
- **Conteggi accanto ai filtri** — Ogni filtro di eventi e mappa mostra quanti risultati restano scegliendolo. *Solo web.*
- **Spinta della sera** — Una notifica nei giorni e all'orario scelti, con poche proposte vicine. *Sito e app.*
- **Prossime ore** — Gli eventi che iniziano a breve, con i posti realmente disponibili. *Sito e app.*
- **Avviso «quasi esaurito»** — Arriva quando restano pochi posti, se la capienza è nota. *Sito e app.*
- **Livelli di notifica per chi si segue** — Per ogni locale seguito: tutto, solo le novità o niente. *Sito e app.*
- **Proposte con il loro motivo** — Ogni proposta spiega perché compare, con accesso al catalogo completo. *Sito e app.*
- **Informazioni pratiche e costi della data** — Con filtri di budget sul costo totale. *Sito e app.*
- **Lista d'attesa con posizione** — Chi aspetta vede la propria posizione e chi è promosso conferma entro una scadenza. *Sito e app.*
- **Widget «stasera in città»** — Le serate di stasera sulla schermata del telefono. *Android.*
- **Calendario del telefono sempre allineato** — Le date salvate si aggiornano da sole, e quelle annullate o spostate spariscono. *Android.*
- **Galleria e video nella scheda** — Il video di YouTube o Vimeo si carica solo al tocco. *Sito e app.*
- **Ricerche salvate con avviso** — «Avvisami per nuovi eventi come questi», al massimo un avviso al giorno. *Sito e app.*
- **Raccolte condivisibili** — Elenchi di date con un nome, privati o condivisi con un link. *Sito e app.*
- **Calendari ICS pubblici** — Di locali, organizzatori e categorie, senza account. *Sito e app.*
- **Sovrapposizioni** — Al salvataggio avvisa se la data si accavalla con altre salvate o prenotate. *Sito e app.*
- **Riepilogo del lunedì** — Le date salvate della settimana, con quelle annullate o spostate. *Sito e app.*
- **«Aperto adesso»** — Badge e filtro «solo aperti», chiusure straordinarie comprese. *Sito e app.*
- **Nascondere un locale** — Lo toglie dagli elenchi personali. *Sito e app.*
- **Esigenze di accessibilità nel profilo** — Filtrano automaticamente gli elenchi, con «Mostra tutto». *Sito e app.*
- **Biglietti senza rete** — I biglietti restano consultabili nell'app anche offline. *Android.*
- **Un biglietto per partecipante** — Un link personale per ogni accompagnatore o l'invio per email. *Sito e app.*
- **Avvisi di disponibilità** — Quando una data esaurita si libera, quando aprono le prenotazioni e quando arriva la prossima edizione di un evento passato. *Sito e app.*
- **«Le mie serate»** — Le date a cui si è davvero entrati. *Sito e app.*
- **«Sono qui»** — Un link temporaneo per gli amici con la posizione del locale, mai quella del telefono. *Sito e app.*
- **Domande in prenotazione** — Le risposte date restano visibili nei propri biglietti. *Sito e app.*
- **Stato delle assenze** — «Non presentato» e conteggio delle assenze recenti nei biglietti. *Sito e app.*
- **Recensioni degli organizzatori e risposte dei gestori** — Visibili nelle schede. *Sito e app.*
- **Area personale riordinata** — Attività, persone e impostazioni raggruppate, un solo editor per notifiche e newsletter, nome utente modificabile una volta ogni trenta giorni. *Sito e app.*
- **Link che si aprono nell'app** — I link del sito aprono direttamente l'app; file e moduli solo web vanno al browser. *Android; per l'installazione da Play manca ancora l'impronta della firma di Play.*
- **«Resta collegato»** — L'accesso dura trenta giorni se lo si sceglie. *Solo web.*

## Potenzialità commerciali e condizioni di utilizzo

Il valore della piattaforma deriva dall’incontro fra offerta locale, pubblico interessato e risultati misurabili.

- **Dalla scoperta all’azione** — Un evento può portare a salvataggio, calendario, contatto, indicazioni, biglietti o prenotazione.
- **Un contenuto su più superfici** — La scheda alimenta catalogo, mappa, app e materiali social, riducendo il lavoro ripetitivo.
- **Ritorno del pubblico** — Interessi, calendari e notifiche creano occasioni di ritorno oltre la singola visita.
- **Visibilità a pagamento misurabile** — Le sponsorizzazioni associano esposizione e click a eventi e posizionamenti precisi, e si possono vendere a periodo o a numero di visualizzazioni con una consegna distribuita nel tempo.
- **Misure da interpretare correttamente** — Visualizzazioni e click non equivalgono a persone uniche, presenze fisiche o vendite certificate.
- **Integrazioni da abilitare** — Social, Google Calendar, push, email, WhatsApp e Stripe richiedono credenziali, permessi e servizi configurati.
- **Accesso ai contenuti Facebook** — L’importazione dipende dalla disponibilità pubblica dei dati e dalle limitazioni della fonte.
- **Distinzione fra ruoli social** — La redazione pubblica sui canali centrali; il locale genera e scarica materiali per i propri canali.
- **Perimetro dei pagamenti** — Il ticketing interno gestisce gratuità o pagamento all’ingresso, non un checkout online con carta. Con carta, tramite Stripe, si acquistano solo le sponsorizzazioni.
- **Misurazione e riservatezza** — I report riflettono gli eventi tracciati e i consensi applicabili, senza promettere copertura assoluta.

## Pianificato, non ancora realizzato

- **Biglietto in Google Wallet** — Il codice c’è su server e app, ma resta spento finché non ci sono le credenziali dell’emittente Google.
- **Sondaggi fra amici nell’app Android** — Oggi sono solo sul sito.
- **Apple Wallet** — Da fare.
- **Feedback dopo l’evento** — Recensioni verificate di chi è entrato davvero.
- **Cessione del biglietto** — Passare il proprio biglietto a un’altra persona.
- **Autenticazione a due fattori** — Per redazione e gestori: con l'accesso unico si potrà fare in un punto solo.
- **Accesso con Google o Apple.**
- **Sito in inglese.**
- **Artisti da seguire** — Oggi la lineup è solo testo.
- **Importazione da Excel** — Oggi si importano solo file CSV.
- **Sponsorizzazioni dei co-organizzatori** — Oggi la sponsorizzazione copre l’organizzatore principale.
- **Pacchetti a visualizzazioni comprabili dal gestore** — Oggi le visualizzazioni le vende la redazione; il listino del gestore resta a mesi.
- **Esplorazione e tetto di frequenza delle sponsorizzazioni** — Scelta dell'evento migliore dentro lo stesso committente e al massimo quattro apparizioni al giorno per chi è collegato.
- **Altri campi della pagina del locale** — Fascia di prezzo, lingue parlate, animali ammessi, prenotazione consigliata.
- **Personale di ingresso per locale** — Oggi si assegna data per data.
- **Chat e gruppi persistenti, pubblicità a click, aggiornamenti in tempo reale** — Rinviati.

## Registro degli aggiornamenti

- **20 novembre 2026** — 265 test nuovi che seguono dall’inizio alla fine passaggi, iscrizione e gestione degli eventi dei locali, con le correzioni trovate: si può chiedere di nuovo un posto dopo averlo ritirato, la ricerca per tappa ignora maiuscole e accenti, la pubblicazione programmata esce all’ora giusta, una data spostata non ricompare al giorno vecchio, «Duplica» funziona anche sugli eventi valutati dalla redazione, il motivo del rifiuto si legge nel pannello, accesso e profilo accettano indirizzi di ritorno e nomi lunghi.
- **20 novembre 2026 (notte)** — L'app Android ha la home del sito dal telefono: titolo con Oggi, Domani, Weekend e Gratis scritti in grande (senza numeri, anche sul sito), «Esplora», «Apri la mappa», «Trova la tua serata», il calendario del mese con gli eventi di ogni giorno, «Inizia tra poco» con «A seguire», «Organizzi eventi?» e i numeri della città. La barra in basso ha le stesse sette voci del sito, con Calendario; la scheda Calendario mostra il mese in griglia e le date del giorno scelto.
- **20 novembre 2026 (sera)** — La vista degli elenchi (card grandi, griglia, elenco compatto) si salva nel profilo come il tema: scelta sul sito dal telefono la ritrova l'app, e viceversa. Nell'app le tre viste ci sono ora anche in home, ricerca, salvati, calendario, locali, organizzatori e nella scheda dell'evento, con il selettore sopra gli elenchi e nel profilo.
- **20 novembre 2026 (pomeriggio)** — Biglietto ridisegnato come un biglietto vero, con la serata, la data in grande, il nome di chi entra e la matrice col QR, sul sito, nel link condiviso, nel PDF e nell'app Android. Nell'app le descrizioni tengono la riga vuota fra i paragrafi e i punti degli elenchi; anche i riassunti dei locali e le note di «ci vado» vanno a capo dove li si è scritti.
- **20 novembre 2026 (mattina)** — Il riepilogo del lunedì delle abilitazioni arriva anche a chi gestisce il locale o l'organizzazione; deciso di non prolungare le campagne rimaste sotto l'obiettivo, perché a evento passato non c'è più niente da promuovere. Il login del sito non dice più «Stai entrando nella redazione» a chi aveva aperto un pannello e poi ha lasciato perdere. Tolte le domande ripetute al database (N+1) trovate visitando 547 pagine di redazione, pannelli e sito.
- **20 novembre 2026** — Sponsorizzazioni a visualizzazioni con consegna distribuita nel tempo, «tutti gli eventi» come un posto solo, nuova sponsorizzazione in quattro passi con selettore degli eventi, avanzamento con barra e stato per redazione e gestori, avviso del mattino, misure contate dopo un secondo sullo schermo con un gettone usa e getta (anche nell'app Android 1.23.0), card che cambiano a ogni visita anche con la pagina in cache. Nella stessa giornata: il foglio della mappa solo a periodo e contato anche sul sito, il banner del sito che non conta più redazione e committente collegati, gettoni che il testo degli eventi non può più farsi firmare.
- **19 novembre 2026 (sera)** — Pannelli di redazione, locale e organizzatore più leggibili: date per esteso, selettore degli eventi con locandina, statistiche con una frase per ogni numero, menu comune, cambio del tema nei pannelli, ritorno indietro in tutte le pagine di servizio.
- **19 novembre 2026** — Pagine di servizio ridisegnate con lo stesso impianto, accesso unico, calendario e prossimo evento in home, tre viste degli elenchi da telefono, registrazione e rivendicazione del locale con account, pannello del locale semplificato, sponsorizzazione richiesta senza pagamento online, iscrizioni in evidenza nell'admin, commenti in stile social, «Stasera» passo per passo, prenotazione senza ripetere i dati.
- **18 novembre 2026** — Aggiunte le funzioni entrate dal 15 settembre: community verificata, «ci vado», commenti, sondaggi, passaggi in auto, analytics completi, link e QR tracciati, acquisto delle sponsorizzazioni, strumenti per le date (domande, messaggi, assenze, lista stampabile, personale su invito, ingressi senza rete), chiusure e avvisi dei locali, co-organizzatori, e una sezione per il pianificato.
- **15 settembre 2026** — Prima versione.

# Piano di crescita — blocchi approvati il 24 settembre 2026

Approvato da Fabio il 24 settembre 2026, come seguito della [relazione di prodotto](ROADMAP-PRODOTTO.md). Otto blocchi, ognuno con il proprio giro di progettazione, test e verifica in produzione. Sette sono rilasciabili da soli; il quinto è l'unica eccezione, perché i sondaggi fra amici partono dalla partecipazione introdotta dal quarto. L'ordine è per rapporto fra valore e rischio, non per importanza: un blocco più in basso non è meno approvato.

Ogni blocco parte da ciò che esiste davvero nel repository al 24 settembre 2026, verificato file per file. Dove una funzione risulta già presente è scritto: il rischio maggiore in questo progetto non è ricostruire da zero, è ricostruire ciò che c'è già.

## Stato al 24 settembre 2026

Tutti gli otto blocchi sono stati realizzati nella stessa giornata, ognuno con i propri test. Quello che è cambiato rispetto a come erano stati pensati, e che vale la pena ricordare:

- Il blocco 1 doveva rinunciare alla distanza reale. Non è stato necessario: accanto alla posizione cifrata ci sono ora le stesse coordinate arrotondate in due colonne interrogabili, e il raggio si calcola in SQL con il motore geografico che la home usava già.
- Il blocco 6 era stato scritto come «lista d'attesa da completare» ed è diventato molto più piccolo di così: la coda funzionava già, mancavano la finestra di conferma e la posizione mostrata a chi aspetta.
- Il blocco 7 è quasi tutto «mostrare ciò che c'era»: galleria e caratteristiche del locale erano compilabili e invisibili.
- Il biglietto nel portafoglio resta spento finché non esistono le credenziali dell'emittente Google: il server dichiara la funzione assente e l'app non disegna il pulsante. Servono quattro variabili d'ambiente e due passaggi amministrativi, descritti nel blocco 8.
- Non verificato su dispositivo reale: il widget Android è coperto dai test della sua logica, non dalla prova su un telefono.

## Quadro d'insieme

| # | Blocco | Per chi | Dimensione | Dipende da |
|---|---|---|---|---|
| 1 | Spinta della sera | Utenti | Piccola | — |
| 2 | Rapporto mensile al locale | Locali | Piccola | — |
| 3 | QR e canali liberi nei link tracciati | Locali | Piccola | — |
| 4 | «Ci vado» e chi partecipa | Utenti | Media | — |
| 5 | Sondaggi fra amici | Utenti | Media | 4 |
| 6 | Lista d'attesa: conferma a tempo e posizione | Locali e utenti | Media | — |
| 7 | Pagina del locale più ricca | Locali | Media | — |
| 8 | Widget Android e biglietto nel wallet | Utenti | Media | — |

Trasversale a tutti: **i numeri di ritorno** (quante persone tornano entro sette giorni, quante prenotano, quante si presentano). Non è un blocco a sé: ogni blocco che produce un dato nuovo lo espone nel pannello, con le definizioni accanto al numero.

---

## Blocco 1 — Spinta della sera

**Obiettivo.** Una notifica breve nel tardo pomeriggio dei giorni giusti: poche date di stasera, scelte fra quelle compatibili con la città e la zona della persona. Non un altro digest: meno righe, più vicino al momento in cui si decide.

**Cosa esiste già.** L'infrastruttura completa. `notifications:plan` (ogni ora) scrive in anticipo nella tabella `scheduled_notifications` con una chiave di deduplica unica; `notifications:send` (ogni cinque minuti) consegna con `FOR UPDATE SKIP LOCKED`, applica preferenze, ore di silenzio e tetto giornaliero, e sceglie il canale al momento dell'invio fra email, push web e push Android (`app/Services/Notifications/`). Il motore che sceglie gli eventi è `app/Services/Search/TonightDiscovery.php`, con la finestra «sera» già definita in `EventOccurrenceQuery::tonight()`.

**Cosa mancava, e oggi c'è.** Il tipo di notifica (`NotificationType::TonightNearby`), il suo ramo in `MessageFactory::build()`, l'interruttore in `NotificationPreferences` e la pianificazione in `DigestPlanner`. È servita una migration, che il piano non prevedeva: le due colonne approssimate della posizione.

**Decisioni di progetto.**

- ~~**Niente distanza reale nella prima versione.**~~ **Superata.** Era motivata così: la posizione salvata è cifrata, arrotondata a circa un chilometro, scade dopo sei mesi e non è interrogabile in SQL, quindi filtrare per raggio avrebbe voluto dire decifrare riga per riga a ogni invio. La soluzione è stata scrivere lo stesso valore già arrotondato anche in due colonne interrogabili, con lo stesso ciclo di vita: il raggio si calcola in SQL e la distanza è reale dalla prima versione. Chi non ha dato la posizione riceve la serata della propria città.
- **Conta nel tetto giornaliero** (`countsTowardDailyCap()` vero) e rispetta le ore di silenzio: è una notifica di iniziativa della piattaforma, esattamente ciò per cui quel tetto esiste.
- **Attivazione esplicita**, spenta per chi non la sceglie, con orario (predefinito le 18) e giorni (predefiniti venerdì e sabato) modificabili.
- **Nessun invio a vuoto:** sotto due proposte utili non parte niente, come fa già il digest quando non ha contenuto.
- Filtro eventi dimostrativi sempre applicato, come negli altri messaggi.

**Verifica.** Test sul fuso orario dell'utente rispetto a quello della città, sull'idempotenza della pianificazione, sul tetto giornaliero e sul silenzio, sul caso «nessun contenuto» e su chi non ha città di riferimento.

---

## Blocco 2 — Rapporto mensile al locale

**Obiettivo.** Una pagina via email il primo del mese: quante persone hanno visto, salvato, prenotato e quante si sono presentate, con il confronto sul mese precedente. Arriva senza che nessuno la chieda: è ciò che fa ricordare il servizio a chi lo paga.

**Cosa esiste già.** Buona parte dei numeri: `app/Services/Analytics/ManagementAnalytics.php` calcola viste, salvataggi, follower e sponsorizzazioni per locale nel periodo scelto, isolando i dati del solo locale. Prenotazioni e presenze non stanno lì e vanno contate dalle tabelle della biglietteria, che è ciò che fa il servizio scritto per questo rapporto. Il modello di email periodica esiste ed è collaudato: `app/Notifications/SponsorshipWeeklyReport.php` con il comando `SendSponsorshipReports` pianificato il lunedì mattina.

**Cosa manca.** Il comando mensile, la notifica dedicata ai referenti del locale e la preferenza per spegnerla.

**Decisioni di progetto.**

- Destinatari: i referenti del locale, non i collaboratori, coerentemente con i permessi già in vigore.
- Numeri calcolati fuori dalle ore di punta, come già fa il rapporto delle sponsorizzazioni; mai calcolati dentro una pagina pubblica.
- Ogni numero accompagnato dalla sua definizione e dal limite dichiarato: una visualizzazione non è una persona, un click non è una vendita.
- Nessun dato individuale, nessuna attività presso altri locali.
- Niente rapporto per il primo mese senza dati: meglio silenzio che una pagina di zeri.

**Verifica.** Isolamento fra locali (un locale non vede mai numeri di un altro), assenza di invio quando non c'è nulla da dire, corretto confronto col mese precedente a cavallo dell'ora legale.

---

## Blocco 3 — QR e canali liberi nei link tracciati

**Obiettivo.** Dare al locale un codice QR stampabile e la possibilità di nominare i propri canali («volantino», «radio», «partner X»), oltre ai quattro già previsti.

**Cosa esiste già.** I link brevi tracciati per evento e data, con conteggio giornaliero di condivisioni e click, filtro dei bot e rispetto del consenso: `app/Services/Analytics/EventShares.php`, modello `EventShareLink`, redirect `/s/{code}`.

**Cosa manca.** Tre cose, e una è un difetto: i canali sono un elenco chiuso di quattro voci scritte nel codice; non esiste alcun QR; e **la pagina del locale ha i pulsanti di condivisione non tracciati** (`resources/views/venues/show.blade.php:262` non riceve i link generati, a differenza della pagina evento). Inoltre i link tracciati esistono solo per gli eventi: un link «del locale» richiede un bersaglio nuovo.

**Decisioni di progetto.**

- Canali liberi con un'etichetta scelta dal locale, normalizzata, con un tetto al numero per evitare che la tabella diventi un raccoglitore.
- QR generato dallo stesso codice breve già esistente, quindi sempre coerente col conteggio, e scaricabile in formato vettoriale per la stampa.
- Generazione dei codici idempotente: la pagina del locale è servita da cache, e un codice diverso a ogni ricostruzione sporcherebbe i conteggi.
- La creazione dei canali resta al referente del locale, come le altre modifiche al profilo.

**Verifica.** Stesso codice a ogni rigenerazione della pagina, conteggi corretti per canale, isolamento fra locali, nessun dato sensibile nel redirect.

---

## Blocco 4 — «Ci vado» e chi partecipa

**Obiettivo.** Dichiarare in modo esplicito che si va a una data, e vedere chi altro ci va.

**Cosa esiste già, ed è più di quanto sembri.** Il salvataggio di una data ha già una visibilità per singola data (`SavedEvent.visibility`, privato o pubblico) e una procedura di pubblicazione volontaria collaudata: `Community::publication()` richiede WhatsApp verificato e un profilo, crea il trafiletto pubblico e solo allora rende pubblico il salvataggio; se la persona torna indietro, il trafiletto viene cancellato nella stessa transazione. `CommunityAccess` ricontrolla visibilità, blocchi e idoneità **a ogni lettura**, non solo alla pubblicazione. Il conteggio degli interessati esiste (`EventOccurrence::interestedCount()`) ed è già mostrato nelle schede.

**Cosa manca.** L'elenco aggregato delle persone su una singola data, e un gesto diretto sulla scheda evento: oggi per rendersi visibili si passa da una pagina separata di pubblicazione.

**Decisioni di progetto.**

- **Privato per difetto, sempre.** «Ci vado» pubblico è una scelta esplicita per quella data, mai un'impostazione globale, mai attivata da un salvataggio.
- Si riusa `SavedEvent.visibility` invece di introdurre un secondo concetto di partecipazione: due stati che dicono quasi la stessa cosa divergono al primo cambiamento.
- L'elenco si legge con lo stesso filtro di `CommunityAccess`, quindi chi blocca non compare e chi perde idoneità sparisce senza interventi.
- Il numero mostrato agli anonimi resta il conteggio complessivo; i nomi solo a chi è verificato.
- Nessuna notifica automatica a chi partecipa: sarebbe un canale di disturbo nuovo, e non è stato chiesto.

**Verifica.** Nessuna comparsa non voluta in elenco (il test parte da un salvataggio privato e verifica che resti invisibile), blocchi rispettati in entrambe le direzioni, elenco coerente dopo revoca della verifica WhatsApp.

---

## Blocco 5 — Sondaggi fra amici

**Obiettivo.** Proporre più date a un gruppo di persone — non solo due — e decidere insieme entro una scadenza.

**Cosa esiste già.** Niente di riusabile come contenitore di più date: le liste condivisibili non esistono, il calendario personale esportabile è privato e non condivisibile, e ogni trafiletto pubblico è legato a una sola data. Esistono però i due schemi tecnici che servono: i comandi idempotenti con chiave di richiesta e blocco ordinato (`app/Services/Carpool/CarpoolCommands.php`) e la visibilità granulare della community.

**Cosa manca.** Tutto il blocco: contenitore, inviti, voti, scadenza, esito.

**Decisioni di progetto.**

- **Partecipazione per link, senza obbligo di account per votare?** No: voto riservato a chi ha un account, altrimenti il sondaggio diventa un sistema di voto anonimo da moderare. L'invito però è un link, non una ricerca di persone dentro l'app.
- Da due a un numero limitato di partecipanti (dieci nella prima versione) e da due a cinque date proposte: sopra queste soglie non è più una decisione fra amici.
- Scadenza obbligatoria, al massimo quattordici giorni; alla scadenza il sondaggio si chiude e mostra l'esito, senza scegliere al posto delle persone.
- Chi propone può chiudere prima; chi partecipa può cambiare voto finché è aperto e può uscire.
- Nessuna visibilità pubblica: un sondaggio è privato al gruppo, e non produce trafiletti in bacheca.
- Scritture con chiave idempotente e blocco ordinato, come i passaggi: un doppio tocco su un telefono lento non deve produrre due voti.

**Verifica.** Un voto per persona per data anche sotto richieste concorrenti, scadenza rispettata, chi esce non compare nell'esito, nessuna fuga di dati verso chi ha solo il link ma non è invitato.

---

## Blocco 6 — Lista d'attesa: conferma a tempo e posizione

**Obiettivo.** Completare una funzione che esiste già, invece di riscriverla.

**Cosa esiste già, e funziona.** `TicketingService` gestisce la coda: chi arriva quando i posti sono finiti entra in lista, chi arriva dopo non scavalca chi aspetta nemmeno se nel frattempo si libera un posto, la promozione avviene per gruppi interi in ordine di arrivo e solo se il gruppo entra tutto, la cancellazione libera i posti e promuove subito, e un comando periodico ripassa le date con coda. Le email distinguono «sei in lista», «sei dentro», «annullato». I test coprono il non-oversell e l'ordine di arrivo.

**Cosa manca.**

- **Il tempo di conferma.** Oggi la promozione è immediata e definitiva: chi viene promosso ha il posto anche se non lo vedrà mai. Serve una finestra (ipotesi: dodici ore, comunque non oltre l'inizio) entro cui confermare, scaduta la quale il posto passa al successivo.
- **La posizione in coda**, che oggi la persona non vede da nessuna parte.
- La rinuncia esplicita esiste come annullamento, ma non è raccontata come tale nell'interfaccia.

**Decisioni di progetto.**

- La finestra di conferma è una proprietà della prenotazione promossa, non un lavoro pianificato a parte: alla scadenza il posto torna disponibile e la coda avanza dentro la stessa transazione che già esiste.
- Nessuna promozione silenziosa a ridosso dell'inizio: sotto una soglia di ore il posto resta libero per chi si presenta.
- La posizione in coda è approssimata e dichiarata tale: dipende dai gruppi davanti, non dal numero di persone.

**Verifica.** Il progetto ha già l'unico test di concorrenza reale multi-processo (per i passaggi, `tests/Integration/CarpoolConcurrencyTest.php`): la conferma a tempo va verificata con lo stesso metodo, perché è esattamente il punto in cui due processi possono assegnare lo stesso posto.

---

## Blocco 7 — Pagina del locale più ricca

**Obiettivo.** Dare al locale più campi e mostrare quelli che già compila.

**Cosa esiste già, e non si vede.** Questa è la scoperta che cambia il blocco: il locale può caricare **fino a trenta foto in galleria e nessuna pagina le mostra**; e le caratteristiche, i servizi, le fasce d'età e le note pratiche che compila non vengono mai stampati, perché il codice che li disegna gira solo per gli eventi (`app/Services/Seo/EditorialContent.php:42`). Sono dati già inseriti, già salvati, invisibili. Orari, contatti, come arrivare, accessibilità, scheda informativa e recensioni invece compaiono già.

**Cosa manca.** Nell'ordine: mostrare la galleria; far funzionare il blocco delle caratteristiche anche per i locali; poi, solo dopo, valutare campi nuovi.

**Decisioni di progetto.**

- Prima si rende visibile ciò che esiste, poi si aggiunge. Aggiungere campi nuovi mentre quelli vecchi restano invisibili significa chiedere lavoro a chi compila senza restituire niente.
- Campi nuovi candidati, da confermare con un locale vero prima di scriverli: fascia di prezzo tipica, lingue parlate, prenotazione consigliata, animali ammessi, periodo di chiusura.
- La pagina resta servita da cache: ogni aggiunta deve essere calcolabile senza query pesanti.

**Verifica.** Un locale con galleria e caratteristiche compilate le vede pubblicate; un locale senza non mostra sezioni vuote.

---

## Blocco 8 — Widget Android e biglietto nel wallet

**Obiettivo.** Essere presenti senza essere aperti, e togliere attrito davanti alla porta.

**Cosa esiste già.** Nulla in nessuna delle due direzioni: nel progetto non c'è alcun riferimento a wallet o pass, né web né Android, e l'app non ha widget né la libreria per farli. La versione minima di Android supportata non è un ostacolo.

**Cosa manca.** Un widget che mostri «stasera in città» aggiornato due volte al giorno, e un pass per il portafoglio digitale collegato al biglietto e al suo codice di ingresso.

**Decisioni di progetto.**

- Il widget legge gli stessi dati della schermata «stasera», non una via separata: due fonti divergono.
- Aggiornamento a bassa frequenza e rispettoso della batteria; nessun aggiornamento quando il telefono è in risparmio energetico.
- Il pass riporta solo ciò che serve all'ingresso; l'annullamento di una prenotazione deve invalidarlo, non lasciarlo valido nel portafoglio.
- Il pass richiede una registrazione presso il fornitore del portafoglio: è un passaggio amministrativo da mettere in conto, non solo codice.

**Verifica.** Widget con dati veri su un dispositivo reale, pass che scade e si invalida dopo un annullamento.

---

## Numeri di ritorno (trasversale)

Oggi si misura molto il lato locale e poco l'abitudine delle persone. Servono tre numeri, con la definizione accanto: quante persone tornano entro sette giorni dalla prima visita, quante arrivano a prenotare, quante si presentano davvero. Si aggiungono man mano che i blocchi sopra li producono, e valgono sia per capire se le funzioni servono sia per raccontare il prodotto a chi lo finanzia.

Regola che non cambia: i numeri aggregati non devono rendere riconoscibile una persona, e nessun numero va presentato come prova di un ricavo che non è stato misurato.

## Cosa resta fuori, e perché

- **Chat e gruppi**: rinviati, come nella relazione di prodotto. Costo di moderazione alto e nessuna domanda dimostrata.
- **Raccomandazioni automatiche**: prima servono dati migliori sul catalogo e sul ritorno, altrimenti si automatizza un errore.
- **Pubblicità a click**: richiede regole di conteggio, controlli antifrode e rendicontazione, quindi una decisione a sé.
- **Distanza reale nelle notifiche**: rimandata per il costo tecnico descritto nel blocco 1, non per principio.

# inCittà — relazione e priorità di prodotto

Data: 8 settembre 2026. Decisioni concordate con Fabio.

Questo documento guida le prossime implementazioni: non è una dichiarazione di
funzionalità già completate o distribuite. Prima di ogni intervento verificare lo
stato effettivo di Laravel, API, Android e produzione, riutilizzando quanto esiste.
La roadmap non autorizza da sola deploy, nuovi APK o l'attivazione di servizi a pagamento.

## Consolidamento al 10 settembre 2026

Il checkpoint [1.9.1](RELEASE-1.9.1.md) consolida temi web/Android, URL delle singole date, organizzatore distinto dalla sede, pubblicità contestuale ed esclusione dell’evento corrente, filtri mappa e controlli automatici. Backend chiaro, verifica locali, conversione proposte e programmazione social sono documentati nei rispettivi manuali.

Questi risultati coprono parti di P0, P1 e P2, **non completano interamente quelle fasi**. Restano prioritari upload e verifiche Play, test con account/servizi reali, prestazioni e debito di tipizzazione. I requisiti commerciali e le evoluzioni P3/P4 sotto restano invariati.

## Blocchi approvati il 24 settembre 2026

Fabio ha approvato otto blocchi di lavoro, descritti in dettaglio nel [piano di crescita](PIANO-CRESCITA-2026-09.md): spinta della sera, rapporto mensile al locale, QR e canali liberi nei link tracciati, «ci vado» con elenco di chi partecipa, sondaggi fra amici su più date, conferma a tempo e posizione nella lista d'attesa, pagina del locale più ricca, widget Android e biglietto nel wallet. Le tabelle di valutazione qui sotto restano valide come giudizio; le righe toccate da questa decisione riportano ora lo stato approvato.

Tre verifiche fatte sul codice il 24 settembre 2026 correggono altrettante impressioni che le tabelle potevano lasciare: la lista d'attesa del ticketing **è già implementata e collaudata** (coda in ordine di arrivo, promozione per gruppi interi, comando periodico, email dedicate) e le manca solo una finestra di conferma con la posizione mostrata a chi aspetta; la galleria fotografica del locale **si carica ma non è mostrata da nessuna pagina**, insieme a caratteristiche, fasce d'età e note pratiche del locale; i pulsanti di condivisione della pagina del locale **non passano dai link tracciati**, che oggi esistono solo per gli eventi e solo su quattro canali fissi.

## Obiettivo

Per gli utenti, inCittà deve essere il modo più semplice per scegliere cosa fare.
Per locali e organizzatori, deve portare persone e rendere misurabili i risultati.
Non costruire una raccolta indistinta di funzionalità: affidabilità, qualità del
catalogo e utilità quotidiana vengono prima di retargeting e intelligenza artificiale.

**La funzione più sottovalutata è “prima di andare”: informazioni complete e
attendibili che permettono di decidere senza cercare altrove.**

## Decisioni commerciali

- Piano PRO ammesso per i locali: alcuni pagano, altri ricevono una concessione
  gratuita dagli amministratori. Le autorizzazioni devono distinguere accesso al
  piano e pagamento, con condizioni e scadenze esplicite. Questa decisione supera
  l'esclusione del PRO inizialmente riportata nella relazione.
- Nessuna commissione su ticket o prenotazioni; mantenere il ticketing gratuito.
- Nessun abbonamento utenti.
- Consolidare il modello attuale di sponsorizzazione basato su autorizzazioni,
  periodi, pagamenti registrati e scadenze, comprese le autorizzazioni commerciali gratuite.
- Pubblicità a click: possibile evoluzione, **non subito**. Misurare i click non
  significa fatturarli. Un eventuale passaggio richiederà una decisione esplicita,
  regole di conteggio, controlli antifrode, budget e rendicontazione verificabile.
- Non peggiorare l'esperienza gratuita per vendere vantaggi: sponsorizzazioni
  discrete, riconoscibili e pertinenti, non presenti ovunque né al primo avvio.

## Roadmap approvata

### P0 — Consolidamento

Parità funzionale web/Android, organizzatori, ricerca, date e luoghi corretti,
calendario, biglietti e notifiche affidabili.

Azioni principali:

- Verificare i flussi reali, non solo l'esistenza di schermate o endpoint.
- Consolidare organizzatori separati dai locali, archivi su più locali/date e
  ricerca per nome e descrizione; in assenza di organizzatore separato, usare il locale ospitante.
- Mantenere coerente il luogo della singola data in mappe, schede, ticket e calendari.
- Verificare accesso, permessi, errori comprensibili e sincronizzazione fra sito e app.
- Consolidare salvati futuri/passati, collegamento calendario, aggiornamenti,
  annullamenti e revoca dei collegamenti personali.
- Rendere affidabili biglietti, check-in e notifiche di servizio.

Criteri di completamento: test dei percorsi critici, verifica delle autorizzazioni
tra utenti/locali/organizzatori e controlli su dispositivi Android; eventuali
differenze tra sorgenti e versioni distribuite devono essere documentate.

### P1 — Scelta rapida

“Stasera”, informazioni prima di andare, feed spiegabile e follow degli organizzatori.

Azioni principali:

- Proporre pochi eventi compatibili con orario, zona e budget, senza riempitivi.
- Mostrare perché un evento viene consigliato e permettere di cambiare preferenze
  o scegliere “Mostra tutto”. Le esclusioni esplicite prevalgono sulle inferenze.
- Estendere follow e preferenze di notifica agli organizzatori.
- Dare priorità ai dati strutturati “prima di andare”: accessibilità, ingresso,
  tessera, costi obbligatori, età minima, trasporti, parcheggio e condizioni meteo.
- Distinguere “non comunicato” da “no”; non inventare dati mancanti.

Criteri di completamento: l'utente può scegliere un evento e capire condizioni,
costo e luogo senza ambiguità; filtri e raccomandazioni usano dati realmente disponibili.

### P2 — Valore dimostrabile ai locali

Percorso dalla visualizzazione all'ingresso, link/QR per canale, report e
sponsorizzazioni discrete.

Azioni principali:

- Distinguere impression, click, salvataggi, prenotazioni confermate, annullamenti
  e check-in, con definizioni visibili e controlli sui duplicati.
- Creare link/QR per Instagram, locandine, partner e altri canali.
- Mostrare risultati di eventi e campagne a locali, organizzatori autorizzati e admin,
  rispettando la separazione dei dati e verificando grafici e tabelle responsive.
- Consolidare scadenze, autorizzazioni, pertinenza e frequenza delle sponsorizzazioni.
- Separare conteggio dei click e futura eventuale fatturazione a click.

Criteri di completamento: report riconciliabili con prenotazioni e presenze, limiti
di attribuzione dichiarati e nessuna promessa di ricavi non misurati. Un click
non prova una vendita; ricavo attribuito non equivale a ricavo incrementale.

### P3 — Partecipazione e ritorno

Lista d'attesa, raccolte condivise e sondaggi.

Azioni principali:

- Lista d'attesa per eventi con posti limitati: disponibilità riservata per un
  tempo definito, conferma, scadenza e passaggio al successivo.
- Gestire cancellazioni, concorrenza sulle prenotazioni e rinuncia alla lista.
- Raccolte di eventi condivisibili tramite link e votazioni semplici con scadenza.
- Chat e gruppi restano rinviati. Il 18 settembre 2026 il proprietario ha autorizzato follow tra persone e pubblicazione volontaria di singole date da parte degli utenti WhatsApp verificati: vedere [piano Community](PIANO-UTENTI-VERIFICATI-SOCIAL.md).

Criteri di completamento: nessuna sovraprenotazione o promessa dello stesso posto
a più utenti; condivisione volontaria e raccolte private protette correttamente.

### P4 — Crescita

Campagne controllate, automazioni e analisi della domanda; solo dopo previsioni e concierge.

Azioni principali:

- Campagne con anteprima, destinatari stimati, limiti d'invio e disiscrizione.
- Automazioni inizialmente propositive e confermabili, senza spese automatiche implicite.
- Analizzare ricerche senza risultati e richieste dichiarate dagli utenti.
- Mostrare dati aggregati, numerosità e incertezza; non equiparare ricerche a persone.
- Valutare previsioni e concierge solo dopo aver consolidato dati e qualità del catalogo.

Criteri di completamento: evidenza di utilità reale, controllo dell'utente e del
gestore, nessuna informazione inventata e nessuna promessa predittiva senza dati adeguati.

## Valutazione delle 25 proposte per locali e organizzatori

Le priorità indicano la fase di valutazione o consolidamento, non una promessa di
implementare automaticamente ogni proposta. “Rimandata” richiede una nuova decisione.

| # | Proposta | Priorità e valutazione |
|---|---|---|
| 1 | Dashboard analytics | P2. Centrale: separare visibilità, interesse, prenotazioni e presenze. **Dal 24/09/2026** se ne ricava anche il rapporto mensile via email al locale. |
| 2 | ROI per evento | P2, limitatamente ai dati misurabili. Nel ticketing gratuito usare costo per prenotazione/presenza, senza inventare ricavi. |
| 3 | Analytics pubblico | P2/P4. Preferenze aggregate, senza esporre profili individuali né raccogliere dati inutili. |
| 4 | Benchmark locale | P4. Solo con campioni sufficienti e comparabili per categoria, periodo e investimento. |
| 5 | Heatmap della domanda | P4. Prima ricerche senza risultati e fasce orarie; geografia solo con dati adeguati. |
| 6 | Previsione affluenza AI | Rimandata. Prima storico e andamento prenotazioni; poi eventuali stime con incertezza. |
| 7 | Promozioni sponsorizzate | P2. Consolidare il modello a periodo, mantenendo discrezione e pertinenza. |
| 8 | Boost geolocalizzato | P2/P4. Iniziare da città/zona scelta; posizione precisa solo quando disponibile e autorizzata. |
| 9 | Targeting utenti | P2. Preferenze esplicite predominanti; deduzioni secondarie e controllabili, mai contro esclusioni esplicite. |
| 10 | Retargeting | Rimandato, non prioritario. Eventuale adesione e frequenza controllate; escludere chi ha già prenotato. |
| 11 | Codici promozionali | Rimandati per gli sconti. Nel ticketing gratuito valutare solo accessi o quote riservate con utilità concreta. |
| 12 | Flash promo | P4. Vantaggi, scadenze e disponibilità reali; niente falsa urgenza o push indiscriminati. |
| 13 | CRM clienti | P4, essenziale. Prenotazioni, presenze e autorizzazioni ai contatti; nessuno storico trasversale tra locali. |
| 14 | Segmentazione audience | P4. Segmenti comprensibili, evitando stime arbitrarie del valore economico delle persone. |
| 15 | Campagne push/email | P4. Anteprima, limiti, disiscrizione, controlli antiabuso e corretta gestione delle autorizzazioni. |
| 16 | Follower | P1. Locali e organizzatori, con preferenze di notifica distinte dal semplice follow. |
| 17 | Automazioni marketing | P4. Prima suggerimenti confermabili; regole sulla capienza solo se dichiarata. |
| 18 | AI Content Studio | Template social da consolidare; assistenza AI rimandata. Mai inventare prezzi, orari o accessibilità. |
| 19 | QR/link tracciati | **Approvato 24/09/2026.** I link esistono per gli eventi su quattro canali fissi; mancano QR, canali liberi e tracciamento sulla pagina del locale. |
| 20 | Affiliazioni/creator | Nessuna commissione automatica nel modello approvato. Link identificativi per partner possibili in P2. |
| 21 | Check-in QR | P0/P2. Affidabilità, doppio ingresso, ruoli staff e comportamento senza rete. |
| 22 | Lista d'attesa | **Approvata 24/09/2026.** Avanzamento ordinato già in produzione; da aggiungere l'assegnazione temporanea con scadenza. |
| 23 | Upsell | Rimandato. Inventario, pacchetti e condizioni commerciali esulano dal ticketing gratuito essenziale. |
| 24 | Profilo premium/verificato | PRO ammesso per locali paganti o autorizzati gratuitamente dagli admin. La verifica identità resta separata dal piano e dal pagamento. |
| 25 | Raccomandazioni B2B | P4 e oltre. Prima evidenze aggregate; suggerimenti predittivi solo se motivabili. |

## Valutazione delle 25 proposte per utenti

| # | Proposta | Priorità e valutazione |
|---|---|---|
| 1 | Feed Per Te | P0/P1. Spiegabile, modificabile e con “Mostra tutto”; scegliere, non scorrere senza fine. |
| 2 | Cosa faccio stasera? | P1. Poche proposte adatte a orario, budget e zona. |
| 3 | Mappa eventi | P0. Centratura, filtri e schede affidabili; non promettere affluenza o code in tempo reale. |
| 4 | Filtri potenti | P0/P1. Quando, dove, prezzo e categoria subito; altri filtri progressivi e alimentati da dati reali. |
| 5 | Mood | Sperimentazione dopo P1. Poche etichette curate, distinte dalle categorie. |
| 6 | Concierge AI | Rimandato a dopo P4. Tradurre richieste in criteri sul catalogo reale, senza inventare disponibilità. |
| 7 | Follow locali/artisti/organizzatori | P1 per locali e organizzatori. Artisti da valutare come entità distinta, non testo ambiguo. |
| 8 | Salva evento | P0. Data corretta, passati/futuri e sincronizzazione affidabile. |
| 9 | Alert intelligenti | P0/P1. Prima spostamenti e annullamenti; quasi esaurito solo con posti residui conosciuti. |
| 10 | Last minute | P1. Prossime ore e disponibilità reali, utile anche senza sconti. |
| 11 | Drop esclusivi | Rimandati. Richiedono vantaggi reali; non nascondere informazioni essenziali per forzare l'installazione. |
| 12 | Waitlist | **Approvata 24/09/2026.** Il sistema esiste già e funziona; restano finestra di conferma, posizione in coda e rinuncia raccontata. |
| 13 | Prezzo finale trasparente | P1. Ingresso, consumazione obbligatoria, tessera e altri costi distinti. |
| 14 | Chi ci va? | **Approvato 24/09/2026.** Resta privato per difetto: si riusa la visibilità per singola data già esistente, con elenco visibile ai soli verificati. |
| 15 | Gruppi | **Approvato 24/09/2026** nella sola forma del sondaggio fra più amici. Chat e gruppi persistenti restano fuori. |
| 16 | Sondaggio evento | **Approvato 24/09/2026**, con più di due partecipanti, invito per link, voto riservato a chi ha un account e scadenza obbligatoria. |
| 17 | Matching gusti gruppo | Dopo P3. Prima vincoli comuni e regole semplici, senza necessità di AI. |
| 18 | Calendario personale | P0. Collegamenti, aggiornamenti, annullamenti e revoca affidabili. |
| 19 | Wallet biglietti | **Approvato 24/09/2026.** Nel progetto non esiste oggi alcun pass per portafoglio digitale: è lavoro nuovo, compresa la registrazione presso il fornitore. |
| 20 | Loyalty/punti | Rimandati. Servono vantaggi finanziati e controlli contro gli abusi. |
| 21 | Badge/livelli | Bassa priorità. Non premiare click, spam o attività prive di valore. |
| 22 | Recensioni verificate | Dopo P3. Presenza verificata, distinzione evento/locale/organizzazione e moderazione. |
| 23 | Stories/video | Rimandati. Prima contenuti dei gestori; contenuti utenti richiedono moderazione e gestione dei diritti. |
| 24 | Prima di andare | P1, priorità alta. Dati strutturati attendibili e informazioni mancanti esplicitate. |
| 25 | Event Roulette | Esperimento dopo P1. Scelta tra eventi compatibili, non un modo mascherato per mostrare sempre sponsor. |

## Regole trasversali

- Distinguere personalizzazione dei contenuti e profilazione pubblicitaria:
  desiderare eventi pertinenti non equivale ad autorizzare qualsiasi marketing.
- Gli interessi dedotti devono essere controllabili e azzerabili; quelli espliciti
  prevalgono. Non ogni categoria è automaticamente adatta al targeting pubblicitario.
- Non esporre ai gestori dati individuali o attività presso altri locali.
- Valutare base giuridica, informative, autorizzazioni e tempi di conservazione
  prima di attivare nuovi trattamenti, soprattutto profilazione e campagne.
- Proteggere i piccoli gruppi nei report aggregati: evitare segmenti che rendano
  riconoscibile una persona. Definire le soglie prima della distribuzione.
- Rispettare capienze facoltative: non applicare indicatori percentuali o previsioni
  di esaurimento quando il dato non esiste.
- Tenere separate domanda osservata e domanda dichiarata. Molte ricerche non
  dimostrano altrettante persone intenzionate a partecipare.
- Nessun sistema AI deve inventare eventi, prezzi, accessibilità o disponibilità.

## Riferimenti della relazione

I riferimenti confermano esempi e rischi, non impongono di replicare altri prodotti:

- [Eventbrite — report dei link tracciati](https://www.eventbrite.com/help/en-us/articles/591670/download-a-tracking-links-report/).
- [Eventbrite — traffico e conversioni](https://www.eventbrite.com/help/en-us/articles/840658/view-your-traffic-and-conversion-report/).
- [EDPB — linee guida sul targeting degli utenti dei social media](https://www.edpb.europa.eu/documents/guideline/guidelines-82020-on-the-targeting-of-social-media-users_en).

## Sintesi decisionale

La combinazione prioritaria è **scelta semplice per l'utente e partecipazioni
misurabili per il locale**. Consolidare prima di ampliare; valorizzare “prima di
andare”; non iniziare da retargeting o AI. Monetizzazione tramite sponsorizzazioni,
con PRO per locali paganti o autorizzati gratuitamente, senza commissioni o
abbonamenti utenti. Il pagamento a click resta una
possibilità futura, distinta dal tracciamento necessario già oggi.

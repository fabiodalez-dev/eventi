# Funzioni dei repository car pooling e proposta per inCittà

Verifica del 18 settembre 2026, richiesta dal proprietario per preservare le opzioni utili delle applicazioni complete. Documento parte del [piano](PIANO-CARPOOLING-COMMUNITY.md), ancora non implementato.

Sono stati letti modelli, rotte, servizi, moduli frontend e test presenti nei repository. Non sono state avviate le applicazioni terze né validate tutte le loro funzioni in esecuzione: «presente» significa riscontro nel codice, non certificazione di funzionamento. Nessun codice applicativo è stato copiato nel progetto.

Revisioni esaminate:

- Carpoolear backend: `2f8bf2cb3b3b39ef5d331e08165c2572801fc1f1`.
- Carpoolear frontend: `92849408469a011d9745d3aa411edb4f8a777d9b`.
- rimadjamaa/CarPooling: `560cb031524c1fc40871b5a790c04aca3d8771ab`.

## 1. Confronto funzionale

**Prima versione** significa compresa nel perimetro proposto da approvare, non già realizzata. **Adattata** significa che il beneficio viene mantenuto con le regole del prodotto. **Successiva/esclusa** ha una motivazione esplicita.

| Funzione riscontrata | Evidenza | Proposta per inCittà |
|---|---|---|
| Offrire un viaggio o dichiarare che si cerca | C1, C2: `is_passenger`, scelta del ruolo nel wizard | **Prima versione:** offerte + ricerca; annuncio «Cerco un passaggio» facoltativo e scadente per data/tratta |
| Partenza, arrivo, data, ora | C1, C2, R1 | **Prima versione:** sempre legati a una data evento, fuso locale mostrato, ritorno notturno esplicito |
| Tappe intermedie | C2: step STOPS; C3: punti viaggio | **Prima versione:** fino a tre tappe pubbliche concordabili; andata con salita a una tappa e arrivo all'evento, ritorno con partenza dall'evento e discesa a una tappa |
| Mappa del percorso e ricerca geografica | C3: path/OSRM e ricerca origine/destinazione | **Adattata:** mappa dei punti dichiarati e filtro per zona/comune; indicazioni nell'app mappe. Ottimizzazione stradale/routing esterno e prezzi basati su distanza non necessari |
| Viaggio di ritorno collegato | C1: `return_trip_id` | **Prima versione:** «Crea anche il ritorno» precompila una seconda offerta; capacità, richieste e accettazioni indipendenti |
| Posti totali/residui e variazione capacità | C1, C4, C5, R1 | **Prima versione:** richieste multiposto, capacità atomica e divieto di ridurre sotto i confermati |
| Richieste in attesa, accettazione/rifiuto/cancellazione | C4, C5 | **Prima versione:** tab Da gestire con filtro/stato e azioni esplicite; richiesta non equivale a posto assegnato |
| Viaggi futuri, richiesti e storico | C5, R2 | **Prima versione:** I miei passaggi, Offro/Cerco/Storico, stato aggiornato e collegamento alla chat |
| Filtri date, posti e disponibilità | C3, R2 | **Prima versione:** singola data scelta, tratta, fascia oraria, zona/tappa, posti; escludere normalmente offerte senza capacità sufficiente |
| Preferenza fumo | C1, C2, C3 | **Prima versione:** campo strutturato «Auto non fumatori», filtro corrispondente |
| Animali | C1, C2, C3 | **Prima versione:** ammessi / da concordare / non previsti; eventuali diritti relativi ad animali di assistenza da valutare nei termini, senza raccogliere diagnosi |
| Spazio bagagli | R1, R2 | **Prima versione:** nessuno/zaino/piccolo bagaglio/da concordare, nota breve e filtro |
| Comfort sedile posteriore | C1: `rear_max_two_passengers` | **Prima versione:** indicazione facoltativa «Massimo due persone dietro», coerente con posti dichiarati e senza promessa di verifica del veicolo |
| Bambini/minori ammessi | C1, C3: `allow_kids` | **Esclusa:** il proprietario ha scelto solo maggiorenni, anche tra gli accompagnatori |
| Filtro per genere | R1, R2 | **Escluso dal perimetro proposto:** nessuna necessità espressa; introdurrebbe nuove categorie di dati/regole da valutare |
| Veicoli dell'utente | C6: marca, modello, colore, anno, targa | **Adattata:** garage privato facoltativo con nome/modello/colore e posti passeggeri; selezione sull'offerta. Niente targa o documenti obbligatori; dettagli identificativi solo agli accettati |
| Modelli di creazione riutilizzabili | C7, C2 | **Prima versione:** salva impostazioni e «Ripeti per un'altra data»; genera bozza, mai richieste/passeggeri/chat già accettati |
| Ricorrenza settimanale | C1, C2: schedule/giorni | **Adattata:** duplicazione guidata sulle date esplicitamente selezionate dell'evento; nessun viaggio perpetuo svincolato dal calendario |
| Privacy pubblica/amici/amici di amici | C1, C3 | **Adattata:** offerte visibili agli idonei; dati precisi solo ai partecipanti; rispetto blocchi. Il profilo social resta indipendente, niente nuovo sistema di amicizie |
| Accettazione automatica degli amici | C1, C2 | **Esclusa:** contraddice la conferma manuale richiesta dal proprietario |
| Invito amici e alert sui viaggi degli amici | C8 | **Adattata:** condivisione volontaria di un link protetto e suggerimenti nel contesto; niente messaggi massivi ai follower o consenso presunto |
| Ricerche salvate e avviso nuovi viaggi | C9 | **Prima versione:** «Avvisami se compare un passaggio» con tratta/zona/posti/preferenze; scadenza alla partenza e disiscrizione semplice |
| Avviso quando tornano posti | Estensione utile del meccanismo C9; non presentata come funzione upstream accertata | **Prima versione:** sottoscrizione alla disponibilità di un'offerta piena; nessuna riserva o accettazione automatica |
| Promemoria richieste senza risposta | C10 | **Prima versione:** promemoria aggregato al conducente con limite di frequenza; non spam ad ogni richiesta |
| Promemoria partenza | C10 | **Prima versione:** proposti 24 ore e 2 ore prima, con deduplica e controllo dello stato corrente |
| Chat e non letti | C8, C5 | **Adattata:** chat privata per richiesta accettata, archivio Messaggi e badge unificati; non importare chat anticipata o gruppi |
| Preferenze notifiche conversazione | C8: `/conversations/{id}/notifications` | **Prima versione:** silenzia push della chat, mantenendo messaggi e contatori nell'app; non sopprime avvisi di cancellazione nell'archivio |
| Recensioni viaggio e repliche | C1, C8 | **Adattata:** feedback privato dopo la tratta e segnalazione strutturata visibili allo staff. Stelle pubbliche rinviate: evitare reputazione automatica basata su presenze non verificate |
| Riferimenti/referenze tra persone | C8 | **Successiva:** il social ha già profili e follow; referenze pubbliche richiedono regole antiritorsione e moderazione ulteriori |
| Posizione in tempo reale e link pubblico | C8: live share | **Esclusa dalla prima versione:** non necessaria per coordinarsi e introduce posizione precisa, consenso, scadenze e uso in background |
| Verifica documentale conducenti | C8, C11 | **Successiva su decisione esplicita:** non confondere WhatsApp con verifica di patente o identità; nessuna acquisizione implicita di documenti |
| Prezzi, contributi spese e controllo contributi eccessivi | C1, C3, C11, R1 | **Esclusi:** gratuità integrale. Riprendere l'utilità del controllo abuso tramite segnalazione «mi è stato chiesto denaro» |
| Pagamenti/MercadoPago/donazioni/campagne | C8, C11 | **Esclusi dal carpool:** non servono al passaggio gratuito |
| Admin utenti/viaggi/ban/permessi | C11, R3 | **Prima versione:** ricerca incrociata utenti-eventi-viaggi-richieste, sospensioni per ambito e motivo obbligatorio |
| Admin assistenza con assegnazione e stati | C12 | **Prima versione:** casi assegnabili, priorità, note interne, risposte all'utente, riapertura e avvisi; gestione concorrente senza sovrascritture |
| Risposte amministrative riutilizzabili | C8: support reply templates | **Prima versione:** modelli revisionabili di risposta, sempre inviati da un operatore identificato e nel caso pertinente |
| Registro azioni amministrative | C11, C8 | **Prima versione:** audit anche di lettura chat/IP/export, attore reale e motivazione; nessuna impersonificazione per aggirarlo |
| Dashboard e statistiche | C11, C8 | **Prima versione:** offerte/richieste/esiti, tempi risposta, casi aperti, segnalazioni denaro, errori notifiche, retention; aggregazione rispettosa della privacy |
| Modalità manutenzione | C11 | **Adattata:** bloccare nuove richieste conservando cancellazioni, conversazioni autorizzate e assistenza ai viaggi concordati |
| Distanza/CO₂ e gamification/badge | C1, C11 | **Successiva:** richiedono metodo e dati attendibili; i badge iniziali sono stati/verifica/notifiche, senza affermare risparmi non misurati |

## 2. Opzioni aggiunte al piano dopo il confronto

Il confronto amplia concretamente il primo perimetro con: tappe, preferenze, garage privato, duplicazione/modelli, annunci «cerco» facoltativi, ricerche con avvisi, avviso posti tornati disponibili, promemoria, silenziamento push chat, feedback privato e assistenza amministrativa più completa. Per ciascuna valgono gli stessi requisiti su verifiche, età, gratuità, privacy, test e parità Android.

Le tappe non introducono subito inventario per segmenti: per una tratta diretta allo stesso evento, ogni richiesta consuma i suoi posti sull'intera offerta. È una regola conservativa, esplicita e verificabile; non si vende due volte lo stesso posto ipotizzando salite/discese non coordinate. Andata e ritorno hanno inventari distinti.

Gli annunci «cerco» non aprono chat: un conducente risponde proponendo una propria offerta pertinente; il richiedente la esamina e invia la normale richiesta di posti, che resta da accettare. Limiti e deduplica impediscono inviti ripetuti.

Il feedback privato distingue «ho viaggiato», «ho rinunciato», «l'altra persona non si è presentata» e «segnalo un problema». Sono dichiarazioni, non fatti automaticamente dimostrati; nessuna penalizzazione automatica o accesso ai messaggi dell'altra persona.

## 3. Perché non importare l'intera applicazione

La completezza funzionale di Carpoolear è utile e guida questa matrice. Importare l'intero backend porterebbe però utenti/JWT, viaggi generici, conversazioni, notifiche, pannello amministrativo e schema duplicati rispetto a quelli già attivi in inCittà. Servirebbe riconciliare Laravel 12/PHP 8.5 con Laravel 13/PHP 8.4, il modello di amicizia, la singola data evento e le licenze discordanti nei metadati.

L'alternativa di un servizio Carpoolear separato richiederebbe SSO, sincronizzazione verifiche/blocchi/cancellazioni, notifiche duplicate e transazioni distribuite per lo stesso utente. È un'opzione possibile per un prodotto autonomo, ma aggiunge complessità qui e non conserva automaticamente le regole richieste.

Il repository rimadjamaa è più piccolo, ma non fornisce la stessa maturità: il percorso di prenotazione letto usa una rotta GET con utente/posti nell'URL, e il controller decrementa i posti senza mostrare una transazione con lock; gli handler `create/edit/update` di RideController sono in parte vuoti. Non adottare queste scorciatoie. Riprendere ricerca, bagagli e gestione lista con le nostre policy e transazioni. Non è un audit completo di vulnerabilità, ma evidenza sufficiente per non considerarlo pronto da innestare.

Scelta proposta: **stesse opzioni utili, integrate nel dominio eventi**, riusando Musonza per chat, Spatie per permessi/audit e Laravel Notifications/FCM/Web Push per consegna. Le funzioni scartate sono motivate nella matrice, non perse perché si è scelto uno stack diverso.

## 4. Fonti di codice

- **C1:** [Trip e campi viaggio](https://github.com/STS-Rosario/carpoolear_backend/blob/2f8bf2cb3b3b39ef5d331e08165c2572801fc1f1/app/Models/Trip.php).
- **C2:** [Wizard creazione](https://github.com/STS-Rosario/carpoolear/blob/92849408469a011d9745d3aa411edb4f8a777d9b/src/components/views/NewTripCreationWizard.vue).
- **C3:** [Ricerca, percorso e tappe](https://github.com/STS-Rosario/carpoolear_backend/blob/2f8bf2cb3b3b39ef5d331e08165c2572801fc1f1/app/Repository/TripRepository.php), [TripPoint](https://github.com/STS-Rosario/carpoolear_backend/blob/2f8bf2cb3b3b39ef5d331e08165c2572801fc1f1/app/Models/TripPoint.php).
- **C4:** [Gestione richieste](https://github.com/STS-Rosario/carpoolear_backend/blob/2f8bf2cb3b3b39ef5d331e08165c2572801fc1f1/app/Services/Logic/PassengersManager.php).
- **C5:** [I miei viaggi](https://github.com/STS-Rosario/carpoolear/blob/92849408469a011d9745d3aa411edb4f8a777d9b/src/components/views/MyTrips.vue), [Richieste pendenti](https://github.com/STS-Rosario/carpoolear/blob/92849408469a011d9745d3aa411edb4f8a777d9b/src/components/PendingRequest.vue).
- **C6:** [Veicoli](https://github.com/STS-Rosario/carpoolear_backend/blob/2f8bf2cb3b3b39ef5d331e08165c2572801fc1f1/app/Models/Car.php).
- **C7:** [Modelli riutilizzabili](https://github.com/STS-Rosario/carpoolear_backend/blob/2f8bf2cb3b3b39ef5d331e08165c2572801fc1f1/app/Http/Controllers/Api/v1/TripCreationTemplateController.php).
- **C8:** [Rotte e operazioni esposte](https://github.com/STS-Rosario/carpoolear_backend/blob/2f8bf2cb3b3b39ef5d331e08165c2572801fc1f1/routes/api.php).
- **C9:** [Ricerche salvate](https://github.com/STS-Rosario/carpoolear_backend/blob/2f8bf2cb3b3b39ef5d331e08165c2572801fc1f1/app/Models/Subscription.php), [Avvisi di corrispondenza](https://github.com/STS-Rosario/carpoolear_backend/blob/2f8bf2cb3b3b39ef5d331e08165c2572801fc1f1/app/Listeners/Subscriptions/OnNewTrip.php).
- **C10:** [Promemoria richiesta](https://github.com/STS-Rosario/carpoolear_backend/blob/2f8bf2cb3b3b39ef5d331e08165c2572801fc1f1/app/Console/Commands/RequestRemainder.php), [Promemoria viaggio](https://github.com/STS-Rosario/carpoolear_backend/blob/2f8bf2cb3b3b39ef5d331e08165c2572801fc1f1/app/Console/Commands/TripRemainder.php).
- **C11:** [Permessi amministrativi](https://github.com/STS-Rosario/carpoolear_backend/blob/2f8bf2cb3b3b39ef5d331e08165c2572801fc1f1/app/Admin/AdminPermission.php).
- **C12:** [Gestione assistenza](https://github.com/STS-Rosario/carpoolear_backend/blob/2f8bf2cb3b3b39ef5d331e08165c2572801fc1f1/app/Services/SupportTicketService.php).
- **R1:** [Creazione e gestione offerta rimadjamaa](https://github.com/rimadjamaa/CarPooling/blob/560cb031524c1fc40871b5a790c04aca3d8771ab/app/Http/Controllers/RideController.php).
- **R2:** [Ricerca e prenotazione rimadjamaa](https://github.com/rimadjamaa/CarPooling/blob/560cb031524c1fc40871b5a790c04aca3d8771ab/app/Http/Controllers/UserController.php), [rotte](https://github.com/rimadjamaa/CarPooling/blob/560cb031524c1fc40871b5a790c04aca3d8771ab/routes/web.php).
- **R3:** [Gestione admin rimadjamaa](https://github.com/rimadjamaa/CarPooling/blob/560cb031524c1fc40871b5a790c04aca3d8771ab/app/Http/Controllers/AdminController.php).

# Migliorare il sistema di sponsorizzazioni

*Scritto il 2 settembre 2026, dopo aver letto il codice che c'è.*

---

## Quello che c'è già, e funziona

Vale la pena dirlo prima, perché condiziona tutto il resto: **le fondamenta
sono a posto**. Circa mille righe, quattro file di test con cinquantatré
verifiche, e le cose difficili sono già risolte:

- la **selezione** rispetta il tetto per collocazione, la priorità e una
  rotazione deterministica dentro il minuto — che è ciò che la rende
  compatibile con la cache di pagina invece di combatterla;
- la **trasparenza** è dichiarata ovunque: etichetta «sponsorizzato», nome di
  chi paga, `rel="sponsored"` per i motori, e la dichiarazione arriva anche
  all'API per le applicazioni;
- le **misure** si contano dal browser e non dal server, il che è l'unico modo
  di contare visitatori invece di minuti di cache, con un tetto per chi chiama
  che impedisce di gonfiarle con un ciclo `for`;
- i **permessi** escludono moderatori e referenti di locale: il posto in cima
  si compra, non si prende.

Quattro collocazioni sono usate davvero nel sito: apertura della pagina
iniziale, card nella pagina iniziale, cima delle liste, foglio della mappa.

Quindi questo piano non è un rifacimento. È l'elenco delle cose che mancano
per **poterlo vendere a qualcuno senza imbarazzo**.

---

## Il vincolo che viene prima di tutto

Il piano di prodotto è esplicito (§ modello economico):

> «Nella v1 l'obiettivo è **avere eventi**, non monetizzare: la monetizzazione
> viene dopo che esiste un pubblico.»

Questo ordina le priorità in un modo preciso: **non serve costruire un
apparato pubblicitario più ricco.** Serve che quel poco che c'è sia
verificabile, onesto e non danneggi il prodotto. Tutto quello che segue è
scelto con quel metro; alla fine c'è l'elenco delle cose che sembrano
miglioramenti e sarebbero un errore adesso.

---

## Le lacune, in ordine di rischio

### 1. I numeri che finiscono in fattura non si possono verificare

**Il problema.** Ci sono due colonne, `impressions` e `clicks`, e sono
contatori **cumulativi**. Non esiste una serie storica. Le conseguenze si
sentono al primo cliente vero:

- «quante visualizzazioni ha avuto a novembre?» → non c'è risposta;
- se un numero sembra sbagliato, non c'è modo di ricostruire come ci si è
  arrivati;
- una campagna sospesa e ripresa mescola due periodi in un totale solo;
- un errore che gonfia il contatore è **irreversibile**: non si può sottrarre
  quello che non si sa quando è entrato.

È un rischio contrattuale, non tecnico: si sta fatturando su un numero che non
si può difendere se qualcuno lo contesta.

**Cosa fare.** Una tabella `sponsorship_daily_stats` — campagna, giorno,
visualizzazioni, click. Il contatore cumulativo resta dov'è (è comodo e
veloce), ma diventa una somma, non l'unica verità. La scrittura è un
`upsert` sulla riga del giorno, quindi non aggiunge query.

Con quella tabella diventano possibili, quasi gratis, tutte le cose sotto.

**Costo:** una migrazione, una modifica al controller delle misure, i test.
Mezza giornata.

---

### 2. Le campagne finite restano «attive»

**Il problema.** Non c'è niente di schedulato: nessun comando, nessuna
scadenza. Una campagna con `ends_at` nel passato mantiene lo stato `active`.
Il selettore la filtra correttamente — quindi **non si vede sul sito**, e va
bene — ma nell'elenco di gestione appare fra le attive.

Chi apre il pannello per sapere «cosa sta girando adesso» legge una lista che
mescola vive e finite. È il genere di cosa che non rompe niente e fa perdere
mezz'ora ogni volta.

**Cosa fare.** Un comando `sponsorships:close` schedulato di notte, che porta
a uno stato terminale — `Completed`, da aggiungere all'enum — le campagne il
cui `ends_at` è passato. Lo stato `Paused` resta quello che è: una sospensione
decisa da una persona, che si può riprendere.

**Attenzione a una cosa**: il comando non deve rimettere in gioco niente e non
deve toccare le campagne future. Cambia solo `active` → `completed`, e solo
all'indietro.

**Costo:** un enum, un comando, una riga in `routes/console.php`, i test.
Due ore.

---

### 3. Chi paga non vede niente

**Il problema.** `advertiser_email` viene raccolta e non usata da nessuna
parte. Ogni volta che un inserzionista chiede come sta andando, la risposta è
un lavoro manuale: aprire il pannello, leggere due numeri, scrivere una mail.

Con un cliente è sopportabile. Con cinque diventa il motivo per cui non se ne
prendono altri.

**Cosa fare, in ordine di ambizione crescente** — e va fatto solo il primo
gradino finché non ci sono clienti veri:

1. **Un riepilogo settimanale automatico** all'indirizzo dell'inserzionista:
   visualizzazioni e click della settimana, e il totale da inizio campagna.
   Richiede la tabella del punto 1. È una notifica, non una pagina: nessun
   accesso da gestire, nessuna password da recuperare.
2. Una **pagina pubblica con collegamento firmato** (`URL::signedRoute`), da
   mettere in fondo a quella mail. Nessun account, il collegamento scade.
3. Un vero pannello per inserzionisti — **solo se un giorno saranno molti**.
   Prima è un pannello da mantenere per tre persone.

**Costo:** il primo gradino è mezza giornata dopo il punto 1.

---

### 4. La stessa persona vede sempre la stessa campagna

**Il problema.** Non c'è alcun limite di frequenza. Chi apre la pagina
iniziale, poi una lista, poi la mappa, vede la stessa campagna tre volte in un
minuto. Con una sola campagna in vendita — il caso di oggi — la vede a ogni
visita, per tutta la durata.

Due danni distinti: la persona si stanca, e l'inserzionista paga
visualizzazioni che non valgono niente. Il secondo è peggio del primo, perché
è lui che poi non rinnova.

**Cosa fare.** Un limite per visitatore e per campagna, tenuto in un cookie o
in `localStorage`: dopo N visualizzazioni nella stessa giornata, la campagna
cede il posto alla successiva in rotazione — o a niente, se non ce n'è.

**La difficoltà vera è la cache di pagina.** Il server disegna la stessa
pagina per tutti dentro il minuto: un limite deciso lato server la
spaccherebbe in tante versioni quanti sono i visitatori. Va fatto **nel
browser**, come già si fa per le misure: la pagina porta tutte le campagne
ammesse per quella collocazione, e lo script mostra la prima che il visitatore
non ha ancora visto abbastanza.

Questo funziona solo con più campagne in vendita, quindi **non è il primo
lavoro da fare** — ma la struttura va pensata adesso, perché cambia cosa il
server manda alla pagina.

**Costo:** un giorno, e solo quando ci sono almeno due campagne per
collocazione.

---

### 5. Nessuno si accorge se una campagna non funziona

**Il problema.** Una campagna con molte visualizzazioni e zero click sta
occupando il posto migliore del sito senza portare niente a nessuno: né a chi
paga, né a chi legge. Oggi non c'è modo di accorgersene se non guardando due
numeri a mano.

**Cosa fare.** Un widget nel riepilogo dell'amministrazione — che oggi non
esiste per le sponsorizzazioni — con le campagne vive, quelle in scadenza
entro sette giorni, e quelle il cui rapporto click/visualizzazioni è sotto una
soglia. Non un allarme: un elenco che si guarda una volta a settimana.

**Costo:** mezza giornata, dopo il punto 1.

---

### 6. Non si può vendere «un numero di visualizzazioni»

**Il problema.** Le campagne vanno solo a tempo: `starts_at`, `ends_at`. Non
c'è modo di venderne una che finisca al raggiungimento di un tetto di
visualizzazioni — che è il modo in cui si vende pubblicità quasi ovunque.

**Cosa fare.** Una colonna `impressions_cap` opzionale, e il selettore che
esclude chi l'ha raggiunta. Con la tabella del punto 1 il conto è affidabile.

**Ma va fatto solo se serve davvero.** Vendere a tempo è più semplice da
capire per un locale di quartiere, che è il cliente probabile di questo sito.
Un tetto a visualizzazioni ha senso quando il traffico è tanto e prevedibile:
è una funzione da aggiungere quando qualcuno la chiede, non prima.

**Costo:** due ore. Da tenere in coda.

---

## Stato al 2 settembre 2026, sera

Il piano è stato eseguito, con **due correzioni al piano stesso** emerse
scrivendo il codice.

| | cosa | stato |
|---|---|---|
| 1 | Serie storica giornaliera | **fatto** — `sponsorship_daily_stats`, una riga per campagna e giorno in `upsert` |
| 2 | Campagne finite che sembrano attive | **fatto diversamente** — vedi sotto |
| 3 | Riepilogo settimanale a chi paga | **fatto** — `sponsorships:report`, lunedì mattina |
| 4 | Widget di controllo | **fatto** — quattro numeri e un grafico a due linee, in cima all'elenco |
| 5 | Rotazione | **fatto, e prima del previsto** — vedi sotto |
| 6 | Tetto a visualizzazioni | **fatto** — `impressions_cap` e `clicks_cap` |

### Correzione 1: niente stato `Completed`

Il piano proponeva uno stato terminale scritto da un comando notturno. Il
commento in testa a `SponsorshipStatus` conteneva già l'obiezione, ed era
fondata:

> «Uno stato che deve essere aggiornato da un processo notturno per restare
> vero è uno stato che prima o poi mente: basta che il processo non giri.»

Il problema era reale — nell'elenco una campagna finita da tre settimane si
leggeva «attiva» — ma la soluzione era un'altra: una **fase derivata** da stato
più finestra (`SponsorshipPhase`), che dice il vero anche se il cron è fermo da
una settimana. Nessun comando da far girare, nessuno stato che può mentire.

### Correzione 2: la rotazione era più urgente di quanto scritto

Il piano metteva la rotazione al quinto posto, «solo quando ci sono almeno due
campagne per collocazione». Guardando il codice è emerso un difetto più grave
di quello che avevo descritto: la rotazione era **circolare a parità di
priorità**, e la priorità un ordine rigido. Nella collocazione in apertura, che
ammette una sola campagna, questo significa che la priorità più alta prende il
**cento per cento** delle apparizioni e le altre **non compaiono mai** — anche
se hanno pagato.

Con quel comportamento si può vendere solo «il primo posto», e una volta
venduto non c'è più niente da vendere: non è un limite di comodità, è un limite
di quanti clienti si possono avere.

Ora c'è `weight`: peso 3 contro peso 1 non significa «sta davanti», significa
«compare tre volte su quattro». Misurato su 400 minuti: 300 e 100 esatte. La
priorità resta il criterio più forte, perché è un impegno contrattuale — il
peso distribuisce dentro quel gruppo, non lo scavalca.

La rotazione resta **deterministica dentro il minuto**: una funzione casuale
darebbe la stessa proporzione e romperebbe la cache di pagina, perché la pagina
salvata conterrebbe una scelta a caso valida per tutto il minuto.

### Quello che è emerso strada facendo

- **Tutte le email del sito finivano in inglese.** «Regards,» e «If you're
  having trouble clicking the button» arrivano dal framework e nessuno le aveva
  tradotte: valeva per reset password, accesso e promemoria, non solo per il
  riepilogo nuovo. Corretto con `lang/it.json`.
- `spatie/laravel-stats` scartato: scrive una riga per ogni incremento, e
  quella tabella crescerebbe senza limite per contenere un dato che serve
  aggregato per giorno. Preso `flowframe/laravel-trend`, che non memorizza
  niente e aggrega una tabella che già esiste.

---

## L'ordine che consiglio

| | cosa | perché prima | costo |
|---|---|---|---|
| **1** | Serie storica giornaliera | senza, tutto il resto non si può costruire — e si fattura su numeri indifendibili | ½ giorno |
| **2** | Chiusura automatica delle campagne finite | costa due ore e toglie confusione ogni giorno | 2 ore |
| **3** | Riepilogo settimanale a chi paga | è la differenza fra un cliente che rinnova e uno che chiede «allora?» | ½ giorno |
| **4** | Widget di controllo nell'amministrazione | rende visibile ciò che sta per scadere o non rende | ½ giorno |
| **5** | Limite di frequenza per visitatore | solo quando ci sono almeno due campagne per collocazione | 1 giorno |
| **6** | Tetto a visualizzazioni | solo se un cliente lo chiede | 2 ore |

I primi due sono indipendenti e si possono fare subito. Dal terzo in poi,
ognuno ha senso solo dopo il precedente.

---

## Quello che NON farei adesso

Elencarlo serve quanto l'altro elenco, perché sono tutte cose che *sembrano*
miglioramenti.

**Targeting comportamentale.** Mostrare campagne diverse in base a ciò che una
persona ha guardato prima significa profilare i visitatori, e questo sito ha
scelto di non farlo: la posizione geografica non viene nemmeno salvata (§11.7),
i salvataggi da anonimo restano nel browser. Aggiungere profilazione per
vendere meglio contraddirebbe una promessa già fatta a chi legge — e
richiederebbe un banner del consenso molto più invadente di quello attuale.

**Un'asta fra inserzionisti.** Ha senso con decine di campagne in concorrenza
per lo stesso spazio. Con una o due, la priorità manuale che c'è già fa
esattamente lo stesso lavoro e la si capisce guardandola.

**Un portale self-service** dove gli inserzionisti comprano da soli. Vuol dire
pagamenti, fatturazione automatica, moderazione di quello che caricano, e
assistenza. È un prodotto a sé, e va costruito quando la domanda esiste — non
per crearla.

**Più collocazioni pubblicitarie.** Le quattro attuali coprono i punti che
contano. Aggiungerne significa togliere spazio agli eventi, che sono il motivo
per cui qualcuno visita il sito. Il momento di aggiungerne una è quando le
quattro sono **vendute tutte e da tempo**, non quando sono vuote.

**Formati grafici** (banner, immagini caricate dall'inserzionista). Oggi una
campagna promuove **un evento vero**, con la stessa card degli altri: è la
ragione per cui non stona e per cui la si può marcare con onestà. Un banner
generico sarebbe pubblicità in mezzo al contenuto, e ne cambierebbe la natura —
oltre a richiedere moderazione di quello che arriva.

---

## Una cosa da decidere, non da programmare

Le misure si contano dal browser, quindi **chi blocca gli script non viene
contato**: le cifre sono una stima al ribasso. Il codice lo dice già in un
commento, ma è un'informazione che deve stare **nel contratto**, non nel
sorgente.

Va scritta prima del primo cliente, non dopo la prima contestazione.

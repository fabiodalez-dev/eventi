# Sponsorizzazioni dei locali

## Registrare un periodo

Il modo più semplice è **Admin → Sponsorizzazioni → Nuova sponsorizzazione**: si sceglie il locale (o l'organizzatore) e poi «Tutti gli eventi», che crea un'abilitazione automatica e la sincronizza subito (D70). La pagina qui sotto resta per le abilitazioni a eventi scelti e per modificarle.

In **Admin → Sponsorizzazioni → Abilitazioni e pagamenti → Nuova abilitazione**:

1. Scegliere locale, posizione della pubblicità e modalità: tutti gli eventi oppure eventi scelti dal locale.
2. Indicare l’inizio e la scadenza, oppure usare la durata in mesi per calcolare la scadenza.
3. Per un periodo pagato, registrare l’importo in euro, la data di incasso, il metodo e il riferimento. Senza data di incasso l’abilitazione resta in attesa del pagamento.
4. Per una concessione commerciale gratuita, attivare **Autorizzazione gratuita** e indicare la motivazione.
5. Nella sezione **Consegna**, facoltativa: visualizzazioni comprate (vuoto = a periodo), distribuzione (nel periodo o il prima possibile), priorità (0–1000) e peso (1–100). Valgono per l'abilitazione intera, non per le sue righe. L'apertura della home non accetta un obiettivo di visualizzazioni.
6. Salvare. La modalità automatica genera le campagne subito; il controllo ogni minuto include anche i nuovi eventi e attiva i periodi programmati.

**Un'abilitazione è un posto solo (D70).** Tutti i suoi eventi si dividono un posto nella rotazione, con il peso e la priorità dell'abilitazione: un locale con quaranta eventi non conta quaranta volte più di chi ne ha sponsorizzato uno. Dentro l'abilitazione l'evento si sceglie per vicinanza della data. Con un obiettivo di visualizzazioni le consegnate sono la somma delle sue righe, comprese quelle tolte, e quando arrivano all'obiettivo l'abilitazione smette di comparire.

Gli orari dei moduli sono Europe/Rome. La scadenza è esclusiva: alle 15:00 del giorno di scadenza l’abilitazione non vale più. Anche la cache pubblica cambia alla scadenza. Revocare un’abilitazione ne interrompe la visibilità senza eliminare lo storico.

Ogni riga rappresenta un periodo commerciale con un pagamento registrato manualmente, non un incasso online o una fattura fiscale. Per un rinnovo creare una nuova abilitazione con il nuovo periodo e pagamento, conservando la precedente. Non sommare il prezzo del periodo alle singole campagne: le campagne generate non duplicano l’importo incassato. Le modifiche alle abilitazioni sono registrate nella cronologia, con autore e data.

## Gestione del locale

In **Gestione → Sponsorizzazioni** il locale vede una scheda per ogni abilitazione: posizione, periodo, «tutti i tuoi eventi» o gli eventi scelti, una barra con una frase che dice a che punto è («1.240 di 3.000 visualizzazioni, in linea: al ritmo attuale arrivi all'obiettivo il 28 novembre») e sotto gli eventi con i loro numeri. Per il prodotto a periodo la scheda mostra le visualizzazioni senza obiettivo. In testa ci sono i totali. Solo con un periodo valido in modalità scelta compare **Sponsorizza un evento**. Il locale può scegliere soltanto i propri eventi e interrompere le campagne scelte; non modifica importi, scadenze, posizioni o autorizzazioni.

Una bozza può essere preparata per una campagna, ma non viene mai mostrata pubblicamente prima della pubblicazione. Le campagne derivanti dalle abilitazioni senza date future non occupano gli spazi pubblicitari. I locali sospesi non possono utilizzare l’abilitazione.

## Statistiche e autorizzazioni

Il riepilogo admin mostra pagamenti registrati per le abilitazioni, periodi attivi/in scadenza e visualizzazioni/clic delle campagne. La pagina campagne mantiene l’andamento giornaliero già disponibile. Il locale vede soltanto i propri contatori, anche dopo la fine di una campagna; lo storico è paginato.

I contatori sono le misure delle inserzioni, non le visite complessive alla pagina evento né persone uniche. Una visualizzazione conta quando metà della card resta sullo schermo per un secondo, una volta per visita; non contano i programmi automatici, la redazione e i membri del committente collegati. Restano applicati i limiti anti-abuso del sistema esistente. Il prodotto a periodo non garantisce un numero minimo di visualizzazioni. Quello a visualizzazioni distribuisce l'obiettivo fino alla fine vera del periodo, cioè la prima fra la scadenza e l'ultima data in programma; se il traffico previsto non basta lo stato diventa «a rischio» e il modulo lo dice già prima di salvare. Non è un rimborso automatico.

L'avanzamento (barra, stato e frase) è lo stesso calcolo per admin, gestore, statistiche, riepilogo del lunedì e app (`App\Services\Sponsorship\SponsorshipProgress`). Ogni mattina alle 7 `sponsorships:pacing-check` avvisa nella campanella del pannello admin le abilitazioni e campagne a visualizzazioni in ritardo o a rischio. Il riepilogo del lunedì di un'abilitazione arriva a chi gestisce il locale o l'organizzazione, all'email del suo account, oltre che all'eventuale indirizzo del committente scritto sulle righe. Un'unità che chiude sotto l'obiettivo non si prolunga: a evento passato non c'è più niente da promuovere (D70).

Solo admin e superadmin amministrano le abilitazioni. Le campagne create direttamente dagli admin restano autorizzazioni editoriali/commerciali esplicite e non vengono convertite automaticamente in pagamenti. Anche **In evidenza** resta riservato ad admin e superadmin: i locali non possono usarlo per aggirare il pagamento.

## Anteprima delle bozze

Dalla modifica dell’evento, **Anteprima pagina** apre la grafica del sito usando i dati salvati. Serve l’accesso del gestore di quel locale o dello staff autorizzato a modificare l’evento. La pagina non è memorizzabile in cache né indicizzabile; non permette di prenotare o salvare la bozza. Il link pubblico resta inaccessibile finché l’evento non viene pubblicato.

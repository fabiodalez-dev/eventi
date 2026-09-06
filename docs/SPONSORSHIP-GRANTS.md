# Sponsorizzazioni dei locali

## Registrare un periodo

In **Admin → Sponsorizzazioni → Abilitazioni e pagamenti → Nuova abilitazione**:

1. Scegliere locale, posizione della pubblicità e modalità: tutti gli eventi oppure eventi scelti dal locale.
2. Indicare l’inizio e la scadenza, oppure usare la durata in mesi per calcolare la scadenza.
3. Per un periodo pagato, registrare l’importo in euro, la data di incasso, il metodo e il riferimento. Senza data di incasso l’abilitazione resta in attesa del pagamento.
4. Per una concessione commerciale gratuita, attivare **Autorizzazione gratuita** e indicare la motivazione.
5. Salvare. La modalità automatica genera le campagne subito; il controllo ogni minuto include anche i nuovi eventi e attiva i periodi programmati.

Gli orari dei moduli sono Europe/Rome. La scadenza è esclusiva: alle 15:00 del giorno di scadenza l’abilitazione non vale più. Anche la cache pubblica cambia alla scadenza. Revocare un’abilitazione ne interrompe la visibilità senza eliminare lo storico.

Ogni riga rappresenta un periodo commerciale con un pagamento registrato manualmente, non un incasso online o una fattura fiscale. Per un rinnovo creare una nuova abilitazione con il nuovo periodo e pagamento, conservando la precedente. Non sommare il prezzo del periodo alle singole campagne: le campagne generate non duplicano l’importo incassato. Le modifiche alle abilitazioni sono registrate nella cronologia, con autore e data.

## Gestione del locale

In **Gestione → Sponsorizzazioni** il locale vede periodi, scadenze, storico e totali. Solo con un periodo valido in modalità scelta compare **Sponsorizza un evento**. Il locale può scegliere soltanto i propri eventi e interrompere le campagne scelte; non modifica importi, scadenze, posizioni o autorizzazioni.

Una bozza può essere preparata per una campagna, ma non viene mai mostrata pubblicamente prima della pubblicazione. Le campagne derivanti dalle abilitazioni senza date future non occupano gli spazi pubblicitari. I locali sospesi non possono utilizzare l’abilitazione.

## Statistiche e autorizzazioni

Il riepilogo admin mostra pagamenti registrati per le abilitazioni, periodi attivi/in scadenza e visualizzazioni/clic delle campagne. La pagina campagne mantiene l’andamento giornaliero già disponibile. Il locale vede soltanto i propri contatori, anche dopo la fine di una campagna; lo storico è paginato.

I contatori sono le misure delle inserzioni, non le visite complessive alla pagina evento né persone uniche. Restano applicati i limiti anti-abuso del sistema esistente. La presenza in rotazione non garantisce un numero minimo di visualizzazioni.

Solo admin e superadmin amministrano le abilitazioni. Le campagne create direttamente dagli admin restano autorizzazioni editoriali/commerciali esplicite e non vengono convertite automaticamente in pagamenti. Anche **In evidenza** resta riservato ad admin e superadmin: i locali non possono usarlo per aggirare il pagamento.

## Anteprima delle bozze

Dalla modifica dell’evento, **Anteprima pagina** apre la grafica del sito usando i dati salvati. Serve l’accesso del gestore di quel locale o dello staff autorizzato a modificare l’evento. La pagina non è memorizzabile in cache né indicizzabile; non permette di prenotare o salvare la bozza. Il link pubblico resta inaccessibile finché l’evento non viene pubblicato.

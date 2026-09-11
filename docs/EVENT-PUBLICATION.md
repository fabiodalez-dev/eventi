# Pubblicazione degli eventi e ticketing

Il pannello locale non espone provenienza, verifica redazionale, punteggio, evidenza o motivo del rifiuto. Il salvataggio rimuove questi campi e il modello blocca modifiche redazionali non autorizzate. La conferma del locale deriva da `venues.is_verified`; la verifica redazionale resta riservata allo staff.

Su una bozza con almeno una data, **Programma pubblicazione** sceglie un istante futuro (Europe/Rome, salvato in UTC). Il locale con `auto_publish` autorizzato può pubblicare autonomamente; altrimenti la richiesta resta in attesa. Gli admin possono approvarla con **Approva programmazione**. Rifiuto, annullamento, archiviazione e ritiro in bozza cancellano la programmazione. **Annulla programmazione** permette di ritirare la richiesta.

`events:publish-due` gira ogni minuto tramite lo scheduler Laravel esistente, con blocco anti-sovrapposizione e transazione per evento. Ricontrolla scadenza, stato, presenza di date e permessi attuali di chi ha autorizzato la pubblicazione. Non duplica la pubblicazione. Le istruzioni del cron di sistema e il comando sono in **Sistema → Cron e programmazioni**.

Il ticketing inCittà non incassa online: offre prenotazioni per biglietti gratuiti o con pagamento all’ingresso. Nella scheda **Ingresso** appare per ingresso libero, biglietto e riservato ai soci. L’attivazione rimane per singola data, dopo l’abilitazione degli admin in Locali → modifica → Ticketing. Il collegamento dalla scheda evento apre direttamente questa scheda. **Biglietti e partecipanti** apre la gestione con titolari, ricerca, check-in, annullamenti, disponibilità ed esportazione CSV. I link di acquisto esterni sono distinti.

Per gli eventi gratuiti i campi importo sono nascosti e gli importi precedenti vengono cancellati anche lato server.

## Configurazione senza uscire dall’evento

In **Ingresso → Ticketing inCittà → Configura ticketing · data** si aprono direttamente attivazione, capienza complessiva, limite di 1–20 biglietti per account/data, lista d’attesa, apertura/chiusura, scadenza annullamento, istruzioni e campi personali facoltativi/obbligatori. Il limite è per account, non una verifica dell’identità fisica. L’abilitazione del locale rimane amministrativa; quando manca, il modulo è consultabile ma il salvataggio è disabilitato.

Le finestre temporali sono verificate a ogni prenotazione. Il cron `ticketing:promote` controlla la lista d’attesa; la coda invia conferme con PDF/QR e aggiornamenti per promozioni, variazioni e annullamenti via email e account. Nessun pagamento online viene introdotto.

Un rifiuto non viene aggirato con invii ripetuti. Se un locale senza pubblicazione autonoma modifica un evento programmato già approvato, la richiesta torna alla redazione.

Il titolo del modale include evento e data. I calendari del ticketing e della programmazione usano locale italiano, settimane da lunedì e formato giorno/mese/anno. L’email è visualizzata come dato obbligatorio già acquisito dall’account; i campi opzionali aggiuntivi non la sostituiscono.

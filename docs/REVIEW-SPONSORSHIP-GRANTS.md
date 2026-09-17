# Review delle abilitazioni alle sponsorizzazioni

17 settembre 2026. Ambito: creazione e modifica amministrativa, campagne collegate, autorizzazioni e cronologia.

## Correzioni

- Concessione gratuita: importo, data del pagamento, metodo e riferimento scompaiono insieme. Al salvataggio i quattro valori vengono esplicitamente azzerati anche su una vecchia abilitazione pagata. I valori precedenti rimangono nel registro delle modifiche; la motivazione è obbligatoria.
- Passaggio da gratuita a pagamento: l'importo torna obbligatorio, senza ripristinare un vecchio pagamento. Senza data di incasso la concessione resta in attesa di pagamento.
- Importi: massimo due decimali, nessun arrotondamento silenzioso di frazioni inferiori al centesimo. Conversione euro/centesimi preservata.
- Durata: cambiare l'inizio aggiorna la scadenza quando sono selezionati i mesi. Il calcolo preserva l'ora Europe/Rome anche attraverso l'ora legale e gestisce la fine del mese. Una scadenza manuale disattiva la durata preimpostata.
- Cronologia: lettura del formato corrente di activitylog e compatibilità con quello precedente; visualizzazione dei valori prima e dopo, senza lasciare righe vuote.

## Funzioni verificate

Creazione e modifica riservate ad admin e super admin; gestori e moderatori esclusi. Locale, modalità e posizione non si modificano dopo la creazione, anche con stato del modulo alterato. Pagamenti futuri, finestre temporali invertite e importi non validi vengono rifiutati.

Una revoca e la scadenza escludono subito le campagne dalla selezione pubblica. La selezione degli eventi resta limitata al locale autorizzato; la sincronizzazione automatica è idempotente e non rende pubbliche le bozze. Modificare il periodo aggiorna le campagne senza riattivare quelle fermate dal locale. Le abilitazioni automatiche attive vengono sincronizzate ogni minuto.

La pagina registra pagamenti già ricevuti. La review usa database di test; sul sito pubblico è stato verificato il comportamento del modulo senza creare o modificare abilitazioni reali.

## Verifica estesa della cronologia

Il registro condiviso ora mostra correttamente anche le decisioni su eventi e locali. Il pannello del locale amministrativo espone la relazione già registrata. L'orario è Europe/Rome; un account non più risolvibile viene distinto dalle operazioni senza autore. Le prove verificano escaping HTML, autore, valori prima/dopo, dati storici e accessi negati ai gestori. I test di sicurezza verificano inoltre che password e token emessi non siano inclusi nei log.

La verifica della conversazione completa e i limiti delle funzionalità precedenti sono riepilogati in `VERIFICA-CRONOLOGIA-2026-09-17.md`.

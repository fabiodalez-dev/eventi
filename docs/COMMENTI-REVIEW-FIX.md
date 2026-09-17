# Correzioni della review commenti

La versione di riferimento è `commenti-eventi`; il prototipo con il pacchetto resta un esperimento separato.

- Filament applica il filtro a due salti nel proprio `scopeEloquentQueryToTenant`, anche quando risolve un record per un'azione. Nessuna disattivazione della tenancy.
- Gli avvisi passano da `scheduled_notifications`, `NotificationGate`, `MessageFactory`, `NotificationDispatcher` e `ScheduledMessage`: invio immediato se consentito, altrimenti rinvio alle ore ammesse. Il worker già esistente riprende gli avvisi rinviati. Restano attivi verifica email, preferenze, cap, registro, payload push, archivio e link di disiscrizione.
- Le chiavi di deduplicazione delle reazioni sopravvivono alla cancellazione della reazione. Le decisioni di invio sono serializzate per destinatario.
- Il proprietario dell'organizzatore viene incluso assieme ai collaboratori. `reply_to_id` conserva il destinatario effettivo di una risposta, separato da `parent_id`, che mantiene un solo livello visuale.
- `?commento=ID` è il permalink della conversazione: seleziona il thread e la pagina che contiene la risposta. La lista mostra dieci capostipiti con tre risposte ciascuno; nella conversazione si leggono venti risposte per pagina. Si carica soltanto la reazione del lettore corrente, mentre il totale viene contato dal database.
- La migrazione `2026_09_17_120000_fix_event_comment_integrity` aggiunge `reply_to_id` e rimuove il contatore denormalizzato. Le reazioni esistenti non vengono cancellate. Le risposte pregresse vengono collegate al loro capostipite: il destinatario specifico originario non era stato registrato e non può essere ricostruito.
- Cancellare un account elimina i suoi commenti e reazioni anche in soft delete. La cancellazione del capostipite continua a eliminare le risposte, come previsto dalla scelta originale.
- Commenti nascosti non accettano reazioni né risposte. L'autore vede un segnaposto privato; il testo non è esposto agli altri utenti.
- La preferenza commenti è modificabile via API, profilo e pagina preferenze. La conferma di cancellazione usa un listener nel bundle JavaScript, senza handler inline e senza indebolire la CSP.
- Gli aggiornamenti dei pulsanti restano confinati al commento interessato. I toggle vengono serializzati nel browser e una risposta di rete persa non provoca un secondo POST che annullerebbe il primo.
- I form di risposta restano accessibili senza JavaScript, gli inviti ospite sono link reali e il dialogo viene incluso soltanto nella sezione commenti per gli ospiti.

Test di regressione: `tests/Feature/Web/EventCommentsRegressionTest.php` e `tests/Browser/EventCommentsInteractionTest.php`, oltre ai test originali aggiornati per il trasporto notifiche condiviso. I test browser esercitano i controlli reali nei temi chiaro e scuro e verificano l'assenza di violazioni CSP durante la conferma di cancellazione.

Nel worktree del prototipo è stata rimossa l'esclusione `eventi/*` da `config/security.php`. Non si deve reintrodurla per far funzionare il componente sperimentale: il percorso da integrare è la versione su misura.

## Verifiche locali

- Suite completa: **2409 test passati, 10058 asserzioni**.
- Test originali dei commenti: 20 passati.
- Regressioni della review e suite notifiche: 162 casi verificati, inclusa la riesecuzione positiva del caso preferenze web. Le regressioni aggiunte sono 18; tre sono state aggiunte dopo l’avvio della suite completa e verificate nel gruppo mirato.
- Browser: 3 casi passati, 15 asserzioni, con interazioni reali nei due temi.
- Pint, PHPStan e deptrac: nessun errore; build Vite completata.
- Migrazione applicata a `eventi_local`; smoke HTTP dell'istanza `127.0.0.1:8097`: 200, `strict-dynamic` presente, un solo dialogo ospite, nessun handler `onsubmit` inline.

Nessun deploy, commit o push eseguito da questa correzione.

## Ritorno ai commenti dopo l'accesso

I link ospite e quelli nel dialogo portano la destinazione dell'evento, inclusi query string e frammento `#commenti`. Le pagine di accesso, registrazione e magic link conservano nella sessione soltanto URL della stessa origine. Accesso e registrazione consumano questa destinazione; il magic link la conserva quando viene aperto nello stesso browser. Senza destinazione resta il feed.

Verifica CAPTCHA nell'anteprima locale: entrambe le chiavi Turnstile risultano assenti, quindi il widget e la validazione sono disattivati. Il componente esistente protegge la registrazione quando configurato; login e commenti non lo includono. Questa correzione non cambia la configurazione CAPTCHA. La configurazione di produzione non è stata verificata.

Verifiche aggiuntive: 47 test passati per autenticazione, ritorno ai commenti e Turnstile (167 asserzioni). I tre casi browser precedenti restano verdi; il nuovo caso percorre il dialogo ospite, esegue il login e verifica il ritorno al form dell'evento con `#commenti` (4 asserzioni). PHPStan e Pint passano. L'anteprima su porta 8097 serve già i link corretti.

## XSS, gestione admin e conferma email

- Test HTTP con script, handler su immagini/SVG, iframe, URL JavaScript e tentativi di uscita da attributi e textarea. Verificati commenti, risposte e ripopolamento del form dopo validazione fallita. Il contenuto resta testo escapato.
- Prove browser su pubblicazione e validazione con CSP attiva e disattivata, più tabella e dettagli del pannello admin: nessuna esecuzione dei payload provati. Queste prove non equivalgono a un penetration test completo dell'applicazione.
- Amministrazione → Moderazione → Commenti agli eventi: aggiunta lettura integrale con nota, autore della moderazione e data. Confermate le azioni nascondi/ripristina/elimina per admin e super admin. L'eliminazione avverte della cancellazione delle risposte. I moderatori possono nascondere e ripristinare ma non eliminare commenti altrui; gli utenti normali non accedono al pannello.
- Pubblicati gli asset Filament mancanti nell'anteprima locale, necessari per i dialoghi delle azioni.
- La registrazione usa l'evento Laravel `Registered` per inviare una sola mail di conferma e conduce alla schermata di verifica. Link firmati, scadenza e reinvio erano già implementati; ora la verifica conserva anche il ritorno ai commenti nello stesso browser. Commenti e reazioni richiedono email verificata tramite middleware `verified`; lettura e salvataggi rimangono disponibili.
- Mail di conferma personalizzata in tema chiaro con accento terracotta, layout a tabelle, stili inline, link copiabile e alternativa solo testo. Schermata web coerente con il tema dell'app, verificata in chiaro a 390 e 1280 px. Non modificato né ricompilato il client Android.
- Trasporto locale configurato come SMTP. I test intercettano le notifiche: nessuna mail reale è stata spedita per questa verifica e non è stata certificata la consegna nella casella del destinatario.

Verifiche finali: 96 test funzionali passati (478 asserzioni) tra iscrizione web/API, verifica email, commenti, permessi e XSS. PHPStan, Pint, deptrac e build Vite passano. Artefatti visivi in `/Users/fabio/Desktop/Review commenti inCitta/Anteprime/`.

Browser: 8 casi verificati, incluso il nuovo percorso conferma → ritorno al form dell'evento (9 asserzioni nella riesecuzione finale). Nessun deploy, commit o push eseguito.

## Rilascio web e Android 1.13.0

Il client Android ora mostra i commenti nella scheda evento: lettura, accesso con ritorno all’evento, pubblicazione, risposte, reazioni e cancellazione con conferma. Usa endpoint API autenticati per le scritture, senza cache delle risposte personali. L’email deve essere verificata per pubblicare o reagire. Rimossi pulsante, tracciamento e messaggi di debug della rete; ripulite le vecchie preferenze diagnostiche all’avvio.

Il pacchetto DirectoryTree PrivacyFilter è stato valutato: il binario Linux disponibile richiede versioni GLIBC/GLIBCXX assenti sull’hosting. Non viene incluso in questo rilascio, né viene scaricato il modello. Su scelta esplicita del proprietario, i commenti superati i controlli locali sono pubblicati subito.

I controlli server condivisi da sito e API bloccano email (anche comuni mascheramenti), numeri telefonici riconoscibili, codice fiscale, IBAN con checksum valido, carte con checksum Luhn, indirizzi esplicitamente descritti come privati e credenziali etichettate. Per lo spam: duplicati dello stesso account nell’ultima ora, eccesso di link, ripetizioni estreme e alcune formule promozionali evidenti. Le scritture per account sono serializzate per evitare duplicati concorrenti. Il testo non viene inviato a servizi esterni né registrato nei log del filtro. Non è un classificatore semantico: nomi propri, dati mascherati in modi nuovi e spam meno evidente possono sfuggire. Restano necessari moderazione e limiti di frequenza.

Test aggiunti: API commenti, blocco contenuti e falsi positivi su informazioni ordinarie dell’evento, duplicati normalizzati tra eventi, ripopolamento del form; interfaccia Android con contenuto letterale, accesso ospite, pubblicazione ed eliminazione confermata.

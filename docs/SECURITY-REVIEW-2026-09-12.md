# Chiusura della revisione di sicurezza — 12 settembre 2026

Le modifiche conservano le correzioni locali iniziali e completano i casi che la prima revisione non dimostrava. Non costituiscono una certificazione dell'intera applicazione.

## Correzioni

- Azioni di massa: autorizzazione per singolo record; il controllo integra quello `deleteAny` delle risorse Filament. Campi ruolo: un amministratore non può assegnare o rimuovere privilegi riservati al superadmin.
- Reset password: revoca centralizzata dei token e dei challenge anche dai tre pannelli; invalidazione delle sessioni web. Login falliti API, login di sessione, blocchi e impersonificazione sono registrati senza password o token. I blocchi ripetuti dello stesso IP producono al massimo una voce al minuto.
- Pannelli: risposta indistinguibile sul recupero password per indirizzi presenti e assenti, limiti per account sul login e per IP sugli invii email. Il sito applica il limite degli invii anche cambiando email a ogni richiesta.
- Magic link web: consumo atomico persistente, resistente alla pulizia della cache. Magic link mobile: S256 PKCE, scadenza e uso singolo, impronta della password e emissione del token nella stessa transazione. Collegamento HTTPS verificabile e pagina di ripiego esistente, senza riflessione del segreto o referrer. I client senza PKCE richiedono aggiornamento; i sorgenti Android sono allineati, senza generare APK.
- Importazione ICS: CurlHandler esplicito, indirizzo pubblico imposto a ogni connessione, nessun ripiego su un DNS non verificato, redirect controllati singolarmente, credenziali rimosse al cambio origine e limite ai byte durante la scrittura della risposta.
- Web Push: destinazioni interne respinte sia in registrazione sia durante l'invio delle iscrizioni già presenti; connessione HTTPS vincolata a un IP pubblico, proxy e redirect disabilitati.
- Dispositivi: limite comune per sito e API, sotto lock dell'utente, valido anche per riattivazioni e righe revocate; eliminazione dei token dei dispositivi rimossi.
- Cache pagine: paginazione canonica e tetto complessivo di 500 voci indicizzate, con espulsione delle più vecchie.
- Immagini: controllo dei pixel anche per AVIF/HEIC e sequenze, prima della decodifica completa; limiti di memoria, mappatura e disco del decoder. La pipeline continua a ridimensionare gli originali validi.
- Redirect: destinazioni interne validate anche quando provengono da vecchie righe e dopo la sostituzione del jolly. Service worker: aperture limitate alla stessa origine.
- File statici: `nosniff` e `SAMEORIGIN` dichiarati per le estensioni statiche, senza alterare la politica dei widget incorporabili; directory listing disabilitato indipendentemente da mod_negotiation. Sul remoto alcuni header erano già forniti dall'hosting: l'affermazione «zero header» del rapporto non era corretta.
- Mappa: il dettaglio richiamato dalla mappa non espone locali sospesi. Android release: traffico HTTP in chiaro disabilitato; eccezione dell'emulatore confinata a debug.
- Profilo mobile: pulsante Esci accessibile dalla pagina, verificato con registrazione, modifica, logout e nuovo login nel browser.

## Verifiche

I test di regressione includono registrazioni invalide e duplicate, tentativi di assegnarsi privilegi, accessi validi e negati nei tre pannelli, reset dai moduli Filament, revoca e riuso dei token, modifiche del profilo atomiche, isolamento fra utenti, PKCE errato, cache svuotata, DNS rebinding, redirect e limiti alle risorse. Le prove di notifica usano trasporti finti o il blocco preventivo della connessione; non inviano email agli utenti.

Sono stati eseguiti inoltre i test JavaScript, i test dell'infrastruttura CI Python, compilazione/test/lint Android, PHPStan, Pint, build Vite e audit Composer/npm. Gli esiti definitivi e il commit distribuito sono riportati nel riepilogo del rilascio e nella relativa CI.

## Esclusioni concordate

Installer, MFA, nuovi audit pianificati e politica di conservazione dei log restano attività successive. Non è stata prodotta una nuova APK. Nessuna nuova dipendenza runtime è necessaria per queste correzioni.

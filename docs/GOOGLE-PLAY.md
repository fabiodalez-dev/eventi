# Packaging Google Play

Kit locale: `/Users/fabio/Documents/GitHub/Android App/inCitta/`.
Leggere `LEGGIMI-PRIMA.md` e `04-console/CHECKLIST.md`: il kit 1.8.4 (19) è preparato per test interno, non rappresenta approvazione alla produzione.

Rigenerare sul Mac con `node "/Users/fabio/Documents/GitHub/Android App/inCitta/strumenti/prepara-aab.cjs"`.
Lo script usa il Portachiavi macOS, servizio `it.fabiodalez.incitta.play-upload`, account `incitta-upload`, e la chiave privata esterna al repository `/Users/fabio/.config/incitta-play/upload-keystore.p12`. Non inserirli in Git, archivi pubblici o log.

La build AAB richiede `-PplayStore=true` e le variabili `INCITTA_UPLOAD_KEYSTORE`, `INCITTA_UPLOAD_PASSWORD`, `INCITTA_UPLOAD_ALIAS`. Il parametro è incompatibile con `deviceTests`. Il percorso APK storico mantiene la propria firma: non cambiarla incidentalmente durante il packaging Play.

La release esclude eccezioni HTTP per emulatori. VersionCode deve aumentare dopo ogni bundle accettato da Play. Il certificato di upload non è quello con cui Google firma l'app distribuita: aggiornare App Links e integrazioni con il certificato finale corretto.

Il kit contiene verifiche statiche, test unitari e screenshot reali. Restano da completare le verifiche runtime 16 KB, privacy, accesso revisori, OAuth pubblico e push reale prima del lancio. Nessun deploy o caricamento è implicito nella generazione del kit.

## Stato Console e automazione — 9 settembre 2026

Creata l'app nell'account sviluppatore `8635202959016191699`, ID app `4973265892338217997`. La Console impone test chiuso con almeno 12 tester per 14 giorni; attualmente zero tester. Non è possibile richiedere produzione prima di soddisfare il requisito.

Il caricamento via selettore file automatizzato Chrome è stato rifiutato (`Not allowed`); il bundle non risulta caricato. I dettagli della prima release sono compilati ma il salvataggio resta disabilitato senza bundle. Non confondere la creazione dell'app con una release distribuita.

Preparato localmente `.github/workflows/play-bundle.yml`, validato con actionlint: test/lint, firma privata e artefatti su modifiche Android in main. Non è ancora attivo su GitHub e NON carica su Play. Richiede ambiente `play-internal`, segreti `PLAY_UPLOAD_KEY_BASE64`, `PLAY_UPLOAD_PASSWORD`, `ANDROID_GOOGLE_SERVICES_JSON`. La build si arresta se mancano, per evitare bundle con notifiche non configurate. Prima di attivarlo proteggere l'ambiente limitandolo a main. Il caricamento Play richiede ancora identità di servizio e autorizzazioni specifiche dell'app, non accesso amministrativo globale.

Non dichiarare terminati privacy/Data Safety, integrazione Firebase, test 16 KB o revisione pubblica: sono ancora aperti. La privacy del PageSeeder contiene affermazioni obsolete su assenza di pubblicità e interessi; non eseguire quel seeder sul remoto come correzione.

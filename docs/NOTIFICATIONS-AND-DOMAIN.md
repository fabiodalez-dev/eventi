# Calendari, notifiche e cambio dominio

Decisione del proprietario, 7 settembre 2026: Firebase Cloud Messaging per
Android, configurato dal browser Chrome. Niente Analytics o Gemini attivati.
Il dominio pubblico cambierà in futuro: non è un'identità stabile del prodotto.

- Firebase deve restare nello stesso progetto al cambio dominio; il package
  Android esistente e le chiavi di firma non vanno cambiati.
- `APP_URL` governa i collegamenti generati da Laravel. `apiBaseUrl` governa
  l'endpoint della build Android. Nessun segreto Firebase nei repository.
- I link già distribuiti (`.ics`, email, QR e vecchie app) richiedono redirect
  sul vecchio dominio. Mantenere almeno gli endpoint calendario e API, non
  solo la home, e predisporre `.well-known/assetlinks.json` sul nuovo dominio.
- I calendari sottoscritti si aggiornano ai tempi decisi dal client esterno.
  Un file ICS scaricato è una copia statica, non una sottoscrizione.
- Prima di generare un nuovo APK serve conferma esplicita del proprietario.
- Ricerca AJAX: Scout database/MariaDB, nessun servizio esterno.

Le preferenze di notifica vivono sullo stesso account su sito e app.
Gli interessi riusano i follow di categorie e locali; i digest sono aggregati,
con deduplica, limiti giornalieri e ore di silenzio del motore esistente.
Togliere tutti gli interessi non iscrive implicitamente a tutta la città.

## Firebase

Progetto `incitta-11b5b`, piano Spark, senza Analytics. Android release:
`it.fabiodalez.incitta`. Account tecnico dedicato:
`incitta-push@incitta-11b5b.iam.gserviceaccount.com`, ruolo limitato a Firebase
Cloud Messaging API Admin. Il controllo FCM `validate_only` ha verificato
credenziali e permessi senza inviare notifiche a utenti reali.

La chiave server è in `storage/app/private/firebase/incitta-push.json` con
permessi 600. `FIREBASE_CREDENTIALS` contiene il percorso assoluto di quel file,
`API_PUSH_ENABLED=true` abilita il canale. Mai copiare la chiave nell'APK.
La configurazione client Android è il file ignorato
`android/app/src/release/google-services.json`. Per build debug abilitate a FCM
registrare separatamente `it.fabiodalez.incitta.debug` e salvare la relativa
configurazione in `src/debug/google-services.json`.

Il dispositivo si registra solo su richiesta dell'utente. I messaggi sono
data-only, contengono l'ID del destinatario e vengono mostrati solo se coincide
con l'account attualmente connesso. Il logout disattiva FCM e cancella gli avvisi.

## Verifiche

Suite Laravel completa: 1.776 test e 6.671 asserzioni superati prima degli ultimi
test di inquadratura e wizard (anch'essi superati). Build Android e test unitari
release verificati. Autocomplete locali provato in Chrome, inclusi tastiera,
ritorno allo step precedente e rimozione. Test JS: `node --test tests/js/*.test.js`.

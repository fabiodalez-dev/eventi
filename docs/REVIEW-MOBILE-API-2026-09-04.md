# Review completa — Laravel, API v1 e Android

Data: 4 settembre 2026. Stato: implementazione completata e verificata.

## Verdetto

Il progetto Laravel aveva già un dominio ricco e coerente, ma l'API non era
ancora sufficiente per un client mobile affidabile: mancavano una home
aggregata, sincronizzazione incrementale e tombstone, scambio sicuro del magic
link, gestione delle sessioni, lettura notifiche, sponsorizzazioni, FCM e una
specifica OpenAPI abbastanza concreta. Questi vuoti sono stati chiusi.

L'app Android è nativa (Kotlin + Jetpack Compose), non una WebView. Consuma la
v1 pubblica, conserva il bearer in Android Keystore, supporta cache offline,
deep link, ricerca completa, calendari, mappe, locali, dettaglio ricco, wishlist ospite/autenticata e account. La sua
direzione visiva deriva dalla reference `Aggregatore eventi moderno.zip`.

## Copertura funzionale

| Area | Web/Laravel | API v1 | Android |
|---|---|---|---|
| Scoperta eventi | completa | eventi, home, calendario, aree, stats | home, filtri reali e calendario |
| Ricerca | completa | eventi, luoghi e tag | risultati separati eventi/locali/tag |
| Dettaglio | completa | evento, occorrenze, simili, venue | tutte le date e informazioni del sito |
| Locali | completa | scheda, futuri e passati | indice e scheda completa collegata |
| Mappe | completa | marker e coordinate | mappa generale e mappe di dettaglio |
| Wishlist ospite | localStorage | merge autenticato | archivio locale per installazione |
| Wishlist account | database | CRUD per bearer | sync dopo login e lista privata |
| Account | email/password e magic link | token, verifica, reset, challenge | login, registrazione, magic link |
| Sessioni | Sanctum | elenco e revoca per dispositivo | token cifrato e logout locale/remoto |
| Notifiche | email, database, Web Push | preferenze, archivio, read/read-all | API pronta; FCM richiede credenziali |
| Offline | cache web/server | ETag e sync incrementale | ultima lista valida in locale |
| Sponsorizzazioni | campagne native | placement + metriche firmate | payload compatibile |
| Legale | pagine CMS | pages/config legal | scelta iniziale e informative |

## Correzioni di sicurezza

1. I token Sanctum scadono dopo 90 giorni e sono nominati per dispositivo.
2. `GET /me/sessions` e `DELETE /me/sessions/{id}` permettono la revoca mirata.
3. Il magic link mobile usa un challenge hashato, monouso, bloccato in
   transazione e con scadenza; nessun bearer viaggia nell'URL.
4. La cancellazione del profilo richiede password corrente e conferma
   esplicita `CANCELLA`.
5. I token FCM sono identificati da hash globale: se la stessa installazione
   cambia account, la vecchia associazione e la relativa sessione vengono
   revocate.
6. L'idempotenza è separata per utente/installazione/IP e per URI completa;
   una risposta di una wishlist non può essere riusata per un altro account.
7. Le risposte private dichiarano cache privata e `Vary: Authorization,
   X-Installation-ID`.
8. La wishlist non accetta mai un identificativo utente dal client. I test
   dedicati dimostrano che due bearer non leggono né modificano i salvataggi
   reciproci.

## Pacchetti Laravel: scelta definitiva

- **Da usare, installati:** Laravel Sanctum per bearer token; Dedoc Scramble
  per OpenAPI; Laravel Notification Channels FCM per Android; Laravel
  Telescope solo in sviluppo/staff e spento per default.
- **Spatie è conveniente dove risolve un problema preciso:** Media Library,
  Permission e Activitylog restano scelte valide già integrate nel progetto.
- **Spatie Laravel Data non conviene qui:** FormRequest, API Resources e
  Scramble descrivono già input, output e contratto. Aggiungerlo ora
  introdurrebbe una seconda gerarchia di DTO da tenere sincronizzata senza
  dare vantaggi al client Kotlin, che legge l'OpenAPI.
- **Spatie Query Builder non conviene in questa fase:** il progetto ha un
  `EventOccurrenceQuery` condiviso fra sito, API e notifiche; sostituirlo
  rischierebbe di duplicare la definizione di “stasera”.
- **Socialite:** utile solo quando esisteranno credenziali e una decisione di
  prodotto su Google/Apple login; non è necessario per la prima APK.

## Contratto e resilienza

- 60 operazioni versionate sotto `/api/v1`.
- OpenAPI 3.1 esportata in `docs/openapi.json` e verificata in test.
- Letture pubbliche con ETag/304; scritture protette da rate limit e, dove
  necessario, chiave di idempotenza.
- `/sync` espone aggiornamenti di eventi, dipendenze e tombstone delle
  occorrenze eliminate, evitando database mobili eternamente stantii.
- `/home` riduce i round-trip e consegna sezioni editoriali, mappa, statistiche
  e contenuto sponsorizzato in un'unica risposta.

## Verifica eseguita

- PHPStan livello di progetto: 0 errori; Pint pulito; Composer senza advisory.
- Suite Laravel completa: 1.641 test, 5.968 asserzioni.
- Gruppo API isolato: 120 test, 1.062 asserzioni.
- Android: build debug e release minificata riuscite, lint release passato,
  4 test JVM e 3 test strumentali su Pixel virtuale Android 15.
- Controllo visivo a 1080×2400 su consenso, home, filtri, calendario, ricerca
  locale, mappa generale, dettaglio evento e dettaglio locale.
- Controllo reale dell'API di produzione e caricamento delle immagini remote.

## Dipendenze che il codice non può inventare

La push Android richiede un progetto Firebase: file `google-services.json` per
l'app e credenziali service account sul server. Il backend è predisposto e
ripiega su email/archivio finché `API_FEATURE_PUSH=false`. La firma Play Store
richiede allo stesso modo una chiave privata del proprietario; l'APK desktop è
installabile e firmata con chiave debug, non pubblicabile sullo Store.

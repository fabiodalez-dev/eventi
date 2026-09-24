# inCittà Android 1.17.0 (39)

L’app include le funzioni di scelta eventi completate nella review: informazioni pratiche della singola data, costi completi/parziali, prossime ore, preferenze follow, feed spiegabile e calendario delle date salvate.

Dal profilo si apre **Gestione eventi**, interamente nativa: date autorizzate, staff assegnabile/revocabile, lettura QR con fotocamera o inserimento del codice, informazioni e costi modificabili, statistiche degli ingressi e costi per prenotazione/presenza delle campagne.

Le letture senza risposta vengono conservate cifrate con chiave Android Keystore, legate all’account e ritentate nella sezione di gestione. Non autorizzano ingressi fino alla conferma del server. Una risposta persa conserva la stessa chiave UUID; una nuova scansione dopo un ingresso accettato viene trattata come nuovo tentativo e respinta. Logout o cambio account eliminano la coda locale.

La fotocamera usa [ZXing Android Embedded](https://github.com/journeyapps/zxing-android-embedded), con alternativa manuale e senza salvare le immagini dei QR. Il widget usa il provider pubblico per i colori giorno/notte, coerente con le [API Glance](https://developer.android.com/develop/ui/compose/glance/theme).

Wallet resta disattivato senza credenziali. Le prove su emulatore non certificano il comportamento di calendario e widget sui telefoni dei diversi produttori.

Gli artefatti APK e AAB vengono preparati separatamente: l’APK mantiene la firma storica per installazione diretta, l’AAB usa la chiave di upload privata. Nessun caricamento su Play è implicito nella generazione.

## Verifiche

- 155 test unitari release: zero errori, un test API live saltato.
- Lint release completato senza errori; restano avvisi preesistenti.
- Due test Compose su emulatore Android 15: staff e gestore.
- Cinque test della coda coprono risposta persa, ripristino, doppia scansione, isolamento account, revoca e codice invalido.
- Test API su permessi, revoca, idempotenza, validazione costi e privacy dei rapporti.
- Sedici test browser ricontrollati: allineamento card e import Facebook.

Artefatti locali: cartella `01-bundle` del kit inCittà. L’APK non richiede
Google Play per l’installazione; il bundle richiede il normale percorso Console.

SHA-256 APK: `ddd57245060a1c70c3b1151af123be1073de1a5d555739b67c5d30c09b99cec4`

SHA-256 AAB: `14e445fdd165c23caebe80aa11a20ff8fbebccd45f45aaf95941020d8dd722a3`

Entrambe le firme sono state verificate; export OpenAPI senza diagnostiche,
149 operazioni. Test API finali: 6 test e 874 asserzioni. PHPStan senza errori.

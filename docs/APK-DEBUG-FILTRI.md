# APK debug, filtri (9 settembre 2026)

- File: `/Users/fabio/Desktop/inCitta-1.8.4-debug-filtri.apk`.
- Pacchetto `it.fabiodalez.incitta`, versione `1.8.4-debug-filtri`, codice 20.
- Build realmente debuggabile, stessa firma Android Debug dell'APK 1.8.4
  già sul Desktop. Nessun bundle o caricamento Google Play.
- SHA-256: `b45e13bd48a4668660f653f8c3a5e187cfb40d5ff3df0b18d78036a468843f9f`.
- API: `https://eventi.fabiodalez.it/api/v1/`.

Aggiornamenti limitati ai filtri: vincoli contestuali, rimozioni indipendenti,
reset, raggi 1/5/10/25 km, prevenzione delle combinazioni vuote, selezione date,
tag e ordinamento. Lista, anteprime e mappa condividono la codifica dei parametri.
Le modifiche Google Play preesistenti nel workspace non sono attivate nella
build (`playStore` non impostato; nessuna firma upload utilizzata).

Verifiche: 59 test unitari, lint debug completato, 3 test UI sui tap di distanza,
prezzo e reset superati su emulatore Android 15. Installazione `adb install -r`
e avvio riusciti; nessun crash del processo avviato nel controllo finale.

Comando: `./gradlew -PsideloadDebug=true :app:testDebugUnitTest :app:lintDebug :app:assembleDebug`.
L'opzione `sideloadDebug` usa lo stesso application ID per aggiornare le precedenti
installazioni locali firmate debug. Non può aggiornare installazioni firmate
con una chiave diversa, ad esempio una futura distribuzione Play App Signing.

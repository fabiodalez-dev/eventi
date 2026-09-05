# inCittà Android

## Stack e build

- Kotlin 2.2.21, Jetpack Compose, min SDK 26, target/compile SDK 36.
- Package: `it.fabiodalez.incitta`; debug: `it.fabiodalez.incitta.debug`.
- API predefinita: `https://eventi.fabiodalez.it/api/v1/`.
- Build riproducibile: `cd android && ./gradlew test assembleDebug`.
- Override locale: `./gradlew assembleDebug -PapiBaseUrl=http://10.0.2.2:8000/api/v1/`.

## Navigazione

Cinque destinazioni persistenti: Eventi, Mappa, Cerca, Salvati, Profilo. Dalla
home si aprono anche Calendario e l'indice Locali. Il dettaglio evento include
locandina integrale, tutte le date, calendario di sistema, descrizione, listino,
prenotazione, locale collegato, mappa interattiva, accessibilità, tag, link,
condivisione ed eventi simili. Il dettaglio locale include contatti, orari,
accessibilità, indicazioni e tutti gli eventi prossimi e passati disponibili.

Sono accettati deep link HTTPS per eventi, locali, mappa, calendario, categorie,
tag e preset, oltre a `incitta://auth/magic?token=...`.

## Autenticazione e wishlist

Il bearer è cifrato AES-GCM con una chiave non esportabile di Android Keystore.
L'installation ID non è un'identità utente: serve a limiti, idempotenza e
device binding. Senza sessione la wishlist è un set locale. Dopo il login:

1. lo stato dell'account precedente viene rimosso dalla memoria;
2. si salva il nuovo bearer cifrato;
3. si invia `POST /me/saved/merge` con i soli `occurrence_id` ospite;
4. gli ID locali vengono cancellati solo dopo il successo;
5. si rilegge `/me/saved` dal server.

Un 401 elimina la sessione locale e impedisce che dati privati restino visibili.
La cancellazione account è disponibile nell'area critica e richiede la password
corrente; il client invia anche la conferma esplicita richiesta dal backend.

## UI

La sorgente è `DESIGN.md`: fondo `#0B0B0B`, carta `#F5F5F0`, lime `#CCFF00`,
Archivo SemiBold, fotografie in bianco e nero, divisori da 2 dp e raggio zero.
I target interattivi sono almeno 48 dp e hanno etichette per TalkBack.
Le mappe sono native MapLibre/OpenFreeMap e non richiedono una chiave Google.
Al primo avvio compare la scelta privacy con accetta/rifiuta e collegamenti alle
informative; non sono attivi cookie pubblicitari o profilazione.

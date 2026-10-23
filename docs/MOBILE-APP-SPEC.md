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

## Allineamento D60 (ottobre 2026)

Versione 1.19.0 (code 41). L'app porta le funzioni rilasciate sul server con [D60](DECISIONS.md) il 23 ottobre 2026, con le stesse rotte del sito e i testi in `res/values/strings.xml`.

- Scheda evento: foto in griglia a due colonne con l'ingrandimento a schermo intero; il video è un riquadro dell'app che al tocco apre `watch_url` fuori dall'app, senza WebView e senza richieste al fornitore prima del tocco; i co-organizzatori compaiono come «con Nome» e portano alla loro pagina. Ogni data ha «Aggiungi a una raccolta».
- Raccolte (`ui/CollectionsScreen.kt`, `data/CollectionsApi.kt`): elenco e creazione, dettaglio con rinomina, interruttore «condivisa con un link», link da copiare o condividere, «Togli» ed eliminazione; si raggiungono dal profilo e dai salvati. I link `/raccolte/{token}` aprono la raccolta condivisa in sola lettura, anche senza account.
- Ricerche salvate (`data/SavedSearchesApi.kt`): nella pagina Eventi e nella ricerca «Avvisami per nuovi eventi come questi» compare quando c'è almeno un criterio che il server conserva; in Profilo › Notifiche e newsletter la sezione «Le mie ricerche salvate» ha «Apri» e «Rimuovi». I filtri dell'app usano i nomi dell'API (`categories`, `tags`, `time_of_day`), le ricerche salvate quelli di `/eventi` (`category`, `tag`, `time`): `SavedSearchFilters` traduce nei due sensi.
- Biglietti: stato «Non presentato» e, con `meta.no_shows` maggiore di zero, l'avviso sulle assenze recenti (tre in novanta giorni bloccano le prenotazioni gratuite).
- Gestione di una data: «Scrivi a chi ha prenotato» (con conferma e tetto giornaliero del server) e «Aggiungi una prenotazione» dal banco, un nome per riga.
- Controllo ingressi senza rete: all'apertura della data l'app scarica il manifest (impronte SHA-256 dei codici e nomi) e lo tiene cifrato per 48 ore. Senza rete respinge subito i QR estranei e quelli già entrati; gli altri restano in coda con il nome accanto e l'ingresso è registrato solo quando il server conferma. Ogni lettura invia `scanned_at`.
- Scheda del locale: l'avviso in vista oggi (pausa, chiusura, riapertura, comunicazione) in alto, e «Calendario pubblico (ICS)» che apre `/locali/{slug}/calendario.ics`. Il campo `notice` di `GET /v1/venues/{slug}` è stato aggiunto per questo.

Restano fuori, e l'app rimanda al sito: Apple Wallet, cessione del biglietto, login social, rivendicazione di un locale e registrazione degli organizzatori. Gli strumenti del pannello web e della redazione (import CSV, fusione dei doppioni, ripristino dalla cronologia, registro delle ricerche senza risultati) non entrano nell'app.

## Allineamento D61 (1.20.0, 23 ottobre 2026)

Le dieci funzioni di [PIANO-UTENTE-2026-10-23](PIANO-UTENTE-2026-10-23.md) sono nell'app: avviso di sovrapposizione al salvataggio, link del riepilogo del lunedì verso i salvati, «aperto adesso» nella scheda e nell'elenco dei locali con il filtro, nascondere un locale e gestire i nascosti negli interessi, esigenze di accessibilità precompilate nei filtri con «Mostra tutto», biglietti salvati cifrati e mostrati senza rete, «Invia a questa persona» e invio per email con consenso, avviso di disponibilità sulle date esaurite, «Le mie serate», «sono qui» con il foglio di condivisione. Lo scanner manda al server anche i codici assenti dall'elenco quando la rete c'è (validata da Android) e mostra comunque l'avviso dell'elenco. Regola: ogni modifica per l'utente sul sito si porta nell'app nello stesso lavoro.

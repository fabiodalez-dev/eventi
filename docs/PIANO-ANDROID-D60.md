# Android — allineamento alle funzioni del 23 ottobre 2026 (D60)

Data: 23 ottobre 2026. Piano operativo per portare nell'app Android (`android/`, Kotlin + Compose, versione 1.18.0 / code 40) le funzioni rilasciate sul server con [D60](DECISIONS.md). Il server è già in produzione: ogni rotta citata qui risponde oggi su `eventi.fabiodalez.it`. Le forme JSON sono quelle di [API.md](API.md) («Aggiunte del 23 ottobre 2026») e di [TICKETING.md](TICKETING.md) («Strumenti del banco»).

## Come è fatta l'app (per chi la tocca)

- Un solo modulo `app`, pacchetto `it.fabiodalez.incitta`. Rete con OkHttp e kotlinx-serialization in `data/ApiClient.kt` (`get`/`post`/`delete`/`execute`, token Bearer, `Idempotency-Key` con `idempotent = true`). Le chiamate stanno in `data/AppRepository.kt` (pubbliche e account) e `data/ManagementApi.kt` (gestione date e check-in). I modelli in `data/Models.kt`, `data/TicketingModels.kt`, `data/ManagementApi.kt`.
- Stato in `MainViewModel.kt` (`AppUiState`, `AppTab`, funzioni `open*`, `load*`, `search`, `updateSearchFilters`). Navigazione in `ui/InCittaApp.kt`; profilo in `ui/ProfileScreen.kt` (righe `ProfileRow`); scheda evento in `ui/RichScreens.kt` (`CompleteEventDetailScreen`, sezioni `DetailSection("…")`); organizzatore in `ui/OrganizerScreen.kt`; biglietti in `ui/TicketingScreens.kt`; gestione e scanner in `ui/ManagementScreen.kt` con la coda `data/CheckinOutbox.kt` persistita da `data/LocalStore.kt` (`readCheckins`/`writeCheckins`, cifrati).
- Testi in `res/values/strings.xml` (126 voci) **e** in alcuni file Kotlin (`ui/CarpoolStrings.kt`): i testi nuovi vanno in `strings.xml`.
- Test unitari in `app/src/test` (JUnit 4, parsing dei modelli come `data/ApiCompatibilityTest.kt`, logica pura come `data/CheckinOutboxTest.kt`); test strumentali in `app/src/androidTest` (non girano in CI). La CI esegue `./gradlew testDebugUnitTest compileDebugKotlin compileDebugAndroidTestKotlin` in `android/`.
- Regole: nessun testo nuovo dentro i Kotlin, tocco minimo di 48 dp, niente informazione affidata al solo colore, stati vuoto/errore espliciti; il server resta l'unica fonte di verità (un ingresso è registrato solo quando il server risponde).

## Lotti, in ordine

Ogni lotto è un commit. Dopo ogni lotto: `./gradlew testDebugUnitTest compileDebugKotlin compileDebugAndroidTestKotlin` verde.

### 1. Scheda evento: galleria, video, co-organizzatori

Server: `GET /v1/events/{slug}` ora riporta `gallery` (lista di `{thumb, card, full, blurhash, width, height}`, stessa forma di `poster`), `video` (`{provider, embed_url, watch_url}` oppure `null`) e `organizers` (lista di `{id, name, slug, url}`).

- `data/Models.kt`: in `EventDetail` aggiungere `gallery: List<Poster> = emptyList()`, `video: EventVideo? = null`, `organizers: List<Organizer> = emptyList()`; nuova `@Serializable data class EventVideo(val provider: String, @SerialName("embed_url") val embedUrl: String, @SerialName("watch_url") val watchUrl: String)`.
- `ui/RichScreens.kt`, `CompleteEventDetailScreen`: dopo la locandina una `DetailSection("FOTO")` con griglia a due colonne (`card`, click apre `PosterLightbox` con `full`); una `DetailSection("VIDEO")` con un riquadro **nostro** (icona play, nome del fornitore, testo «si apre su YouTube/Vimeo») che al tocco apre `watch_url` nel browser con `LocalUriHandler`: **niente WebView, niente richieste al fornitore prima del tocco**. In «LINK E ORGANIZZATORE», dopo l'organizzatore principale, i co-organizzatori come «con Nome» cliccabili verso `onOrganizer(slug)`.
- Test: in `data/ApiCompatibilityTest.kt` (o un nuovo `EventExtrasModelsTest.kt`) il parsing di una risposta con e senza i tre campi.

### 2. Co-organizzatori nelle pagine organizzatore

Server: `GET /v1/organizers/{slug}` elenca già anche le date co-organizzate. Nessuna modifica di modello; verificare in `ui/OrganizerScreen.kt` che niente filtri per `organizer.slug == slug` lato client (se lo fa, togliere il filtro). Strings: nessuna.

### 3. Raccolte

Server: `GET|POST /v1/me/collections`, `GET|PATCH|DELETE /v1/me/collections/{id}`, `POST /v1/me/collections/{id}/items` (`occurrence_id`, `note`), `DELETE /v1/me/collections/{id}/items/{occurrence}`; pubblica `GET /v1/collections/{token}` (404 se privata). Forme in API.md.

- `data/CollectionsApi.kt` (nuovo): modelli `EventCollection(id, name, description, isPublic, publicUrl, itemsCount, createdAt)`, `CollectionItem(occurrenceId, title, startsAt, venue, url, note)`, `CollectionDetail`; classe `CollectionsApi(api, token)` con `list()`, `create(name, isPublic, occurrenceId?)`, `show(id)`, `update(id, name, description, isPublic)`, `delete(id)`, `addItem(id, occurrenceId, note?)`, `removeItem(id, occurrenceId)`, `shared(token)` (senza token).
- `ui/CollectionsScreen.kt` (nuovo): elenco con creazione (nome, interruttore «condivisa con un link»), dettaglio con rinomina, interruttore pubblico, link da copiare/condividere (`Intent.ACTION_SEND`), elenco date con `EventRow` e «Togli»; stati vuoto ed errore. Raggiungibile da `ProfileScreen` con una `ProfileRow("Le mie raccolte", "Elenchi di date da condividere con un link")` e dal `SavedScreen`.
- Scheda evento: in `OccurrenceDateBlock` (o accanto al salva) un'azione «Aggiungi a una raccolta» che apre un foglio con le raccolte dell'utente e «Nuova raccolta…»; se non si è dentro, porta al login.
- Deep link: `InCittaApp.kt` gestisce già `eventi`, `locali`, `organizzatori` dai path; aggiungere `raccolte/{token}` → `SharedCollectionScreen` (sola lettura, usa `GET /v1/collections/{token}`), e dichiarare il path nell'`AndroidManifest.xml` accanto agli altri (`android:pathPrefix="/raccolte"`).
- Test: parsing dei modelli; una classe pura `CollectionsRoutes` (se serve) con test come `ui/CommunityRoutesTest.kt`.

### 4. Ricerche salvate

Server: `GET|POST /v1/me/searches` (`filters` = mappa come la query string di `/eventi`, `name` facoltativo), `DELETE /v1/me/searches/{id}`.

- `data/SavedSearchesApi.kt` (nuovo): modello `SavedSearch(id, name, filters: Map<String,String>, url, lastCheckedAt)`; `list()`, `create(filters, name?)`, `delete(id)`.
- `ui/SearchFilters.kt` / `ui/Screens.kt` (`SearchScreen`, `EventsScreen` con filtri attivi): un pulsante «Avvisami per nuovi eventi come questi» visibile quando c'è almeno un filtro o una parola (la mappa `filters` che `apply` già produce, più `q`); da fuori sessione porta al login. Dopo il salvataggio, messaggio di conferma.
- Nelle preferenze di notifica (`ui/NotificationSettingsPanel.kt`) o in `ContentPreferencesPanel`: sezione «Le mie ricerche salvate» con elenco, «Apri» (applica i filtri con `updateSearchFilters`) e «Rimuovi».
- Test: parsing; una funzione pura che decide se il pulsante è visibile (filtri non vuoti) con test unitario.

### 5. Biglietti: assenze

Server: `GET /v1/me/bookings` riporta `meta.no_shows`; i biglietti hanno stato `no_show` oltre ai precedenti.

- `data/TicketingModels.kt`: nessun cambio di forma (lo stato è una stringa); `AppRepository.bookings()` deve leggere `meta.no_shows` (oggi legge solo `data`): aggiungere un campo allo stato (`AppUiState.noShows: Int`).
- `ui/TicketingScreens.kt`, `TicketsScreen`: etichetta «Non presentato» per `status == "no_show"`; in testa, se `noShows > 0`, l'avviso «Hai N prenotazioni recenti a cui non ti sei presentato…» (testo come `lang/it/ticketing.php` → `no_show.account`, con limite 3 e 90 giorni).
- Test: parsing di un biglietto `no_show` e dell'envelope con `meta.no_shows`.

### 6. Gestione: messaggio ai prenotati e prenotazione dal banco

Server: `POST /v1/ticketing/{occurrence}/message` (`subject`, `body`, `include_waitlisted`) → `{data: {id, recipients_count, created_at}}`, 422 con `error.fields.ticketing[0]` oltre il tetto; `POST /v1/ticketing/{occurrence}/guest-booking` (`names` una per riga in una stringa, `email`, `phone`, `note`) → prenotazione (forma `BookingResource`). Entrambe solo per chi `can_manage`.

- `data/ManagementApi.kt`: `message(id, subject, body, includeWaitlisted)`, `guestBooking(id, names, email?, phone?, note?)`.
- `ui/ManagementScreen.kt`, nel dettaglio di una data con `canManage`: due blocchi a scomparsa, «Scrivi a chi ha prenotato» (oggetto, testo, spunta lista d'attesa, invio con conferma; mostra «Inviato a N persone» o l'errore del server) e «Aggiungi una prenotazione» (nomi uno per riga, email, telefono, nota; mostra «Registrata: N biglietti»). Dopo la prenotazione ricaricare la data (`api.date`) per aggiornare i posti.
- Test: serializzazione dei body; parsing della risposta del messaggio.

### 7. Controllo ingressi senza rete

Server: `GET /v1/ticketing/{occurrence}/manifest` → `{data: {generated_at, window: {from, to}, tickets: [{h, name, status}]}}` dove `h` è SHA-256 esadecimale del codice; `POST /v1/ticketing/{occurrence}/check-in` accetta `scanned_at` (ISO 8601).

- `data/CheckinManifest.kt` (nuovo, logica pura e testabile): `data class ManifestEntry(name, status)`, classe `CheckinManifest(entries: Map<String, ManifestEntry>, generatedAt)` con `lookup(code): ManifestEntry?` (SHA-256 con `java.security.MessageDigest`), `markUsed(code)`; serializzabile per salvarla.
- `data/LocalStore.kt`: `readManifest(occurrenceId)`/`writeManifest(occurrenceId, manifest)` cifrati come i check-in; cancellare i manifest più vecchi di 48 ore.
- `data/CheckinOutbox.kt`: `PendingCheckin` acquista `scannedAt: String` (ISO, istante della lettura); `ManagementApi.checkIn` lo invia come `scanned_at`.
- `ui/ManagementScreen.kt`: all'apertura di una data con `canCheckIn` scaricare il manifest (e un pulsante «Aggiorna l'elenco»); mostrare «Elenco scaricato alle HH:mm: N biglietti» o «Elenco non scaricato: senza rete non riconosco i biglietti». Alla lettura di un codice: se il manifest c'è e il codice non è in elenco → rifiuto immediato («QR non presente nell'elenco scaricato»), senza accodare; se è in elenco con `checked_in` (o già segnato usato in locale) → «ingresso già registrato»; altrimenti accodare come oggi, con il nome accanto a «in attesa di rete». Sostituire il testo `management_online_only` con la spiegazione nuova. Il server resta l'unico a confermare: le righe accettate dal server restano le sole «Ingresso registrato».
- Test: `data/CheckinManifestTest.kt` (lookup con hash reale di un codice noto, markUsed, codice sconosciuto) e aggiornare `CheckinOutboxTest.kt` per `scannedAt`.

### 8. Pagina del locale: avviso e calendario pubblico

Server: la scheda del locale (`GET /v1/venues/{slug}`) non espone ancora l'avviso corrente. **Lotto condizionato**: se `VenueResource` non ha `notice`, aggiungere lato server `'notice' => ['kind', 'body', 'until']` (o `null`) in `app/Http/Resources/V1/VenueResource.php` con test in `tests/Feature/Api`, poi in `ui/RichScreens.kt` `VenueDetailScreen` un riquadro in alto con tipo, testo e «fino al …». Inoltre un'azione «Calendario pubblico (ICS)» che apre `https://eventi.fabiodalez.it/locali/{slug}/calendario.ics` nel browser (l'indirizzo base pubblico è `BuildConfig.API_BASE_URL` senza `api/v1/`; verificare come è costruito l'URL della scheda in `EventDetail.url`).

### 9. Versione, documentazione e chiusura

- `android/app/build.gradle.kts`: `versionCode = 41`, `versionName = "1.19.0"`.
- `docs/MOBILE-APP-SPEC.md`: paragrafo «Allineamento D60 (ottobre 2026)» con le schermate nuove e cosa resta fuori (Apple Wallet, cessione biglietto, login social, rivendicazione locale e registrazione organizzatori — flussi web, l'app rimanda al sito).
- `docs/API.md`: aggiornare la riga «l'app Android non li mostra ancora» con quanto fatto.
- Nessun APK né bundle in questo lotto: la pubblicazione su Play è una decisione a parte ([GOOGLE-PLAY.md](GOOGLE-PLAY.md)).

## Cosa non entra

Rivendicazione di un locale, registrazione degli organizzatori, import CSV, fusione doppioni, ripristino dalla cronologia, registro ricerche senza risultati: sono strumenti del pannello web e della redazione, non dell'app. L'avviso del locale a chi lo segue arriva già come notifica push (tipo `venue_notice`) tramite il canale esistente; serve solo che `NotificationModels` tolleri il tipo nuovo (verificare che il parsing non lo scarti).

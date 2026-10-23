# Dieci funzioni per chi usa inCittà — sito e app insieme

Data: 23 ottobre 2026. Scelte da Fabio dalla lista «15 cose utili»: 3, 4, 5, 8, 9, 11, 12, 13, 14, 15. Regola: sito e app Android restano allineati (vedi memoria «Android sempre allineata»). Questo documento è il **contratto** fra server e app: le forme JSON qui sono vincolanti per entrambe le parti. Tutte le rotte `/v1/me/…` richiedono `auth:sanctum`; risposte nell'envelope `{ "data": … }` salvo i 204.

## 3. Sovrapposizioni fra salvati

- Regola: due date si sovrappongono se gli intervalli `[starts_at, effective_ends_at)` si intersecano. Si confronta la data appena salvata con le altre date **salvate** e con quelle che hanno una **prenotazione attiva** della stessa persona; la data stessa è esclusa.
- `POST /v1/me/saved` (body invariato `{occurrence_id}`) → `data` aggiunge `conflicts: [{occurrence_id, title, starts_at, ends_at, kind}]` con `kind` = `"saved"` o `"booked"`; lista vuota se nessuna. Il campo `message` resta.
- Web `POST /salvataggi` (JSON): stessa lista `conflicts`; `message` diventa «Salvato. Si sovrappone a «Titolo» (21:00).» quando c'è almeno una sovrapposizione.
- `GET /v1/me/saved`: ogni elemento resta com'è; nessun campo nuovo (la segnalazione è al momento del salvataggio). Sul sito la pagina dei salvati mostra l'etichetta «Si sovrappone a …» accanto alle date in conflitto.
- **App:** dopo il salvataggio, se `conflicts` non è vuota, messaggio con il primo titolo e l'orario.

## 4. Riepilogo del lunedì dei salvati

- Nuovo tipo di notifica `saved_weekly`. Pianificato il lunedì alle 09:00 nel fuso della persona, per chi ha l'interruttore **promemoria** acceso e almeno una data salvata nei sette giorni successivi. Contenuto: le date della settimana, con «annullato» o «spostato» dove serve; collegamento a `/i-miei-salvataggi`.
- Arriva sui canali esistenti (push, email, archivio in-app). **App:** nessuna schermata nuova; il tipo nuovo deve essere tollerato dal parsing delle notifiche (lo è già: non c'è un elenco chiuso) e l'azione apre i salvati.

## 5. «Aperto adesso»

- Orari in `venues.opening_hours` come `{"mon":[{"open":"10:00","close":"13:00"}, …], …}`; una chiusura prima dell'apertura significa dopo mezzanotte (la fascia del giorno prima vale fino a quell'ora).
- `GET /v1/venues/{slug}` e gli elementi di `GET /v1/venues`: campi nuovi `open_now` (`true`/`false`, `null` se il locale non ha orari) e `open_until` (`"HH:MM"` o `null`).
- `GET /v1/venues?open_now=1`: solo i locali aperti adesso.
- Web: etichetta «Aperto adesso · fino alle 19:30» / «Chiuso adesso» sulla scheda e nell'elenco dei locali; filtro «Aperti adesso» nell'elenco (`?aperto=1`).
- **App:** etichetta nella scheda del locale e nelle righe dell'elenco; un filtro «Aperti adesso» nell'elenco dei locali che usa `open_now=1`.

## 8. Nascondere un locale

- Le preferenze di contenuto (`content_preferences`) acquistano `hidden_venues: list<int>`. Le date dei locali nascosti spariscono da elenchi, mappa, ricerca, feed e proposte, come le categorie nascoste; restano raggiungibili dal link diretto e restano nei salvati e nei biglietti.
- `GET /v1/me/content-preferences` → `data.selection` aggiunge `hidden_venues` (id) e `access_needs` (vedi 9); `data` aggiunge `hidden_venue_options: [{id, name, slug}]` (i locali nascosti, con nome, per mostrarli).
- `PATCH /v1/me/content-preferences`: `hidden_venues` e `access_needs` **facoltativi**; se mancano, il server conserva i valori già salvati (le app vecchie non li cancellano).
- Scorciatoie: `POST /v1/me/hidden-venues/{venue_id}` e `DELETE /v1/me/hidden-venues/{venue_id}` → 204. Web: `POST /locali-nascosti/{venue}` e `DELETE /locali-nascosti/{venue}`.
- Web: sulla scheda del locale, per chi è dentro, «Non mostrarmi questo locale» / «Mostra di nuovo questo locale»; nella pagina degli interessi l'elenco dei locali nascosti con «Mostra di nuovo».
- **App:** stessa azione nella scheda del locale (con conferma), elenco dei nascosti nel pannello degli interessi.

## 9. Esigenze di accessibilità nel profilo

- `content_preferences.access_needs: list<string>` con i valori di `AccessibilityFeature`: `step_free_entrance`, `accessible_toilets`, `reserved_seating`, `tactile_path`, `assistance_on_request`, `guide_dog_allowed`.
- Web: su `/eventi` e sulle liste derivate, per chi è dentro e ha esigenze, se l'indirizzo **non** porta già il parametro `access`, i filtri le applicano e una striscia lo dice: «Filtri del tuo profilo: ingresso senza gradini · Mostra tutto». «Mostra tutto» porta lo stesso indirizzo con `access=nessuna`, che non applica nulla. Le pagine di chi è dentro non passano dalla cache delle pagine, quindi nessun rischio di servire i filtri di uno a un altro.
- API: `GET /v1/events` **non** applica nulla da solo (resta prevedibile): l'app legge `access_needs` e precompila il filtro.
- Gestione: casella per ciascuna esigenza nella pagina degli interessi (web) e nel pannello degli interessi (app), salvate con `PATCH /v1/me/content-preferences`.
- **App:** quando si aprono i filtri senza un `access` già scelto, preselezionare le esigenze del profilo e mostrare la stessa striscia con «Mostra tutto».

## 11. Biglietti nell'app anche senza rete

- Solo app (sul sito c'è già il PDF da scaricare). Dopo ogni lettura riuscita di `GET /v1/me/bookings`, salvare la risposta cifrata in `LocalStore`; se la rete non c'è, mostrare i biglietti salvati con «Ultimo aggiornamento: …» e i QR dei biglietti ancora validi. Le prenotazioni annullate dal server mentre si era senza rete risultano valide finché non si aggiorna: la striscia lo dice. Cancellare la copia al logout.

## 12. Un biglietto per ciascun partecipante

- Ogni biglietto valido ha un **link personale** firmato che mostra solo quel QR: chi prenota lo inoltra lui (WhatsApp, SMS, email propria). Nessun messaggio parte dal server verso persone che non l'hanno chiesto.
- `BookingResource.tickets[]` aggiunge `share_url` (stringa, solo per i biglietti validi; `null` altrimenti). Scade alla fine dell'evento più sei ore. La pagina pubblica `GET /biglietto/{ticket}?expires=…&signature=…` mostra titolo, data, luogo, nome sul biglietto e il QR se il biglietto è ancora valido; altrimenti lo stato, senza QR.
- Invio facoltativo per email, con consenso dichiarato: `POST /v1/me/tickets/{ticket}/send` body `{email, consent: true}` → `{data: {message}}`; web `POST /biglietti/biglietto/{ticket}/invia`. Una email per biglietto ogni 24 ore, dieci all'ora per persona. L'indirizzo **non si conserva**: nel registro resta solo che un biglietto è stato inviato. Il testo dice chi l'ha mandato e che l'indirizzo non è stato salvato.
- **App:** per ogni biglietto valido «Invia a questa persona» (foglio di condivisione di Android con il link) e, sotto, «Invia per email» con campo indirizzo e casella «Questa persona sa che le scrivo e mi ha dato l'indirizzo».

## 13. Avvisami se tornano disponibili

- Tabella `availability_alerts(user_id, occurrence_id)`, una riga per persona e data. Si attiva su una data **esaurita**: `status = sold_out`, oppure tutte le fasce di prezzo della data o dell'evento in `sold_out`.
- `GET /v1/me/availability-alerts` → `{data: [occurrence_id, …]}`; `POST /v1/me/availability-alerts/{occurrence}` → 201 (422 se la data non è esaurita o è passata); `DELETE /v1/me/availability-alerts/{occurrence}` → 204. Web: `POST|DELETE /avvisi-disponibilita/{occurrence}`.
- Quando la data torna disponibile (stato da `sold_out` a `scheduled`, o una fascia da `sold_out` a `available`), parte una notifica `tickets_available` a chi l'aveva chiesta e gli avvisi di quella data si chiudono: è un avviso di una volta.
- **App:** nella scheda evento, sulle date esaurite, «Avvisami se tornano disponibili» / «Avviso attivo · annulla».

## 14. Le mie serate

- Le date passate in cui almeno un biglietto della persona ha l'ingresso registrato, dalla più recente.
- `GET /v1/me/attended?page=N` → `{data: [{occurrence_id, title, event_slug, starts_at, url, venue: {name, slug} | null, organizer: {name, slug} | null, checked_in: int}], meta: {next_page: int|null}}`.
- Web: `/le-mie-serate`, dal menu dell'area personale.
- **App:** «Le mie serate» nel profilo; ogni riga apre la data, con collegamenti al locale e all'organizzatore.

## 15. «Sono qui»

- Un link temporaneo, per chi raggiunge qualcuno a un evento: mostra **la posizione del locale** (non quella del telefono), titolo, orario, un nome scelto dalla persona e una nota facoltativa. Scade alla fine della data più due ore; si revoca quando si vuole.
- Si crea solo per una data che comincia entro dodici ore o è in corso.
- `POST /v1/me/presence` body `{occurrence_id, display_name?, note?}` → 201 `{data: {token, url, expires_at}}`; `DELETE /v1/me/presence/{token}` → 204. Pagina pubblica `/sono-qui/{token}` (404 dopo scadenza o revoca), non indicizzabile, con il collegamento alle indicazioni stradali verso il locale.
- Web: sulla pagina della singola data, se la data è di oggi, «Condividi “sono qui”» con il link pronto e «Smetti di condividere».
- **App:** stessa azione nella scheda della data di oggi, che apre il foglio di condivisione con il link; «Smetti di condividere» finché il link è attivo.

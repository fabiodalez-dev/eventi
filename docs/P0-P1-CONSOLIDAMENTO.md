# P0/P1 — primo lotto di consolidamento

Data: 8 settembre 2026. Riferimento: [roadmap di prodotto](ROADMAP-PRODOTTO.md).

## Ambito realizzato nei sorgenti

- Organizzatori separati dai locali, archivi e ricerca web/API/Android.
- Follow degli organizzatori su sito e Android, con controlli di accesso e
  alimentazione del feed tramite il motore temporale condiviso.
- Scelta separata per includere l'organizzatore nei riepiloghi: il nuovo follow
  non attiva implicitamente questa scelta. Restano applicate le preferenze
  generali, la verifica dell'email e le regole di consegna delle notifiche.
- Il flag `notify` viene rispettato nella selezione dei digest anche per le
  sorgenti già esistenti. Ripetere il follow permette di aggiornare la scelta
  senza creare duplicati.
- “Prima di andare”: informazioni strutturate aggiuntive per tessera, costi
  obbligatori e maltempo, disponibili nei form condivisi e nel pannello organizzatori.
- Le indicazioni pratiche ereditate usano il locale della singola data; le
  indicazioni esplicite dell'evento conservano la precedenza.
- Accessibilità non comunicata o sconosciuta distinta da risposta negativa.
- Android mantiene la data aperta come riferimento per le azioni del dettaglio,
  senza sostituirla accidentalmente con la prima data dell'evento.
- Correzione degli errori lint nei testi di login, calendario e notifiche Android.

## Contratto API additivo

- `organizer` è un nuovo valore del tipo follow.
- `POST /api/v1/me/follows` accetta `type`, `id`, `notify`; per gli organizzatori
  il valore omesso di `notify` equivale a `false`.
- `GET /api/v1/me/follows/{type}/{id}` restituisce `following` e `notify` per il
  solo account autenticato. Non restituisce informazioni sui follow di altri utenti.
- I dettagli delle occorrenze dentro `GET /api/v1/events/{slug}` includono
  `content_details` risolto rispetto al luogo effettivo della data.
- Organizzatori inattivi non possono essere seguiti nuovamente e non alimentano
  il feed, anche se una relazione precedente è ancora memorizzata.

## Casi limite introdotti o verificati

- Autenticazione obbligatoria e isolamento fra account.
- Organizzatore inesistente, inattivo o tipo follow contraffatto.
- Follow ripetuto, modifica del consenso ai riepiloghi e mancata duplicazione.
- Evento nel feed anche con riepiloghi disattivati, ma escluso dal digest.
- Venue di una data appartenente a un'altra città rifiutata.
- Locale ospitante usato come organizzatore in assenza di uno separato.
- Preferenze di categoria applicate agli archivi degli organizzatori.
- Informazioni pratiche del luogo effettivo e precedenza delle informazioni esplicite.
- Accessibilità sconosciuta non trasformata in “no”.
- Apertura di una data successiva, assenza di duplicati e rifiuto di una data di altro evento.
- Payload Android contenente esplicitamente tipo follow e scelta negativa sulle notifiche.
- Risposte asincrone del follow non applicate alla schermata di un account diverso.

## Non dichiarare P0 e P1 interamente concluse

Questo è un primo lotto, non il completamento integrale delle due fasi.
Restano da completare o verificare esplicitamente:

- Il wizard “Stasera” per zona/budget e spiegazioni è ora implementato nei sorgenti:
  vedere [Scelta rapida](SCELTA-RAPIDA.md). Restano eventuali spiegazioni aggiuntive
  nel feed generale, fuori dal wizard.
- Percorso completo di gestione delle sorgenti seguite e delle preferenze nelle varie schermate.
- Collaudo della nuova versione Android installata su dispositivo: richiede
  un nuovo APK autorizzato, non generato da questo lotto.
- Verifica end-to-end di consegna push/email e integrazione calendario su dispositivi reali.
- Parità delle versioni effettivamente distribuite: sorgenti locali non equivalgono a deploy.

Il piano PRO è ammesso per locali paganti o autorizzati gratuitamente dagli admin;
il relativo sistema commerciale non è stato implementato in questo lotto P0/P1.
Restano esclusi commissioni e abbonamenti utenti.

## Modalità di verifica

Suite PHP sul solo database di test, test JavaScript, test unitari Android release,
lint Android, PHPStan e build web. Verifiche visuali in Chrome su mobile e desktop,
senza Lighthouse. I dati locali temporanei usati per le verifiche vengono rimossi;
nessuna password esistente viene modificata. Gli esiti numerici sono riportati
nel riepilogo finale della verifica, senza equiparare test unitari a test su telefono.

Verifiche già concluse per questo lotto:

- Suite PHP completa: 1.844 test eseguiti, 1.842 inizialmente passati e due
  regressioni rilevate (area di tocco del link organizzatore e configurazione
  Firebase non esplicitamente disabilitata nel test del caso non configurato).
  Entrambe corrette; rieseguiti integralmente i tre gruppi coinvolti
  (`PushChannelTest`, `EventPageTest`, `Organizers`): 37 test passati e 182
  asserzioni. La suite completa non è stata ripetuta una seconda volta.
- Android: 37 test passati, incluso il test opt-in di decodifica delle API locali
  con i modelli effettivi dell'app; nessun test saltato nell'ultimo giro.
- Android lint release: nessun errore, restano avvisi non bloccanti da valutare separatamente.
- JavaScript: 6 test passati.
- PHPStan: nessun errore.
- Build Vite: riuscita, con avviso sulla dimensione di alcuni bundle.
- Chrome: archivio organizzatore mobile e pagina evento mobile/desktop;
  console senza errori e nessun overflow orizzontale rilevato sulle pagine verificate.

Nessun nuovo APK e nessun deploy sono stati eseguiti da questo lotto.

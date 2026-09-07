# Calendario autenticato Android (1.6.0)

Il collegamento usa CalendarContract, non un abbonamento webcal né un URL
Google `cid`. Google Calendar Android non supporta l'aggiunta di calendari da
URL nell'app: https://support.google.com/calendar/answer/37100.

- Accesso inCittà obbligatorio; consenso runtime READ/WRITE_CALENDAR.
- Calendario locale `inCittà · Eventi`, account locale dedicato
  `it.fabiodalez.incitta.device`. Non sincronizzato nel cloud Google.
- API `GET /api/v1/me/calendar/export`, protetta da Sanctum e no-store.
  Contiene date normalizzate per CalendarContract e copia ICS; nessun token
  nell'URL, nessun link pubblico sottoscrivibile.
- Preferenze salvate sul dispositivo, associate all'utente. Aggiornamento
  all'apertura, manuale e JobScheduler ogni sei ore circa con rete disponibile.
  Android può ritardare il job; dopo riavvio del telefono riaprire inCittà.
- Upsert per ID occorrenza, senza duplicazioni; rimozione delle date ritirate,
  fuori finestra o escluse dai nuovi filtri. Limite feed configurato sul server.
- Batch provider atomico. Nessuna lettura/modifica dei calendari estranei.
- Logout, cambio account, revoca server (HTTP 401) e scollegamento fermano il
  job e rimuovono il solo calendario inCittà. Se il permesso è stato revocato
  Android impedisce la cancellazione: ripristinarlo o rimuovere il calendario
  dall'app calendario. Le copie ICS già esportate non sono revocabili.
- Il vecchio `/eventi.ics` (anche prefissato per città) richiede ora login web:
  non può più alimentare abbonamenti anonimi. Il wizard web offre download
  statico autenticato e spiega la sincronizzazione nativa disponibile nell'app.

Test: NativeCalendarTest usa il provider Android reale e verifica upsert,
ritiro date, account switch, logout, guest, cambio sessione durante la richiesta
e isolamento da un secondo calendario. NativeCalendarLiveTest è opt-in con
un token temporaneo, verifica l'API remota e l'apertura di Google Calendar.
Non salvare token di test, password o chiavi negli artefatti/versionamento.

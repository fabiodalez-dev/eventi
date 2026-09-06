# Email e script del consenso

## Pannello

- Sistema → Testi delle email: pulsanti con nome leggibile del segnaposto,
  inserimento in fondo al campo, esempio aggiornato all'uscita dal campo,
  testo originale espandibile. I segnaposti sconosciuti impediscono il salvataggio.
  Gli esempi non inviano email e usano dati dimostrativi.
- Sistema → Script e consenso (`/admin/script-consenso`): creazione, modifica,
  eliminazione, attivazione e ordine. Accesso riservato ad admin e super_admin.
  Inserire un URL HTTPS e/o codice JavaScript senza tag HTML. Il codice inline
  precede il caricamento defer dello script esterno, per la configurazione.
- Sistema → Cookie (`/admin/cookie`): registro informativo, distinto dagli script.

Le categorie sono le stesse del banner: necessary, statistics, marketing.
I nuovi script sono disattivati. Gli script facoltativi non vengono inclusi
nella pagina prima del consenso; quelli necessari non richiedono consenso.
Con script attivi, il banner usa il POST normale con ricaricamento per applicare
subito la scelta. Modifiche e disattivazioni invalidano le copie della pagina.
Gli script non sono caricati nel pannello Filament. Solo gli amministratori
devono inserire codice di fornitori attendibili: viene eseguito sul sito pubblico.
La revoca impedisce caricamenti successivi, non elimina automaticamente cookie
di terze parti. Aggiornare informative e registro quando si aggiunge un servizio.
Non è un sistema di scansione o autoblocco di script inseriti altrove.

## Modifiche agli eventi

Il pannello locale permette di modificare o annullare le date tramite il relativo
relation manager. Le policy limitano le operazioni al locale autorizzato.
Gli aggiornamenti salvano i singoli modelli, attivando gli observer:

- Agenda: EventOccurrenceObserver → NotificationScheduler → invio programmato.
  Per annullamento e cambio inizio vengono avvisati gli utenti che hanno salvato
  la data. Il canale può essere push se disponibile, altrimenti email.
- Prenotazioni: TicketingObserver → TicketingService → BookingChanged in coda.
  L'annullamento revoca i ticket; i cambi di data/orario notificano i prenotati.
  Queste email non sono personalizzate dalla pagina Testi delle email.

Sul pubblico il 6 settembre 2026 sono stati verificati sendmail, coda database,
cron scheduler/worker ogni minuto e assenza di job falliti. Questo non certifica
la consegna in casella: gli account .test non possono ricevere posta.
Nessuna email di prova è stata inviata a destinatari pubblici durante la verifica.

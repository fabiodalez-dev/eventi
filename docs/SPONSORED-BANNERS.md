# Banner sponsorizzati (web e Android)

## Gestione

Nel pannello admin, **Banner sponsorizzati** (`/admin/sponsorship-banners`) contiene due interruttori indipendenti per sito e Android. Solo admin e superadmin possono modificarli. Non modificano le collocazioni originali delle campagne.

I banner usano le campagne già attive: nessun nuovo contratto, pagamento o diritto del locale. Restano obbligatori autorizzazione/pagamento del grant, finestra della campagna e limiti di impression/click. La rotazione mantiene priorità e pesi; un evento con più campagne appare una volta sola nel pool aggiuntivo.

Web: banner prima del footer nelle pagine di scoperta (home, eventi, locali, ricerca, categorie e tag) e nel profilo. Android: banner sopra la navigazione nelle viste eventi, ricerca, locali, dettagli e profilo autenticato; escluso da login, prenotazione, mappa e finestre troppo basse. Nessuna sovrapposizione ai contenuti. L'evento già aperto è escluso.

Per creare o aggiornare tre campagne di prova sugli eventi esistenti: `php artisan db:seed --class=SponsorshipBannerDemoSeeder`. Sono riconoscibili dalla nota **DEMO locale — banner di prova** e non registrano pagamenti. Il seeder è idempotente, non viene eseguito dai deploy e rifiuta l'ambiente production. La rotazione cambia ogni minuto: non compaiono tutte e tre insieme.

Su richiesta esplicita del proprietario si possono pubblicare anche sul remoto: `php artisan sponsorships:demo padova --allow-production`. Il comando non è inserito in cron/deploy e senza il flag rifiuta production. La dicitura nelle note resta la stessa per idempotenza; sono campagne dimostrative anche quando pubblicate.

## Preferenze e feed

Nel **Mio feed** il banner è in testa. Locali e categorie rimangono gestibili dopo il primo follow: sei per pagina, ricerca locali e paginatori indipendenti. Gli eventi del feed usano pagine esplicite da 12 risultati, non scroll infinito.

Le categorie escluse nelle preferenze personali non vengono mai sponsorizzate a quell'utente, indipendentemente dal pagamento. Fra i candidati ammessi l'affinità non cambia priorità commerciale: modifica solo il peso nella rotazione, senza salvarlo sulla campagna. Moltiplicatori non cumulativi: ×4 locale/evento seguito, ×3 categoria dichiarata nei propri interessi o categoria/tag seguito, ×2 contesto categoria/tag/locale della pagina, ×1,5 categoria di eventi salvati negli ultimi 90 giorni (disattivabile), ×1 senza segnali. Il segnale inferito scade dopo la finestra configurata, non diventa una preferenza permanente. Nessuna cronologia dei click, fingerprint o dato esterno. La stessa regola vale per banner orizzontali, hero e card sponsorizzate.

Il sito legge il banner da `/{city}/banner-sponsorizzato?platform=web`, con la sessione web; Android dall'API con il proprio token, quando presente. Entrambe le risposte sono private e non memorizzabili. Nessun user_id può essere scelto dal chiamante. Gli anonimi usano soltanto il contesto esplicito della pagina, altrimenti la rotazione neutra.

## Scadenze e cache

`Sponsorship::visible()` esclude eventi senza date valide dalla mezzanotte locale successiva alla fine effettiva. Valgono solo date scheduled/sold_out. Eventi su più giorni e ricorrenti restano eleggibili se esiste ancora una data valida. La home hero conserva la sua regola più restrittiva: nessuna data già terminata.

`GET /api/v1/sponsorships/banner?platform=web|android&city=padova&exclude_event=slug` restituisce `data: null` oppure un banner autocompilato. Non è nella cache JSON; risponde `Cache-Control: no-store, private`. Il contenitore web è compatibile con HTML in cache: il contenuto arriva separatamente. Il banner ha una validità massima di 60 secondi, limitata anche da mezzanotte e fine campagna/grant. Refresh ogni 45 secondi, solo in primo piano. Offline o a scadenza si nasconde. Gli interruttori valgono entro un minuto anche sulle viste già aperte.

Le metriche usano gli endpoint e i limiti di deduplicazione delle campagne esistenti. Web misura l'impression solo quando almeno metà banner è visibile; Android solo quando composto in una schermata ammessa. Non si misurano semplici download API.

## Calendario Android

Il calendario personalizzato è un calendario **locale al dispositivo**, aggiornato da inCittà dopo login: non una sottoscrizione nel cloud Google. Prima dell'inserimento compare una conferma; dopo, il numero di date inserite e l'apertura facoltativa dell'app Calendario. Logout/scollegamento eliminano solo il calendario inCittà. L'apertura punta a un giorno con date importate, se presenti.

Per un singolo evento si usa `ACTION_INSERT` sul Calendar Provider (editor con pulsante Salva), preferendo Google Calendar. Il vecchio link web TEMPLATE rimane solo il fallback quando non è disponibile un'app nativa.

## Verifiche

### Registro dei clic

La voce **Statistiche sponsorizzazioni** è disponibile in admin e gestione locale, con ricerca della campagna, periodi di 7/30/90 giorni, contatori, grafici e registro da 25 clic per pagina. Le query del locale verificano il tenant e la sua appartenenza anche nelle richieste Livewire. Gli ID di campagne altrui sono rifiutati.

`sponsorship_clicks` conserva soltanto campagna, istante UTC, canale, collocazione e tipo di pagina. Nessun IP, identificativo utente o URL con query string. La chiave tecnica rende idempotente la ritrasmissione di un singolo clic; clic distinti hanno chiavi diverse. Contatori cumulativi, aggregato giornaliero e registro vengono aggiornati nella stessa transazione. I client API precedenti senza `click_id` mantengono la deduplicazione di 15 minuti; il nuovo sorgente Android invia un UUID per azione, senza distribuire un APK.

Il registro non può ricostruire i clic storici: i precedenti aggregati rimangono nei contatori/grafici. Non si promette una misurazione assoluta: blocchi JavaScript, perdita di connessione e limiti antiabuso possono impedire il conteggio. Le impression restano aggregate, non sono un log di navigazione personale.

- Test PHP `SponsorshipBannerTest`: autocompilazione, metriche, interruttori, autorizzazioni, esclusione dettaglio corrente, mezzanotte Europe/Rome, eventi su più giorni e ricorrenti.
- Regressioni dell'intera cartella `tests/Feature/Sponsorships` e `MobileHomeAndSponsorshipTest`.
- Android: `SponsoredBannerTest`, `SponsoredBannerUiTest`, `CalendarLinksTest`, `CalendarConfirmationUiTest`, `NativeCalendarTest`.
- Prova visiva Chrome a 390 px e verifica console; test Android su emulatore API 35.

Non cambiare versionCode/versionName né distribuire un nuovo APK senza conferma dell'utente.

# Banner sponsorizzati (web e Android)

## Gestione

Nel pannello admin, **Banner sponsorizzati** (`/admin/sponsorship-banners`) contiene due interruttori indipendenti per sito e Android. Solo admin e superadmin possono modificarli. Non modificano le collocazioni originali delle campagne.

I banner usano le campagne già attive: nessun nuovo contratto, pagamento o diritto del locale. Restano obbligatori autorizzazione/pagamento del grant, finestra della campagna e limiti di impression/click. La rotazione mantiene priorità e pesi; un evento con più campagne appare una volta sola nel pool aggiuntivo.

Web: banner prima del footer nelle pagine di scoperta (home, eventi, locali, ricerca, categorie e tag). Android: banner sopra la navigazione nelle viste eventi, ricerca, locali e dettagli; escluso da login, prenotazione e mappa. Nessuna sovrapposizione ai contenuti. L'evento già aperto è escluso.

## Scadenze e cache

`Sponsorship::visible()` esclude eventi senza date valide dalla mezzanotte locale successiva alla fine effettiva. Valgono solo date scheduled/sold_out. Eventi su più giorni e ricorrenti restano eleggibili se esiste ancora una data valida. La home hero conserva la sua regola più restrittiva: nessuna data già terminata.

`GET /api/v1/sponsorships/banner?platform=web|android&city=padova&exclude_event=slug` restituisce `data: null` oppure un banner autocompilato. Non è nella cache JSON; risponde `Cache-Control: no-store, private`. Il contenitore web è compatibile con HTML in cache: il contenuto arriva separatamente. Il banner ha una validità massima di 60 secondi, limitata anche da mezzanotte e fine campagna/grant. Refresh ogni 45 secondi, solo in primo piano. Offline o a scadenza si nasconde. Gli interruttori valgono entro un minuto anche sulle viste già aperte.

Le metriche usano gli endpoint e i limiti di deduplicazione delle campagne esistenti. Web misura l'impression solo quando almeno metà banner è visibile; Android solo quando composto in una schermata ammessa. Non si misurano semplici download API.

## Calendario Android

Il calendario personalizzato è un calendario **locale al dispositivo**, aggiornato da inCittà dopo login: non una sottoscrizione nel cloud Google. Prima dell'inserimento compare una conferma; dopo, il numero di date inserite e l'apertura facoltativa dell'app Calendario. Logout/scollegamento eliminano solo il calendario inCittà. L'apertura punta a un giorno con date importate, se presenti.

Per un singolo evento si usa `ACTION_INSERT` sul Calendar Provider (editor con pulsante Salva), preferendo Google Calendar. Il vecchio link web TEMPLATE rimane solo il fallback quando non è disponibile un'app nativa.

## Verifiche

- Test PHP `SponsorshipBannerTest`: autocompilazione, metriche, interruttori, autorizzazioni, esclusione dettaglio corrente, mezzanotte Europe/Rome, eventi su più giorni e ricorrenti.
- Regressioni dell'intera cartella `tests/Feature/Sponsorships` e `MobileHomeAndSponsorshipTest`.
- Android: `SponsoredBannerTest`, `SponsoredBannerUiTest`, `CalendarLinksTest`, `CalendarConfirmationUiTest`, `NativeCalendarTest`.
- Prova visiva Chrome a 390 px e verifica console; test Android su emulatore API 35.

Non cambiare versionCode/versionName né distribuire un nuovo APK senza conferma dell'utente.

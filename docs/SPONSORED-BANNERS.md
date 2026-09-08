# Banner sponsorizzati (web e Android)

## Gestione

Nel pannello admin, **Banner sponsorizzati** (`/admin/sponsorship-banners`) contiene due interruttori indipendenti per sito e Android. Solo admin e superadmin possono modificarli. Non modificano le collocazioni originali delle campagne.

I banner usano le campagne già attive: nessun nuovo contratto, pagamento o diritto del locale. Restano obbligatori autorizzazione/pagamento del grant, finestra della campagna e limiti di impression/click. La rotazione mantiene priorità e pesi; un evento con più campagne appare una volta sola nel pool aggiuntivo.

Web: banner prima del footer nelle pagine di scoperta (home, eventi, locali, ricerca, categorie e tag) e nel profilo. Android: banner sopra la navigazione nelle viste eventi, ricerca, locali, dettagli e profilo autenticato; escluso da login, prenotazione, mappa e finestre troppo basse. Nessuna sovrapposizione ai contenuti. L'evento già aperto è escluso.

Per creare o aggiornare tre campagne di prova sugli eventi esistenti: `php artisan db:seed --class=SponsorshipBannerDemoSeeder`. Sono riconoscibili dalla nota **DEMO locale — banner di prova** e non registrano pagamenti. Il seeder è idempotente, non viene eseguito dai deploy e rifiuta l'ambiente production. La rotazione cambia ogni minuto: non compaiono tutte e tre insieme.

Su richiesta esplicita del proprietario si possono pubblicare anche sul remoto: `php artisan sponsorships:demo padova --allow-production`. Il comando non è inserito in cron/deploy e senza il flag rifiuta production. La dicitura nelle note resta la stessa per idempotenza; sono campagne dimostrative anche quando pubblicate.

## Preferenze e feed

Nel **Mio feed** il banner è in testa. Locali e categorie rimangono gestibili dopo il primo follow: sei per pagina, ricerca locali e paginatori indipendenti. Gli eventi del feed usano pagine esplicite da 12 risultati, non scroll infinito.

L'affinità non cambia ammissibilità o priorità commerciale: modifica solo il peso nella rotazione, senza salvarlo sulla campagna. Moltiplicatori (non cumulativi): ×4 locale/evento seguito, ×3 categoria/tag seguito, ×2 categoria di eventi salvati di recente o contesto esplicito categoria/tag/locale della pagina, ×1 senza segnali. Tutti i candidati restano in rotazione. Non vengono usati cronologia, fingerprint o dati esterni.

Il sito legge il banner da `/{city}/banner-sponsorizzato?platform=web`, con la sessione web; Android dall'API con il proprio token, quando presente. Entrambe le risposte sono private e non memorizzabili. Nessun user_id può essere scelto dal chiamante. Gli anonimi usano soltanto il contesto esplicito della pagina, altrimenti la rotazione neutra.

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

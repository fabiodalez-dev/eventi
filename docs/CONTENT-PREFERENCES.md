# Interessi personali (web e Android 1.7.0)

Profilo → I miei interessi, `/profilo/interessi`. API Sanctum: GET/PATCH `/api/v1/me/content-preferences`.

`users.content_preferences` contiene `mode` (`all` / `selected`), `categories`, `hidden_categories` e `inferred_ads`. Ogni categoria attiva del database compare dinamicamente. Nessun interesse viene imposto agli account esistenti: inizialmente tutti vedono tutte le categorie. Il selettore per categoria offre Nessuna preferenza / Mi interessa / Nascondi.

- `all`: esclude soltanto le categorie nascoste; quelle nuove sono visibili.
- `selected`: mostra solo le categorie dichiarate interessanti; quelle nuove vanno scelte. Lista vuota significa nessun evento, non ritorno automatico a tutto.
- Le scelte sono reversibili; il pulsante Ripristina riabilita tutte le categorie.
- Scoperta, ricerca (anche suggerimenti), mappe, calendari pubblici, eventi simili e feed usano lo stesso filtro nel motore temporale. I locali e le tassonomie rimangono esplorabili, ma i loro elenchi eventi sono filtrati.
- Un link diretto all'evento funziona comunque. Salvataggi, prenotazioni, biglietti, esportazioni dei salvati e strumenti di gestione non vengono cancellati o limitati dalle preferenze consumer.
- Il feed include anche le categorie dichiarate, senza iscrivere automaticamente alle notifiche. Preferenze di notifica e follow restano separati.
- Gli AD rispettano le esclusioni prima della priorità commerciale; i pesi sono documentati in SPONSORED-BANNERS.md. I report dei locali rimangono aggregati, senza identità del visitatore.
- Risposte autenticate private/no-store; ticker separato per selezione, nessuna personalizzazione nella cache anonima. Android svuota risultati/cache dopo il salvataggio; logout/cambio account non conserva il catalogo personalizzato precedente.

La migrazione aggiunge solo una colonna nullable: non esegue seed né modifica credenziali. L'esportazione dell'account comprende le preferenze.

Test dedicati: `ContentPreferencesTest`, regressioni Sponsorships, API caching/ricerca/mappe, Android modelli e controlli UI su emulatore. Lighthouse resta temporaneamente sospeso per richiesta dell'utente; il resto della CI rimane obbligatorio.

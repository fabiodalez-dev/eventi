# Recensioni dei locali

Gli iscritti possono lasciare una recensione per locale, con un voto intero da 1 a 5 e un testo da 10 a 3000 caratteri. La recensione è inizialmente in attesa; ogni modifica la riporta in attesa. L'autore può eliminarla. Anche l'eliminazione dell'account rimuove le recensioni.

La pagina del locale mostra solo recensioni approvate, con media e conteggio calcolati sulle stesse recensioni. Il pubblico vede il primo nome dell'autore, testo, voto e data; stato personale e motivazione del rifiuto sono visibili solo all'autore.

Nel backend `/admin/venue-reviews`, la voce con icona stella nel gruppo Moderazione è riservata ai ruoli `admin` e `super_admin`. I gestori dei locali e il ruolo `moderator` non possono moderare recensioni. Il rifiuto richiede una motivazione. Una revisione numerica impedisce di approvare un testo modificato dopo l'apertura del modulo.

## API e Android

- `GET /api/v1/venues/{slug}/reviews?page=1`: elenco pubblico, media, conteggio, paginazione da 10, `can_review`. Con token Sanctum restituisce anche `my_review`.
- `POST /api/v1/venues/{slug}/reviews`: token richiesto, corpo `{rating, body}`; inserisce o aggiorna solo la recensione dell'utente autenticato.
- `DELETE /api/v1/venues/{slug}/reviews`: token richiesto; elimina solo la propria recensione.

Le risposte personali non sono memorizzabili in cache. Le scritture sono limitate a 10 richieste/ora e consentite solo su locali approvati. I locali sospesi mantengono leggibili le recensioni già pubblicate.

Android 1.10.0 usa queste API per lettura, invio, modifica, eliminazione e stato di moderazione. Include inoltre copertine a tutta larghezza, hero con foto di sfondo e distanze fra le card.

## Valutazione delle dipendenze

Sono stati esaminati l'articolo Medium «Creating ratings and reviews with Laravel», CodebyRay/laravel-review-rateable e jobmetric/laravel-star. CodebyRay dichiara compatibilità Laravel 13 e supporta approvazione e recensioni; Laravel Star è orientato ai voti, anche anonimi. È stato scelto un modulo dedicato, senza nuove dipendenze, per mantenere in un solo punto i vincoli di unicità, nuova moderazione dopo modifica e autorizzazione esclusiva degli amministratori della piattaforma.

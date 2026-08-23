<?php

declare(strict_types=1);

/*
 * Pannello dei locali `/gestione` (§10 del piano).
 *
 * Il vocabolario è diverso da quello di `lang/it/admin.php` e non per svista:
 * la redazione parla di occorrenze, verifica e punteggio editoriale, un
 * gestore parla di date, locandina e quanto costa. Le due lingue restano
 * separate perché il giorno in cui una delle due cambia, l'altra non deve
 * cambiare con lei.
 *
 * Le etichette degli stati non stanno qui: sono in `lang/it/enums.php`, dove
 * le leggono i metodi `label()` degli enum.
 */
return [

    'dashboard' => [
        'title' => 'Riepilogo',
        'subheading' => 'Come vanno i tuoi eventi.',
        'today' => 'Oggi',
        'today_hint' => 'Date in programma nella giornata di oggi.',
        'upcoming' => 'Prossimi',
        'upcoming_hint' => 'Date da oggi in avanti.',
        'views' => 'Visualizzazioni',
        'views_hint' => 'Negli ultimi :days giorni.',
        'today_schedule' => 'In programma oggi',
    ],

    'resources' => [
        'event' => ['label' => 'Evento', 'plural' => 'I tuoi eventi'],
        'occurrence' => ['label' => 'Data', 'plural' => 'Date'],
        'lineup' => ['label' => 'Artista', 'plural' => 'Chi suona'],
    ],

    'sections' => [
        'what' => 'Che cosa è',
        'taxonomy' => 'Che genere di serata',
        'price' => 'Quanto costa',
    ],

    'fields' => [
        'poster' => 'Locandina',
        'title' => 'Titolo',
        'description' => 'Descrizione',
        'starts_at' => 'Inizio',
        'ends_at' => 'Fine',
        'next_date' => 'Prossima data',
        'category' => 'Categoria',
        'tags' => 'Tag',
        'price_type' => 'Prezzo',
        'price_min' => 'Da (€)',
        'price_max' => 'A (€)',
        'ticket_url' => 'Link per i biglietti',
        'status' => 'Stato',
        'occurrence_status' => 'Stato della data',
        'artist' => 'Nome',
        'artist_role' => 'Come si esibisce',
        'name' => 'Nome',
        'email' => 'Email',
        'role' => 'Ruolo',
        'accepted_at' => 'Ha accettato il',
    ],

    'hints' => [
        'poster' => 'La locandina è la prima cosa che si vede. Va bene anche la foto scattata al volantino.',
        'ends_at' => 'Solo se la sai. Altrimenti la calcoliamo dalla categoria.',
        'tags' => 'Facoltativi: aiutano a farti trovare da chi cerca un genere preciso.',
        'price_max' => 'Solo se il prezzo cambia (per esempio in prevendita e alla porta).',
    ],

    'placeholders' => [
        'title' => 'Per esempio: Concerto dei Marlene Kuntz',
        'description' => 'Due righe bastano. Puoi anche lasciare vuoto.',
        'ticket_url' => 'https://…',
        'no_date' => 'Nessuna data',
        'pending_invite' => 'Invito da accettare',
    ],

    'actions' => [
        'new_event' => 'Nuovo evento',
        'edit' => 'Modifica',
        'duplicate' => 'Duplica',
        'view_on_site' => 'Vedi sul sito',
        'publish' => 'Pubblica',
        'publish_confirm' => 'Da questo momento l’evento è visibile a chi cerca cosa fare in città.',
        'repeat' => 'Ripeti',
        'add_date' => 'Aggiungi una data',
        'cancel_date' => 'Annulla la data',
        'mark_sold_out' => 'Segna tutto esaurito',
        'mark_available' => 'Ci sono ancora posti',
        'add_lineup' => 'Aggiungi chi suona',
    ],

    'wizard' => [
        'title' => 'Nuovo evento',
        'shortcuts' => 'Scorciatoie',
        'finish' => 'Pubblica',
        'step_poster' => 'Locandina',
        'step_poster_hint' => 'La foto e il nome della serata.',
        'step_when' => 'Quando',
        'step_when_hint' => 'Giorno e ora.',
        'step_category' => 'Genere',
        'step_category_hint' => 'Serve a farti trovare.',
        'step_price' => 'Prezzo',
        'step_price_hint' => 'Anche solo "gratis".',
        'step_details' => 'Dettagli',
        'step_details_hint' => 'Facoltativi: puoi pubblicare senza.',
    ],

    'shortcuts' => [
        'tonight' => 'Stasera',
        'tomorrow' => 'Domani',
        'friday' => 'Venerdì',
        'every_thursday' => 'Ogni giovedì',
    ],

    'recurrence' => [
        'toggle' => 'Si ripete',
        'toggle_hint' => 'Per le serate fisse: la creiamo una volta e la mettiamo in calendario per tutte le date.',
        'heading' => 'Ripeti questo evento',
        'description' => 'Le date le mettiamo in calendario noi, fino alla data che indichi.',
        'current' => 'Adesso si ripete :rule. Puoi cambiare la regola qui sotto.',
        'submit' => 'Metti in calendario',
        'frequency' => 'Ogni quanto',
        'weekdays' => 'In quali giorni',
        'weekdays_hint' => 'Se non scegli nulla, usiamo il giorno della prima data.',
        'until' => 'Fino al',
        'until_hint' => 'Lascia vuoto per andare avanti a tempo indeterminato.',
        'summary' => ':frequency il :days',
    ],

    'statistics' => [
        'title' => 'Statistiche',
        'subheading' => 'Ultimi :days giorni. Nessun dato personale di chi guarda.',
        'views' => 'Visualizzazioni',
        'saves' => 'Salvataggi',
        'directions' => 'Indicazioni',
        'tickets' => 'Biglietti',
        'per_event' => 'Evento per evento',
    ],

    'collaborators' => [
        'title' => 'Collaboratori',
        'subheading' => 'Chi può pubblicare gli eventi di questo locale.',
        'invite' => 'Invita un collaboratore',
        'invite_description' => 'Riceverà un’email con il collegamento per entrare. Potrà gestire gli eventi, non i dati del locale.',
        'invite_submit' => 'Manda l’invito',
        'invited' => 'Invito inviato',
        'invited_body' => 'Abbiamo scritto a :email.',
        'remove' => 'Rimuovi',
        'remove_confirm' => 'Non potrà più pubblicare eventi per questo locale. Gli eventi già inseriti restano.',
        'removed' => 'Collaboratore rimosso',
    ],

    'invitation' => [
        'subject' => ':venue su :product',
        'greeting' => 'Ciao :name,',
        'intro' => 'Da adesso puoi pubblicare gli eventi di :venue su :product, come :role.',
        'password_hint' => 'Scegli una password per entrare: il collegamento vale un’ora.',
        'password_action' => 'Scegli la password',
        'ignore' => 'Se non ti aspettavi questo messaggio, puoi ignorarlo.',
        'open_action' => 'Apri il pannello',
    ],

    'notifications' => [
        'published' => 'Evento pubblicato',
        'published_body' => 'È online: chi cerca cosa fare in città lo vede.',
        'sent_to_review' => 'Evento inviato alla redazione',
        'sent_to_review_body' => 'Lo controlliamo e lo pubblichiamo noi. Ti avvisiamo appena è online.',
        'duplicated' => 'Copia creata',
        'duplicated_body' => 'Cambia data e ora, poi pubblica.',
        'dates_created' => 'Aggiunta :count data|Aggiunte :count date',
        'no_new_dates' => 'Nessuna data nuova da aggiungere',
        'dates_updated' => 'Modificata :count data|Modificate :count date',
        'nothing_to_do' => 'Non c’era nulla da cambiare',
    ],

];

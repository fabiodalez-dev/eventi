<?php

declare(strict_types=1);

return [

    'title' => 'Eventi',
    'one' => 'evento',
    'many' => 'eventi',
    'count' => ':count evento|:count eventi',

    /*
     * Badge della card (§11.4). Quale badge mostrare lo decide la finestra
     * temporale da cui l'occorrenza proviene — cioè EventOccurrenceQuery —
     * non la vista: qui ci sono solo le parole.
     */
    'badge' => [
        'ongoing' => 'In corso',
        'starting_soon' => 'Inizia tra :countdown',
        'tonight' => 'Stasera · :time',
        'today' => 'Oggi · :time',
        'tomorrow' => 'Domani · :time',
        'free' => 'Gratis',
        'donation' => 'Offerta libera',
        'cancelled' => 'Annullato',
        'sold_out' => 'Esaurito',
        'postponed' => 'Rinviato',
        'moved' => 'Spostato',
        'featured' => 'In evidenza',
        'all_day' => 'Tutto il giorno',
        'outdoor' => "All'aperto",
    ],

    'price' => [
        'free' => 'Gratis',
        'donation' => 'Offerta libera',
        'range' => ':min – :max',
        'from' => 'da :amount',
        'label' => 'Prezzo: :value',
    ],

    'card' => [
        'poster_alt' => 'Locandina di :title',
        'poster_missing' => 'Locandina non disponibile',
        'at_venue' => 'da :venue',
        'no_venue' => 'Luogo da definire',
        'distance' => 'a :distance',
        'more_dates' => 'e altre :count data|e altre :count date',
    ],

    /* Sezioni della home e delle liste (§11.2) */
    'sections' => [
        'ongoing' => 'In corso adesso',
        'ongoing_lead' => 'Sta succedendo mentre leggi.',
        'starting_soon' => 'Inizia tra poco',
        'starting_soon_lead' => 'Fai in tempo ad arrivare.',
        'tonight' => 'Stasera',
        'today' => 'Oggi',
        'tomorrow' => 'Domani',
        'weekend' => 'Questo weekend',
        'next_days' => 'I prossimi giorni',
        'featured' => 'In evidenza',
        'by_category' => 'Per categoria',
        'free' => 'Gratis',
        'same_venue' => 'Altri eventi in questo locale',
        'similar' => 'Eventi simili',
        'live_loading' => 'Guardo cosa sta succedendo adesso…',
    ],

    /* Rimandi usati quando una finestra è vuota: la sezione non si disegna,
       si porta il lettore dove c'è qualcosa (§8.6). */
    'redirects' => [
        'to_tonight' => 'Guarda cosa c\'è stasera',
        'to_today' => 'Guarda cosa c\'è oggi',
        'to_tomorrow' => 'Guarda cosa c\'è domani',
        'to_weekend' => 'Guarda il weekend',
        'to_all' => 'Sfoglia tutti gli eventi',
    ],

    'detail' => [
        'when' => 'Quando',
        'where' => 'Dove',
        'price' => 'Prezzo',
        'all_dates' => 'Tutte le date',
        'doors_at' => 'Apertura porte alle :time',
        'lineup' => 'In programma',
        'tags' => 'Tag',
        'organizer' => 'Organizza :name',
        'age_restriction' => 'Età minima: :value',
        'language' => 'Lingua: :value',
        'outdoor' => "Evento all'aperto",
        'booking_required' => 'Prenotazione obbligatoria',
        'ends_estimated' => 'Fine stimata',
        'description' => 'Descrizione',
        'source_venue' => 'Dati confermati dal locale',
        'source_editorial' => 'Verificato dalla redazione',
        'finished' => 'Questo evento è già stato: qui sotto trovi le date passate.',
        'age' => 'Età',
        'place_kind' => 'Tipo di spazio',
    ],

    /*
     * Titolo, h1 e descrizione delle liste filtrate (§11.3).
     *
     * La frase si compone di quattro pezzi nell'ordine dell'italiano —
     * soggetto, qualificatore, tempo, luogo — e i pezzi mancanti spariscono:
     * "Concerti gratis stasera a Padova", "Eventi all'aperto a Este".
     */
    'meta' => [
        'pattern' => ':subject :qualifiers :when :place',
        'tagged' => 'Eventi :tag',
        'in_place' => 'a :place',
        'at_venue' => 'da :venue',
        'near_you' => 'vicino a te',
        'on_date' => 'di :date',
        'between_dates' => 'dal :from al :to',
        'outdoor' => "all'aperto",
        'accessible' => 'accessibili',
        'family' => 'per famiglie',
        'description' => ':count evento da vedere: :heading. Aggiornato ogni giorno.|:count eventi da vedere: :heading. Aggiornato ogni giorno.',
        'description_search' => ':count risultato per ":query" a :city.|:count risultati per ":query" a :city.',
        'description_empty' => 'Nessun evento corrisponde a questa ricerca. Prova a togliere un filtro o a cambiare data.',
        'views' => ':count visualizzazione|:count visualizzazioni',
        'saves' => ':count salvataggio|:count salvataggi',
    ],

    'empty' => [
        'search_title' => 'Nessun evento corrisponde alla ricerca',
        'search_body' => 'Prova con meno filtri o con un\'altra data.',
        'venue_title' => 'Questo locale non ha eventi in programma',
        'venue_body' => 'Seguilo per sapere quando ne pubblica uno.',
        'day_title' => 'Per questo giorno non c\'è ancora niente',
        'day_body' => 'I programmi arrivano spesso a ridosso della data.',
    ],

    'submit' => [
        'title' => 'Proponi un evento',
        'lead' => 'Segnalaci cosa succede: la redazione controlla e pubblica.',
    ],

];

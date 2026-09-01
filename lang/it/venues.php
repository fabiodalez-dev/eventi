<?php

declare(strict_types=1);

return [

    'title' => 'Locali',
    'one' => 'locale',
    'many' => 'locali',
    'count' => ':count locale|:count locali',

    'card' => [
        'logo_alt' => 'Logo di :venue',
        'cover_alt' => 'Copertina di :venue',
        'upcoming' => ':count evento in programma|:count eventi in programma',
        'no_upcoming' => 'Nessuna data in programma',
        'distance' => 'a :distance',
    ],

    'badge' => [
        'verified' => 'Verificato',
        'nonprofit' => 'No profit',
        'membership' => 'Riservato ai soci',
        'accessible' => 'Accessibile',
    ],

    'detail' => [
        'about' => 'Il locale',
        'opening_hours' => 'Orari di apertura',
        'closed' => 'Chiuso',
        'contacts' => 'Contatti',
        'socials' => 'Social',
        'address' => 'Indirizzo',
        'accessibility' => 'Accessibilità',
        'membership' => 'Tesseramento',
        'capacity' => 'Capienza: :count persone',
        'upcoming_events' => 'Prossimi eventi',
        'past_events' => 'Eventi passati',
        'map_title' => 'Posizione di :venue',
        'follow_soon' => 'Il pulsante funzionerà quando arriveranno gli account.',
        'transit' => 'Come arrivare',
        'info' => 'Buono a sapersi',
        'zone' => 'Zona',
    ],

    /*
     * L'accessibilità dichiarata dal locale. Si elencano soltanto le voci
     * **presenti**: una voce dichiarata assente non si stampa come divieto, e
     * una non dichiarata non si stampa affatto — «non lo sappiamo» non è «no».
     */
    'accessibility' => [
        'undeclared' => 'Il locale non ha ancora dichiarato le voci di accessibilità.',
        'item_alt' => 'Presente',
    ],

    'filters' => [
        'search' => 'Cerca un locale',
        'search_placeholder' => 'Nome, zona, descrizione',
        'type' => 'Tipo di locale',
        'any_type' => 'Tutti i tipi',
    ],

    'meta' => [
        'title' => 'Locali a :city e provincia',
        'description' => 'Circoli, club, teatri, librerie e spazi pubblici di :city e provincia, con i loro prossimi eventi.',
        'venue_title' => ':venue, :municipality',
    ],

    'sections' => [
        'active' => 'Locali attivi',
        'nearby' => 'Vicino a te',
        'by_type' => 'Per tipo di locale',
    ],

    'empty' => [
        'list_title' => 'Nessun locale corrisponde ai filtri',
        'list_body' => 'Prova ad allargare la zona o a togliere un filtro.',
    ],

    'claim' => [
        'title' => 'Registra il tuo locale',
        'lead' => 'Pubblica i tuoi eventi e raggiungi chi è già in cerca di qualcosa da fare.',
    ],

    /* Widget incorporabile (§11.10). */
    'widget' => [
        'title' => 'Metti i tuoi eventi sul tuo sito',
        'lead' => 'Copia questo codice e incollalo nella pagina del tuo sito: mostra le tue prossime date, e si aggiorna da solo ogni volta che ne pubblichi una.',
        'frame_title' => 'Prossimi eventi di :venue',
        'snippet_label' => 'Codice da incollare',
        'preview' => 'Anteprima del riquadro',
        'powered_by' => 'Eventi da :app',
        'all_events' => 'Tutti gli eventi',
        'empty' => 'Nessuna data in programma al momento.',
    ],

];

<?php

return [
    // Procedura «Stasera»: una domanda per schermata.
    'title' => 'La tua prossima serata.',
    'context' => 'Stasera a :city',
    'progress' => 'Passo :current di :total',
    'progress_label' => 'Avanzamento della procedura',
    'next' => 'Continua',
    'back' => 'Indietro',
    'back_home' => 'Torna alla home',
    'skip' => 'Salta',
    'skip_label' => 'Salta questa domanda',
    'actions_label' => 'Comandi della procedura',
    'see_events' => '{0} Vedi i risultati|{1} Vedi 1 evento|[2,*] Vedi :count eventi',
    'count_loading' => 'Conteggio in corso…',
    'count_error' => 'Conteggio non disponibile',
    'events_unit' => 'eventi',
    'events_count' => '{0} Nessun evento|{1} 1 evento|[2,*] :count eventi',
    'search_place' => 'Cerca nell’elenco',
    'places_more' => 'Mostra tutti (:count)',
    'places_less' => 'Mostra meno',
    'preferences' => 'Modifica i miei interessi',

    // Le domande e il loro aiuto.
    'when' => 'Quando vuoi uscire?',
    'when_help' => 'Poche domande veloci, e puoi saltare quelle che non ti interessano. Alla fine ti mostro tutti e solo gli eventi che rispettano le tue scelte.',
    'tonight' => 'Stasera', 'soon' => 'Tra poco',
    'tonight_help' => 'Eventi della giornata che iniziano dalle 17:00, non ancora conclusi.',
    'soon_help' => 'Eventi che iniziano da adesso entro :minutes minuti. Non quelli già iniziati.',
    'municipality' => 'In quale comune?',
    'municipality_help' => 'Accanto a ogni comune vedi quanti eventi ci sono con le scelte fatte finora.',
    'everywhere' => 'Ovunque in provincia',
    'district' => 'In quale quartiere di Padova?',
    'district_help' => 'Il quartiere o rione: per esempio Brusegana, Guizza o Arcella. Serve a trovare gli eventi nella zona giusta.',
    'district_unknown' => 'I locali che non hanno ancora indicato il quartiere compaiono scegliendo tutta Padova.',
    'any_zone' => 'Tutta la città',
    'budget' => 'Quanto vuoi spendere per l’ingresso?',
    'budget_help' => 'Il limite riguarda l’ingresso, non consumazioni, tessere o altri extra. Quando imposti un limite escludo gli eventi che non hanno comunicato il prezzo.',
    'any_budget' => 'Nessun limite', 'free' => 'Solo ingresso gratuito', 'up_to' => 'Fino a :amount €',
    'categories' => 'Che cosa ti va di fare?',
    'categories_help' => 'Scegli una o più categorie, oppure lasciale tutte aperte. Le categorie che hai escluso dal profilo restano escluse.',
    'all_categories' => 'Tutte le categorie',

    // Il riepilogo delle scelte.
    'summary' => [
        'title' => 'Le tue scelte',
        'when' => 'Quando', 'municipality' => 'Comune', 'district' => 'Quartiere', 'budget' => 'Ingresso', 'categories' => 'Cosa ti va',
        'pending' => 'Da scegliere',
        'current' => 'Adesso',
        'change' => 'Cambia',
    ],

    // I primi risultati, sotto ogni domanda.
    'preview' => [
        'title' => 'Con queste scelte',
        'empty' => 'Nessun evento corrisponde a queste scelte. Prova un altro comune, un budget più largo o un’altra categoria: non aggiungo eventi diversi per riempire la lista.',
        'all' => '{0} Vedi i risultati|{1} Vedi l’evento|[2,*] Vedi tutti i :count eventi',
    ],

    // Usati fuori dalla procedura: home, elenco degli eventi, motivi delle proposte.
    'hero_action' => 'Trova la tua serata',
    'banner_title' => 'Stasera, cosa fai?',
    'banner_help' => 'Zona, budget, quello che ti va: ti trovo una serata adatta a te.',
    'find' => 'Mostra gli eventi',
    'filtered' => 'Questi risultati rispettano le tue scelte. Puoi modificare o rimuovere i filtri.',
    'reason_time' => 'Nella fascia oraria scelta', 'reason_municipality' => 'A :municipality', 'reason_zone' => 'Nella zona :zone',
    'reason_budget' => 'Ingresso nel budget indicato', 'reason_category' => 'Categoria scelta: :category',
    'unknown' => 'Non comunicato',
];

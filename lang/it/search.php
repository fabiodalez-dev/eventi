<?php

declare(strict_types=1);

/*
 * La ricerca del sito (`/cerca`).
 */
return [

    'title' => 'Cerca',
    'saved' => [
        'cta' => 'Avvisami per nuovi eventi come questi',
        'cta_hint' => 'Riceverai un avviso quando viene pubblicata una data che corrisponde a questi filtri.',
        'login' => 'Accedi per salvare questa ricerca',
        'stored' => 'Ricerca salvata: ti avviso quando escono date nuove.',
        'exists' => 'Questa ricerca è già salvata.',
        'limit' => 'Puoi salvare al massimo :max ricerche: cancellane una per aggiungerne un’altra.',
        'empty_filters' => 'Scegli almeno un filtro o una parola da cercare prima di salvare.',
        'removed' => 'Ricerca rimossa.',
        'default_name' => 'La mia ricerca',
        'title' => 'Le mie ricerche salvate',
        'help' => 'Per ognuna ricevi al massimo un avviso al giorno, solo quando compaiono date nuove. Per smettere, rimuovila.',
        'none' => 'Nessuna ricerca salvata. Dalla pagina degli eventi, dopo aver scelto i filtri, premi «Avvisami per nuovi eventi come questi».',
        'open' => 'Apri la ricerca',
        'remove' => 'Rimuovi',
        'checked' => 'Ultimo controllo: :date',
        'never' => 'Primo controllo entro un’ora',
    ],
    'results_for' => 'Risultati per ":query"',
    'placeholder' => 'Un evento, un locale, un artista',
    'label' => 'Che cosa cerchi',
    'submit' => 'Cerca',
    'live' => [
        'loading' => 'Ricerca in corso…',
        'error' => 'I suggerimenti non sono disponibili. Premi Cerca per riprovare.',
        'all' => 'Vedi tutti i risultati',
        'label' => 'Risultati della ricerca in diretta',
    ],

    'meta' => [
        'title' => 'Cerca fra gli eventi di :city',
        'description' => 'Cerca un evento, un locale o un tag fra ciò che succede a :city e provincia.',
        'results_description' => '{0} Nessun risultato per ":query" a :city.|{1} Un risultato per ":query" a :city.|[2,*] :count risultati per ":query" a :city.',
    ],

    'groups' => [
        'events' => 'Eventi',
        'venues' => 'Locali',
        'tags' => 'Tag',
    ],

    'all_events' => 'Tutti gli eventi che contengono ":query"',
    'all_venues' => 'Tutti i locali che contengono ":query"',

    'empty' => [
        'title' => 'Non ho trovato niente per ":query"',
        'body' => 'Può darsi che sia scritto in un altro modo, o che quell\'evento sia già passato. Da qui si riparte:',
        'prompt_title' => 'Che cosa stai cercando?',
        'prompt_body' => 'Scrivi il nome di un evento, di un locale o di un artista. Oppure parti da qui:',
        'categories' => 'Categorie con eventi in programma',
        'tags' => 'Tag più usati',
        'windows' => 'Oppure guarda subito',
    ],

];

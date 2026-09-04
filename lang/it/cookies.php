<?php

declare(strict_types=1);

return [
    'title' => 'Cookie',
    'lead' => 'Cosa questo sito deposita nel browser di chi lo visita. È il contenuto della Cookie Policy: quello che scrivi qui, chi legge lo trova lì.',
    'own' => 'Questo sito',

    'fields' => [
        'category' => 'Finalità',
        'category_help' => 'Decide se serve il consenso e in quale riquadro compare.',
        'name' => 'Nome del cookie',
        'name_help' => 'Come si chiama davvero, per esempio «XSRF-TOKEN». Chi controlla lo cerca con quel nome.',
        'provider' => 'Chi lo deposita',
        'provider_help' => 'Vuoto se è questo sito. Altrimenti il servizio esterno: è la distinzione che conta di più.',
        'purpose' => 'A cosa serve',
        'purpose_help' => 'In una riga e in italiano corrente, non in gergo.',
        'duration' => 'Quanto dura',
        'duration_help' => 'Scritta com\'è: «un anno», «la sessione», «fino all\'uscita».',
    ],

    'actions' => [
        'add' => 'Aggiungi un cookie',
    ],

    'public' => [
        'none' => 'Nessun cookie: in questa finalità il sito non deposita niente nel tuo browser.',
    ],

    'empty' => [
        'heading' => 'Nessun cookie dichiarato in questa finalità',
        'body' => 'Va bene se il sito non ne deposita: la Cookie Policy lo dirà, ed è un\'informazione utile quanto l\'elenco.',
    ],
];

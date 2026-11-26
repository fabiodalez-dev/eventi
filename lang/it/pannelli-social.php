<?php

declare(strict_types=1);

/*
 * I testi scritti in parole dello studio social e dei risultati delle
 * sponsorizzazioni: periodi, frasi che spiegano i numeri, righe dell'elenco.
 */
return [
    'studio' => [
        /* «2 serate da oggi a dom 23 nov». */
        'results' => '{0} Nessuna serata :period|{1} Una serata :period|[2,*] :count serate :period',
        'today_only' => 'oggi',
        'one_day' => 'solo :day',
        'from_today' => 'da oggi a :day',
        'all_day' => 'tutto il giorno',
        'recent_item' => '{0} Grafiche per :day · :format · nessuna immagine|{1} Grafiche per :day · :format · un\'immagine|[2,*] Grafiche per :day · :format · :count immagini',
        'prepared' => 'preparate :when',
        'expected' => 'prevista per :date',
    ],
    /* La redazione nell'app (R9): i messaggi dell'API dei social. */
    'app' => [
        'secret_web_only' => 'App Secret, token della Pagina e token del bot si scrivono solo dalla pagina del sito.',
        'meta_not_ready' => 'Salva App ID e App Secret prima di collegare Meta.',
        'verified_telegram' => 'Bot e permessi del canale Telegram verificati',
        'disconnected' => 'Collegamento locale rimosso; pubblicazioni automatiche disattivate',
        'today_empty' => 'Oggi non ci sono serate da preparare.',
    ],

    'sponsorship' => [
        'campaign' => 'Campagna',
        'campaign_placeholder' => 'Tutte le campagne',
        'campaign_meta' => 'in vetrina :period · :placement',
        'window' => 'Numeri :period. I giorni sono quelli della città.',
        'explain_empty' => 'In questo periodo nessuno ha ancora visto le campagne scelte.',
        'explain' => '{1} Le campagne sono comparse :views volte e una persona le ha aperte.|[2,*] Le campagne sono comparse :views volte e sono state aperte :clicks volte.',
        'explain_none' => 'Le campagne sono comparse :views volte, ma nessuno le ha ancora aperte.',
        'explain_rate' => 'In media una persona su :every le apre dopo averle viste.',
    ],
];

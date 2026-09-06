<?php

declare(strict_types=1);

return [
    'insert' => 'Inserisci :token',
    'preview_title' => 'Esempio con dati dimostrativi, non inviato',
    'original_title' => 'Mostra il testo originale',
    'tokens' => ['title' => 'titolo evento', 'name' => 'nome destinatario', 'venue' => 'nome locale', 'municipality' => 'comune', 'product' => 'nome sito', 'when' => 'data e ora', 'note' => 'nota del locale', 'days' => 'numero di giorni', 'type' => 'tipo di avviso', 'count' => 'quantità', 'reason' => 'motivo'],
    'previous_example' => 'venerdì 11 settembre alle 20:00',
    'preview' => 'Esempio con dati dimostrativi (non inviato): :text',
    'invalid' => 'Segnaposti non disponibili in questo campo: :tokens. Usa i pulsanti Inserisci.',
    'examples' => ['title' => 'Jazz in acustico', 'name' => 'Giulia', 'venue' => 'Circolo Arci La Fornace', 'municipality' => 'Padova', 'product' => 'inCittà', 'when' => 'sabato 12 settembre alle 21:00', 'note' => 'Il concerto si terrà nella sala interna', 'days' => '30', 'type' => 'aggiornamento evento', 'count' => '2', 'reason' => 'Cambio di programma'],
    'title' => 'Testi delle email',
    'lead' => 'Come sono scritte le email che il sito manda. Quello che non tocchi resta com\'è, e puoi sempre tornare indietro.',
    'group_lead' => 'I segnaposti vengono sostituiti automaticamente con i dati dell’evento o del destinatario. I pulsanti Inserisci li aggiungono in fondo al campo: puoi poi spostarli nel testo. Uscendo dal campo si aggiorna l’esempio. Svuota il campo per ripristinare l’originale. Questi testi riguardano gli avvisi dell’agenda; le email dei biglietti sono separate.',
    'default_was' => 'Originale: «:testo»',
    'variables' => 'variabili disponibili: :elenco',
    'saved' => 'Salvato: :scritti testi riscritti, :ripristinati tornati all\'originale.',
    'reset_done' => 'Tutti i testi sono tornati come erano.',

    'actions' => [
        'save' => 'Salva',
        'reset_all' => 'Riporta tutto all\'originale',
        'reset_all_confirm' => 'Le riscritture vengono cancellate e le email tornano ai testi di partenza.',
    ],
];

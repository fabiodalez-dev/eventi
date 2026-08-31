<?php

declare(strict_types=1);

return [

    'errors' => [
        'no_driver' => 'Nessun driver disponibile per le sorgenti di tipo :type.',
        'no_url' => 'La sorgente non ha un indirizzo da cui scaricare il calendario.',
        'invalid_url' => 'L\'indirizzo del calendario non è scritto in una forma valida.',
        'unsupported_scheme' => 'L\'indirizzo del calendario deve cominciare con http:// oppure https://.',
        'private_address' => 'L\'indirizzo :host appartiene alla rete interna del server: un calendario pubblico non può stare lì.',
        'unreachable' => 'Calendario irraggiungibile (:url): :reason',
        'http_status' => 'Il calendario remoto ha risposto con lo stato HTTP :status.',
        'too_large' => 'Il calendario supera la dimensione ammessa (:bytes byte).',
        'unreadable' => 'Il file non è un calendario iCalendar leggibile: :reason',
        'not_a_calendar' => 'La risposta non contiene un calendario iCalendar.',
        'no_category' => 'Nessuna categoria disponibile: gli eventi importati non possono nascere senza.',
        'unmappable' => 'Una voce del calendario è priva di identificativo, titolo o data di inizio.',
        'and_more' => 'e altri :count problemi',
    ],

    /* I sei contatori di `App\DTOs\ImportReport`, con i nomi che leggono sia
       la redazione sia il gestore del locale: sono lo stesso fatto, e due
       vocabolari diversi per lo stesso numero si contraddicono. */
    'report' => [
        'summary' => 'Creati: :created. Aggiornati: :updated. Invariati: :unchanged. Esclusi dal filtro: :excluded. Annullati perché spariti: :cancelled. Errori: :errors.',
        'created' => 'Creati',
        'updated' => 'Aggiornati',
        'unchanged' => 'Invariati',
        'excluded' => 'Esclusi',
        'cancelled' => 'Annullati',
        'errors' => 'Errori',
    ],

    'preview' => [
        'all_day' => 'Intera giornata',
        'recurring' => 'Si ripete',
        'cancelled' => 'Annullato alla sorgente',
        'empty' => 'Il calendario non porta alcuna data importabile.',
        'when' => 'Quando',
        'title' => 'Titolo',
        'where' => 'Luogo dichiarato',
    ],

];

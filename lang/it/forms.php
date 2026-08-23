<?php

declare(strict_types=1);

/*
 * I moduli pubblici: proponi un evento, registra il tuo locale, segnala un
 * errore (§11.1, §14.6).
 *
 * Le chiavi sotto `fields` sono anche i nomi dei campi usati nei messaggi di
 * validazione: `attributes()` di ogni Form Request legge esattamente questo
 * elenco, così l'etichetta sopra al campo e quella dentro all'errore non
 * possono divergere.
 */
return [

    'required' => 'campo obbligatorio',
    'has_errors' => 'Controlla i campi segnalati: qualcosa non va.',
    'honeypot' => 'Lascia vuoto questo campo',
    'privacy_note' => 'Usiamo questi dati solo per valutare e pubblicare la segnalazione. Non finiscono in nessuna lista.',

    'submission' => [
        'title' => 'Proponi un evento',
        'lead' => 'Segnalaci cosa succede a :city: la redazione controlla e pubblica. Bastano il titolo e un modo per ricontattarti.',
        'submit' => 'Invia la proposta',
        'received' => 'Proposta ricevuta. La redazione la valuta e, se pubblicabile, la mette online.',
        'venue_unknown' => 'Il locale non è in elenco',

        'fields' => [
            'title' => 'Titolo dell\'evento',
            'starts_at_hint' => 'Quando (anche approssimativo)',
            'venue_id' => 'Locale',
            'venue_hint' => 'Oppure scrivi dove si tiene',
            'raw_text' => 'Descrizione, programma, link',
            'contact_name' => 'Il tuo nome',
            'contact_email' => 'La tua email',
        ],

        'hints' => [
            'starts_at_hint' => 'Se non sai l\'ora esatta indica il giorno: la sistemiamo noi.',
            'venue_hint' => 'Serve solo se il locale non è ancora in elenco.',
            'raw_text' => 'Incolla pure il testo del volantino o del post: lo riscriviamo noi.',
        ],
    ],

    'application' => [
        'title' => 'Registra il tuo locale',
        'lead' => 'Pubblica i tuoi eventi su :city e raggiungi chi sta già cercando qualcosa da fare stasera.',
        'submit' => 'Invia la richiesta',
        'received' => 'Richiesta ricevuta. Ti ricontattiamo per verificare i dati e aprire il pannello del locale.',
        'type_unknown' => 'Non saprei',

        'fields' => [
            'venue_name' => 'Nome del locale',
            'type' => 'Tipo di locale',
            'website' => 'Sito o pagina social',
            'address' => 'Indirizzo',
            'contact_name' => 'Referente',
            'contact_role' => 'Ruolo',
            'contact_phone' => 'Telefono',
            'contact_email' => 'Email',
            'message' => 'Raccontaci qualcosa',
        ],

        'hints' => [
            'message' => 'Che tipo di eventi organizzate e ogni quanto.',
        ],
    ],

    'report' => [
        'title' => 'Segnala un errore',
        'heading' => 'Segnala un errore su :subject',
        'lead' => 'Se un orario, un prezzo o un indirizzo non tornano, diccelo: correggiamo.',
        'submit' => 'Invia la segnalazione',
        'received' => 'Segnalazione ricevuta. Grazie: la controlliamo al più presto.',
        'reason_placeholder' => 'Scegli un motivo',

        'fields' => [
            'reason' => 'Cosa non va',
            'note' => 'Dettagli',
            'reporter_email' => 'La tua email',
        ],

        'hints' => [
            'note' => 'Più sei preciso, prima correggiamo.',
            'reporter_email' => 'Facoltativa: serve solo se vogliamo chiederti un chiarimento.',
        ],
    ],

];

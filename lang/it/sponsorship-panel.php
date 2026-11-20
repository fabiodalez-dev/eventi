<?php

declare(strict_types=1);

/*
 * L'avanzamento delle sponsorizzazioni: la scheda del gestore, le statistiche,
 * il riepilogo del lunedì e l'avviso alla redazione.
 *
 * Ogni frase dice un numero e cosa vuol dire, in italiano semplice. Le date
 * arrivano già scritte per esteso («venerdì 28 novembre»), i numeri con il
 * punto delle migliaia.
 */
return [

    'units' => [
        'title' => 'Le tue sponsorizzazioni',
        'lead' => 'Ogni periodo acquistato o concesso, con l\'avanzamento e i numeri di ogni evento.',
        'measures' => 'Le misure sono una stima al ribasso: una visualizzazione conta quando l\'evento resta a schermo per almeno un secondo, e chi blocca gli script non viene contato. Le visite tue e di chi gestisce con te non contano.',
        'scope_all' => 'Tutti i tuoi eventi',
        'scope_selected' => 'Eventi scelti',
        'events' => 'Eventi in vetrina',
        'events_none' => 'Nessun evento in vetrina con questo periodo, per ora.',
        'event_numbers' => ':views visualizzazioni · :clicks aperture',
        'event_share' => ':share% delle visualizzazioni',
        'event_stopped' => 'Fermato',
        'show_all' => '{1} Mostra l\'altro evento|[2,*] Mostra gli altri :count eventi',
        'show_less' => 'Mostra meno eventi',
        'singles_title' => 'Campagne della redazione',
        'singles_lead' => 'Le sponsorizzazioni sui tuoi eventi decise dalla redazione, anche concluse.',
        'totals_label' => 'Totali di tutte le sponsorizzazioni',
        'total_views' => 'Visualizzazioni',
        'total_views_hint' => 'Da quando hai cominciato a sponsorizzare',
        'total_clicks' => 'Aperture',
        'total_clicks_hint' => 'Persone che hanno aperto l\'evento dalla sponsorizzazione',
    ],

    'progress' => [
        'label' => 'Avanzamento',
        'aria' => 'Avanzamento: :percent per cento',
        'goal_numbers' => ':delivered di :goal visualizzazioni',
        'period_numbers' => ':views visualizzazioni',
        'expected_marker' => 'La tacca sulla barra segna dove dovrebbe essere oggi.',
        'provisional' => 'Stima del traffico provvisoria: si basa su meno di :days giorni di dati.',
        'days_left' => '{0} Ultimo giorno|{1} Manca un giorno|[2,*] Mancano :count giorni',

        'goal' => [
            'scheduled' => 'Comincia :start: :goal visualizzazioni da consegnare entro :end.',
            'completed' => ':numbers: obiettivo raggiunto.',
            'missed' => ':numbers: il periodo si è chiuso sotto l\'obiettivo.',
            'at_risk' => ':numbers, :state: il traffico che resta fino a :end non basta per arrivare all\'obiettivo.',
            'on_date' => ':numbers, :state: al ritmo attuale arrivi all\'obiettivo :date.',
            'short' => ':numbers, :state: al ritmo attuale arrivi a circa :total entro :end.',
            'early' => ':numbers, :state: è ancora presto per stimare quando arrivi all\'obiettivo.',
            'idle' => ':numbers: nessun evento con date in arrivo, la consegna riprende con il prossimo evento pubblicato.',
        ],

        'period' => [
            'scheduled' => 'Comincia :start e dura fino a :end.',
            'running' => ':views visualizzazioni e :clicks aperture finora, giorno :day di :days: termina :end.',
            'ended' => 'Conclusa :end: :views visualizzazioni e :clicks aperture.',
            'idle' => ':views visualizzazioni e :clicks aperture finora: nessun evento con date in arrivo, la vetrina riprende con il prossimo evento pubblicato.',
        ],

        'halted' => [
            'unpaid' => ':numbers. Non compare sul sito finché il pagamento non risulta registrato.',
            'paused' => ':numbers. Sospesa: in questo momento non compare sul sito.',
        ],
    ],

    /* Il nome dello stato in un distintivo e, in minuscolo, dentro una frase. */
    'state' => [
        'on_track' => 'In linea',
        'slightly_behind' => 'In leggero ritardo',
        'behind' => 'In ritardo',
        'ahead' => 'In anticipo',
        'at_risk' => 'A rischio',
        'completed' => 'Obiettivo raggiunto',
        'missed' => 'Sotto l\'obiettivo',
        'period' => 'A periodo',
        'scheduled' => 'Programmata',
        'ended' => 'Conclusa',
        'unpaid' => 'In attesa di pagamento',
        'paused' => 'Sospesa',
    ],

    'state_inline' => [
        'on_track' => 'in linea',
        'slightly_behind' => 'in leggero ritardo',
        'behind' => 'in ritardo',
        'ahead' => 'in anticipo',
        'at_risk' => 'a rischio',
        'completed' => 'obiettivo raggiunto',
        'missed' => 'sotto l\'obiettivo',
    ],

    'analytics' => [
        'rate_pending' => 'Da :min visualizzazioni',
        'rate_threshold' => 'Il tasso di aperture compare da :min visualizzazioni in su: con meno è troppo incerto per dire qualcosa.',
        'progress_title' => 'Avanzamento della sponsorizzazione',
        'progress_lead_grant' => 'Il periodo di cui fa parte questa campagna, con tutti i suoi eventi.',
        'progress_lead_single' => 'L\'obiettivo e il ritmo di questa campagna.',
        'delivery_title' => 'Consegnate e attese, giorno per giorno',
        'delivery_lead' => 'La linea piena sono le visualizzazioni consegnate, quella tratteggiata quelle attese seguendo il traffico previsto.',
        'delivery_label' => 'Visualizzazioni consegnate e attese fino a :date: :delivered consegnate, :expected attese.',
        'delivered' => 'Consegnate',
        'expected' => 'Attese',
    ],

    'report' => [
        'subject_unit' => 'Come sta andando: :sponsor',
        'unit_line' => 'Il riepilogo riguarda tutti gli eventi in vetrina con questa sponsorizzazione.',
        'goal' => 'Obiettivo: :goal visualizzazioni.',
        'delivered' => 'Consegnate finora: :delivered (:percent%).',
        'state' => 'Stato: :state.',
        'period' => 'È una sponsorizzazione a periodo: non ha un obiettivo di visualizzazioni.',
        'rate_threshold' => 'Il tasso di aperture compare da :min visualizzazioni in su: con meno è troppo incerto per dire qualcosa.',
    ],

    'pacing_check' => [
        'title' => '{1} Una sponsorizzazione da guardare|[2,*] :count sponsorizzazioni da guardare',
        'body' => 'In ritardo o a rischio sull\'obiettivo di visualizzazioni: :units.',
        'more' => '{1} e un\'altra|[2,*] e altre :count',
        'unit' => ':name (:state)',
        'action' => 'Apri le sponsorizzazioni',
        'none' => 'Nessuna sponsorizzazione in ritardo o a rischio.',
        'already' => 'Avviso di oggi già inviato a :name.',
        'sent' => '{0} Nessun avviso nuovo.|{1} Un avviso inviato.|[2,*] :count avvisi inviati.',
        'dry_run' => 'Prova: nessun avviso inviato.',
    ],
];

<?php

declare(strict_types=1);

/*
 * I parametri della consegna delle sponsorizzazioni.
 *
 * Sono i valori di partenza del piano (capitolo 4, «Parametri di partenza»).
 * Quelli marcati come ipotesi valgono solo finché non ci sono misure vere: non
 * sono numeri da promettere a un cliente.
 *
 * Le regole che restano da sempre in `config/eventi.php` (affinità, tetto
 * orario delle misure) non si spostano qui: le leggono già altri pezzi.
 */
return [

    /* Un quinto dello spazio resta sempre agli altri: nessuna unità chiede
       più di questa quota di una collocazione. */
    'quota_max' => 0.8,

    /* Vicinanza della data dentro un'unità: u = max(floor, e^(−giorni / days)). */
    'event_proximity_days' => 7,
    'event_weight_floor' => 0.1,

    /*
     * La durata della casella dell'apertura della home, in minuti.
     *
     * Vuoto vuol dire «uguale alla durata della cache di pagina»
     * (`page_cache.ttl_minutes`): la casella deve coincidere con la vita della
     * copia salvata, e un numero scritto qui a mano prima o poi smetterebbe di
     * coincidere. Si legge con `HashDraw::slotMinutes()`.
     */
    'hero_slot_minutes' => null,

    /* Oltre questa età il piano di consegna si ricostruisce al volo. */
    'plan_ttl_seconds' => 120,

    /* Previsione del traffico: pesi delle ultime quattro settimane, dalla più
       recente, e giorni di dati sotto i quali la stima è «provvisoria». */
    'forecast_weights' => [8, 4, 2, 1],
    'forecast_min_days' => 14,

    /*
     * Visualizzazioni al giorno per collocazione quando non c'è ancora nessun
     * dato. **Sono ipotesi, non misure**: servono solo a partire.
     */
    'default_daily_views' => [
        'home_hero' => 400,
        'home_card' => 250,
        'list_top' => 200,
        'map_sheet' => 60,
    ],

    /* Una visualizzazione conta se metà dell'elemento resta nello schermo per
       questo tempo continuo. */
    'viewable_ms' => 1000,

    /* Validità del gettone di visualizzazione. */
    'token_ttl_hours' => 6,

    /*
     * Fino a quando conta il gettone giornaliero delle app già installate
     * (`X-Metric-Token` senza `X-Impression-Token`), come data `AAAA-MM-GG`.
     * Vuoto: conta ancora. Dopo quella data la misura risponde 204 e non
     * conta, come sulla strada nuova. Da fissare quando quasi tutte le app
     * avranno la versione con il gettone usa e getta (1.23.0).
     */
    'legacy_metric_token_until' => env('SPONSORSHIP_LEGACY_METRIC_TOKEN_UNTIL'),

    /* Segnali di occasione contati per indirizzo, città e collocazione in un'ora. */
    'opportunity_per_hour' => 12,

    /* Soglie dello stato del pacing: consegnate ÷ attese. Sotto `late` «in
       ritardo», sotto `on_track` «in leggero ritardo», sopra `ahead` «in
       anticipo». */
    'pacing_thresholds' => [
        'late' => 0.7,
        'on_track' => 0.9,
        'ahead' => 1.1,
    ],

    /* Sotto queste visualizzazioni nessun tasso di clic nei rapporti. */
    'min_impressions_for_ctr' => 100,

    /* Quanto restano i dati di servizio prima della potatura. */
    'retention' => [
        'nonce_hours' => 6,
        'hourly_days' => 70,
        'exposure_days' => 8,
    ],

    /* Secondo rilascio (P7): esplorazione fra gli eventi della stessa unità e
       tetto di frequenza per chi è collegato. Non letti nel primo rilascio. */
    'ctr_prior' => 0.015,
    'kappa' => 200,
    'thompson_draws' => 300,
    'stats_window_days' => 14,
    'frequency_cap_daily' => 4,
];

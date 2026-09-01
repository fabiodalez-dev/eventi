<?php

declare(strict_types=1);

/*
 * Parametri del sito pubblico (§11). Stanno qui e non dentro le viste perché
 * sono scelte di prodotto che cambiano senza toccare il codice: quante card
 * per sezione, quanti giorni mostra lo scroller, quali categorie contano come
 * "adatte alle famiglie".
 */
return [

    /*
     * Card per pagina nelle liste. Multiplo di 4: è il numero di colonne della
     * griglia più larga, così l'ultima riga non resta monca.
     */
    'per_page' => 24,

    /*
     * Card per sezione in homepage. Oltre questa soglia la pagina diventa un
     * elenco, e per l'elenco esistono /eventi/oggi e compagnia.
     */
    'home_section_size' => 8,

    /*
     * Giorni dello scroller della homepage (§11.2, punto 5), oggi compreso.
     */
    'day_scroller_days' => 14,

    /*
     * Il raggio della sezione «vicino a te» della pagina iniziale, in
     * chilometri. Dodici coprono una citta' media e la sua prima cintura:
     * piu' stretto lascia la sezione vuota nei giorni fiacchi, piu' largo
     * mette in cima cose a mezz'ora di macchina che «vicino» non sono.
     */
    'nearby_radius_km' => 12.0,

    /*
     * Eventi mostrati nelle sezioni di coda della scheda evento.
     */
    'related_size' => 4,

    /*
     * Date future elencate nella scheda evento prima di riassumere le altre.
     */
    'dates_shown' => 8,

    /*
     * Raggi offerti dal filtro "vicino a me" (§11.7), in chilometri.
     */
    'distance_options' => [1, 5, 10, 25],

    /*
     * Categorie che il filtro "adatto alle famiglie" (§11.3) seleziona. Il
     * modello dati non ha un flag dedicato: l'informazione vive nella
     * tassonomia, e questa è la riga che la traduce in un filtro.
     */
    'family_categories' => ['bambini-e-famiglie'],

    /*
     * Quanti filtri attivi bastano perché una combinazione smetta di essere
     * indicizzabile: le combinazioni sono infinite, le pagine utili poche.
     */
    'indexable_filters' => 2,

    /*
     * Da quanti giorni devono essere passate **tutte** le date di un evento
     * perché `events:archive` lo tolga dalle liste (§14.5).
     *
     * Novanta giorni e non pochi: un evento appena concluso viene ancora
     * cercato per nome — le foto, il programma, «chi suonava» — e la sua
     * pagina resta comunque raggiungibile dopo l'archiviazione (§11.9). La
     * soglia governa la sparizione dalle liste, non quella dal sito.
     */
    'archive_after_days' => (int) env('EVENTS_ARCHIVE_AFTER_DAYS', 90),

];

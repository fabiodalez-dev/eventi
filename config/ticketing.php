<?php

declare(strict_types=1);

return [
    /*
     * La finestra entro cui chi viene promosso dalla lista d'attesa deve
     * confermare. Dodici ore coprono una notte: chi riceve l'avviso a mezzanotte
     * lo trova la mattina e fa in tempo. Scaduta, il posto torna in circolo.
     */
    'promotion' => [
        'confirm_hours' => 12,
        /*
         * Sotto questa soglia dall'inizio non si promuove più nessuno: un
         * posto assegnato un'ora prima a chi non lo vedrà resta un posto vuoto,
         * e chi si presenta alla porta lo troverebbe occupato da nessuno.
         */
        'min_hours_before' => 2,
    ],
];

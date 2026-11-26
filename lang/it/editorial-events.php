<?php

declare(strict_types=1);

/*
 * I messaggi dell'API degli eventi della redazione (R2) che il pannello non
 * ha già in `admin.php`: lì «Programmazione approvata» è scritto nella
 * pagina, e il cestino non ha un avviso suo.
 */
return [
    'scheduled_approved' => 'Programmazione approvata',
    'deleted' => 'Evento spostato nel cestino.',
    'restored' => 'Evento ripristinato dal cestino.',
    'action_not_available' => 'Questa azione non vale più per l\'evento: ricarica la scheda.',
    'submission_rejected' => 'Proposta rifiutata.',
    'submission_published' => 'Evento pubblicato',
    'submission_already_reviewed' => 'Questa proposta è già stata esaminata.',
    'revision_empty' => 'Non c\'è niente da ripristinare in questa riga.',
];

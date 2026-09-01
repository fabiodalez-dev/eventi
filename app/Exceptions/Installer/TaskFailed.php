<?php

declare(strict_types=1);

namespace App\Exceptions\Installer;

use RuntimeException;

/**
 * Un'operazione della checklist di installazione non è riuscita.
 *
 * Porta due cose separate, e la separazione è il punto: `getMessage()` è già
 * il testo italiano che si mostra a chi installa, con dentro la soluzione;
 * `detail` è il dettaglio tecnico — l'output di Artisan, il messaggio di PDO —
 * che va **solo** nel log. L'installer è un endpoint pubblico per costruzione,
 * e un errore grezzo in pagina racconta a un estraneo com'è fatto il server.
 */
final class TaskFailed extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly string $detail = '',
        public readonly ?string $command = null,
    ) {
        parent::__construct($message);
    }
}

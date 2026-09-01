<?php

declare(strict_types=1);

namespace App\DTOs\Installer;

/**
 * L'esito della prova di connessione al database (D42, punto 3, passo 2).
 *
 * Tre esiti e non due, perché «credenziali sbagliate» e «database
 * inesistente» sono due guasti diversi con due rimedi diversi: il primo si
 * risolve nel pannello dell'hosting, il secondo creando il database — cosa
 * che l'installer prova a fare da sé prima di chiederla.
 *
 * `errorKey` è una chiave di traduzione, mai il messaggio di PDO: il dettaglio
 * tecnico finisce nel log, perché un errore PDO in pagina racconta a un
 * estraneo quali host e porte rispondono.
 */
final readonly class ConnectionProbe
{
    private function __construct(
        public bool $ok,
        public ?string $errorKey = null,
        public bool $databaseCreated = false,
    ) {}

    public static function success(bool $databaseCreated = false): self
    {
        return new self(true, null, $databaseCreated);
    }

    public static function failure(string $errorKey): self
    {
        return new self(false, $errorKey);
    }

    public function message(): ?string
    {
        return $this->errorKey === null ? null : __('installer.database.errors.'.$this->errorKey);
    }
}

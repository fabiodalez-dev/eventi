<?php

declare(strict_types=1);

namespace App\DTOs;

/**
 * Il resoconto della rotazione della chiave dell'impronta. Contiene soltanto
 * conteggi e identificativi di riga: né numeri, né impronte, né chiavi, perché
 * finisce sul terminale e da lì nei log e nella cronologia.
 */
final readonly class PhoneRehashReport
{
    /**
     * Le classi sono `already`, `rehash`, `mismatch` e, per i soli utenti, `orphan`.
     *
     * @param  array<string, int>  $users  classe => utenti
     * @param  array<string, int>  $challenges  classe => richieste
     * @param  list<int>  $mismatchedUsers
     * @param  list<string>  $mismatchedChallenges
     * @param  list<int>  $collidingUsers
     */
    public function __construct(
        public array $users,
        public array $challenges,
        public array $mismatchedUsers,
        public array $mismatchedChallenges,
        public array $collidingUsers,
        public bool $orphansBlocking,
        public bool $written,
    ) {}

    /** Nulla è stato scritto perché qualcosa non torna: righe sconosciute, collisioni o orfane non autorizzate. */
    public function aborted(): bool
    {
        return $this->mismatchedUsers !== [] || $this->mismatchedChallenges !== []
            || $this->collidingUsers !== [] || $this->orphansBlocking;
    }
}

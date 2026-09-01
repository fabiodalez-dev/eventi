<?php

declare(strict_types=1);

namespace App\DTOs\Installer;

use App\Enums\RequirementStatus;

/**
 * L'esito di un singolo controllo dei requisiti (D42, punto 4).
 *
 * Ogni requisito non soddisfatto porta con sé la soluzione: `hint` la spiega a
 * parole, `command` è la riga da incollare in un terminale quando ce n'è una.
 * Un requisito che dice solo «manca» costringe chi installa a cercare altrove
 * proprio nel momento in cui è bloccato.
 */
final readonly class Requirement
{
    /**
     * @param  string  $key  chiave di traduzione sotto `installer.requirements.items`
     * @param  array<string, string>  $replace  segnaposto del testo (valore attuale, valore richiesto)
     */
    public function __construct(
        public string $key,
        public RequirementStatus $status,
        public array $replace = [],
        public ?string $command = null,
    ) {}

    public function label(): string
    {
        return __('installer.requirements.items.'.$this->key.'.label', $this->replace);
    }

    /** La soluzione. Nulla da dire quando il requisito è soddisfatto. */
    public function hint(): ?string
    {
        if ($this->status === RequirementStatus::Ok) {
            return null;
        }

        return __('installer.requirements.items.'.$this->key.'.hint', $this->replace);
    }

    public function isBlocking(): bool
    {
        return $this->status === RequirementStatus::Blocking;
    }
}

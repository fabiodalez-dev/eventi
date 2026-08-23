<?php

declare(strict_types=1);

namespace App\DTOs;

use App\Enums\NotificationSkipReason;
use Carbon\CarbonImmutable;

/**
 * Cosa fare di un invio arrivato a scadenza: mandarlo, spostarlo o saltarlo.
 *
 * Le tre risposte stanno in un oggetto solo perché sono la stessa decisione:
 * un `bool` avrebbe costretto chi chiama a chiedere due volte — "si manda?" e
 * poi "e se no, perché?" — con il rischio che le due domande divergano.
 */
final readonly class NotificationDecision
{
    private function __construct(
        public bool $send,
        public ?NotificationSkipReason $reason,
        public ?CarbonImmutable $deferTo,
    ) {}

    public static function send(): self
    {
        return new self(true, null, null);
    }

    public static function skip(NotificationSkipReason $reason): self
    {
        return new self(false, $reason, null);
    }

    /**
     * L'invio esce dalla finestra di silenzio e riparte da lì (§15.4). Resta
     * in attesa: non è stato saltato, è stato spostato — e la differenza si
     * vede nel pannello.
     */
    public static function defer(CarbonImmutable $sendAt): self
    {
        return new self(false, null, $sendAt);
    }
}

<?php

declare(strict_types=1);

namespace App\Services\Sponsorship;

use App\Models\SponsorshipOrder;

/** L'esito di un gesto sulla pagina «Sponsorizzazioni», con l'ordine quando ce n'è uno. */
final readonly class SponsorshipDeskResult
{
    public function __construct(
        public SponsorshipDeskOutcome $outcome,
        public ?SponsorshipOrder $order = null,
    ) {}
}

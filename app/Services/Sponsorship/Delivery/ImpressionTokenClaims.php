<?php

declare(strict_types=1);

namespace App\Services\Sponsorship\Delivery;

use App\Enums\SponsorshipPlacement;
use Carbon\CarbonImmutable;

/**
 * Ciò che dice un gettone di visualizzazione verificato: quale riga, in quale
 * collocazione, emesso quando, con quale numero casuale.
 *
 * Il numero casuale è la chiave della deduplica
 * (`sponsorship_impression_nonces.nonce`): appartiene a una visita di pagina,
 * non a una persona.
 */
final readonly class ImpressionTokenClaims
{
    public function __construct(
        public int $sponsorshipId,
        public string $placement,
        public CarbonImmutable $issuedAt,
        public string $nonce,
    ) {}

    /** Se il gettone è stato emesso per questa riga (e, se indicata, per questa collocazione). */
    public function isFor(int $sponsorshipId, SponsorshipPlacement|string|null $placement = null): bool
    {
        if ($sponsorshipId !== $this->sponsorshipId) {
            return false;
        }

        if ($placement === null) {
            return true;
        }

        return ($placement instanceof SponsorshipPlacement ? $placement->value : $placement) === $this->placement;
    }

    /** La collocazione come tipo, se è una delle quattro; `null` per gli altri spazi (il banner). */
    public function placementCase(): ?SponsorshipPlacement
    {
        return SponsorshipPlacement::tryFrom($this->placement);
    }
}

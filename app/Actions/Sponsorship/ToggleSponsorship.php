<?php

declare(strict_types=1);

namespace App\Actions\Sponsorship;

use App\Enums\SponsorshipStatus;
use App\Models\Sponsorship;

/**
 * «Metti in pausa» e «Attiva» di una campagna (R-SP-05): una campagna attiva
 * va in pausa, una in pausa o in bozza diventa attiva. Lo chiamano la riga
 * dell'elenco del pannello e l'API dell'app; chi chiama controlla prima la
 * policy (`SponsorshipPolicy::update`).
 */
final class ToggleSponsorship
{
    /** Vero se ora la campagna è attiva. */
    public function handle(Sponsorship $sponsorship): bool
    {
        $attiva = $sponsorship->status !== SponsorshipStatus::Active;

        $sponsorship->update(['status' => $attiva ? SponsorshipStatus::Active : SponsorshipStatus::Paused]);

        return $attiva;
    }
}

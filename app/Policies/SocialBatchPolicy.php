<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\SocialBatch;
use App\Models\User;

class SocialBatchPolicy
{
    /**
     * Il pacchetto di un locale lo apre chi ne fa parte (invito accettato)
     * **finché il locale non è sospeso**, come il resto della gestione
     * (`isActiveMemberOfVenue`); quelli della redazione solo gli amministratori.
     * Prima bastava l'invito accettato, e lo ZIP del sito si scaricava anche
     * da un locale sospeso.
     */
    public function view(User $user, SocialBatch $batch): bool
    {
        return $user->hasAnyRole(['admin', 'super_admin']) || ($batch->venue_id !== null && $user->isActiveMemberOfVenue($batch->venue_id));
    }
}

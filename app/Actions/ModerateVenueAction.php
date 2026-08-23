<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\VenueStatus;
use App\Models\User;
use App\Models\Venue;
use Illuminate\Support\Facades\DB;

/**
 * Le decisioni della redazione su un locale (§9.2): approvare, rifiutare,
 * sospendere, verificare.
 *
 * Sta qui e non nel pannello perché le stesse transizioni servono all'import,
 * alle richieste di iscrizione e — un domani — a un endpoint di servizio: se
 * ogni chiamante scrivesse `status` per conto proprio, prima o poi qualcuno
 * dimenticherebbe di azzerare il motivo del rifiuto, e un locale approvato
 * resterebbe con addosso la spiegazione di quando era stato respinto.
 *
 * Ogni transizione è registrata da `LogsActivity`, che il model dichiara
 * proprio su `status`, `is_verified`, `approved_at`, `approved_by` e
 * `rejection_reason`: la cronologia delle decisioni non va scritta a mano.
 */
final class ModerateVenueAction
{
    public function approve(Venue $venue, User $moderator): Venue
    {
        return DB::transaction(function () use ($venue, $moderator): Venue {
            $venue->status = VenueStatus::Approved;
            $venue->approved_at = now();
            $venue->approved_by = $moderator->getKey();
            $venue->rejection_reason = null;
            $venue->save();

            return $venue;
        });
    }

    public function reject(Venue $venue, User $moderator, string $reason): Venue
    {
        return DB::transaction(function () use ($venue, $reason): Venue {
            $venue->status = VenueStatus::Rejected;
            $venue->rejection_reason = $reason;
            $venue->approved_at = null;
            $venue->approved_by = null;
            $venue->save();

            return $venue;
        });
    }

    /**
     * Sospendere non è rifiutare: il locale è stato approvato e resta tale
     * nella storia, ma esce temporaneamente dal sito pubblico. Per questo
     * `approved_at` e `approved_by` non vengono cancellati.
     */
    public function suspend(Venue $venue, string $reason): Venue
    {
        $venue->status = VenueStatus::Suspended;
        $venue->rejection_reason = $reason;
        $venue->save();

        return $venue;
    }

    public function setVerified(Venue $venue, bool $verified): Venue
    {
        $venue->is_verified = $verified;
        $venue->save();

        return $venue;
    }
}

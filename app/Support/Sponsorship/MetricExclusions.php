<?php

declare(strict_types=1);

namespace App\Support\Sponsorship;

use App\Models\Event;
use App\Models\Organizer;
use App\Models\Sponsorship;
use App\Models\SponsorshipGrant;
use App\Models\User;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * Chi, quando è collegato, non viene contato nelle misure di una campagna.
 *
 * **La redazione** (amministratori, amministratori di sistema e moderatori):
 * guarda le sponsorizzazioni per controllarle, non per sceglierle, e le sue
 * visite finirebbero nei numeri che il committente paga.
 *
 * **Chi sta dalla parte del committente**: i membri del locale o
 * dell'organizzatore che ha comprato. Il proprietario che apre la home per
 * vedere se la propria pubblicità c'è gonfia i propri numeri, e il giorno in
 * cui li legge crede a un pubblico che era lui.
 *
 * Il committente è quello dell'abilitazione, se la campagna ne ha una. Una
 * campagna singola messa dalla redazione non ha un committente nel sistema
 * (nome ed email sono testo libero): lì si escludono i membri del locale e
 * dell'organizzatore dell'evento in vetrina, che sono comunque i proprietari
 * di ciò che si promuove.
 *
 * Chi non è collegato si conta sempre: senza identificativi, che di proposito
 * non si salvano, non c'è modo di sapere chi è, ed è giusto così.
 */
final class MetricExclusions
{
    public static function excludes(?Authenticatable $user, Sponsorship $campaign): bool
    {
        if (! $user instanceof User) {
            return false;
        }

        if (self::isEditorialStaff($user)) {
            return true;
        }

        [$venueId, $organizerId] = self::sponsor($campaign);

        if ($venueId !== null && $user->venueMemberships()->has($venueId)) {
            return true;
        }

        return $organizerId !== null && self::managesOrganizer($user, $organizerId);
    }

    /** Se chi guarda è della redazione: il solo controllo che vale anche senza una campagna. */
    public static function isEditorialStaff(?Authenticatable $user): bool
    {
        return $user instanceof User && $user->isEditorialStaff();
    }

    /**
     * Locale e organizzatore che stanno dalla parte di chi paga.
     *
     * @return array{0: int|null, 1: int|null}
     */
    private static function sponsor(Sponsorship $campaign): array
    {
        $grant = $campaign->sponsorship_grant_id !== null ? $campaign->grant : null;

        if ($grant instanceof SponsorshipGrant) {
            return [
                $grant->venue_id !== null ? (int) $grant->venue_id : null,
                $grant->organizer_id !== null ? (int) $grant->organizer_id : null,
            ];
        }

        $event = $campaign->event;

        if (! $event instanceof Event) {
            return [null, null];
        }

        return [
            $event->venue_id !== null ? (int) $event->venue_id : null,
            $event->organizer_id !== null ? (int) $event->organizer_id : null,
        ];
    }

    private static function managesOrganizer(User $user, int $organizerId): bool
    {
        return Organizer::query()
            ->whereKey($organizerId)
            ->where(fn ($query) => $query->where('owner_id', $user->getKey())
                ->orWhereHas('users', fn ($users) => $users->whereKey($user->getKey())))
            ->exists();
    }
}

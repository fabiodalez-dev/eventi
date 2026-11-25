<?php

declare(strict_types=1);

namespace App\Support\Notifications;

use App\Models\User;
use App\Models\Venue;

/**
 * Chi vede l'interruttore «Rapporto mensile dei locali che gestisco»
 * (`venue_report`, W-ST-10): chi ha accettato l'accesso ad almeno un locale.
 *
 * Una regola sola per la pagina delle preferenze del sito e per l'API
 * (`venue_report_available` di `GET /v1/me/notification-preferences`), così
 * l'app mostra l'interruttore alle stesse persone.
 */
final class VenueReportPreference
{
    public static function available(User $user): bool
    {
        /* Letta sul locale e non sulla relazione `venues()`, che il menu
           dell'account in testata legge già: due letture della stessa
           relazione sarebbero un N+1. */
        return Venue::query()->join('venue_user', 'venue_user.venue_id', '=', 'venues.id')
            ->where('venue_user.user_id', $user->getKey())->whereNotNull('venue_user.accepted_at')->exists();
    }
}

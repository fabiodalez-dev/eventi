<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Actions\CreateVenueApplication;
use App\Models\User;
use App\Models\VenueApplication;
use Illuminate\Auth\Events\Verified;

/**
 * La conferma dell'email mette in coda le richieste che aspettavano.
 *
 * Chi registra il proprio locale da ospite crea anche l'account: la richiesta
 * nasce fuori dalla coda e ci entra qui, quando la persona apre il
 * collegamento della registrazione. Solo le richieste scritte con quello
 * stesso indirizzo: è l'indirizzo appena dimostrato.
 */
final class QueueVenueApplicationOnVerified
{
    public function __construct(private readonly CreateVenueApplication $applications) {}

    public function handle(Verified $event): void
    {
        $user = $event->user;

        if (! $user instanceof User) {
            return;
        }

        VenueApplication::query()
            ->withoutGlobalScope(VenueApplication::AWAITING_EMAIL)
            ->awaitingEmail()
            ->where('user_id', $user->getKey())
            ->where('contact_email', mb_strtolower(trim($user->email)))
            ->get()
            ->each(fn (VenueApplication $application) => $this->applications->queue($application));
    }
}

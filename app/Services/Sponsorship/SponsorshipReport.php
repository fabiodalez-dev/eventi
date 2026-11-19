<?php

declare(strict_types=1);

namespace App\Services\Sponsorship;

use App\Filament\Venue\Support\CurrentVenue;
use App\Models\City;
use App\Models\Organizer;
use App\Models\Sponsorship;
use App\Models\User;
use App\Models\Venue;
use Filament\Facades\Filament;
use Illuminate\Database\Eloquent\Builder;

/**
 * Le campagne che chi guarda le statistiche puo' vedere.
 *
 * Nel pannello del locale: le campagne sugli eventi del locale, nate da una
 * sua abilitazione o dalla redazione. Nel pannello dell'organizzazione: lo
 * stesso per gli eventi che firma. Nell'amministrazione: tutte, solo per chi
 * ha il ruolo.
 */
final class SponsorshipReport
{
    /** @return Builder<Sponsorship> */
    public function campaigns(): Builder
    {
        $user = auth()->user();
        abort_unless($user instanceof User, 403);
        $query = Sponsorship::withTrashed();
        $panel = Filament::getCurrentPanel()?->getId();

        if ($panel === 'venue') {
            $venue = CurrentVenue::get();
            abort_unless($user->canAccessTenant($venue), 403);

            return $query->whereHas('event', fn ($q) => $q->where('venue_id', $venue->id))
                ->where(fn ($q) => $q->whereNull('sponsorship_grant_id')->orWhereHas('grant', fn ($g) => $g->where('venue_id', $venue->id)));
        }

        if ($panel === 'organizer') {
            $organizer = Filament::getTenant();
            abort_unless($organizer instanceof Organizer && $user->canAccessTenant($organizer), 403);

            return $query->whereHas('event', fn ($q) => $q->where('organizer_id', $organizer->id))
                ->where(fn ($q) => $q->whereNull('sponsorship_grant_id')->orWhereHas('grant', fn ($g) => $g->where('organizer_id', $organizer->id)));
        }

        abort_unless($user->hasAnyRole(['admin', 'super_admin']), 403);

        return $query;
    }

    /**
     * Il fuso in cui leggere i giorni: quello della citta' del committente
     * nei pannelli (i contatori giornalieri si scrivono nel fuso della citta'
     * della campagna), quello dell'applicazione per la redazione, che vede
     * piu' citta' insieme.
     */
    public function timezone(): string
    {
        $tenant = match (Filament::getCurrentPanel()?->getId()) {
            'venue' => CurrentVenue::get(),
            'organizer' => Filament::getTenant(),
            default => null,
        };
        $city = $tenant instanceof Venue || $tenant instanceof Organizer ? $tenant->city : null;
        $timezone = $city instanceof City ? $city->timezone : null;

        return is_string($timezone) && $timezone !== '' ? $timezone : 'Europe/Rome';
    }
}

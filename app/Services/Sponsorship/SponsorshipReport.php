<?php

declare(strict_types=1);

namespace App\Services\Sponsorship;

use App\Filament\Venue\Support\CurrentVenue;
use App\Models\Sponsorship;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Database\Eloquent\Builder;

final class SponsorshipReport
{
    /** @return Builder<Sponsorship> */
    public function campaigns(): Builder
    {
        $user = auth()->user();
        abort_unless($user instanceof User, 403);
        $query = Sponsorship::withTrashed();
        if (Filament::getCurrentPanel()?->getId() === 'venue') {
            $venue = CurrentVenue::get();
            abort_unless($user->canAccessTenant($venue), 403);

            return $query->whereHas('event', fn ($q) => $q->where('venue_id', $venue->id))
                ->where(fn ($q) => $q->whereNull('sponsorship_grant_id')->orWhereHas('grant', fn ($g) => $g->where('venue_id', $venue->id)));
        }
        abort_unless($user->hasAnyRole(['admin', 'super_admin']), 403);

        return $query;
    }
}

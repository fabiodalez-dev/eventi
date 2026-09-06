<?php

declare(strict_types=1);

namespace App\Services\Sponsorship;

use App\Enums\PromotionMode;
use App\Enums\SponsorshipStatus;
use App\Models\Event;
use App\Models\Sponsorship;
use App\Models\SponsorshipGrant;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

final class GrantCampaigns
{
    public function choose(User $user, SponsorshipGrant $grant, Event $event): Sponsorship
    {
        Gate::forUser($user)->authorize('update', $event);

        return DB::transaction(function () use ($grant, $event): Sponsorship {
            $grant = SponsorshipGrant::query()->lockForUpdate()->findOrFail($grant->id);
            abort_unless($grant->mode === PromotionMode::Selected && $grant->venue_id === $event->venue_id
                && SponsorshipGrant::active()->whereKey($grant->id)->exists(), 403);

            $campaign = $this->campaign($grant, $event);
            abort_if($campaign->trashed(), 403);
            $campaign->update(['status' => SponsorshipStatus::Active]);

            return $campaign;
        });
    }

    public function sync(SponsorshipGrant $grant): void
    {
        if (! SponsorshipGrant::active()->whereKey($grant->id)->exists()) {
            return;
        }
        $grant->sponsorships()->update(['starts_at' => $grant->starts_at, 'ends_at' => $grant->ends_at]);
        if ($grant->mode !== PromotionMode::Automatic) {
            return;
        }
        Event::query()->where('venue_id', $grant->venue_id)->chunkById(100, function ($events) use ($grant): void {
            foreach ($events as $event) {
                $this->campaign($grant, $event);
            }
        });
    }

    private function campaign(SponsorshipGrant $grant, Event $event): Sponsorship
    {
        return Sponsorship::withTrashed()->firstOrCreate([
            'sponsorship_grant_id' => $grant->id, 'event_id' => $event->id, 'placement' => $grant->placement,
        ], [
            'city_id' => $event->city_id, 'created_by' => $grant->created_by,
            'status' => SponsorshipStatus::Active, 'starts_at' => $grant->starts_at, 'ends_at' => $grant->ends_at,
            'advertiser_name' => $grant->venue->name, 'weight' => 1, 'priority' => 0, 'currency' => 'EUR',
        ]);
    }
}

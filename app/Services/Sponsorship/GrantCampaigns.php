<?php

declare(strict_types=1);

namespace App\Services\Sponsorship;

use App\Enums\PromotionMode;
use App\Enums\SponsorshipStatus;
use App\Models\Event;
use App\Models\Sponsorship;
use App\Models\SponsorshipGrant;
use App\Models\User;
use App\Support\Sponsorship\SponsorableEvents;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Le campagne che nascono da un'abilitazione: scelte una per una dal
 * committente (`choose`), messe dalla redazione approvando una richiesta che
 * indicava già l'evento (`attach`), o create da sole per tutti gli eventi in
 * modalità automatica (`sync`).
 *
 * In tutte e tre le strade l'evento deve essere sponsorizzabile — pubblicato e
 * con una data ancora da fare — e lo controlla il server, non la pagina.
 */
final class GrantCampaigns
{
    public function choose(User $user, SponsorshipGrant $grant, Event $event): Sponsorship
    {
        Gate::forUser($user)->authorize('update', $event);

        return $this->attach($grant, $event);
    }

    /** Senza controllo dell'utente: la usa chi ha già deciso, cioè la redazione che approva. */
    public function attach(SponsorshipGrant $grant, Event $event): Sponsorship
    {
        return DB::transaction(function () use ($grant, $event): Sponsorship {
            $grant = SponsorshipGrant::query()->lockForUpdate()->findOrFail($grant->id);
            abort_unless($grant->mode === PromotionMode::Selected && $grant->coversEvent($event)
                && SponsorableEvents::isSponsorable($event)
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
        SponsorableEvents::scope($grant->eventsQuery())->chunkById(100, function ($events) use ($grant): void {
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
            'advertiser_name' => $grant->sponsorName(), 'weight' => 1, 'priority' => 0, 'currency' => 'EUR',
        ]);
    }
}

<?php

declare(strict_types=1);

namespace App\Services\Ticketing;

use App\Enums\NotificationType;
use App\Enums\OccurrenceStatus;
use App\Enums\TicketTierStatus;
use App\Models\AvailabilityAlert;
use App\Models\EventOccurrence;
use App\Models\User;
use App\Services\Concerns\RemembersAlertIds;
use App\Services\Notifications\NotificationScheduler;
use App\Support\TicketTiers;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

/**
 * «Avvisami se tornano disponibili».
 *
 * Esaurita vuol dire una di due cose: la data è segnata come esaurita, oppure
 * tutte le sue fasce di prezzo (quelle della data o, se non ne ha, quelle
 * dell'evento) sono esaurite. Quando torna disponibile, chi l'aveva chiesto
 * riceve un avviso e la richiesta si chiude: è una promessa di una volta.
 */
final class AvailabilityAlerts
{
    use RemembersAlertIds;

    public function __construct(private readonly NotificationScheduler $scheduler) {}

    public function isSoldOut(EventOccurrence $date): bool
    {
        if ($date->status === OccurrenceStatus::SoldOut) {
            return true;
        }
        $event = $date->event;
        if ($event === null) {
            return false;
        }
        $tiers = TicketTiers::for($event, $date);

        return $tiers->isNotEmpty() && $tiers->every(static fn ($tier): bool => $tier->status === TicketTierStatus::SoldOut);
    }

    public function subscribe(User $user, EventOccurrence $date): void
    {
        if ($date->starts_at->isPast() || ! $this->isSoldOut($date)) {
            throw ValidationException::withMessages(['occurrence' => __('ticketing.availability.not_sold_out')]);
        }
        AvailabilityAlert::query()->firstOrCreate(['user_id' => $user->getKey(), 'occurrence_id' => $date->getKey()]);
        $this->forgetAlertIds($user);
    }

    public function unsubscribe(User $user, int $occurrenceId): void
    {
        AvailabilityAlert::query()->where('user_id', $user->getKey())->where('occurrence_id', $occurrenceId)->delete();
        $this->forgetAlertIds($user);
    }

    /** @return list<int> */
    public function ids(User $user): array
    {
        return AvailabilityAlert::query()->where('user_id', $user->getKey())->orderBy('occurrence_id')
            ->pluck('occurrence_id')->map(static fn (mixed $id): int => (int) $id)->all();
    }

    /**
     * Da chiamare quando qualcosa può aver riaperto la vendita: se la data
     * non è più esaurita, avvisa chi aspettava e chiude le richieste.
     */
    public function announceIfAvailable(EventOccurrence $date): int
    {
        if ($date->starts_at->isPast() || $date->status === OccurrenceStatus::Cancelled || $this->isSoldOut($date)) {
            return 0;
        }
        $alerts = AvailabilityAlert::query()->where('occurrence_id', $date->getKey())->get();
        $now = CarbonImmutable::now();
        $sent = 0;
        foreach ($alerts as $alert) {
            $sent += (int) $this->scheduler->queue(
                (int) $alert->user_id,
                NotificationType::TicketsAvailable,
                sprintf('tickets_available:user_%d:occ_%d:%d', $alert->user_id, $date->getKey(), $now->getTimestamp()),
                $now,
                $date,
            );
        }
        AvailabilityAlert::query()->whereKey($alerts->modelKeys())->delete();

        return $sent;
    }
}

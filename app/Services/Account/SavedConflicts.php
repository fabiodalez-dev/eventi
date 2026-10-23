<?php

declare(strict_types=1);

namespace App\Services\Account;

use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\EventOccurrence;
use App\Models\SavedEvent;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Le date della stessa persona che si sovrappongono a una data.
 *
 * Due date si sovrappongono se gli intervalli `[inizio, fine effettiva)` si
 * intersecano: la fine effettiva è quella che il motore temporale calcola
 * anche quando l'evento non la dichiara. Si guardano le date salvate e
 * quelle prenotate; una prenotazione vince sul salvataggio nella stessa
 * data, perché è l'impegno più forte.
 */
final class SavedConflicts
{
    /** @return list<array{occurrence_id: int, title: string, starts_at: string, ends_at: string, kind: string}> */
    public function for(User $user, EventOccurrence $date): array
    {
        $start = $date->starts_at;
        $end = $date->effective_ends_at ?? $date->ends_at ?? $date->starts_at->copy()->addHours(3);

        $saved = SavedEvent::query()->where('user_id', $user->getKey())->pluck('occurrence_id');
        $booked = Booking::query()->where('user_id', $user->getKey())
            ->whereIn('status', [BookingStatus::Confirmed, BookingStatus::Waitlisted])->pluck('occurrence_id');
        $ids = $saved->merge($booked)->map(static fn (mixed $id): int => (int) $id)->unique()
            ->reject(static fn (int $id): bool => $id === (int) $date->getKey())->values();
        if ($ids->isEmpty()) {
            return [];
        }

        $bookedIds = $booked->map(static fn (mixed $id): int => (int) $id)->all();

        /** @var Collection<int, EventOccurrence> $others */
        $others = EventOccurrence::query()->with('event:id,title')->whereKey($ids->all())
            ->where('status', '!=', 'cancelled')
            ->where('starts_at', '<', $end)
            ->whereRaw('COALESCE(effective_ends_at, ends_at, DATE_ADD(starts_at, INTERVAL 3 HOUR)) > ?', [$start])
            ->orderBy('starts_at')->get();

        return $others->map(static fn (EventOccurrence $other): array => [
            'occurrence_id' => (int) $other->getKey(),
            'title' => (string) $other->event?->title,
            'starts_at' => $other->starts_at->toIso8601String(),
            'ends_at' => ($other->effective_ends_at ?? $other->ends_at ?? $other->starts_at->copy()->addHours(3))->toIso8601String(),
            'kind' => in_array((int) $other->getKey(), $bookedIds, true) ? 'booked' : 'saved',
        ])->values()->all();
    }

    /**
     * Per la pagina dei salvati: quali date salvate si sovrappongono ad
     * almeno un'altra, con il titolo della prima trovata.
     *
     * @param  iterable<EventOccurrence>  $dates
     * @return array<int, string>
     */
    public function overlappingTitles(iterable $dates): array
    {
        $list = collect($dates)->sortBy('starts_at')->values();
        $found = [];
        foreach ($list as $i => $a) {
            $aEnd = $a->effective_ends_at ?? $a->ends_at ?? $a->starts_at->copy()->addHours(3);
            for ($j = $i + 1; $j < $list->count(); $j++) {
                $b = $list[$j];
                if ($b->starts_at->greaterThanOrEqualTo($aEnd)) {
                    break;
                }
                $found[(int) $a->getKey()] ??= (string) $b->event?->title;
                $found[(int) $b->getKey()] ??= (string) $a->event?->title;
            }
        }

        return $found;
    }
}

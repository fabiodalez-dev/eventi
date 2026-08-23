<?php

declare(strict_types=1);

namespace App\Observers;

use App\Models\Event;
use App\Models\EventOccurrence;
use Illuminate\Database\Eloquent\Collection;

/**
 * `business_date` ed `effective_ends_at` dipendono dalla categoria dell'evento
 * (`is_nightlife`, `default_duration_minutes`) e dalla sua città (fuso orario e
 * `night_cutoff_time`). Se uno dei due cambia, le occorrenze già salvate
 * restano ferme su un calcolo che non vale più: qui vengono risalvate, così
 * che `EventOccurrenceObserver` le ricalcoli.
 */
final class EventObserver
{
    /**
     * Occorrenze ricaricate a blocchi: un evento con una ricorrenza
     * pluriennale ne ha centinaia.
     */
    private const CHUNK = 200;

    public function updated(Event $event): void
    {
        if (! $event->wasChanged(['category_id', 'city_id'])) {
            return;
        }

        $event->load(['city', 'category', 'venue']);

        $event->occurrences()
            ->orderBy('id')
            ->chunkById(self::CHUNK, function (Collection $occurrences) use ($event): void {
                /** @var EventOccurrence $occurrence */
                foreach ($occurrences as $occurrence) {
                    $occurrence->setRelation('event', $event);
                    $occurrence->save();
                }
            });
    }
}

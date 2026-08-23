<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\EventStatus;
use App\Models\Event;
use RuntimeException;

/**
 * Mette online un evento, o lo riporta in bozza (§9.2).
 *
 * **Un evento senza date non si pubblica.** Non è una preferenza redazionale:
 * l'intero prodotto interroga `event_occurrences`, quindi un evento pubblicato
 * senza occorrenze non comparirebbe in nessuna finestra temporale — sarebbe
 * online e invisibile insieme, e nessuno se ne accorgerebbe finché non lo
 * cerca il locale che lo ha proposto.
 *
 * `published_at` si scrive solo la prima volta: è la data della prima uscita,
 * non quella dell'ultima modifica di stato — `updated_at` fa già quel lavoro.
 */
final class PublishEventAction
{
    public function publish(Event $event): Event
    {
        if (! $event->occurrences()->exists()) {
            throw new RuntimeException('An event without occurrences cannot be published.');
        }

        $event->status = EventStatus::Published;
        $event->published_at ??= now();
        $event->rejection_reason = null;
        $event->save();

        return $event;
    }

    public function unpublish(Event $event): Event
    {
        $event->status = EventStatus::Draft;
        $event->save();

        return $event;
    }

    public function canPublish(Event $event): bool
    {
        return $event->occurrences()->exists();
    }
}

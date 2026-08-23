<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\EventStatus;
use App\Enums\OccurrenceStatus;
use App\Models\Event;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Rifiuto, annullamento e messa in evidenza di un evento (§9.2).
 *
 * L'annullamento porta con sé **tutte le date**: un evento annullato le cui
 * occorrenze restassero `scheduled` continuerebbe a comparire in "oggi" e in
 * "stasera", perché il motore temporale legge le occorrenze e non lo stato
 * dell'evento. Le date già passate non si toccano — annullare qualcosa che è
 * già successo non ha senso e cancellerebbe la storia.
 */
final class ModerateEventAction
{
    public function reject(Event $event, string $reason): Event
    {
        $event->status = EventStatus::Rejected;
        $event->rejection_reason = $reason;
        $event->save();

        return $event;
    }

    public function cancel(Event $event, ?string $note = null): Event
    {
        return DB::transaction(function () use ($event, $note): Event {
            $event->status = EventStatus::Cancelled;
            $event->save();

            $upcoming = $event->occurrences()
                ->where('starts_at', '>=', now())
                ->where('status', '!=', OccurrenceStatus::Cancelled)
                ->get();

            foreach ($upcoming as $occurrence) {
                $occurrence->status = OccurrenceStatus::Cancelled;
                $occurrence->status_note = $note;
                $occurrence->save();
            }

            return $event;
        });
    }

    public function setFeatured(Event $event, bool $featured, ?Carbon $until = null): Event
    {
        $event->is_featured = $featured;
        $event->featured_until = $featured ? $until : null;
        $event->save();

        return $event;
    }
}

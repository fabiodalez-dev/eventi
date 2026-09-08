<?php

declare(strict_types=1);

namespace App\Observers;

use App\Enums\EventStatus;
use App\Enums\OccurrenceStatus;
use App\Models\Event;
use App\Models\EventOccurrence;
use App\Services\Ticketing\TicketingService;
use Illuminate\Database\Eloquent\Model;

class TicketingObserver
{
    public function updated(Model $model): void
    {
        $service = app(TicketingService::class);
        if ($model instanceof EventOccurrence) {
            if ($model->wasChanged('status') && $model->status === OccurrenceStatus::Cancelled) {
                $service->cancelDate($model);
            } elseif ($model->wasChanged(['starts_at', 'ends_at', 'doors_at', 'status', 'venue_id'])) {
                $service->announceChange($model);
            }
        } elseif ($model instanceof Event && $model->wasChanged(['status', 'venue_id', 'title'])) {
            $model->occurrences()->each(function (EventOccurrence $date) use ($service, $model): void {
                $model->status === EventStatus::Cancelled ? $service->cancelDate($date) : $service->announceChange($date);
            });
        }
    }

    public function deleting(Model $model): void
    {
        $service = app(TicketingService::class);
        if ($model instanceof EventOccurrence) {
            $service->cancelDate($model);
        } elseif ($model instanceof Event) {
            $model->occurrences()->each(fn (EventOccurrence $date) => $service->cancelDate($date));
        }
    }
}

<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\EventStatus;
use App\Models\Event;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

final class ScheduleEventPublication
{
    public function schedule(Event $event, User $user, CarbonImmutable $at): void
    {
        $event->load('venue');
        Gate::forUser($user)->authorize('update', $event);
        if (! in_array($event->status, [EventStatus::Draft, EventStatus::Pending], true) || $at->isPast() || ! $event->occurrences()->exists()) {
            throw ValidationException::withMessages(['publish_at' => 'Scegli un orario futuro per una bozza con almeno una data.']);
        }
        $event->scheduled_publish_at = $at->utc();
        $event->publication_scheduled_by = $user->id;
        $event->status = Gate::forUser($user)->allows('publish', $event) ? EventStatus::Draft : EventStatus::Pending;
        $event->save();
    }

    public function cancel(Event $event, User $user): void
    {
        Gate::forUser($user)->authorize('update', $event);
        $event->scheduled_publish_at = null;
        $event->publication_scheduled_by = null;
        $event->save();
    }
}

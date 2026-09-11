<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\PublishEventAction;
use App\Enums\EventStatus;
use App\Models\Event;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class PublishDueEvents extends Command
{
    protected $signature = 'events:publish-due';

    protected $description = 'Pubblica gli eventi programmati, ricontrollando autorizzazioni e moderazione.';

    public function handle(): int
    {
        Event::query()->where('scheduled_publish_at', '<=', now())
            ->whereIn('status', [EventStatus::Draft, EventStatus::Pending])
            ->chunkById(100, function ($events): void {
                foreach ($events as $candidate) {
                    DB::transaction(function () use ($candidate): void {
                        $event = Event::query()->lockForUpdate()->find($candidate->id);
                        if (! $event || ! $event->scheduled_publish_at || $event->scheduled_publish_at->isFuture()
                            || ! in_array($event->status, [EventStatus::Draft, EventStatus::Pending], true)) {
                            return;
                        }
                        $user = User::find($event->publication_scheduled_by);
                        if (! $user || ! Gate::forUser($user)->allows('publish', $event)
                            || ! app(PublishEventAction::class)->canPublish($event)) {
                            return;
                        }
                        app(PublishEventAction::class)->publish($event);
                    });
                }
            });

        return self::SUCCESS;
    }
}

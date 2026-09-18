<?php

declare(strict_types=1);

namespace App\Services\Analytics;

use App\Enums\ContentMetric;
use App\Models\City;
use App\Models\Event;
use App\Models\EventOccurrence;
use App\Models\EventShareLink;
use App\Queries\EventOccurrenceQuery;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class EventShares
{
    public const CHANNELS = ['native', 'whatsapp', 'telegram', 'email'];

    /** @return array<string, array{url: string, metric: string}> */
    public function links(Event $event, ?EventOccurrence $occurrence): array
    {
        abort_if($occurrence !== null && $occurrence->event_id !== $event->id, 404);
        $existing = EventShareLink::query()->where('event_id', $event->id)
            ->where('occurrence_id', $occurrence?->id)->get()->keyBy('channel');
        $links = [];
        foreach (self::CHANNELS as $channel) {
            $link = $existing->get($channel) ?? $this->create($event, $occurrence, $channel);
            $links[$channel] = [
                'url' => route('event-shares.open', ['code' => $link->code]),
                'metric' => route('event-shares.share', ['code' => $link->code], false),
            ];
        }

        return $links;
    }

    private function create(Event $event, ?EventOccurrence $occurrence, string $channel): EventShareLink
    {
        // The non-null key also serializes concurrent requests for series links.
        for ($attempt = 0; $attempt < 5; $attempt++) {
            try {
                return EventShareLink::query()->createOrFirst([
                    'target_key' => $event->id.':'.($occurrence->id ?? 0).':'.$channel,
                ], [
                    'code' => Str::random(7), 'event_id' => $event->id,
                    'occurrence_id' => $occurrence?->id, 'channel' => $channel,
                ]);
            } catch (UniqueConstraintViolationException $exception) {
                if ($attempt === 4) {
                    throw $exception;
                }
            }
        }
        throw new \LogicException('Unreachable');
    }

    public function resolve(string $code): EventShareLink
    {
        $link = EventShareLink::query()->with(['event.city', 'occurrence'])->where('code', $code)->firstOrFail();
        $event = $link->event;
        abort_unless($event !== null && $event->city->is_active
            && Event::query()->readable()->whereKey($event->id)->exists(), 404);
        if ($link->occurrence_id !== null) {
            $visible = EventOccurrenceQuery::for($event->city)->forEvent($event)->forOccurrence($link->occurrence_id)->upcoming()->get()->contains('id', $link->occurrence_id)
                || EventOccurrenceQuery::archiveFor($event->city)->forEvent($event)->forOccurrence($link->occurrence_id)->past()->get()->contains('id', $link->occurrence_id);
            abort_unless($visible, 404);
        }

        return $link;
    }

    public function destination(EventShareLink $link): string
    {
        $event = $link->event;
        abort_if($event === null, 404);
        $prefix = City::query()->active()->orderBy('id')->value('id') === $event->city_id ? '' : 'city.';
        $parameters = ['slug' => $event->slug];
        if ($prefix !== '') {
            $parameters['city'] = $event->city->slug;
        }
        if ($link->occurrence !== null) {
            $parameters['occurrence'] = $link->occurrence->url_number;
        }

        // Relative, generated routes only: no caller-controlled redirect target.
        return route($prefix.($link->occurrence !== null ? 'events.occurrence' : 'events.show'), $parameters, false);
    }

    public function record(EventShareLink $link, bool $share): void
    {
        $event = $link->event;
        abort_if($event === null, 404);
        $column = $share ? 'shares' : 'clicks';
        DB::transaction(function () use ($link, $event, $column, $share): void {
            DB::table('event_share_daily')->upsert([[
                'share_link_id' => $link->id,
                'date' => CarbonImmutable::now($event->city->timezone)->toDateString(),
                $column => 1,
            ]], ['share_link_id', 'date'], [$column => DB::raw($column.' + 1')]);
            if ($share) {
                app(RecordContentMetric::class)->record('event', $event->id, ContentMetric::Shares, $link->occurrence_id);
            }
        });
    }
}

<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Me;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Web\Concerns\InteractsWithCity;
use App\Http\Requests\Api\V1\Me\NativeCalendarRequest;
use App\Models\EventOccurrence;
use App\Queries\EventOccurrenceQuery;
use App\Services\Calendar\OccurrenceCalendar;
use App\Services\Feeds\EventFeed;
use App\Support\Api\ApiResponse;
use App\Support\EventUrl;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;

final class NativeCalendarController extends Controller
{
    use InteractsWithCity;

    public function __invoke(NativeCalendarRequest $request, EventFeed $feed, OccurrenceCalendar $calendar): JsonResponse
    {
        $city = $this->city();
        $items = $request->boolean('saved_only')
            ? EventOccurrenceQuery::for($city)->savedBy($request->user())->calendarActive()->nextDays($request->integer('days', 30))->get()
            : $feed->occurrences($city, $request->filters(), days: $request->integer('days', 30), activeOnly: true);
        $events = $items->map(function (EventOccurrence $item) use ($city): array {
            $start = CarbonImmutable::instance($item->starts_at);
            $end = CarbonImmutable::instance($item->effective_ends_at);
            if ($item->is_all_day) {
                $start = CarbonImmutable::parse($start->setTimezone($city->timezone)->format('Y-m-d'), 'UTC');
                $end = CarbonImmutable::parse($end->subSecond()->setTimezone($city->timezone)->format('Y-m-d'), 'UTC')->addDay();
            }

            return [
                'id' => (int) $item->id,
                'title' => $item->event->title,
                'description' => (string) $item->event->short_description,
                'location' => $item->locationLabel(),
                'start' => $start->getTimestamp() * 1000,
                'end' => $end->getTimestamp() * 1000,
                'allDay' => (bool) $item->is_all_day,
                'timezone' => $item->is_all_day ? 'UTC' : $city->timezone,
                'status' => $item->status->value,
                'url' => EventUrl::occurrence($item),
            ];
        })->all();

        return ApiResponse::item([
            'events' => $events,
            'ics' => $calendar->feed($items, $feed->name($city, $request->filters())),
        ])->withHeaders(['Cache-Control' => 'private, no-store', 'X-Robots-Tag' => 'noindex']);
    }
}

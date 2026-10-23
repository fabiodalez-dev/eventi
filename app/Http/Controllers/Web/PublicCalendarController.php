<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\DTOs\EventFilters;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Web\Concerns\InteractsWithCity;
use App\Models\Category;
use App\Models\EventOccurrence;
use App\Models\Organizer;
use App\Models\Venue;
use App\Queries\EventOccurrenceQuery;
use App\Services\Calendar\OccurrenceCalendar;
use App\Services\Feeds\EventFeed;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Response;

/**
 * I calendari pubblici, senza account: quello di un locale, di un
 * organizzatore, di una categoria.
 *
 * `/eventi.ics` con i filtri liberi resta dietro l'accesso, perché accetta
 * qualunque combinazione e qualcuno deve risponderne. Questi tre no: hanno
 * un soggetto preciso e pubblico, un orizzonte fisso e si possono mettere
 * in cache. Sono ciò che un circolo incorpora nel proprio sito e ciò a cui
 * una persona si abbona dal telefono senza registrarsi.
 */
final class PublicCalendarController extends Controller
{
    use InteractsWithCity;

    public function __construct(
        private readonly EventFeed $feed,
        private readonly OccurrenceCalendar $calendar,
    ) {}

    public function venue(string $slug): Response
    {
        $city = $this->city();
        $venue = Venue::query()->approved()->where('slug', $slug)->firstOrFail();
        $filters = new EventFilters(venue: $venue->slug);

        return $this->respond($this->feed->occurrences($city, $filters, days: $this->days()), $venue->name, $venue->slug, $this->feed->name($city, $filters));
    }

    public function organizer(string $slug): Response
    {
        $city = $this->city();
        $organizer = Organizer::query()->where('is_active', true)->where('slug', $slug)->firstOrFail();
        /** @var Collection<int, EventOccurrence> $occurrences */
        $occurrences = EventOccurrenceQuery::for($city)->excludingDemo()->upcoming()->byOrganizer($organizer)
            ->nextDays($this->days())->get()->take(config()->integer('feeds.max_items'))->values();
        $occurrences->load(['venue', 'event.venue', 'event.category', 'event.city']);

        return $this->respond($occurrences, $organizer->name, $organizer->slug, $organizer->name);
    }

    public function category(string $slug): Response
    {
        $city = $this->city();
        $category = Category::query()->active()->where('slug', $slug)->firstOrFail();
        $filters = new EventFilters(categories: [$category->slug]);

        return $this->respond($this->feed->occurrences($city, $filters, days: $this->days()), $category->name, $category->slug, $this->feed->name($city, $filters));
    }

    /** @param Collection<int, EventOccurrence> $occurrences */
    private function respond(Collection $occurrences, string $name, string $slug, string $description): Response
    {
        $body = $this->calendar->feed($occurrences, $name, __('feeds.calendar.description', ['name' => $description, 'app' => config()->string('app.name')]));

        return response($body, 200, [
            'Content-Type' => 'text/calendar; charset=utf-8',
            'Content-Disposition' => 'inline; filename="'.$slug.'.ics"',
            'Cache-Control' => 'public, max-age=900',
        ]);
    }

    private function days(): int
    {
        return config()->integer('feeds.public_days_ahead', 90);
    }
}

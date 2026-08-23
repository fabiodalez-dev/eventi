<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\DTOs\PageMeta;
use App\Enums\EventStatus;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Web\Concerns\InteractsWithCity;
use App\Models\City;
use App\Models\Event;
use App\Models\EventOccurrence;
use App\Queries\EventOccurrenceQuery;
use App\Services\Calendar\OccurrenceCalendar;
use App\Services\Seo\StructuredData;
use App\Support\Poster;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Response;
use Illuminate\Support\Str;

/**
 * La scheda di un evento (§11.5).
 *
 * Un evento **non è una data**: è una scheda editoriale che può avere dieci
 * date. La pagina le elenca tutte, e ogni data porta il proprio pulsante per
 * il calendario e il proprio nodo JSON-LD.
 */
final class EventController extends Controller
{
    use InteractsWithCity;

    public function __construct(
        private readonly StructuredData $structuredData,
        private readonly OccurrenceCalendar $calendar,
    ) {}

    public function show(string $slug): View
    {
        $city = $this->city();
        $event = $this->findPublished($city, $slug);

        $upcoming = $this->hydrate(EventOccurrenceQuery::for($city)->forEvent($event)->upcoming()->get());

        /*
         * Un evento le cui date sono tutte passate resta una pagina legittima —
         * ci arrivano i motori di ricerca e i link condivisi mesi prima — e
         * mostra il proprio archivio invece di una scheda vuota.
         */
        $past = $upcoming->isEmpty()
            ? $this->hydrate(EventOccurrenceQuery::for($city)->forEvent($event)->past()->orderByNewestFirst()->get()->take(3))
            : new Collection;

        $related = $this->related($city, $event);
        $atVenue = $this->atSameVenue($city, $event);

        return view('events.show', [
            'city' => $city,
            'event' => $event,
            'occurrences' => $upcoming,
            'pastOccurrences' => $past,
            'related' => $related,
            'atVenue' => $atVenue,
            'meta' => $this->meta($event, $upcoming),
            'calendar' => $this->calendar,
            'structuredData' => [
                ...$this->structuredData->events($event, $upcoming->isNotEmpty() ? $upcoming : $past),
                $this->structuredData->breadcrumbs([
                    ['name' => __('ui.nav.home'), 'url' => url('/')],
                    ['name' => __('events.title'), 'url' => route('events.index')],
                    ['name' => $event->title, 'url' => route('events.show', $event)],
                ]),
            ],
        ]);
    }

    /**
     * Il file `.ics` di **una** data: chi salva un appuntamento nel proprio
     * calendario salva quella sera, non l'intera rassegna.
     */
    public function calendar(string $slug, EventOccurrence $occurrence): Response
    {
        $city = $this->city();
        $event = $this->findPublished($city, $slug);

        abort_unless((int) $occurrence->event_id === (int) $event->getKey(), 404);

        $occurrence->setRelation('event', $event);

        return response($this->calendar->ics($occurrence), 200, [
            'Content-Type' => 'text/calendar; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="'.$this->calendar->filename($occurrence).'"',
        ]);
    }

    private function findPublished(City $city, string $slug): Event
    {
        $event = Event::query()
            ->with(['venue', 'category', 'tags', 'city', 'media'])
            ->inCity($city)
            ->published()
            ->where('slug', $slug)
            ->first();

        abort_if($event === null, 404);

        return $event;
    }

    /**
     * @param  Collection<int, EventOccurrence>  $occurrences
     */
    private function meta(Event $event, Collection $occurrences): PageMeta
    {
        $description = $event->short_description
            ?? Str::of((string) $event->description)->stripTags()->squish()->limit(180)->value();

        return new PageMeta(
            title: $event->title,
            heading: $event->title,
            description: $description === '' ? null : $description,
            canonical: route('events.show', $event),
            image: Poster::absoluteUrl($event),
            indexable: $event->status === EventStatus::Published && $occurrences->isNotEmpty(),
        );
    }

    /**
     * Eventi simili: stessa categoria, prossime date, questo escluso.
     *
     * @return Collection<int, EventOccurrence>
     */
    private function related(City $city, Event $event): Collection
    {
        if ($event->category === null) {
            return new Collection;
        }

        return $this->hydrate(
            EventOccurrenceQuery::for($city)
                ->upcoming()
                ->inCategories([$event->category])
                ->excludingEvent($event)
                ->orderByRelevance()
                ->get()
                ->unique('event_id')
                ->take(config()->integer('eventi.related_size'))
                ->values(),
        );
    }

    /**
     * @return Collection<int, EventOccurrence>
     */
    private function atSameVenue(City $city, Event $event): Collection
    {
        $venue = $event->venue;

        if ($venue === null) {
            return new Collection;
        }

        return $this->hydrate(
            EventOccurrenceQuery::for($city)
                ->upcoming()
                ->atVenue($venue)
                ->excludingEvent($event)
                ->get()
                ->unique('event_id')
                ->take(config()->integer('eventi.related_size'))
                ->values(),
        );
    }

    /**
     * @param  Collection<int, EventOccurrence>  $occurrences
     * @return Collection<int, EventOccurrence>
     */
    private function hydrate(Collection $occurrences): Collection
    {
        return $occurrences->load(['event.venue', 'event.category', 'event.media', 'lineups']);
    }
}

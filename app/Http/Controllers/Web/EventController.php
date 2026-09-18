<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\DTOs\PageMeta;
use App\Enums\EventStatus;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Web\Concerns\InteractsWithCity;
use App\Http\Requests\Comments\EventCommentPageRequest;
use App\Models\Booking;
use App\Models\City;
use App\Models\Event;
use App\Models\EventOccurrence;
use App\Queries\EventCommentQuery;
use App\Queries\EventOccurrenceQuery;
use App\Services\Analytics\EventShares;
use App\Services\Calendar\OccurrenceCalendar;
use App\Services\Events\EventPoster;
use App\Services\Seo\EditorialContent;
use App\Services\Seo\StructuredData;
use App\Support\CurrentCity;
use App\Support\EventUrl;
use App\Support\Poster;
use App\Support\Seo\PageTitle;
use App\Support\TicketTiers;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
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

    public function show(EventCommentPageRequest $request, string $slug): View
    {
        $city = $this->city();
        $event = $this->findReadable($city, $slug);

        return $this->renderEvent($city, $event);
    }

    public function preview(Event $event): Response
    {
        Gate::authorize('update', $event);
        $event->load(['venue', 'category', 'tags', 'city', 'media', 'ticketTiers']);
        app(CurrentCity::class)->set($event->city);

        return response($this->renderEvent($event->city, $event, true))
            ->header('Cache-Control', 'private, no-store')->header('X-Robots-Tag', 'noindex, nofollow');
    }

    public function date(EventCommentPageRequest $request, string $slug, string $occurrence): View
    {
        $city = $this->city();
        $event = $this->findReadable($city, $slug);
        $date = $event->occurrences()->where('url_number', $occurrence)->firstOrFail();
        $date->setRelation('event', $event);

        return $this->renderEvent($city, $event, false, $date);
    }

    private function renderEvent(City $city, Event $event, bool $isPreview = false, ?EventOccurrence $selected = null): View
    {

        $upcoming = $this->hydrate(($isPreview ? EventOccurrenceQuery::managementFor($event) : EventOccurrenceQuery::for($city)->forEvent($event))->upcoming()->get());

        /*
         * Un evento le cui date sono tutte passate resta una pagina legittima —
         * ci arrivano i motori di ricerca e i link condivisi mesi prima — e
         * mostra il proprio archivio invece di una scheda vuota.
         */
        $past = $upcoming->isEmpty()
            ? $this->hydrate(EventOccurrenceQuery::archiveFor($city)->forEvent($event)->past()->orderByNewestFirst()->get()->take(3))
            : new Collection;

        $related = $this->related($city, $event);
        if ($selected !== null) {
            $past = $this->hydrate(EventOccurrenceQuery::archiveFor($city)->forEvent($event)->past()->get());
            $upcoming = $upcoming->where('id', $selected->id)->values();
            $past = $past->where('id', $selected->id)->values();
            abort_if($upcoming->isEmpty() && $past->isEmpty(), 404);
        }
        $dates = $upcoming->isNotEmpty() ? $upcoming : $past;
        $isSeries = $event->occurrences()->count() > 1 || $event->recurrences()->exists();
        $canonicalDate = $selected ?? (! $isSeries ? $dates->first() : null);
        $meta = $this->meta($event, $dates)->withIndexable(! $isPreview && in_array($event->status, [EventStatus::Published, EventStatus::Archived], true));
        if ($canonicalDate !== null) {
            $meta = $meta->withCanonical(EventUrl::occurrence($canonicalDate));
        }
        if ($selected !== null) {
            /*
             * La data entra prima del passaggio editoriale, non dopo: un
             * titolo scritto a mano in `seo.title` deve arrivare intero alla
             * pagina, non con una data appesa in coda che lo porta oltre la
             * misura che un motore mostra.
             */
            $meta = $meta->withTitle(PageTitle::forEvent(
                $event->title,
                $city->name,
                $selected->starts_at->copy()->timezone($city->timezone)->format('d/m/Y'),
            ));
        }
        $meta = app(EditorialContent::class)->meta($event, $meta);
        $schema = $selected === null && $isSeries
            ? [$this->structuredData->collection($event->title, route('events.show', $event), $dates->map(fn ($date): array => [
                'name' => $event->title.' · '.$date->business_date->format('d/m/Y'),
                'url' => EventUrl::occurrence($date),
            ])->all())]
            : $this->structuredData->events($event, $dates);
        if ($selected !== null && isset($schema[0])) {
            $schema[0]['url'] = $meta->canonical;
            $schema[0]['@id'] = $meta->canonical.'#event';
        }
        $atVenue = $this->atSameVenue($city, $event);

        request()->attributes->set('sponsorship_exclude_event', $event->slug);

        /*
         * L'anteprima non mostra i commenti e non valida i parametri di
         * pagina: interrogarli servirebbe solo a trasformare un `?commento=`
         * storto in un 404 dell'anteprima. Sulla scheda pubblica il commento
         * che non c'è più lascia al suo posto un avviso, non un 404.
         */
        $commentListing = $isPreview ? [
            'comments' => new Collection,
            'commentsPage' => 1,
            'commentsLastPage' => 1,
            'commentsTotal' => 0,
            'commentThread' => null,
            'repliesPage' => 1,
            'repliesLastPage' => 1,
            'commentMissing' => false,
        ] : app(EventCommentQuery::class)->listing(
            $event, auth()->user(), request()->integer('commenti', 1),
            request()->filled('commento') ? request()->integer('commento') : null,
            request()->filled('risposte') ? request()->integer('risposte') : null,
            strict: false,
        );

        return view('events.show', [
            ...$commentListing,
            'shareLinks' => $isPreview ? [] : app(EventShares::class)->links($event, $canonicalDate),
            'isPreview' => $isPreview,
            'selectedOccurrence' => $canonicalDate,
            'city' => $city,
            'event' => $event,
            'occurrences' => $upcoming,
            'activeBookings' => auth()->check()
                ? Booking::query()->active()->where('user_id', auth()->id())->whereIn('occurrence_id', $upcoming->modelKeys())->get()->keyBy('occurrence_id')
                : collect(),
            'pastOccurrences' => $past,
            'related' => $related,
            'atVenue' => $atVenue,
            'meta' => $meta,
            'calendar' => $this->calendar,
            /* Il listino dell'evento. Quello di una singola data — quando
               esiste — lo risolve la vista chiedendolo per quell'occorrenza,
               sempre attraverso `App\Support\TicketTiers`. */
            'tiers' => TicketTiers::for($event),
            'structuredData' => $isPreview ? [] : [
                ...$schema,
                $this->structuredData->breadcrumbs([
                    ['name' => __('ui.nav.home'), 'url' => url('/')],
                    ['name' => __('events.title'), 'url' => route('events.index')],
                    ...($isSeries ? [['name' => $event->title, 'url' => route('events.show', $event)]] : []),
                    ...($canonicalDate !== null ? [['name' => $event->title.' · '.$canonicalDate->starts_at->copy()->timezone($city->timezone)->format('d/m/Y'), 'url' => $meta->canonical]] : []),
                ]),
            ],
        ]);
    }

    /**
     * Il file `.ics` di **una** data: chi salva un appuntamento nel proprio
     * calendario salva quella sera, non l'intera rassegna.
     */
    public function calendar(string $slug, string $occurrence): Response
    {
        $city = $this->city();
        $event = $this->findReadable($city, $slug);

        $occurrence = $event->occurrences()->where('url_number', $occurrence)->firstOrFail();

        $occurrence->setRelation('event', $event);

        return response($this->calendar->ics($occurrence), 200, [
            'Content-Type' => 'text/calendar; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="'.$this->calendar->filename($occurrence).'"',
        ]);
    }

    /**
     * La locandina A4 di una data, col QR che riporta a questa scheda.
     *
     * **Pubblica come l'ICS, e per la stessa ragione.** Chiunque può volerla
     * stampare — il locale per la vetrina, un cliente per la bacheca del
     * condominio, un'associazione per il proprio circolo — e chiuderla dietro
     * un accesso significherebbe che il volantino lo rifà ognuno a modo suo,
     * col rischio che l'orario sul muro diverga da quello sul sito.
     */
    public function poster(string $slug, string $occurrence, EventPoster $poster): Response
    {
        $city = $this->city();
        $event = $this->findReadable($city, $slug);

        /* Come per l'ICS: la data deve appartenere a questo evento, altrimenti
           si potrebbe comporre la locandina di un evento accostando lo slug di
           uno e il numero di una data di un altro. */
        $occurrence = $event->occurrences()->where('url_number', $occurrence)->firstOrFail();

        $occurrence->setRelation('event', $event);

        return response($poster->pdf($occurrence), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$poster->filename($occurrence).'"',
        ]);
    }

    /**
     * Pubblicati **e archiviati** (§14.5): l'archiviazione toglie dalle liste,
     * non dal sito. Un indirizzo che ha ricevuto visite per mesi non deve
     * diventare un 404 il giorno in cui un comando notturno lo tocca.
     */
    private function findReadable(City $city, string $slug): Event
    {
        $event = Event::query()
            ->with(['venue', 'category', 'tags', 'city', 'media', 'ticketTiers'])
            ->inCity($city)
            ->readable()
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

        $social = Poster::social($event);

        return new PageMeta(
            title: PageTitle::forEvent($event->title, $event->city->name),
            heading: $event->title,
            description: $description === '' ? null : $description,
            canonical: route('events.show', $event),
            image: $social?->url,
            indexable: $event->status === EventStatus::Published && $occurrences->isNotEmpty(),
            imageWidth: $social?->width,
            imageHeight: $social?->height,
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
        return $occurrences->load(['event.venue', 'event.category', 'event.media', 'lineups', 'ticketTiers']);
    }
}

<?php

declare(strict_types=1);

namespace App\Services\Management;

use App\Enums\EventStatus;
use App\Enums\OccurrenceStatus;
use App\Enums\VenueStatus;
use App\Http\Resources\V1\PosterResource;
use App\Models\Booking;
use App\Models\City;
use App\Models\Event;
use App\Models\EventOccurrence;
use App\Models\EventViewDaily;
use App\Models\Organizer;
use App\Models\Venue;
use App\Queries\EventOccurrenceQuery;
use App\Queries\VenueDashboardQuery;
use App\Services\Analytics\ManagementAnalytics;
use App\Support\Management\ManagementSubject;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Collection as BaseCollection;
use Illuminate\Support\Facades\Gate;

/**
 * Il riepilogo di un locale o di un'organizzazione (W-DB-01…08): i riquadri
 * che stanno sopra il rapporto nella prima pagina dei due pannelli.
 *
 * Le domande le facevano i widget del pannello (`app/Filament/Venue/Widgets`
 * e `Widgets/Support/ManagementContext`); adesso stanno qui, e le chiedono
 * sia i widget sia l'API dell'app (`GET S/dashboard`), così «oggi», «prossimi»
 * e «bozze» hanno una risposta sola.
 *
 * Due regole del pannello che conservo così come sono, anche se diverse
 * dall'elenco degli eventi:
 *
 * - le date (oggi, prossimi) dell'organizzazione comprendono gli eventi
 *   co-organizzati (`EventOccurrenceQuery::byOrganizer`), mentre le bozze e
 *   le ultime visite contano solo quelli con il suo `organizer_id`;
 * - «oggi» lo decide la città del locale; per l'organizzazione quella del
 *   suo evento più recente, altrimenti la prima del catalogo.
 */
final class DashboardSummary
{
    /** «Prossimi eventi»: cinque, uno per evento, oggi escluso. */
    public const UPCOMING_LIMIT = 5;

    /** «Ultime visite»: le tre schede più viste negli ultimi sette giorni. */
    public const RECENT_DAYS = 7;

    public const RECENT_LIMIT = 3;

    /** @var array<string, bool> `BookingPolicy::manage` per organizzatore e locale, ricordata per il riepilogo. */
    private array $bookingAnswers = [];

    /**
     * La città che decide che cos'è «oggi». L'organizzazione non ha una città
     * propria: vale quella della sede principale dei suoi eventi, altrimenti
     * la prima del catalogo.
     */
    public function city(Venue|Organizer $subject): ?City
    {
        if ($subject instanceof Venue) {
            return $subject->city ?? City::query()->find($subject->city_id);
        }

        $cityId = $this->events($subject)->reorder()->orderByDesc('id')->value('city_id');

        return City::query()->find($cityId) ?? City::query()->orderBy('id')->first();
    }

    /**
     * Le date pubblicate del locale o dell'organizzazione, con lo stesso
     * motore temporale del sito: «oggi» e «prossimi» hanno una definizione sola.
     * Ogni chiamata è una query nuova: il motore si modifica sul posto.
     */
    public function occurrences(Venue|Organizer $subject, ?City $city = null): ?EventOccurrenceQuery
    {
        $city ??= $this->city($subject);

        if ($city === null) {
            return null;
        }

        $query = EventOccurrenceQuery::for($city);

        return $subject instanceof Venue ? $query->atVenue($subject) : $query->byOrganizer($subject);
    }

    /**
     * Gli eventi del riepilogo, bozze comprese: quelli del locale, o quelli
     * con l'organizzazione come organizzatore principale.
     *
     * @return Builder<Event>
     */
    public function events(Venue|Organizer $subject): Builder
    {
        $column = $subject instanceof Venue ? 'venue_id' : 'organizer_id';

        return Event::query()->where($column, $subject->getKey());
    }

    /**
     * «Non siete ancora sulla mappa» (W-DB-02): il punto del locale coincide
     * con il centro della città alla precisione con cui la colonna salva.
     * Chi ha spostato il segnaposto anche di pochi metri non coincide più.
     */
    public function missingLocation(Venue $venue): bool
    {
        $city = $venue->city ?? City::query()->find($venue->city_id);

        if ($city === null) {
            return false;
        }

        return (float) $venue->lat === (float) $city->center_lat
            && (float) $venue->lng === (float) $city->center_lng;
    }

    /**
     * «Il tuo cartellone» (W-DB-03): date di oggi, date da oggi in avanti,
     * eventi in bozza.
     *
     * @return array{today: int, upcoming: int, drafts: int}
     */
    public function counts(Venue|Organizer $subject): array
    {
        $city = $this->city($subject);

        return [
            'today' => $this->occurrences($subject, $city)?->today()->count() ?? 0,
            'upcoming' => $this->occurrences($subject, $city)?->upcoming()->count() ?? 0,
            'drafts' => $this->events($subject)->where('status', EventStatus::Draft)->count(),
        ];
    }

    /**
     * «Oggi» (W-DB-04): le serate di oggi con l'evento, la locandina e il luogo.
     *
     * @return Collection<int, EventOccurrence>
     */
    public function today(Venue|Organizer $subject): Collection
    {
        $dates = $this->occurrences($subject)?->today()->get() ?? new Collection;
        $dates->load(['event.media', 'event.venue', 'venue']);

        return $dates->filter(fn (EventOccurrence $date): bool => $date->event !== null)->values();
    }

    /**
     * «Prossimi eventi» (W-DB-05): uno per evento, oggi escluso (ha il suo
     * riquadro). Se ne chiedono il doppio perché quelli di oggi si scartano
     * dopo.
     *
     * @return Collection<int, EventOccurrence>
     */
    public function upcoming(Venue|Organizer $subject): Collection
    {
        $occurrences = $this->occurrences($subject);
        $today = $occurrences?->currentBusinessDate();
        $dates = $occurrences?->upcoming()->onePerEvent()->take(self::UPCOMING_LIMIT * 2) ?? new Collection;
        $dates->load(['event.media', 'event.venue', 'venue']);

        return $dates
            ->filter(fn (EventOccurrence $date): bool => $date->event !== null && $date->business_date->format('Y-m-d') !== $today)
            ->take(self::UPCOMING_LIMIT)
            ->values();
    }

    /**
     * «Ultime visite» (W-DB-06): le visite aggregate per giorno
     * (`event_views_daily`, contate con il consenso come nel resto delle
     * statistiche) degli ultimi sette giorni nel fuso della città.
     *
     * @return BaseCollection<int, object{event_id: int, views: int|string}>
     */
    public function recentViews(Venue|Organizer $subject): BaseCollection
    {
        $timezone = $this->city($subject)->timezone ?? (string) config('app.timezone');
        $from = CarbonImmutable::now($timezone)->subDays(self::RECENT_DAYS - 1)->toDateString();

        /** @var BaseCollection<int, object{event_id: int, views: int|string}> $rows */
        $rows = EventViewDaily::query()
            ->whereIn('event_id', $this->events($subject)->select('id'))
            ->where('date', '>=', $from)
            ->groupBy('event_id')
            ->selectRaw('event_id, SUM(views) as views')
            ->havingRaw('SUM(views) > 0')
            ->orderByDesc('views')
            ->limit(self::RECENT_LIMIT)
            ->toBase()
            ->get();

        return $rows;
    }

    /**
     * Il riepilogo per l'app (`GET S/dashboard`): gli stessi riquadri, nello
     * stesso ordine, con i collegamenti che il pannello mette su ogni card
     * detti come permessi. Le scorciatoie «Vai a» l'app le compone da
     * `permissions`; il rapporto sotto è `GET S/analytics/summary` (P5).
     *
     * @return array<string, mixed>
     */
    public function forApp(ManagementSubject $subject): array
    {
        $model = $subject->model;
        $city = $this->city($model);
        $timezone = $city->timezone ?? (string) config('app.timezone');
        $venue = $subject->venue();
        $card = fn (Event $event, ?EventOccurrence $date, ?int $views = null): array => $this->card($subject, $event, $date, $views);

        $views = $this->recentViews($model);
        $viewed = Event::query()->whereKey($views->pluck('event_id'))->with(['media', 'venue'])->get()->keyBy('id');

        return [
            'subject' => ['type' => $subject->type(), 'id' => (int) $model->getKey(), 'name' => $model->name],
            'timezone' => $timezone,
            'missing_location' => $venue !== null && $this->missingLocation($venue),
            'counts' => $this->counts($model),
            'today' => $this->today($model)->map(fn (EventOccurrence $date): array => $card($date->event, $date))->values()->all(),
            'upcoming' => $this->upcoming($model)->map(fn (EventOccurrence $date): array => $card($date->event, $date))->values()->all(),
            'recent_views' => [
                'days' => self::RECENT_DAYS,
                'items' => $views
                    ->filter(fn (object $row): bool => $viewed->has($row->event_id))
                    ->map(fn (object $row): array => $card($viewed[$row->event_id], null, (int) $row->views))
                    ->values()
                    ->all(),
            ],
            'programme' => $venue === null ? null : $this->programme($venue, $timezone),
        ];
    }

    /**
     * Una card dei riquadri: l'evento con la locandina, la data se c'è (ora
     * in UTC, il luogo, lo stato se non è «in programma») e i collegamenti
     * che il pannello offre su quella card, con le stesse condizioni di
     * `EventResource::eventLinks`.
     *
     * @return array<string, mixed>
     */
    private function card(ManagementSubject $subject, Event $event, ?EventOccurrence $date, ?int $views): array
    {
        $date?->setRelation('event', $event);
        $published = $event->status === EventStatus::Published;
        $gate = Gate::forUser($subject->user);
        $tickets = $this->ticketDate($subject, $event);

        return [
            'event' => [
                'id' => (int) $event->getKey(),
                'slug' => $event->slug,
                'title' => $event->title,
                'status' => ['value' => $event->status->value, 'label' => $event->status->label()],
                'poster' => PosterResource::toArray($event),
            ],
            'date' => $date === null ? null : [
                'id' => (int) $date->getKey(),
                'starts_at' => $date->starts_at->copy()->utc()->toIso8601String(),
                'place' => $date->locationLabel(),
                'status' => ['value' => $date->status->value, 'label' => $date->status->label()],
                'scheduled' => $date->status === OccurrenceStatus::Scheduled,
            ],
            'views' => $views,
            'links' => [
                'edit' => $gate->allows('update', $event),
                'tickets_occurrence_id' => $tickets === null ? null : (int) $tickets->getKey(),
                // Le statistiche dell'evento sono del locale o dell'organizzatore principale (P5).
                'statistics' => ManagementAnalytics::covers($subject->model, $event),
                // Le grafiche social esistono solo nel pannello del locale.
                'social' => $subject->isVenue(),
                'sponsor' => $published,
            ],
        ];
    }

    /**
     * La prossima data dell'evento, se chi guarda ne gestisce le prenotazioni
     * (`BookingPolicy::manage`), come `EventResource::ticketDate`. La risposta
     * dipende solo dall'organizzatore e dal locale effettivo della data: la
     * ricordo per la durata del riepilogo.
     */
    private function ticketDate(ManagementSubject $subject, Event $event): ?EventOccurrence
    {
        $city = $event->relationLoaded('city') ? $event->city : City::query()->find($event->city_id);
        $next = $city === null ? null : VenueDashboardQuery::nextOccurrence($event, $city);

        if (! $next instanceof EventOccurrence) {
            return null;
        }

        $next->setRelation('event', $event);
        $key = ($event->organizer_id ?? '-').':'.($next->effectiveVenue()?->getKey() ?? '-');
        $this->bookingAnswers[$key] ??= Gate::forUser($subject->user)->allows('manage', [Booking::class, $next]);

        return $this->bookingAnswers[$key] ? $next : null;
    }

    /**
     * I due pulsanti del programma in PDF (W-DB-08, W-PB-02): il lunedì di
     * questa settimana e quello della prossima, nel fuso del locale. Il PDF
     * esiste solo per un locale approvato, come la pagina pubblica.
     *
     * @return array{available: bool, this_week: string, next_week: string}
     */
    private function programme(Venue $venue, string $timezone): array
    {
        $monday = CarbonImmutable::now($timezone)->startOfWeek(CarbonImmutable::MONDAY);

        return [
            'available' => $venue->status === VenueStatus::Approved,
            'this_week' => $monday->toDateString(),
            'next_week' => $monday->addWeek()->toDateString(),
        ];
    }
}

<?php

declare(strict_types=1);

namespace App\Services\Management;

use App\Enums\EventStatus;
use App\Enums\OccurrenceStatus;
use App\Http\Resources\V1\PosterResource;
use App\Models\Booking;
use App\Models\Category;
use App\Models\City;
use App\Models\Event;
use App\Models\EventOccurrence;
use App\Models\Organizer;
use App\Models\Venue;
use App\Queries\EventOccurrenceQuery;
use App\Queries\VenueDashboardQuery;
use App\Services\Events\EventSubmission;
use App\Support\BeforeGoingDefaults;
use App\Support\Description;
use App\Support\Management\ManagementSubject;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;

/**
 * Gli eventi di un locale o di un'organizzazione come li legge l'app di
 * gestione: l'elenco a card con le schede Tutti, Prossimi, Passati e Bozze,
 * e la scheda completa con i campi del modulo e le azioni possibili.
 *
 * Le regole sono quelle dell'elenco dei pannelli (`EventResource::listTable`
 * e `ListEvents::timingTabs`): prossima data o ultima con «Passato», prima
 * gli eventi in arrivo in ordine di data, poi i passati dal più recente.
 */
final class ManagedEvents
{
    public const PER_PAGE = 20;

    public function __construct(private readonly EventSubmission $submission) {}

    /** La città con cui calcolare «oggi»: quella del soggetto, come nei pannelli. */
    public static function city(Venue|Organizer $subject): City
    {
        return $subject->city ?? City::query()->firstOrFail();
    }

    /**
     * @return LengthAwarePaginator<int, Event>
     */
    public function page(ManagementSubject $subject, ?string $tab, ?string $status, ?string $search, int $page): LengthAwarePaginator
    {
        $city = self::city($subject->model);
        $query = ManagedContent::for($subject->model)->events();
        VenueDashboardQuery::applyNextOccurrence($query, $city);
        VenueDashboardQuery::applyLastOccurrence($query);
        VenueDashboardQuery::applyTiming($query, $city, $tab === 'all' ? null : $tab);
        if ($status !== null) {
            $query->where('events.status', $status);
        }
        if ($search !== null && trim($search) !== '') {
            $query->where('events.title', 'like', '%'.addcslashes(trim($search), '%_\\').'%');
        }

        return $query->with(['media', 'venue'])
            ->orderByRaw('next_starts_at is null')
            ->orderBy('next_starts_at')
            ->orderByDesc('last_starts_at')
            ->orderByDesc('events.id')
            ->paginate(self::PER_PAGE, ['events.*'], 'page', max(1, $page));
    }

    /**
     * La card dell'elenco: locandina, titolo, prossima o ultima data, stato,
     * provenienza, locale.
     *
     * @return array<string, mixed>
     */
    public function card(Event $event): array
    {
        $next = $event->getAttribute('next_starts_at');
        $last = $event->getAttribute('last_starts_at');

        return [
            'id' => (int) $event->getKey(),
            'slug' => $event->slug,
            'title' => $event->title,
            'status' => $this->status($event->status),
            'source' => ['value' => $event->source->value, 'label' => $event->source->label(), 'imported' => $event->source->isImported()],
            'poster' => PosterResource::toArray($event),
            'next_starts_at' => $next === null ? null : self::iso($next),
            'last_starts_at' => $last === null ? null : self::iso($last),
            'past' => $next === null && $last !== null,
            'venue' => $event->venue === null ? null : ['id' => (int) $event->venue->getKey(), 'name' => $event->venue->name],
        ];
    }

    /**
     * La scheda: i campi del modulo (con «Prima di andare» già compilato con i
     * valori del locale, come il pannello), lo stato di moderazione, la
     * pubblicazione prevista, le serate in breve, il listino e le azioni.
     *
     * @return array<string, mixed>
     */
    public function detail(Event $event, ManagementSubject $subject): array
    {
        $event->loadMissing(['city', 'category', 'venue', 'organizer', 'tags', 'media', 'ticketTiers']);
        $timezone = $event->city->timezone;
        $dates = $event->occurrences()->with(['venue', 'lineups'])->orderBy('starts_at')->get()->each(fn (EventOccurrence $date) => $date->setRelation('event', $event));
        $today = EventOccurrenceQuery::for($event->city)->currentBusinessDate();
        [$upcoming, $past] = $dates->partition(fn (EventOccurrence $date): bool => $date->business_date->format('Y-m-d') >= $today);
        $own = is_array($event->getAttribute('content_details')) ? $event->getAttribute('content_details') : [];
        $defaults = BeforeGoingDefaults::forVenue($event->venue);

        return [
            ...$this->card($event),
            'rejection_reason' => $event->status === EventStatus::Rejected ? $event->rejection_reason : null,
            'scheduled_publish_at' => $event->scheduled_publish_at?->utc()->toIso8601String(),
            'published_at' => $event->published_at?->utc()->toIso8601String(),
            'city' => ['id' => (int) $event->city->getKey(), 'name' => $event->city->name, 'timezone' => $timezone],
            'category' => $event->category === null ? null : ['id' => (int) $event->category->getKey(), 'name' => $event->category->name],
            'organizer' => $event->organizer === null ? null : ['id' => (int) $event->organizer->getKey(), 'name' => $event->organizer->name],
            'organizer_name' => $event->organizer_name,
            'organizer_url' => $event->organizer_url,
            'price_type' => ['value' => $event->price_type->value, 'label' => $event->price_type->label()],
            'price_min' => $event->price_min === null ? null : (float) $event->price_min,
            'price_max' => $event->price_max === null ? null : (float) $event->price_max,
            'description' => $event->description,
            'description_text' => Description::plain($event->description),
            'tags' => $event->tags->map(fn ($tag): array => ['id' => (int) $tag->getKey(), 'name' => $tag->name])->values()->all(),
            'ticket_url' => $event->ticket_url,
            'booking_url' => $event->booking_url,
            'external_links' => array_values(json_decode((string) json_encode($event->external_links ?? []), true) ?: []),
            'facts' => array_values(json_decode((string) json_encode($event->facts ?? []), true) ?: []),
            'custom_location' => $event->custom_location,
            'content_details' => [
                ...$own,
                ...BeforeGoingDefaults::merge($defaults, array_intersect_key($own, array_flip([...BeforeGoingDefaults::FIELDS, 'food_notes', 'start_notes']))),
            ],
            'venue_before_going' => array_intersect_key($defaults, array_flip(BeforeGoingDefaults::FIELDS)),
            'seo' => [
                'title' => $event->seo['title'] ?? null,
                'description' => $event->seo['description'] ?? null,
                'image' => $event->seo['image'] ?? null,
            ],
            'ticket_tiers' => $event->ticketTiers->whereNull('occurrence_id')->values()->map(fn ($tier): array => [
                'id' => (int) $tier->getKey(),
                'name' => $tier->name,
                'price' => $tier->price === null ? null : (float) $tier->price,
                'status' => $tier->status->value,
                'url' => $tier->url,
                'note' => $tier->note,
            ])->all(),
            'dates' => [
                'upcoming' => $upcoming->values()->map(fn (EventOccurrence $date): array => $this->date($date, $timezone))->all(),
                'past' => $past->reverse()->values()->map(fn (EventOccurrence $date): array => $this->date($date, $timezone))->all(),
            ],
            'public_url' => $event->status === EventStatus::Published && Route::has('events.show') ? route('events.show', $event) : null,
            'abilities' => $this->abilities($event, $subject, $dates->isNotEmpty()),
            'links' => $this->links($event, $subject, $upcoming->first()),
        ];
    }

    /**
     * Che cosa può fare chi guarda, con le stesse condizioni dei pulsanti dei
     * pannelli: l'app mostra solo le azioni vere, e il server ricontrolla.
     *
     * @return array<string, bool>
     */
    public function abilities(Event $event, ManagementSubject $subject, bool $hasDates): array
    {
        $gate = Gate::forUser($subject->user);
        $update = $gate->allows('update', $event);
        $venue = $subject->isVenue();

        return [
            'update' => $update,
            'delete' => $venue && $gate->allows('delete', $event),
            'publish' => $venue && $update && $this->submission->canPublishFrom($event),
            'publish_needs_dates' => ! $hasDates,
            'publish_directly' => $venue && $gate->allows('publish', $event),
            'duplicate' => $venue && $event->venue !== null && $gate->allows('create', [Event::class, $event->venue]),
            'schedule' => $venue && $update && in_array($event->status, [EventStatus::Draft, EventStatus::Pending], true),
            'cancel_schedule' => $venue && $update && $event->scheduled_publish_at !== null,
            'submit' => $subject->isOrganizer() && $update && $this->submission->canSendToEditors($event),
            'preview' => $update,
            'ticket_tiers' => $venue && $update,
        ];
    }

    /**
     * Le pagine collegate all'evento (W-EV-05): la biglietteria della
     * prossima data se chi guarda la gestisce, la scheda pubblica se è
     * online. Statistiche, grafiche social e sponsorizzazione le apre l'app
     * quando ha le loro schermate.
     *
     * @return array<string, mixed>
     */
    private function links(Event $event, ManagementSubject $subject, ?EventOccurrence $next): array
    {
        $published = $event->status === EventStatus::Published;

        return [
            'tickets_occurrence_id' => $next !== null && Gate::forUser($subject->user)->allows('manage', [Booking::class, $next]) ? (int) $next->getKey() : null,
            'statistics' => true,
            'social' => $subject->isVenue(),
            'sponsor' => $published,
            'site' => $published,
        ];
    }

    /**
     * Una serata in breve, come nella scheda in lettura del pannello.
     *
     * @return array<string, mixed>
     */
    private function date(EventOccurrence $date, string $timezone): array
    {
        return [
            'id' => (int) $date->getKey(),
            'starts_at' => $date->starts_at->copy()->utc()->toIso8601String(),
            'ends_at' => $date->ends_at?->copy()->utc()->toIso8601String(),
            'doors_at' => $date->doors_at?->copy()->utc()->toIso8601String(),
            'status' => ['value' => $date->status->value, 'label' => $date->status->label()],
            'cancelled' => $date->status === OccurrenceStatus::Cancelled,
            'place' => $date->locationLabel(),
            'lineup' => $date->lineups->pluck('name')->filter()->values()->all(),
            'capacity' => $date->capacity,
            'capacity_left' => $date->capacity_left,
            'status_note' => $date->status_note,
            'highlight' => $date->highlight,
            'timezone' => $timezone,
        ];
    }

    /** Una data delle colonne calcolate (`next_starts_at`), scritta in UTC dal database. */
    private static function iso(mixed $value): string
    {
        return CarbonImmutable::parse((string) $value, 'UTC')->utc()->toIso8601String();
    }

    /** @return array{value: string, label: string} */
    private function status(EventStatus $status): array
    {
        return ['value' => $status->value, 'label' => $status->label()];
    }

    /**
     * Le categorie attive, nell'ordine del modulo.
     *
     * @return list<array{id: int, name: string}>
     */
    public function categories(): array
    {
        return Category::query()->where('is_active', true)->orderBy('sort_order')->orderBy('name')->get(['id', 'name'])
            ->map(fn (Category $category): array => ['id' => (int) $category->getKey(), 'name' => $category->name])->values()->all();
    }

    /**
     * Il filtro di uno stato valido, o `null`.
     */
    public static function statusFilter(?string $value): ?string
    {
        return $value !== null && EventStatus::tryFrom($value) !== null ? $value : null;
    }
}

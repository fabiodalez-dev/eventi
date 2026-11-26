<?php

declare(strict_types=1);

namespace App\Services\Editorial;

use App\Enums\EventSource;
use App\Enums\EventStatus;
use App\Enums\OccurrenceStatus;
use App\Models\City;
use App\Models\Event;
use App\Models\EventOccurrence;
use App\Models\Sponsorship;
use App\Models\User;
use App\Queries\EditorialDashboardQuery;
use App\Queries\EventOccurrenceQuery;
use App\Queries\VenueDashboardQuery;
use App\Services\Management\ManagedEvents;
use App\Support\Description;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;

/**
 * L'elenco degli eventi della redazione (R-EV-01…03): la query, le cinque
 * schede, i dodici filtri, la ricerca e l'ordinamento della tabella di
 * `EventResource`, scritti una volta sola e chiamati sia dal pannello sia
 * dall'API dell'app.
 *
 * Il pannello non filtra per città: la città di lavoro serve solo a dire
 * quando è «oggi» (prossima data, pubblicati oggi e in settimana, possibili
 * doppioni), come `EventResource::listCity()`. Per restringere a una città
 * c'è il filtro `city`, lo stesso del pannello.
 *
 * Le righe portano le `abilities` calcolate con `EventPolicy` (e con
 * `SponsorshipPolicy` per «Sponsorizza»), con le stesse condizioni di
 * visibilità dei pulsanti di `EventModeration`: l'app mostra solo le azioni
 * vere, e il server le ricontrolla una per una.
 */
final class EditorialEvents
{
    /** Le schede di `ListEvents::getTabs()`, nell'ordine del pannello (`all` è «Tutti»). */
    public const TABS = [
        'pending' => EventStatus::Pending,
        'published' => EventStatus::Published,
        'draft' => EventStatus::Draft,
        'cancelled' => EventStatus::Cancelled,
    ];

    /** I dodici filtri della tabella, con i nomi di Filament. */
    public const FILTERS = [
        'status', 'city', 'venue', 'category', 'source', 'is_featured',
        'published_today', 'published_this_week', 'missing_poster', 'incomplete', 'possible_duplicates', 'trashed',
    ];

    /** I filtri sì/no che restringono con una clausola della redazione. */
    public const FLAGS = ['published_today', 'published_this_week', 'missing_poster', 'incomplete', 'possible_duplicates'];

    /** Le colonne ordinabili della tabella; senza ordinamento vale `-created_at`, come `defaultSort`. */
    public const SORTS = ['title', 'published_at', 'created_at'];

    public function __construct(private readonly ManagedEvents $cards) {}

    /**
     * Quello che ogni riga mostra in una query sola: locandina, luogo,
     * categoria, città, le date in cartellone e la prossima e l'ultima data
     * (`next_starts_at`, `last_starts_at`) misurate sulla città di lavoro.
     *
     * @param  Builder<Event>  $query
     */
    public static function prepare(Builder $query, City $city): void
    {
        $query->with(['media', 'venue', 'category', 'city']);
        VenueDashboardQuery::applyNextOccurrence($query, $city);
        VenueDashboardQuery::applyLastOccurrence($query);
    }

    /**
     * Una scheda di `ListEvents`: `null` o `all` non restringono.
     *
     * @param  Builder<Event>  $query
     */
    public static function applyTab(Builder $query, ?string $tab): void
    {
        $status = self::TABS[$tab ?? ''] ?? null;
        if ($status !== null) {
            $query->where('status', $status);
        }
    }

    /**
     * Uno dei filtri sì/no della redazione (pubblicati oggi, in settimana,
     * senza locandina, incompleti, possibili doppioni), misurato sulla città
     * di lavoro come i riquadri del riepilogo.
     *
     * @param  Builder<Event>  $query
     */
    public static function applyFlag(Builder $query, string $flag, City $city): void
    {
        match ($flag) {
            'published_today' => EditorialDashboardQuery::for($city)->applyPublishedToday($query),
            'published_this_week' => EditorialDashboardQuery::for($city)->applyPublishedThisWeek($query),
            'missing_poster' => EditorialDashboardQuery::missingPosterScope($query),
            'incomplete' => EditorialDashboardQuery::incompleteScope($query),
            'possible_duplicates' => EditorialDashboardQuery::for($city)->applyPossibleDuplicates($query),
            default => null,
        };
    }

    /**
     * La query dell'elenco con i criteri dell'app: scheda, filtri, ricerca,
     * ordinamento. È la stessa dell'esportazione, che esporta quello che si
     * sta guardando.
     *
     * @param  array<string, mixed>  $filters
     * @return Builder<Event>
     */
    public function query(City $city, ?string $tab, array $filters, ?string $search, ?string $sort): Builder
    {
        /* Come `EventResource::getEloquentQuery()`: i cestinati li decide il filtro `trashed`. */
        $query = Event::query()->withoutGlobalScopes([SoftDeletingScope::class]);
        self::prepare($query, $city);
        $query->with('organizer')->withCount('occurrences');
        self::applyTab($query, $tab);
        $this->applyFilters($query, $filters, $city);

        $term = trim((string) $search);
        if ($term !== '') {
            $like = '%'.addcslashes($term, '%_\\').'%';
            /* Le due colonne cercabili della tabella: il titolo e il nome del locale. */
            $query->where(fn (Builder $where) => $where->where('title', 'like', $like)
                ->orWhereHas('venue', fn (Builder $venue) => $venue->where('name', 'like', $like)));
        }

        $direction = str_starts_with((string) $sort, '-') ? 'desc' : 'asc';
        $column = ltrim((string) $sort, '-');
        if (in_array($column, self::SORTS, true)) {
            $query->orderBy($column, $direction);
        } else {
            $query->orderByDesc('created_at');
        }

        return $query->orderByDesc('id');
    }

    /**
     * I conteggi delle schede con i filtri e la ricerca di adesso.
     *
     * @param  array<string, mixed>  $filters
     * @return array<string, int>
     */
    public function tabCounts(City $city, array $filters, ?string $search): array
    {
        $base = $this->query($city, null, $filters, $search, null)->reorder()->setEagerLoads([]);
        $counts = (clone $base)->toBase()->select('status', DB::raw('count(*) as total'))->groupBy('status')->pluck('total', 'status');
        $result = ['all' => (int) $counts->sum()];
        foreach (self::TABS as $key => $status) {
            $result[$key] = (int) ($counts[$status->value] ?? 0);
        }

        return $result;
    }

    /**
     * I filtri della tabella, uno per chiave, con i valori di Filament:
     * `is_featured` `1`/`0`, `trashed` `with`/`only` (senza: solo i non
     * cestinati, come `TrashedFilter`), i flag con `1`.
     *
     * @param  Builder<Event>  $query
     * @param  array<string, mixed>  $filters
     */
    public function applyFilters(Builder $query, array $filters, City $city): void
    {
        $value = fn (string $key): ?string => is_scalar($filters[$key] ?? null) && (string) $filters[$key] !== '' ? (string) $filters[$key] : null;

        if (($status = EventStatus::tryFrom((string) $value('status'))) !== null) {
            $query->where('status', $status);
        }
        foreach (['city' => 'city_id', 'venue' => 'venue_id', 'category' => 'category_id'] as $key => $column) {
            if ($value($key) !== null) {
                $query->where($column, (int) $value($key));
            }
        }
        if (($source = EventSource::tryFrom((string) $value('source'))) !== null) {
            $query->where('source', $source);
        }
        if (in_array($value('is_featured'), ['1', '0'], true)) {
            $query->where('is_featured', $value('is_featured') === '1');
        }
        foreach (self::FLAGS as $flag) {
            if ($value($flag) === '1') {
                self::applyFlag($query, $flag, $city);
            }
        }
        match ($value('trashed')) {
            'with' => null,
            'only' => $query->whereNotNull($query->getModel()->getQualifiedDeletedAtColumn()),
            default => $query->whereNull($query->getModel()->getQualifiedDeletedAtColumn()),
        };
    }

    /**
     * Una riga dell'elenco: la card della gestione più quello che la
     * redazione legge nella tabella (città, categoria, date, pubblicazione,
     * evidenza, cestino) e le azioni permesse.
     *
     * @return array<string, mixed>
     */
    public function row(Event $event, User $user): array
    {
        return [
            ...$this->cards->card($event),
            'city' => $event->city === null ? null : ['id' => (int) $event->city->getKey(), 'name' => $event->city->name, 'timezone' => $event->city->timezone],
            'category' => $event->category === null ? null : ['id' => (int) $event->category->getKey(), 'name' => $event->category->name],
            'place' => $event->venue->name ?? data_get($event->custom_location, 'name'),
            'occurrences_count' => (int) ($event->getAttribute('occurrences_count') ?? 0),
            'published_at' => $event->published_at?->utc()->toIso8601String(),
            'scheduled_publish_at' => $event->scheduled_publish_at?->utc()->toIso8601String(),
            'is_featured' => (bool) $event->is_featured,
            'featured_until' => $event->featured_until?->utc()->toIso8601String(),
            'trashed' => $event->trashed(),
            'abilities' => $this->abilities($event, $user),
        ];
    }

    /**
     * Le azioni della riga e della scheda, con le condizioni dei pulsanti
     * del pannello (`EventModeration`, `EventResource::mergeAction`,
     * `DeleteAction`): il permesso dalla policy, la visibilità dallo stato.
     *
     * @return array<string, bool>
     */
    public function abilities(Event $event, User $user): array
    {
        $gate = Gate::forUser($user);
        $status = $event->status;
        $trashed = $event->trashed();
        $publish = ! $trashed && $gate->allows('publish', $event);
        $moderate = ! $trashed && $gate->allows('moderate', $event);
        /* «Unisci», «Elimina» e «Ripristina» chiedono tutti `EventPolicy::delete`/`restore` (stesso permesso). */
        $delete = $gate->allows('delete', $event);

        return [
            'view' => true,
            /* «Modifica» (R3): finché l'app non ha il modulo si apre sul sito. */
            'edit' => ! $trashed && $gate->allows('update', $event),
            'publish' => $publish && $status !== EventStatus::Published,
            'unpublish' => $publish && $status === EventStatus::Published,
            'reject' => $moderate && $status !== EventStatus::Rejected,
            'cancel' => $moderate && $status !== EventStatus::Cancelled,
            'feature' => ! $trashed && $gate->allows('feature', $event),
            'duplicate' => ! $trashed && $gate->allows('create', [Event::class, $event->venue]),
            'merge' => ! $trashed && $delete,
            'destroy' => ! $trashed && $delete,
            'restore' => $trashed && $gate->allows('restore', $event),
            'preview' => $gate->allows('view', $event),
            'site' => ! $trashed && $status === EventStatus::Published && Route::has('events.show'),
        ];
    }

    /**
     * La scheda in sola lettura (`ViewEvent`): la scheda della gestione con
     * le date nel fuso della città, il riquadro «Redazione» (provenienza,
     * verifica, chi l'ha inserito, quando è uscito, il motivo del rifiuto),
     * i collegamenti alle pagine collegate e quanto tocca «Annulla evento».
     *
     * @return array<string, mixed>
     */
    public function detail(Event $event, User $user): array
    {
        $event->loadMissing(['creator', 'city', 'venue', 'category', 'organizer', 'tags', 'media']);
        $timezone = $event->city->timezone;
        $dates = $event->occurrences()->with(['venue', 'lineups'])->orderBy('starts_at')->get()->each(fn (EventOccurrence $date) => $date->setRelation('event', $event));
        $today = EventOccurrenceQuery::for($event->city)->currentBusinessDate();
        [$upcoming, $past] = $dates->partition(fn (EventOccurrence $date): bool => $date->business_date->format('Y-m-d') >= $today);
        $future = $event->occurrences()->where('starts_at', '>=', now());
        $cancellable = (clone $future)->where('status', '!=', OccurrenceStatus::Cancelled);
        $published = $event->status === EventStatus::Published;
        $gate = Gate::forUser($user);
        $sponsor = $published && ! $event->trashed() && (clone $future)->exists() && $gate->allows('create', Sponsorship::class);
        $can = app(EditorialPermissions::class)->for($user);
        $next = $upcoming->first();

        return [
            ...$this->cards->card($event),
            'city' => ['id' => (int) $event->city->getKey(), 'name' => $event->city->name, 'timezone' => $timezone],
            'category' => $event->category === null ? null : ['id' => (int) $event->category->getKey(), 'name' => $event->category->name],
            'organizer' => $event->organizer->name ?? $event->organizer_name,
            'price_type' => ['value' => $event->price_type->value, 'label' => $event->price_type->label()],
            'price_min' => $event->price_min === null ? null : (float) $event->price_min,
            'price_max' => $event->price_max === null ? null : (float) $event->price_max,
            'tags' => $event->tags->pluck('name')->values()->all(),
            'description' => $event->description,
            'description_text' => Description::plain($event->description),
            'next_date' => $next === null ? null : $this->cards->date($next, $timezone),
            'dates' => [
                'upcoming' => $upcoming->values()->map(fn (EventOccurrence $date): array => $this->cards->date($date, $timezone))->all(),
                'past' => $past->reverse()->values()->map(fn (EventOccurrence $date): array => $this->cards->date($date, $timezone))->all(),
            ],
            'trashed' => $event->trashed(),
            'is_featured' => (bool) $event->is_featured,
            'featured_until' => $event->featured_until?->utc()->toIso8601String(),
            'scheduled_publish_at' => $event->scheduled_publish_at?->utc()->toIso8601String(),
            'editorial' => [
                'source' => $event->source->label(),
                'verification' => $event->verification_status->label(),
                'created_by' => $event->creator->name ?? null,
                'created_at' => self::iso($event->getAttribute('created_at')),
                'updated_at' => self::iso($event->getAttribute('updated_at')),
                'published_at' => $event->published_at?->utc()->toIso8601String(),
                'scheduled_publish_at' => $event->scheduled_publish_at?->utc()->toIso8601String(),
                'editorial_score' => (int) $event->editorial_score,
                'rejection_reason' => filled($event->rejection_reason) ? $event->rejection_reason : null,
            ],
            /* «Annulla evento» porta con sé le date future e avvisa chi le ha salvate: l'app lo dice prima. */
            'cancel_impact' => [
                'upcoming_dates' => (clone $cancellable)->count(),
                'saved_by' => (int) DB::table('saved_events')->whereIn('occurrence_id', (clone $cancellable)->select('id'))->distinct()->count('user_id'),
            ],
            'abilities' => [
                ...$this->abilities($event, $user),
                'sponsor' => $sponsor,
                /* La cronologia sta nella modifica del pannello: la vede chi può modificare. */
                'activity' => $gate->allows('update', $event),
            ],
            'links' => [
                // Le pagine della redazione collegate all'evento, a chi le apre. L'app apre statistiche e
                // sponsorizzazione nelle sue schermate; il percorso del pannello resta per le grafiche social e per le versioni vecchie.
                'statistics' => $can['analytics_view'] ? 'admin/statistiche-condivisioni/event/'.$event->getKey() : null,
                'social' => $can['social_use'] ? 'admin/social?event='.$event->getKey() : null,
                'sponsor' => $sponsor ? 'admin/sponsorships/create?event='.$event->getKey() : null,
                'site' => $published && ! $event->trashed() && Route::has('events.show') ? route('events.show', $event) : null,
            ],
        ];
    }

    /** Un istante del modello in ISO 8601 UTC, o `null`. */
    private static function iso(mixed $value): ?string
    {
        return $value instanceof \DateTimeInterface ? CarbonImmutable::instance($value)->utc()->toIso8601String() : null;
    }
}

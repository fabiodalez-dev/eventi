<?php

declare(strict_types=1);

namespace App\Services\Management;

use App\Enums\ContentMetric;
use App\Enums\StatsPeriod;
use App\Http\Resources\V1\PosterResource;
use App\Models\Event;
use App\Models\EventOccurrence;
use App\Models\Organizer;
use App\Models\Venue;
use App\Queries\EventChoices;
use App\Services\Analytics\AnalyticsWindow;
use App\Services\Analytics\EventAnalyticsDashboard;
use App\Services\Analytics\EventAnalyticsExport;
use App\Services\Analytics\EventShareReport;
use App\Services\Analytics\EventShares;
use App\Services\Analytics\ManagementAnalytics;
use App\Services\Analytics\OccurrenceAnalytics;
use App\Support\Description;
use App\Support\RequestMemo;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Lang;
use stdClass;

/**
 * Le statistiche del locale o dell'organizzazione come le legge l'app di
 * gestione (P5): le stesse tre pagine dei pannelli — «Statistiche»,
 * «Statistiche in dettaglio» e la pagina di un singolo evento, locale o
 * organizzatore (che nel pannello è anche la prima pagina) — più le
 * statistiche di una data e i canali propri del locale.
 *
 * I numeri li calcolano i servizi dei pannelli (`ManagementAnalytics`,
 * `EventAnalyticsDashboard`, `EventShareReport`, `OccurrenceAnalytics`,
 * `EventShares`), che leggono il soggetto da `ManagementSubject`: qui si
 * mettono soltanto in una forma da disegnare, con le etichette e le frasi
 * che il pannello scrive sotto ogni numero. Le chiavi dei riquadri, dei
 * grafici e delle tabelle sono quelle delle viste Blade.
 */
final class ManagedAnalytics
{
    /** Righe delle tabelle di «Statistiche in dettaglio», come la pagina. */
    public const TABLE_PER_PAGE = 20;

    /** Eventi per pagina in «Statistiche», come la pagina. */
    public const EVENTS_PER_PAGE = 25;

    /** I sei numeri in cima a «Statistiche in dettaglio». */
    public const SUMMARY_KEYS = ['views', 'interactions', 'short_shares', 'short_clicks', 'saves', 'bookings_confirmed'];

    public function __construct(
        private readonly ManagementAnalytics $analytics,
        private readonly EventAnalyticsDashboard $dashboard,
        private readonly EventShareReport $shareReport,
        private readonly EventShares $shares,
    ) {}

    // ------------------------------------------------------------ «Statistiche»

    /**
     * La pagina «Statistiche» (`content-analytics.blade.php`): i cinque
     * numeri, le visite giorno per giorno, i pulsanti toccati, la scheda
     * pubblica, il dettaglio delle azioni, gli eventi del periodo, la
     * visibilità sponsorizzata e i link brevi.
     *
     * @return array<string, mixed>
     */
    public function summary(StatsPeriod $period, int $page, int $sharePage): array
    {
        $report = $this->analytics->report($period);
        [$from, $until] = $this->analytics->bounds($period);
        $totals = $report['totals'];
        $profile = $report['profile'];
        $actions = collect(self::actionMetrics());
        $clicks = (int) $actions->sum(fn (ContentMetric $metric): int => (int) $totals[$metric->value]);
        $events = $this->analytics->events($period)->paginate(self::EVENTS_PER_PAGE, ['*'], 'page', $page);
        $shares = $this->shareReport->rows($period, $sharePage);

        return [
            'period' => ['value' => $period->value, 'days' => $period->days(), 'from' => $from->toDateString(), 'until' => $until->toDateString()],
            'periods' => collect(StatsPeriod::options())->map(fn (string $label, string $value): array => ['value' => $value, 'label' => $label])->values()->all(),
            'cards' => [
                $this->card('event_views', __('analytics.event_views'), (int) $totals['views']),
                $this->card('profile_views', __('analytics.profile_views'), (int) $profile['views']),
                $this->card('clicks', __('pannelli-statistiche.labels.clicks'), $clicks),
                $this->card('saves', __('analytics.saves'), (int) $totals['saves']),
                $this->card('followers', __('pannelli-statistiche.labels.followers'), (int) $profile['followers']),
            ],
            'empty' => (int) $totals['views'] === 0 && (int) $profile['views'] === 0 && (int) $totals['saves'] === 0,
            'series' => array_map(fn (array $day): array => [
                'date' => $day['day'], 'views' => $day['views'], 'profile_views' => $day['profile_views'], 'clicks' => $day['clicks'],
            ], $report['series']),
            'interactions' => $actions->sortByDesc(fn (ContentMetric $metric): int => (int) $totals[$metric->value])
                ->map(fn (ContentMetric $metric): array => ['key' => $metric->value, 'label' => $metric->label(), 'value' => (int) $totals[$metric->value]])->values()->all(),
            'profile' => [
                ...collect(ContentMetric::cases())
                    ->reject(fn (ContentMetric $metric): bool => $metric !== ContentMetric::Views && (int) $profile[$metric->value] === 0)
                    ->map(fn (ContentMetric $metric): array => ['key' => $metric->value, 'label' => $metric->label(), 'value' => (int) $profile[$metric->value]])->values()->all(),
                ...array_map(fn (string $key): array => ['key' => $key, 'label' => __('pannelli-statistiche.labels.'.$key), 'value' => (int) $profile[$key]], ['followers', 'new_followers']),
            ],
            'details' => [
                ...array_map(fn (ContentMetric $metric): array => ['key' => $metric->value, 'label' => $metric->label(), 'value' => (int) $totals[$metric->value]], ContentMetric::cases()),
                ['key' => 'saves', 'label' => __('analytics.saves'), 'value' => (int) $totals['saves']],
            ],
            'paid' => $this->paid((int) $report['impressions'], (int) $report['sponsored_clicks'], $report['breakdown']),
            'events' => [
                'columns' => [
                    ...array_map(fn (ContentMetric $metric): array => ['key' => $metric->value, 'label' => $metric->label()], ContentMetric::cases()),
                    ['key' => 'saves', 'label' => __('analytics.saves')],
                ],
                'data' => collect($events->items())->map(fn (Event $event): array => [
                    'id' => (int) $event->getKey(),
                    'title' => $event->title,
                    'values' => [
                        ...collect(ContentMetric::cases())->mapWithKeys(fn (ContentMetric $metric): array => [$metric->value => (int) $event->getAttribute($metric->value.'_total')])->all(),
                        'saves' => (int) $event->getAttribute('saves_total'),
                    ],
                ])->values()->all(),
                'meta' => self::meta($events->currentPage(), $events->hasMorePages(), $events->total()),
            ],
            'shares' => [
                'data' => collect($shares->items())->map(fn (stdClass $row): array => [
                    'title' => $row->title,
                    'occurrence' => $row->url_number === null ? null : __('event-shares.occurrence', [
                        'number' => $row->url_number,
                        'date' => CarbonImmutable::parse($row->starts_at, 'UTC')->timezone($row->timezone)->format('d/m/Y H:i'),
                    ]),
                    'channel' => EventShares::channelLabel($row->channel),
                    'url' => route('event-shares.open', ['code' => $row->code]),
                    'shares' => (int) $row->shares,
                    'clicks' => (int) $row->clicks,
                ])->values()->all(),
                'meta' => self::meta($shares->currentPage(), $shares->hasMorePages(), $shares->total()),
            ],
        ];
    }

    // ------------------------------------------------- «Statistiche in dettaglio»

    /**
     * Il periodo scelto, nel fuso del soggetto: un periodo con nome
     * (`AnalyticsWindow`) oppure due date scelte a mano («custom», come
     * quando nel pannello si tocca «Dal» o «Al»).
     *
     * @param  array<string, mixed>  $input
     * @return array{period: string, from: ?string, until: string}
     */
    public function window(array $input): array
    {
        $timezone = $this->analytics->subject()->city->timezone;
        if (($input['period'] ?? null) === 'custom' || (! isset($input['period']) && filled($input['until'] ?? null))) {
            return ['period' => 'custom', 'from' => $input['from'] ?? null, 'until' => (string) ($input['until'] ?? CarbonImmutable::now($timezone)->toDateString())];
        }

        return AnalyticsWindow::resolve((string) ($input['period'] ?? AnalyticsWindow::DEFAULT), $timezone);
    }

    /**
     * Il rapporto di «Statistiche in dettaglio» o della pagina di un soggetto
     * (`event-share-analytics.blade.php`): filtri attivi, sei numeri, grafici,
     * conteggi delle tabelle. Le righe delle tabelle arrivano con `table`.
     *
     * @param  array{period: string, from: ?string, until: string}  $window
     * @param  array<string, mixed>  $report  quello di `EventAnalyticsDashboard::report`
     * @return array<string, mixed>
     */
    public function report(array $window, array $report, bool $detail): array
    {
        $totals = $report['totals'];
        /** @var Collection<int, array<string, mixed>> $events */
        $events = $report['events'];
        /** @var Collection<int, array<string, mixed>> $venues */
        $venues = $report['venues'];
        /** @var Collection<int, array<string, mixed>> $organizers */
        $organizers = $report['organizers'];
        $label = EventAnalyticsExport::label(...);
        $rows = fn (array $keys): array => array_map(fn (string $key): array => ['id' => null, 'name' => $label($key), 'value' => (int) $totals[$key], 'secondary' => null], $keys);
        $selected = ! $detail && filled($report['filters']['event'] ?? null) ? $events->first() : null;

        return [
            'period' => $window['period'],
            'from' => $window['from'],
            'until' => $window['until'],
            'periods' => collect(AnalyticsWindow::options())->map(fn (string $text, string $value): array => ['value' => $value, 'label' => $text])->values()->all(),
            'filters' => ['event' => $detail ? null : ($report['filters']['event'] ?? null), 'channel' => $report['filters']['channel'] ?? null],
            'active_filters' => collect(is_array($report['filter_labels']) ? $report['filter_labels'] : [])
                ->filter(fn (mixed $value, string $key): bool => filled($value) && ! ($detail && in_array($key, ['event', 'venue', 'organizer'], true)))
                ->map(fn (mixed $value, string $key): array => ['key' => $key, 'label' => __('analytics-dashboard.'.$key), 'value' => (string) $value])->values()->all(),
            'channels' => collect($this->shares->channels())->map(fn (string $channel): array => ['value' => $channel, 'label' => EventShares::channelLabel($channel)])->values()->all(),
            'context' => __('analytics-dashboard.context', ['events' => $events->count(), 'venues' => $venues->count(), 'organizers' => $organizers->count()]),
            'cards' => array_map(fn (string $key): array => $this->card($key, $label($key), (int) $totals[$key]), self::SUMMARY_KEYS),
            'empty' => collect(self::SUMMARY_KEYS)->every(fn (string $key): bool => (int) $totals[$key] === 0),
            'selected_event' => $selected === null ? null : [
                'id' => $selected['id'],
                'title' => $selected['event'],
                'subtitle' => $selected['venue'].' · '.$selected['organizer'],
                'facts' => array_map(fn (string $key): array => ['label' => $label($key), 'value' => (string) ($selected[$key] ?? '')], ['city', 'category', 'status', 'occurrences']),
            ],
            'series' => [
                'granularity' => $report['series']['granularity'],
                'points' => array_map(fn (array $point): array => [
                    'date' => $point['date'], 'views' => $point['views'], 'short_shares' => $point['short_shares'], 'short_clicks' => $point['short_clicks'],
                ], $report['series']['points']),
            ],
            'series_labels' => ['views' => $label('views'), 'short_shares' => $label('short_shares'), 'short_clicks' => $label('short_clicks')],
            'charts' => [
                $this->chart('events', 'event', $label('views'), __('event-shares.clicks'), $events->sortByDesc('views')->take(8)
                    ->map(fn (array $row): array => ['id' => $row['id'], 'name' => $row['event'], 'value' => $row['views'], 'secondary' => $row['short_clicks']])),
                $this->chart('channels', null, $label('clicks'), __('event-shares.shares'), ($report['channels'] instanceof Collection ? $report['channels'] : collect())
                    ->map(fn (array $row): array => ['id' => null, 'name' => $row['channel'], 'value' => $row['clicks'], 'secondary' => $row['shares']])),
                $this->chart('actions', null, null, null, collect(self::actionMetrics())
                    ->map(fn (ContentMetric $metric): array => ['id' => null, 'name' => $metric->label(), 'value' => (int) $totals[$metric->value], 'secondary' => null])->sortByDesc('value')),
                $this->chart('venues', 'venue', $label('event_views'), __('event-shares.clicks'), $venues->sortByDesc('event_views')->take(8)
                    ->map(fn (array $row): array => ['id' => $row['id'], 'name' => $row['name'], 'value' => $row['event_views'], 'secondary' => $row['short_clicks']])),
                $this->chart('organizers', 'organizer', $label('event_views'), null, $organizers->sortByDesc('event_views')->take(8)
                    ->map(fn (array $row): array => ['id' => $row['id'], 'name' => $row['name'], 'value' => $row['event_views'], 'secondary' => null])),
                $this->chart('community', null, null, null, collect($rows(['saves', 'comments', 'hidden_comments', 'reactions']))),
                $this->chart('bookings', null, null, null, collect($rows(['bookings_confirmed', 'bookings_waitlisted', 'bookings_cancelled', 'tickets', 'checkins']))),
                [
                    ...$this->chart('paid', 'event', $label('paid_impressions'), $label('paid_clicks'), $events->where('paid_impressions', '>', 0)->sortByDesc('paid_impressions')->take(5)
                        ->map(fn (array $row): array => ['id' => $row['id'], 'name' => $row['event'], 'value' => $row['paid_impressions'], 'secondary' => $row['paid_clicks']])),
                    'totals' => [
                        ['key' => 'paid_impressions', 'label' => $label('paid_impressions'), 'value' => (int) $totals['paid_impressions']],
                        ['key' => 'paid_clicks', 'label' => $label('paid_clicks'), 'value' => (int) $totals['paid_clicks']],
                    ],
                ],
            ],
            'datasets' => array_map(fn (string $dataset): array => [
                'key' => $dataset, 'label' => __('analytics-dashboard.datasets.'.$dataset), 'count' => $report[$dataset]->count(),
            ], EventAnalyticsExport::DATASETS),
        ];
    }

    /**
     * Una pagina di una tabella di «Tutti i dati»: le colonne della vista
     * (senza gli id tecnici, `EventAnalyticsExport::displayRow`) e, per ogni
     * riga, i collegamenti che il pannello mette sul nome di un evento, di un
     * locale o di un organizzatore.
     *
     * @param  array<string, mixed>  $report
     * @return array<string, mixed>
     */
    public function table(array $report, string $dataset, int $page): array
    {
        /** @var Collection<int, array<string, mixed>> $all */
        $all = $report[$dataset];
        $first = $all->first();
        $columns = array_keys(EventAnalyticsExport::displayRow(is_array($first) ? $first : []));
        $rows = $all->forPage($page, self::TABLE_PER_PAGE)->values();

        return [
            'dataset' => $dataset,
            'label' => __('analytics-dashboard.datasets.'.$dataset),
            'columns' => array_map(fn (string $column): array => [
                'key' => $column,
                'label' => EventAnalyticsExport::label($column),
                'numeric' => is_array($first) && (is_int($first[$column] ?? null) || is_float($first[$column] ?? null)),
            ], $columns),
            'rows' => $rows->map(fn (array $row): array => [
                'cells' => array_values(EventAnalyticsExport::displayRow($row)),
                'links' => (object) $this->rowLinks($dataset, $row),
            ])->all(),
            'unavailable' => __('analytics-dashboard.unavailable'),
            'meta' => self::meta($page, $all->count() > $page * self::TABLE_PER_PAGE, $all->count()),
        ];
    }

    // ------------------------------------------------- la pagina di un soggetto

    /**
     * Il soggetto della pagina di dettaglio, ricontrollato a ogni richiesta
     * come in `AnalyticsDetailPage::subject`: un evento, un locale o un
     * organizzatore che chi guarda può vedere, altrimenti 404.
     */
    public function subject(string $type, int $id): Event|Venue|Organizer
    {
        $key = implode(':', ['management-analytics-subject', $type, $id, auth()->id()]);

        return RequestMemo::remember($key, function () use ($type, $id): Event|Venue|Organizer {
            $query = match ($type) {
                'event' => $this->dashboard->events()->with('organizer'),
                'venue' => $this->dashboard->venues(),
                'organizer' => $this->dashboard->organizers(),
                default => abort(404),
            };

            return $query->with('city')->findOrFail($id);
        });
    }

    /**
     * La testata della pagina di un soggetto (`analytics-subject.blade.php`):
     * di quale evento si parla, i rimandi al locale e all'organizzatore,
     * «Informazioni» e, per un locale o un organizzatore, il report del
     * profilo.
     *
     * @param  array<string, mixed>  $report
     * @return array<string, mixed>
     */
    public function subjectHeader(string $type, Event|Venue|Organizer $subject, array $report): array
    {
        $isEvent = $subject instanceof Event;
        $record = $report[match ($type) {
            'event' => 'events', 'venue' => 'venues', default => 'organizers'
        }]->first();
        $label = EventAnalyticsExport::label(...);
        $information = [['label' => __('analytics-dashboard.columns.city'), 'value' => $subject->city->name, 'link' => null]];
        $related = [];
        if ($isEvent && is_array($record)) {
            foreach (['category', 'status', 'occurrences'] as $key) {
                $information[] = ['label' => $label($key), 'value' => ($record[$key] ?? null) === null ? __('analytics-dashboard.unavailable') : (string) $record[$key], 'link' => null];
            }
            foreach (['venue', 'organizer'] as $relatedType) {
                $relatedId = $record[$relatedType.'_id'] ?? null;
                $link = $relatedId === null ? null : ['type' => $relatedType, 'id' => (int) $relatedId];
                $information[] = ['label' => __('analytics-dashboard.'.$relatedType), 'value' => (string) $record[$relatedType], 'link' => $link];
                if ($link !== null) {
                    $related[] = [...$link, 'label' => __('pannelli-statistiche.subject.related_'.$relatedType, ['name' => $record[$relatedType]])];
                }
            }
        } elseif (! $isEvent) {
            foreach (['address', 'website'] as $field) {
                if (filled($subject->getAttribute($field))) {
                    $information[] = ['label' => __('analytics-dashboard.'.$field), 'value' => (string) $subject->getAttribute($field), 'link' => null];
                }
            }
        }
        $description = $subject->getAttribute('description');

        return [
            'type' => $type,
            'id' => (int) $subject->getKey(),
            'name' => $isEvent ? $subject->title : $subject->name,
            'title' => __('analytics-dashboard.detail_title', ['name' => $isEvent ? $subject->title : $subject->name]),
            'event' => $isEvent ? $this->choice(EventChoices::withDates(Event::query()->whereKey($subject->getKey()))->firstOrFail()) : null,
            'related' => $related,
            'information' => $information,
            'description' => filled($description) ? Description::plain((string) $description) : null,
            'profile_report' => ! $isEvent && is_array($record) ? collect($record)
                ->filter(fn (mixed $value, string $key): bool => str_starts_with($key, 'profile_') || in_array($key, ['followers', 'new_followers', 'reviews', 'approved_reviews', 'rating'], true))
                ->map(fn (mixed $value, string $key): array => ['key' => $key, 'label' => $label($key), 'value' => $value])->values()->all() : [],
        ];
    }

    // ------------------------------------------------------- scelta di un evento

    /**
     * Gli eventi del foglio «Scegli un evento» (W-ST-05, «Guarda un altro
     * evento»), con le regole del selettore dei pannelli: prossimi e passati,
     * ricerca per titolo e locale.
     *
     * @param  Builder<Event>  $events
     * @return array{upcoming: list<array<string, mixed>>, past: list<array<string, mixed>>}
     */
    public function choices(Builder $events, ?string $search): array
    {
        $groups = EventChoices::grouped($events, $search);

        return [
            'upcoming' => $groups['upcoming']->map(fn (Event $event): array => $this->choice($event))->values()->all(),
            'past' => ($groups['past'] ?? collect())->map(fn (Event $event): array => $this->choice($event))->values()->all(),
        ];
    }

    /**
     * Una voce: locandina, titolo, la prossima data (o l'ultima, se sono
     * tutte passate), quante altre ne restano e il locale.
     *
     * @return array<string, mixed>
     */
    public function choice(Event $event): array
    {
        $next = $event->getAttribute('next_starts_at');
        $when = $next ?? $event->getAttribute('last_starts_at');

        return [
            'id' => (int) $event->getKey(),
            'title' => $event->title,
            'poster' => PosterResource::toArray($event),
            'starts_at' => $when === null ? null : CarbonImmutable::parse((string) $when, 'UTC')->utc()->toIso8601String(),
            'timezone' => $event->city?->timezone,
            'past' => $next === null && $when !== null,
            'more' => max(0, (int) $event->getAttribute('upcoming_count') - 1),
            'venue' => $event->venue?->name,
        ];
    }

    // ------------------------------------------------------ le date di un evento

    /**
     * Le date di un evento con visite, pulsanti toccati e salvataggi (le
     * colonne dell'elenco delle date del pannello, `OccurrenceAnalyticsFields`)
     * e se chi guarda può aprirne le statistiche (`update` sulla data, come
     * l'azione «Statistiche» del pannello).
     *
     * @return list<array<string, mixed>>
     */
    public function dates(Event $event): array
    {
        $timezone = $event->city->timezone;

        $event->loadMissing('organizer');

        return OccurrenceAnalytics::withTotals($event->occurrences()->getQuery())
            ->orderBy('starts_at')
            ->get()
            ->each(fn (EventOccurrence $date) => $date->setRelation('event', $event))
            ->map(fn (EventOccurrence $date): array => [
                'id' => (int) $date->getKey(),
                'starts_at' => $date->starts_at->copy()->utc()->toIso8601String(),
                'timezone' => $timezone,
                'status' => ['value' => $date->status->value, 'label' => $date->status->label()],
                'views' => (int) $date->getAttribute('analytics_views'),
                'clicks' => (int) $date->getAttribute('analytics_clicks'),
                'saves' => (int) $date->getAttribute('saved_events_count'),
                'can_view_report' => Gate::allows('update', $date),
            ])->values()->all();
    }

    /**
     * Le statistiche di una data (`occurrence-analytics.blade.php`): tre
     * numeri e gli ultimi trenta giorni. `OccurrenceAnalytics::report` chiede
     * `update` sulla data, come il pannello.
     *
     * @return array<string, mixed>
     */
    public function occurrence(EventOccurrence $date): array
    {
        $report = app(OccurrenceAnalytics::class)->report($date);
        $totals = $report['totals'];
        $clicks = (int) collect(self::actionMetrics())->sum(fn (ContentMetric $metric): int => (int) $totals[$metric->value]);

        return [
            'id' => (int) $date->getKey(),
            'event' => ['id' => (int) $date->event->getKey(), 'title' => $date->event->title],
            'starts_at' => $date->starts_at->copy()->utc()->toIso8601String(),
            'timezone' => $date->event->city->timezone,
            'note' => __('analytics.occurrence_note'),
            'cards' => [
                $this->card('occurrence_views', __('analytics.metrics.views'), (int) $totals['views']),
                $this->card('occurrence_clicks', __('analytics.occurrence_clicks'), $clicks),
                $this->card('occurrence_saves', __('analytics.occurrence_saves'), (int) $totals['saves']),
            ],
            'series' => array_map(fn (array $day): array => ['date' => $day['day'], 'views' => $day['views'], 'clicks' => $day['clicks']], $report['series']),
        ];
    }

    // ------------------------------------------------------------ canali propri

    /**
     * I canali del locale e i loro link (W-ST-06): la tabella del pannello,
     * senza gli indirizzi delle immagini dei QR, che l'app disegna da sé dal
     * link breve.
     *
     * @return array<string, mixed>
     */
    public function channels(Venue $venue): array
    {
        return [
            'data' => array_map(fn (array $row): array => [
                'code' => $row['code'],
                'channel' => $row['channel'],
                'target' => $row['target'],
                'url' => $row['url'],
                'shares' => $row['shares'],
                'clicks' => $row['clicks'],
            ], $this->shares->printableLinks($venue)),
            'meta' => ['max' => EventShares::MAX_CUSTOM, 'custom' => $this->shares->customLinks($venue)->count()],
        ];
    }

    /**
     * I link brevi tracciati di un evento, uno per canale di sistema
     * (W-PB-05): gli stessi che la scheda pubblica crea alla prima visita.
     * Solo per un evento che il pubblico può aprire: il link di una bozza
     * porterebbe a una pagina che non c'è.
     *
     * @return array<string, mixed>
     */
    public function shareLinks(Event $event): array
    {
        $readable = Event::query()->readable()->whereKey($event->getKey())->exists();

        return [
            'available' => $readable,
            'links' => $readable ? collect($this->shares->links($event, null))
                ->map(fn (array $link, string $channel): array => ['channel' => $channel, 'label' => EventShares::channelLabel($channel), 'url' => $link['url']])
                ->values()->all() : [],
        ];
    }

    // ------------------------------------------------------------------ pezzi

    /** @return list<ContentMetric> i pulsanti toccati: tutte le misure tranne visite e condivisioni */
    private static function actionMetrics(): array
    {
        return array_values(array_filter(ContentMetric::cases(), fn (ContentMetric $metric): bool => ! in_array($metric, [ContentMetric::Views, ContentMetric::Shares], true)));
    }

    /** @return array{key: string, label: string, value: int, hint: string} un riquadro con la frase che il pannello gli scrive sotto */
    private function card(string $key, string $label, int $value): array
    {
        return ['key' => $key, 'label' => $label, 'value' => $value, 'hint' => __('pannelli-statistiche.hints.'.$key)];
    }

    /**
     * @param  Collection<int, mixed>  $rows
     * @return array<string, mixed>
     */
    private function chart(string $key, ?string $link, ?string $metric, ?string $secondary, Collection $rows): array
    {
        return ['key' => $key, 'link' => $link, 'metric_label' => $metric, 'secondary_label' => $secondary, 'rows' => $rows->values()->all()];
    }

    /**
     * La visibilità sponsorizzata di «Statistiche»: esposizioni, clic, la
     * percentuale e da dove arrivano i clic.
     *
     * @param  Collection<int, mixed>  $breakdown
     * @return array<string, mixed>
     */
    private function paid(int $impressions, int $clicks, Collection $breakdown): array
    {
        $text = fn (string $group, ?string $value): string => $value !== null && Lang::has('analytics.'.$group.'.'.$value)
            ? __('analytics.'.$group.'.'.$value) : __('analytics.unknown');
        $channels = $breakdown->groupBy('channel')->map(fn (Collection $rows): int => (int) $rows->sum('clicks'))->sortDesc();
        $channelTotal = max(1, (int) $channels->sum());

        return [
            'impressions' => $impressions,
            'clicks' => $clicks,
            'ctr' => $impressions > 0 ? number_format(100 * $clicks / $impressions, 2, ',', '.').'%' : null,
            'channels' => $channels->map(fn (int $count, string $channel): array => [
                'label' => $text('channels', $channel),
                'clicks' => $count,
                'share' => number_format(100 * $count / $channelTotal, 1, ',', '.').'%',
            ])->values()->all(),
            'breakdown' => $breakdown->map(fn (mixed $row): array => [
                'channel' => $text('channels', $row->channel),
                'placement' => $text('placements', $row->placement),
                'page' => $text('pages', $row->page),
                'clicks' => (int) $row->clicks,
            ])->values()->all(),
        ];
    }

    /**
     * I collegamenti di una riga di tabella, colonna per colonna, come i link
     * del pannello.
     *
     * @param  array<string, mixed>  $row
     * @return array<string, array{type: string, id: int}>
     */
    private function rowLinks(string $dataset, array $row): array
    {
        $links = [];
        $eventId = $row['event_id'] ?? ($dataset === 'events' ? ($row['id'] ?? null) : null);
        if (array_key_exists('event', $row) && filled($eventId)) {
            $links['event'] = ['type' => 'event', 'id' => (int) $eventId];
        }
        if (array_key_exists('name', $row) && in_array($dataset, ['venues', 'organizers'], true) && filled($row['id'] ?? null)) {
            $links['name'] = ['type' => $dataset === 'venues' ? 'venue' : 'organizer', 'id' => (int) $row['id']];
        }
        foreach (['venue', 'organizer'] as $type) {
            if (array_key_exists($type, $row) && filled($row[$type.'_id'] ?? null)) {
                $links[$type] = ['type' => $type, 'id' => (int) $row[$type.'_id']];
            }
        }

        return $links;
    }

    /** @return array{page: int, next_page: ?int, total: int} */
    private static function meta(int $page, bool $more, int $total): array
    {
        return ['page' => $page, 'next_page' => $more ? $page + 1 : null, 'total' => $total];
    }
}

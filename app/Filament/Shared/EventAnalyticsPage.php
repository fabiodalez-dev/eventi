<?php

declare(strict_types=1);

namespace App\Filament\Shared;

use App\Filament\Admin\Pages\EventAnalyticsDetail;
use App\Filament\Support\EventSelect;
use App\Models\Event;
use App\Models\Venue;
use App\Services\Analytics\AnalyticsWindow;
use App\Services\Analytics\EventAnalyticsDashboard;
use App\Services\Analytics\EventAnalyticsExport;
use App\Services\Analytics\EventShares;
use App\Services\Analytics\ManagementAnalytics;
use App\Support\CurrentCity;
use BackedEnum;
use Filament\Facades\Filament;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Validator;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;
use Livewire\WithPagination;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/** @property-read array<string, mixed> $dashboard */
abstract class EventAnalyticsPage extends Page
{
    use WithPagination;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChartBar;

    protected static ?string $slug = 'statistiche-condivisioni';

    protected string $view = 'filament.event-share-analytics';

    public string $period = 'month';

    public string $dataset = 'events';

    /** Il modulo dei canali propri: due campi, non una risorsa. */
    public ?string $channelLabel = null;

    public ?int $channelEvent = null;

    /** @var array<string, mixed> */
    #[Url]
    public array $filters = ['venue' => null, 'organizer' => null, 'event' => null, 'channel' => null, 'from' => null, 'until' => null];

    public static function getNavigationLabel(): string
    {
        return __('analytics-dashboard.title');
    }

    public function getTitle(): string
    {
        return __('analytics-dashboard.title');
    }

    public function mount(): void
    {
        app(EventAnalyticsDashboard::class)->events();
        $this->filters = array_replace(['venue' => null, 'organizer' => null, 'event' => null, 'channel' => null, 'from' => null, 'until' => null], $this->filters);
        if (blank($this->filters['until'])) {
            $this->setPeriod('month');
        } else {
            $this->period = 'custom';
        }
        $this->getSchema('form')->fill($this->filters);
    }

    public function setPeriod(string $period): void
    {
        $timezone = Filament::getCurrentPanel()?->getId() === 'admin'
            ? app(CurrentCity::class)->timezone()
            : app(ManagementAnalytics::class)->subject()->city->timezone;
        // La stessa regola dell'app di gestione: `AnalyticsWindow`.
        $window = AnalyticsWindow::resolve($period, $timezone);
        $this->period = $window['period'];
        $this->filters['until'] = $window['until'];
        $this->filters['from'] = $window['from'];
        $this->resetPage('analyticsPage');
        unset($this->dashboard);
    }

    public function updatedFilters(mixed $value = null, ?string $key = null): void
    {
        if (in_array($key, ['venue', 'organizer'], true)) {
            $this->filters['event'] = null;
        }
        if (in_array($key, ['from', 'until'], true)) {
            $this->period = 'custom';
        }
        $this->resetPage('analyticsPage');
        unset($this->dashboard);
    }

    public function detailUrl(string $type, int $id): string
    {
        $class = match (Filament::getCurrentPanel()?->getId()) {
            'admin' => EventAnalyticsDetail::class,
            'venue' => \App\Filament\Venue\Pages\EventAnalyticsDetail::class,
            'organizer' => \App\Filament\Organizer\Pages\EventAnalyticsDetail::class,
            default => abort(403),
        };

        return $class::getUrl(parameters: ['subjectType' => $type, 'subjectId' => $id,
            'filters' => array_intersect_key($this->filters, array_flip(['from', 'until', 'channel']))]);
    }

    /** @return array<string, mixed> */
    protected function reportFilters(): array
    {
        return $this->filters;
    }

    /** @return list<string> */
    protected function entityFilters(): array
    {
        return ['venue', 'organizer', 'event'];
    }

    public function setDataset(string $dataset): void
    {
        abort_unless(in_array($dataset, EventAnalyticsExport::DATASETS, true), 422);
        $this->dataset = $dataset;
        $this->resetPage('analyticsPage');
    }

    /**
     * Il riquadro dei filtri resta chiuso finché non si filtra nulla: il
     * riepilogo dei filtri attivi, sopra, dice già che cosa si sta guardando.
     */
    public function filtersChanged(): bool
    {
        return $this->period === 'custom'
            || collect($this->filters)->only([...$this->entityFilters(), 'channel'])->filter(fn ($value): bool => filled($value))->isNotEmpty();
    }

    public function resetFilters(): void
    {
        $this->filters = ['venue' => null, 'organizer' => null, 'event' => null, 'channel' => null, 'from' => null, 'until' => null];
        $this->setPeriod('month');
    }

    public function form(Schema $schema): Schema
    {
        $components = [];
        foreach ($this->entityFilters() as $type) {
            if ($type === 'event') {
                /* Il filtro «Evento»: locandina, titolo, data e locale, divisi
                   in «Prossimi» e «Passati». La query è quella di sempre,
                   ristretta a chi guarda e al locale o organizzatore scelti. */
                $components[] = EventSelect::make('event', fn () => $this->eventQuery(), showVenue: Filament::getCurrentPanel()?->getId() !== 'venue')->live();

                continue;
            }
            /* Nei pannelli del locale e dell'organizzatore i filtri «Locale» e
               «Organizzatore» non servono: si guardano già i propri eventi. */
            if (Filament::getCurrentPanel()?->getId() !== 'admin' && blank($this->filters[$type] ?? null)) {
                continue;
            }
            $components[] = Select::make($type)->label(__('analytics-dashboard.'.$type))->placeholder(__('analytics-dashboard.all_'.$type))
                ->searchable()->live()->options(fn () => $this->options($type))
                ->getSearchResultsUsing(fn (string $search): array => $this->options($type, $search))
                ->getOptionLabelUsing(function ($value) use ($type): ?string {
                    $service = app(EventAnalyticsDashboard::class);
                    $query = match ($type) {
                        'venue' => $service->venues(), 'organizer' => $service->organizers(), default => $service->events()
                    };

                    return $query->whereKey($value)->value($type === 'event' ? 'title' : 'name');
                });
        }
        $components[] = Select::make('channel')->label(__('event-shares.channel'))->placeholder(__('analytics-dashboard.all_channels'))
            ->options(collect(app(EventShares::class)->channels())->mapWithKeys(fn ($channel) => [$channel => EventShares::channelLabel($channel)])->all())->live();
        $components[] = DatePicker::make('from')->label(__('analytics-dashboard.from'))->native()->live();
        $components[] = DatePicker::make('until')->label(__('analytics-dashboard.until'))->native()->required()->live();

        return $schema->statePath('filters')->columns(1)->components($components);
    }

    /** @return Builder<Event> */
    protected function eventQuery(): Builder
    {
        return app(EventAnalyticsDashboard::class)->events()
            ->when(filled($this->filters['venue'] ?? null), fn ($q) => $q->where('venue_id', $this->filters['venue']))
            ->when(filled($this->filters['organizer'] ?? null), fn ($q) => $q->where('organizer_id', $this->filters['organizer']));
    }

    /** @return array<int, string> */
    private function options(string $type, string $search = ''): array
    {
        $service = app(EventAnalyticsDashboard::class);
        $query = match ($type) {
            'venue' => $service->venues(), 'organizer' => $service->organizers(), default => $service->events()
        };
        $column = $type === 'event' ? 'title' : 'name';
        if ($type === 'event') {
            $query->when(filled($this->filters['venue']), fn ($q) => $q->where('venue_id', $this->filters['venue']))
                ->when(filled($this->filters['organizer']), fn ($q) => $q->where('organizer_id', $this->filters['organizer']));
        }

        return $query->when($search !== '', fn ($q) => $q->where($column, 'like', '%'.addcslashes($search, '%_\\').'%'))
            ->orderBy($column)->limit(50)->pluck($column, 'id')->all();
    }

    /** @return array<string, mixed> */
    #[Computed]
    public function dashboard(): array
    {
        return app(EventAnalyticsDashboard::class)->report($this->reportFilters());
    }

    /** @return LengthAwarePaginator<int, array<string, mixed>> */
    public function tableRows(): LengthAwarePaginator
    {
        Validator::make(['dataset' => $this->dataset], ['dataset' => 'in:'.implode(',', EventAnalyticsExport::DATASETS)])->validate();
        $rows = $this->dashboard[$this->dataset];
        $page = $this->getPage('analyticsPage');

        return new LengthAwarePaginator($rows->forPage($page, 20)->values(), $rows->count(), 20, $page, ['pageName' => 'analyticsPage']);
    }

    /**
     * I canali propri e i QR li governa il referente del locale, come ogni
     * altra modifica alla scheda (`VenuePolicy::update` pretende `requireOwner`):
     * un collaboratore pubblica le date, non apre canali che poi restano nei
     * conteggi. Nel pannello della redazione e in quello dell'organizzatore la
     * sezione non compare: il bersaglio è un locale, e lì non ce n'è uno solo.
     */
    public function canManageChannels(): bool
    {
        $tenant = Filament::getTenant();

        return $tenant instanceof Venue && auth()->user()?->can('update', $tenant) === true;
    }

    /** @return list<array<string, mixed>> */
    #[Computed]
    public function printableLinks(): array
    {
        $tenant = Filament::getTenant();

        return $this->canManageChannels() && $tenant instanceof Venue
            ? app(EventShares::class)->printableLinks($tenant)
            : [];
    }

    /** @return array<int, string> */
    public function channelEvents(): array
    {
        return $this->canManageChannels() ? $this->options('event') : [];
    }

    public function addChannel(): void
    {
        abort_unless($this->canManageChannels(), 403);
        $venue = Filament::getTenant();
        abort_unless($venue instanceof Venue, 403);
        $shares = app(EventShares::class);
        // Le stesse due regole dell'app di gestione: `EventShares::channelProblem`.
        $problem = $shares->channelProblem($venue, $this->channelLabel);
        if ($problem !== null) {
            Notification::make()->{$problem === 'limit' ? 'warning' : 'danger'}()->title(EventShares::channelProblemMessage($problem))->send();

            return;
        }
        // L'evento passa dalla stessa lista del filtro, che è già ristretta a
        // chi guarda: un identificativo inventato non trova nulla.
        $event = filled($this->channelEvent)
            ? Event::query()->whereKey($this->channelEvent)->whereIn('id', app(EventAnalyticsDashboard::class)->events()->select('events.id'))->first()
            : null;
        abort_if(filled($this->channelEvent) && $event === null, 403);
        $link = $shares->addChannel($venue, (string) $this->channelLabel, $event);
        $this->channelLabel = null;
        $this->channelEvent = null;
        unset($this->printableLinks);
        Notification::make()->success()->title(__('event-shares.custom.created', ['channel' => EventShares::channelLabel($link->channel)]))->send();
    }

    public function export(string $format): BinaryFileResponse
    {
        // Rebuild and reauthorize at download time, including forged Livewire IDs.
        return app(EventAnalyticsExport::class)->download($this->reportFilters(), $format, $this->dataset);
    }
}

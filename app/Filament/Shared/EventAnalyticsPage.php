<?php

declare(strict_types=1);

namespace App\Filament\Shared;

use App\Enums\StatsPeriod;
use App\Services\Analytics\EventAnalyticsDashboard;
use App\Services\Analytics\EventAnalyticsExport;
use App\Services\Analytics\EventShares;
use App\Services\Analytics\ManagementAnalytics;
use App\Support\CurrentCity;
use BackedEnum;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Pages\Page;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
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
        $this->period = in_array($period, ['week', 'month', 'quarter', 'year', 'all'], true) ? $period : 'month';
        $timezone = Filament::getCurrentPanel()?->getId() === 'admin'
            ? app(CurrentCity::class)->timezone()
            : app(ManagementAnalytics::class)->subject()->city->timezone;
        $until = CarbonImmutable::now($timezone);
        $days = $this->period === 'year' ? 365 : (StatsPeriod::tryFrom($this->period)?->days() ?? 30);
        $this->filters['until'] = $until->toDateString();
        $this->filters['from'] = $this->period === 'all' ? null : $until->subDays($days - 1)->toDateString();
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

    public function selectEvent(int $id): void
    {
        abort_unless(app(EventAnalyticsDashboard::class)->events()->whereKey($id)->exists(), 403);
        $this->filters['event'] = $id;
        $this->updatedFilters();
    }

    public function selectProfile(string $type, int $id): void
    {
        abort_unless(in_array($type, ['venue', 'organizer'], true), 422);
        $service = app(EventAnalyticsDashboard::class);
        $query = $type === 'venue' ? $service->venues() : $service->organizers();
        abort_unless($query->whereKey($id)->exists(), 403);
        $this->filters[$type] = $id;
        $this->updatedFilters(null, $type);
    }

    public function profileAnalyticsUrl(string $type, int $id): string
    {
        return static::getUrl(parameters: ['filters' => array_replace($this->filters, [$type => $id, 'event' => null])]);
    }

    public function setDataset(string $dataset): void
    {
        abort_unless(in_array($dataset, EventAnalyticsExport::DATASETS, true), 422);
        $this->dataset = $dataset;
        $this->resetPage('analyticsPage');
    }

    public function resetFilters(): void
    {
        $this->filters = ['venue' => null, 'organizer' => null, 'event' => null, 'channel' => null, 'from' => null, 'until' => null];
        $this->setPeriod('month');
    }

    public function form(Schema $schema): Schema
    {
        $components = [];
        foreach (['venue', 'organizer', 'event'] as $type) {
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
            ->options(collect(EventShares::CHANNELS)->mapWithKeys(fn ($channel) => [$channel => __('event-shares.channels.'.$channel)])->all())->live();
        $components[] = DatePicker::make('from')->label(__('analytics-dashboard.from'))->native()->live();
        $components[] = DatePicker::make('until')->label(__('analytics-dashboard.until'))->native()->required()->live();

        return $schema->statePath('filters')->columns(['default' => 1, 'md' => 3])->components($components);
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
        return app(EventAnalyticsDashboard::class)->report($this->filters);
    }

    /** @return LengthAwarePaginator<int, array<string, mixed>> */
    public function tableRows(): LengthAwarePaginator
    {
        Validator::make(['dataset' => $this->dataset], ['dataset' => 'in:'.implode(',', EventAnalyticsExport::DATASETS)])->validate();
        $rows = $this->dashboard[$this->dataset];
        $page = $this->getPage('analyticsPage');

        return new LengthAwarePaginator($rows->forPage($page, 20)->values(), $rows->count(), 20, $page, ['pageName' => 'analyticsPage']);
    }

    public function export(string $format): BinaryFileResponse
    {
        // Rebuild and reauthorize at download time, including forged Livewire IDs.
        return app(EventAnalyticsExport::class)->download($this->filters, $format, $this->dataset);
    }
}

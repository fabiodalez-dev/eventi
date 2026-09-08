<?php

declare(strict_types=1);

namespace App\Filament\Shared;

use App\Models\SponsorshipClick;
use App\Models\SponsorshipDailyStat;
use App\Services\Sponsorship\SponsorshipReport;
use BackedEnum;
use Carbon\CarbonImmutable;
use Filament\Forms\Components\Select;
use Filament\Pages\Page;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Livewire\WithPagination;

abstract class SponsorshipAnalyticsPage extends Page
{
    use WithPagination;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChartBar;

    protected static ?string $navigationLabel = 'Statistiche sponsorizzazioni';

    protected static ?string $title = 'Statistiche sponsorizzazioni';

    protected static ?string $slug = 'statistiche-sponsorizzazioni';

    protected string $view = 'filament.sponsorship-analytics';

    /** @var array<string, mixed> */
    public array $filters = ['days' => 30, 'campaign' => null];

    public function mount(): void
    {
        app(SponsorshipReport::class)->campaigns();
        $this->getSchema('form')->fill($this->filters);
    }

    public function updatedFilters(): void
    {
        $this->resetPage();
        $this->validate(['filters.days' => ['required', 'integer', 'in:7,30,90'], 'filters.campaign' => ['nullable', 'integer', 'min:1']]);
    }

    public function form(Schema $schema): Schema
    {
        $options = static fn (string $search = ''): array => app(SponsorshipReport::class)->campaigns()->with('event')
            ->when($search !== '', fn ($q) => $q->whereHas('event', fn ($e) => $e->where('title', 'like', '%'.addcslashes($search, '%_\\').'%')))
            ->latest('id')->limit(50)->get()->mapWithKeys(fn ($c) => [$c->id => '#'.$c->id.' · '.$c->event?->title])->all();

        return $schema->statePath('filters')->columns(1)->components([
            Select::make('days')->label('Periodo')->options([7 => 'Ultimi 7 giorni', 30 => 'Ultimi 30 giorni', 90 => 'Ultimi 90 giorni'])->required()->live(),
            Select::make('campaign')->label('Campagna / evento')->placeholder('Tutte le campagne autorizzate')->searchable()->live()
                ->options(fn () => $options())->getSearchResultsUsing(fn (string $search): array => $options($search))
                ->getOptionLabelUsing(fn ($value) => app(SponsorshipReport::class)->campaigns()->with('event')->find($value)?->event?->title),
        ]);
    }

    /** @return array<string, mixed> */
    public function report(): array
    {
        $days = in_array($this->filters['days'] ?? null, [7, 30, 90, '7', '30', '90'], true) ? (int) $this->filters['days'] : 30;
        $campaigns = app(SponsorshipReport::class)->campaigns();
        if (filled($this->filters['campaign'] ?? null)) {
            // Never accept an ID belonging to another venue, even in a forged Livewire request.
            abort_unless((clone $campaigns)->whereKey($this->filters['campaign'])->exists(), 403);
            $campaigns->whereKey($this->filters['campaign']);
        }
        $ids = $campaigns->select('sponsorships.id');
        $from = CarbonImmutable::now()->subDays($days - 1)->startOfDay();
        $daily = SponsorshipDailyStat::whereIn('sponsorship_id', clone $ids)->where('day', '>=', $from->toDateString())
            ->selectRaw('day, SUM(impressions) as impressions, SUM(clicks) as clicks')->groupBy('day')->orderBy('day')->get()->keyBy(fn ($row) => $row->day->toDateString());
        $series = collect();
        for ($day = $from; $day->lte(CarbonImmutable::now()); $day = $day->addDay()) {
            $row = $daily->get($day->toDateString());
            $series->push(['day' => $day->format('d/m'), 'impressions' => (int) $row?->impressions, 'clicks' => (int) $row?->clicks]);
        }

        return [
            'series' => $series, 'impressions' => $series->sum('impressions'), 'clicks' => $series->sum('clicks'),
            'records' => SponsorshipClick::whereIn('sponsorship_id', clone $ids)->where('clicked_at', '>=', $from)
                ->with(['sponsorship.event.venue'])->orderByDesc('clicked_at')->orderByDesc('id')->paginate(25),
        ];
    }
}

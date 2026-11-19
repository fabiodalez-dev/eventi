<?php

declare(strict_types=1);

namespace App\Filament\Shared;

use App\Models\SponsorshipClick;
use App\Models\SponsorshipDailyStat;
use App\Services\Sponsorship\CampaignEconomics;
use App\Services\Sponsorship\SponsorshipReport;
use BackedEnum;
use Carbon\CarbonImmutable;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Pages\Page;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Livewire\WithPagination;

/**
 * Visualizzazioni e clic delle campagne, giorno per giorno, e il registro
 * dei clic. La stessa pagina nei tre pannelli: chi guarda lo decide
 * `SponsorshipReport`.
 *
 * **I giorni sono quelli della citta'.** I contatori giornalieri si scrivono
 * nel fuso della citta' della campagna (`RecordSponsorshipMetric`): leggerli
 * con i giorni UTC spostava di un giorno tutto quello che succede dopo
 * mezzanotte d'estate. Il periodo va da sette giorni a tutto lo storico, o
 * fra due date.
 */
abstract class SponsorshipAnalyticsPage extends Page
{
    use WithPagination;

    /** Il periodo libero piu' lungo che si disegna giorno per giorno. */
    private const MAX_DAYS = 731;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChartBar;

    protected static ?string $slug = 'statistiche-sponsorizzazioni';

    protected string $view = 'filament.sponsorship-analytics';

    /** @var array<string, mixed> */
    public array $filters = ['days' => '30', 'from' => null, 'to' => null, 'campaign' => null];

    public static function getNavigationLabel(): string
    {
        return __('sponsorships.analytics.title');
    }

    public function getTitle(): string
    {
        return __('sponsorships.analytics.title');
    }

    public function getSubheading(): ?string
    {
        return __('sponsorships.analytics.lead');
    }

    public function mount(): void
    {
        app(SponsorshipReport::class)->campaigns();
        $this->getSchema('form')->fill($this->filters);
    }

    public function updatedFilters(): void
    {
        $this->resetPage();
        $this->validate([
            'filters.days' => ['required', 'in:7,30,90,365,all,custom'],
            'filters.from' => ['nullable', 'date'],
            'filters.to' => ['nullable', 'date'],
            'filters.campaign' => ['nullable', 'integer', 'min:1'],
        ]);
    }

    public function form(Schema $schema): Schema
    {
        $options = static fn (string $search = ''): array => app(SponsorshipReport::class)->campaigns()->with('event')
            ->when($search !== '', fn ($q) => $q->whereHas('event', fn ($e) => $e->where('title', 'like', '%'.addcslashes($search, '%_\\').'%')))
            ->latest('id')->limit(50)->get()->mapWithKeys(fn ($c) => [$c->id => '#'.$c->id.' · '.$c->event?->title])->all();
        $custom = fn (Get $get): bool => $get('days') === 'custom';

        return $schema->statePath('filters')->columns(1)->components([
            Select::make('days')->label(__('sponsorships.analytics.period'))->options(__('sponsorships.analytics.periods'))->required()->live()->native(false),
            DatePicker::make('from')->label(__('sponsorships.analytics.from'))->native(false)->displayFormat('d/m/Y')->live()
                ->visible($custom)->required($custom)->maxDate(now()),
            DatePicker::make('to')->label(__('sponsorships.analytics.to'))->native(false)->displayFormat('d/m/Y')->live()
                ->visible($custom)->required($custom)->maxDate(now())->afterOrEqual('from'),
            Select::make('campaign')->label(__('sponsorships.analytics.campaign'))->placeholder(__('sponsorships.analytics.all_campaigns'))->searchable()->live()
                ->options(fn () => $options())->getSearchResultsUsing(fn (string $search): array => $options($search))
                ->getOptionLabelUsing(fn ($value) => app(SponsorshipReport::class)->campaigns()->with('event')->find($value)?->event?->title),
        ]);
    }

    public function timezone(): string
    {
        return app(SponsorshipReport::class)->timezone();
    }

    /** @return array{0: CarbonImmutable, 1: CarbonImmutable} il primo e l'ultimo giorno, nel fuso della citta' */
    private function window(mixed $ids): array
    {
        $tz = $this->timezone();
        $today = CarbonImmutable::now($tz)->startOfDay();
        $period = (string) ($this->filters['days'] ?? '30');

        [$from, $to] = match ($period) {
            '7', '90', '365' => [$today->subDays((int) $period - 1), $today],
            'all' => [($first = SponsorshipDailyStat::whereIn('sponsorship_id', $ids)->min('day')) !== null
                ? CarbonImmutable::parse((string) $first, $tz)->startOfDay() : $today->subDays(29), $today],
            'custom' => [$this->dateFilter('from', $today->subDays(29)), $this->dateFilter('to', $today)],
            default => [$today->subDays(29), $today],
        };

        if ($to->greaterThan($today)) {
            $to = $today;
        }
        if ($from->greaterThan($to)) {
            $from = $to;
        }
        if ($from->diffInDays($to) >= self::MAX_DAYS) {
            $from = $to->subDays(self::MAX_DAYS - 1);
        }

        return [$from, $to];
    }

    private function dateFilter(string $key, CarbonImmutable $fallback): CarbonImmutable
    {
        $value = $this->filters[$key] ?? null;

        try {
            return filled($value) ? CarbonImmutable::parse((string) $value, $this->timezone())->startOfDay() : $fallback;
        } catch (\Throwable) {
            return $fallback;
        }
    }

    /** @return array<string, mixed> */
    public function report(): array
    {
        $campaigns = app(SponsorshipReport::class)->campaigns();
        if (filled($this->filters['campaign'] ?? null)) {
            // Never accept an ID belonging to another venue, even in a forged Livewire request.
            abort_unless((clone $campaigns)->whereKey($this->filters['campaign'])->exists(), 403);
            $campaigns->whereKey($this->filters['campaign']);
        }
        $ids = (clone $campaigns)->select('sponsorships.id');
        [$from, $to] = $this->window(clone $ids);
        $daily = SponsorshipDailyStat::whereIn('sponsorship_id', clone $ids)
            ->whereBetween('day', [$from->toDateString(), $to->toDateString()])
            ->selectRaw('day, SUM(impressions) as impressions, SUM(clicks) as clicks')->groupBy('day')->orderBy('day')->get()->keyBy(fn ($row) => $row->day->toDateString());
        $series = collect();
        for ($day = $from; $day->lte($to); $day = $day->addDay()) {
            $row = $daily->get($day->toDateString());
            $series->push(['day' => $day->format('d/m'), 'date' => $day->format('d/m/Y'), 'impressions' => (int) $row?->impressions, 'clicks' => (int) $row?->clicks]);
        }

        return [
            'economics' => filled($this->filters['campaign'] ?? null) ? app(CampaignEconomics::class)->for((clone $campaigns)->firstOrFail()) : null,
            'series' => $series, 'impressions' => $series->sum('impressions'), 'clicks' => $series->sum('clicks'),
            'from' => $from, 'to' => $to,
            'records' => SponsorshipClick::whereIn('sponsorship_id', clone $ids)
                ->where('clicked_at', '>=', $from->utc())->where('clicked_at', '<', $to->addDay()->utc())
                ->with(['sponsorship.event.venue'])->orderByDesc('clicked_at')->orderByDesc('id')->paginate(25),
        ];
    }
}

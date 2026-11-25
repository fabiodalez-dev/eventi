<?php

declare(strict_types=1);

namespace App\Filament\Shared;

use App\Filament\Support\PanelDate;
use App\Models\Sponsorship;
use App\Services\Sponsorship\SponsorshipAnalyticsReport;
use App\Services\Sponsorship\SponsorshipReport;
use BackedEnum;
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
 *
 * **Con una campagna scelta** compare anche l'avanzamento della sua unità di
 * consegna (l'abilitazione di cui fa parte, o lei stessa): barra, frase e,
 * per i prodotti a visualizzazioni, le consegnate giorno per giorno contro
 * le attese. Il tasso di aperture si mostra solo da
 * `sponsorship.min_impressions_for_ctr` visualizzazioni in su: sotto è un
 * numero che cambia del doppio con un clic.
 */
abstract class SponsorshipAnalyticsPage extends Page
{
    use WithPagination;

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
            'filters.days' => ['required', 'in:'.implode(',', SponsorshipAnalyticsReport::PERIODS)],
            'filters.from' => ['nullable', 'date'],
            'filters.to' => ['nullable', 'date'],
            'filters.campaign' => ['nullable', 'integer', 'min:1'],
        ]);
    }

    public function form(Schema $schema): Schema
    {
        $options = static fn (string $search = ''): array => app(SponsorshipAnalyticsReport::class)->campaignOptions($search)
            ->mapWithKeys(fn ($c) => [$c->id => self::campaignLabel($c)])->all();
        $custom = fn (Get $get): bool => $get('days') === 'custom';

        return $schema->statePath('filters')->columns(1)->components([
            Select::make('days')->label(__('sponsorships.analytics.period'))->options(__('sponsorships.analytics.periods'))->required()->live()->native(false),
            DatePicker::make('from')->label(__('sponsorships.analytics.from'))->native(false)->displayFormat('d/m/Y')->live()
                ->visible($custom)->required($custom)->maxDate(now()),
            DatePicker::make('to')->label(__('sponsorships.analytics.to'))->native(false)->displayFormat('d/m/Y')->live()
                ->visible($custom)->required($custom)->maxDate(now())->afterOrEqual('from'),
            Select::make('campaign')->label(__('pannelli-social.sponsorship.campaign'))->placeholder(__('pannelli-social.sponsorship.campaign_placeholder'))->searchable()->live()->allowHtml()
                ->searchPrompt(__('pannelli.select.type_to_search'))->noSearchResultsMessage(__('pannelli.select.no_results'))
                ->options(fn () => $options())->getSearchResultsUsing(fn (string $search): array => $options($search))
                ->getOptionLabelUsing(fn ($value): ?string => ($c = app(SponsorshipReport::class)->campaigns()->with('event.venue', 'event.city')->find($value)) ? self::campaignLabel($c) : null),
        ]);
    }

    /**
     * Una campagna nella tendina: la riga dell'evento (locandina e titolo) e,
     * sotto, quando è stata in vetrina e dove. Mai il numero della campagna.
     */
    private static function campaignLabel(Sponsorship $campaign): string
    {
        $event = $campaign->event;
        if ($event === null) {
            return e(__('sponsorships.analytics.event_gone'));
        }
        $tz = $event->city?->timezone;

        return view('filament.partials.event-option', [
            'event' => $event,
            'meta' => __('pannelli-social.sponsorship.campaign_meta', [
                'period' => PanelDate::period($campaign->starts_at, $campaign->ends_at, $tz),
                'placement' => $campaign->placement->label(),
            ]),
        ])->render();
    }

    public function timezone(): string
    {
        return app(SponsorshipAnalyticsReport::class)->timezone();
    }

    /**
     * Il report di `SponsorshipAnalyticsReport`, con le date scritte come nei
     * pannelli: la serie in una collezione (la vista ne chiede massimo,
     * primo, ultimo), i giorni con l'etichetta dell'asse e quella intera.
     *
     * @return array<string, mixed>
     */
    public function report(): array
    {
        $report = app(SponsorshipAnalyticsReport::class)->report($this->filters);
        $tz = $report['progress']?->timezone;

        $report['series'] = collect($report['series'])->map(fn (array $row): array => [
            'day' => PanelDate::axis($row['day']), 'date' => PanelDate::day($row['day']), 'impressions' => $row['impressions'], 'clicks' => $row['clicks'],
        ]);

        if ($report['delivery'] !== null) {
            $report['delivery']['days'] = array_map(fn (array $row): array => [
                'day' => PanelDate::axis($row['day']), 'date' => PanelDate::day($row['day'], $tz), 'delivered' => $row['delivered'], 'expected' => $row['expected'],
            ], $report['delivery']['days']);
        }

        return $report;
    }

    /** Sotto queste visualizzazioni il tasso di aperture non si mostra. */
    public static function minImpressionsForRate(): int
    {
        return SponsorshipAnalyticsReport::minImpressionsForRate();
    }
}

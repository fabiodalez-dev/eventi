<?php

declare(strict_types=1);

namespace App\Filament\Admin\Widgets;

use App\Models\SponsorshipDailyStat;
use Carbon\CarbonImmutable;
use Filament\Widgets\ChartWidget;
use Flowframe\Trend\Trend;
use Flowframe\Trend\TrendValue;

/**
 * L'andamento delle campagne negli ultimi trenta giorni.
 *
 * **Due linee e non una.** Le visualizzazioni da sole dicono quanto e' stato
 * mostrato, non se e' servito a qualcosa: e' la distanza fra le due linee a
 * dire come sta andando. Una campagna vista molto e mai aperta occupa il posto
 * migliore del sito senza portare niente ne' a chi paga ne' a chi legge, ed e'
 * l'unica cosa che questo grafico deve rendere evidente a colpo d'occhio.
 *
 * **I giorni vuoti sono zeri, non buchi.** Ci pensa `laravel-trend`: senza,
 * una settimana senza traffico si leggerebbe come una linea che salta da un
 * punto all'altro — cioe' come se in mezzo non fosse successo niente,
 * mentre invece e' successo zero, che e' un'informazione diversa.
 */
class SponsorshipTrendWidget extends ChartWidget
{
    protected ?string $heading = null;

    protected int|string|array $columnSpan = 'full';

    protected ?string $maxHeight = '260px';

    public function getHeading(): string
    {
        return __('sponsorships.widgets.trend');
    }

    public function getDescription(): ?string
    {
        return __('sponsorships.widgets.trend_lead');
    }

    /**
     * @return array<string, mixed>
     */
    protected function getData(): array
    {
        $da = CarbonImmutable::now()->subDays(29)->startOfDay();
        $a = CarbonImmutable::now()->endOfDay();

        $serie = static fn (string $colonna): Trend => Trend::query(SponsorshipDailyStat::query())
            ->dateColumn('day')
            ->between(start: $da, end: $a)
            ->perDay();

        $viste = $serie('impressions')->sum('impressions');
        $aperture = $serie('clicks')->sum('clicks');

        return [
            'datasets' => [
                [
                    'label' => __('sponsorships.metrics.impressions'),
                    'data' => $viste->map(static fn (TrendValue $v): int => (int) $v->aggregate)->all(),
                    'borderColor' => '#94a3b8',
                    'backgroundColor' => 'rgba(148, 163, 184, 0.12)',
                    'fill' => true,
                    'tension' => 0.3,
                ],
                [
                    'label' => __('sponsorships.metrics.clicks'),
                    'data' => $aperture->map(static fn (TrendValue $v): int => (int) $v->aggregate)->all(),
                    'borderColor' => '#16a34a',
                    'backgroundColor' => 'rgba(22, 163, 74, 0.15)',
                    'fill' => true,
                    'tension' => 0.3,
                ],
            ],
            /* Giorno e mese senza anno: trenta giorni non ne attraversano due
               in modo confuso, e l'anno ruberebbe spazio a ogni etichetta. */
            'labels' => $viste->map(
                static fn (TrendValue $v): string => CarbonImmutable::parse($v->date)->translatedFormat('j M'),
            )->all(),
        ];
    }

    protected function getType(): string
    {
        return 'line';
    }

    /**
     * @return array<string, mixed>
     */
    protected function getOptions(): array
    {
        return [
            'scales' => [
                /* I conteggi sono interi: mezza visualizzazione non esiste, e
                   un asse che scrive «2,5» fa dubitare del resto. */
                'y' => ['beginAtZero' => true, 'ticks' => ['precision' => 0]],
            ],
            'plugins' => ['legend' => ['display' => true]],
        ];
    }
}

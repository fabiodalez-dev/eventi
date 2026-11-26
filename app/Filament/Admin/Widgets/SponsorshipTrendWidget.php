<?php

declare(strict_types=1);

namespace App\Filament\Admin\Widgets;

use App\Models\City;
use App\Services\Editorial\DeskSummary;
use App\Support\Sponsorship\DashboardCity;
use Filament\Widgets\ChartWidget;

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
    protected static ?int $sort = 8;

    /* Niente aggiornamento automatico ogni cinque secondi: ogni giro era una
       richiesta completa con tutte le sue query, e i numeri cambiano a ore. */
    protected ?string $pollingInterval = null;

    protected ?string $heading = null;

    protected int|string|array $columnSpan = 'full';

    protected ?string $maxHeight = '260px';

    public static function canView(): bool
    {
        return DashboardCity::resolve() instanceof City;
    }

    public function getHeading(): string
    {
        $city = DashboardCity::resolve();

        return $city === null ? __('sponsorships.widgets.trend') : __('sponsorships.widgets.trend_city', ['city' => $city->name]);
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
        /* I giorni della citta': i contatori si scrivono nel suo fuso, e un
           giorno UTC spostava di una casella le visite dopo mezzanotte. La
           serie la calcola `DeskSummary`, la stessa che riceve l'app. */
        $trend = app(DeskSummary::class)->sponsorshipTrend(DashboardCity::resolve());

        return [
            'datasets' => [
                [
                    'label' => __('sponsorships.metrics.impressions'),
                    'data' => $trend['impressions'],
                    'borderColor' => '#94a3b8',
                    'backgroundColor' => 'rgba(148, 163, 184, 0.12)',
                    'fill' => true,
                    'tension' => 0.3,
                ],
                [
                    'label' => __('sponsorships.metrics.clicks'),
                    'data' => $trend['clicks'],
                    'borderColor' => '#16a34a',
                    'backgroundColor' => 'rgba(22, 163, 74, 0.15)',
                    'fill' => true,
                    'tension' => 0.3,
                ],
            ],
            /* Giorno e mese senza anno: trenta giorni non ne attraversano due
               in modo confuso, e l'anno ruberebbe spazio a ogni etichetta. */
            'labels' => $trend['labels'],
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

<?php

declare(strict_types=1);

namespace App\Services\Analytics;

use App\Enums\StatsPeriod;
use Carbon\CarbonImmutable;

/**
 * I periodi della pagina «Statistiche in dettaglio»: 7, 30 e 90 giorni
 * (`StatsPeriod`), più 365 giorni e tutto lo storico.
 *
 * Stava dentro `EventAnalyticsPage::setPeriod`; l'app di gestione chiede gli
 * stessi periodi, e la regola deve restare una: il pannello e l'API la
 * leggono da qui. Il giorno finale è «oggi» nel fuso del soggetto; «tutto lo
 * storico» non ha un giorno iniziale.
 */
final class AnalyticsWindow
{
    /** I periodi, nell'ordine dei pulsanti della pagina. */
    public const PERIODS = ['week', 'month', 'quarter', 'year', 'all'];

    public const DEFAULT = 'month';

    /**
     * @return array{period: string, from: ?string, until: string}
     */
    public static function resolve(string $period, string $timezone): array
    {
        $period = in_array($period, self::PERIODS, true) ? $period : self::DEFAULT;
        $until = CarbonImmutable::now($timezone);
        $days = $period === 'year' ? 365 : (StatsPeriod::tryFrom($period)?->days() ?? 30);

        return [
            'period' => $period,
            'from' => $period === 'all' ? null : $until->subDays($days - 1)->toDateString(),
            'until' => $until->toDateString(),
        ];
    }

    /**
     * Le etichette dei pulsanti, le stesse della pagina.
     *
     * @return array<string, string>
     */
    public static function options(): array
    {
        return [...StatsPeriod::options(), 'year' => __('analytics-dashboard.year'), 'all' => __('analytics-dashboard.all_history')];
    }
}

<?php

declare(strict_types=1);

namespace App\Services\Analytics;

use App\Enums\StatsPeriod;
use App\Rules\AnalyticsRange;
use App\Services\Sponsorship\SponsorshipAnalyticsReport;
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
     * Quanti giorni al massimo copre un periodo scelto a mano: la stessa
     * regola delle statistiche delle sponsorizzazioni, due anni più un giorno.
     */
    public const MAX_DAYS = SponsorshipAnalyticsReport::MAX_DAYS;

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

    /**
     * Le regole delle due date scelte a mano, le stesse per il pannello e per
     * l'API: formato `Y-m-d`, «Al» non oltre oggi nel fuso di chi guarda,
     * al massimo `MAX_DAYS` giorni fra «Dal» e «Al» (`AnalyticsRange`).
     *
     * @return array{from: list<mixed>, until: list<mixed>}
     */
    public static function rules(string $timezone): array
    {
        return [
            'from' => ['nullable', 'date_format:Y-m-d'],
            'until' => ['nullable', 'date_format:Y-m-d', new AnalyticsRange(CarbonImmutable::now($timezone)->toDateString())],
        ];
    }

    /**
     * Le due date riportate dentro i limiti, per i campi del pannello: «Al»
     * oltre oggi torna a oggi, «Dal» troppo indietro avanza fino a
     * `MAX_DAYS` giorni prima di «Al». Una data illeggibile resta com'è: la
     * scarta poi la validazione di `EventAnalyticsDashboard::report`.
     *
     * @return array{from: ?string, until: ?string}
     */
    public static function clamp(?string $from, ?string $until, string $timezone): array
    {
        $today = CarbonImmutable::now($timezone)->startOfDay();
        $end = self::date($until);
        if ($end !== null && $end->greaterThan($today)) {
            $end = $today;
            $until = $today->toDateString();
        }
        $start = self::date($from);
        if ($end !== null && $start !== null && $start->lessThanOrEqualTo($end) && $start->diffInDays($end) >= self::MAX_DAYS) {
            $from = $end->subDays(self::MAX_DAYS - 1)->toDateString();
        }

        return ['from' => $from, 'until' => $until];
    }

    /** Una data `Y-m-d` valida, oppure null. */
    public static function date(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
            return null;
        }
        $date = CarbonImmutable::createFromFormat('!Y-m-d', $value);

        return $date instanceof CarbonImmutable && $date->format('Y-m-d') === $value ? $date : null;
    }
}

<?php

declare(strict_types=1);

namespace App\Rules;

use App\Services\Analytics\AnalyticsWindow;
use Closure;
use Illuminate\Contracts\Validation\DataAwareRule;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Translation\PotentiallyTranslatedString;

/**
 * Il periodo scelto a mano delle statistiche («Dal» / «Al»), sul campo
 * `until`: il giorno finale non va oltre oggi nel fuso di chi guarda, e fra
 * le due date ci stanno al massimo `AnalyticsWindow::MAX_DAYS` giorni.
 *
 * Senza questo tetto `from=0001-01-01&until=9999-12-31` faceva costruire al
 * server una serie di circa 120.000 mesi. La regola è una sola e la usano sia
 * `EventAnalyticsDashboard::report` (pannelli, esportazioni, API) sia la
 * richiesta dell'API, che così risponde 422 prima di fare qualsiasi conto.
 *
 * Un «Dal» vuoto resta permesso: è «tutto lo storico», e la serie parte dal
 * primo giorno con dati veri, non da una data scelta da chi chiede.
 */
final class AnalyticsRange implements DataAwareRule, ValidationRule
{
    /** @var array<string, mixed> */
    private array $data = [];

    public function __construct(private readonly string $today) {}

    /** @param array<string, mixed> $data */
    public function setData(array $data): static
    {
        $this->data = $data;

        return $this;
    }

    /**
     * @param  Closure(string, string|null=): PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $until = AnalyticsWindow::date($value);
        if ($until === null) {
            return; // il formato lo controlla `date_format`
        }
        if ($until->toDateString() > $this->today) {
            $fail(__('analytics-dashboard.until_future'));

            return;
        }
        $from = AnalyticsWindow::date($this->data['from'] ?? null);
        if ($from !== null && $from->lessThanOrEqualTo($until) && $from->diffInDays($until) >= AnalyticsWindow::MAX_DAYS) {
            $fail(__('analytics-dashboard.range_too_long', ['days' => AnalyticsWindow::MAX_DAYS]));
        }
    }
}

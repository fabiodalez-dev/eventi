<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Management\Analytics;

use App\Enums\StatsPeriod;
use App\Services\Analytics\AnalyticsWindow;
use App\Services\Analytics\EventAnalyticsDashboard;
use App\Services\Analytics\EventAnalyticsExport;
use App\Support\Management\ManagementSubject;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Le domande di lettura delle statistiche (P5): «Statistiche», «Statistiche
 * in dettaglio» con le sue tabelle e le sue esportazioni, la pagina di un
 * soggetto, la scelta di un evento, le date di un evento.
 *
 * Chi può: ogni membro del soggetto, come le pagine dei pannelli
 * (`ContentAnalyticsPage::canAccess` e `AnalyticsDetailPage::canAccess`
 * chiedono `canAccessTenant`). Il soggetto l'ha già controllato
 * `ResolveManagementSubject`; qui si rifà la stessa domanda, come fanno le
 * pagine a ogni richiesta.
 *
 * I filtri passano poi dalle regole di `EventAnalyticsDashboard::report`, che
 * ricontrolla evento e canale: un evento di un altro soggetto risponde 403,
 * come nel pannello.
 */
final class AnalyticsQuery extends FormRequest
{
    public function authorize(): bool
    {
        $subject = ManagementSubject::current();

        return $subject !== null && $subject->user->canAccessTenant($subject->model);
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        $summary = str_ends_with((string) $this->route()->getName(), 'analytics.summary');
        // Le date a mano hanno un tetto: «Al» non oltre oggi, al massimo
        // `AnalyticsWindow::MAX_DAYS` giorni. Fuori dai limiti è un 422 qui,
        // prima di costruire il rapporto.
        $dates = AnalyticsWindow::rules(app(EventAnalyticsDashboard::class)->timezone());

        return [
            // «Statistiche» ha tre periodi; «Statistiche in dettaglio» cinque più le date a mano.
            'period' => ['nullable', 'string', Rule::in($summary ? StatsPeriod::values() : [...AnalyticsWindow::PERIODS, 'custom'])],
            'from' => $dates['from'],
            'until' => [...$dates['until'], 'required_if:period,custom'],
            'event' => ['nullable', 'integer', 'min:1'],
            'channel' => ['nullable', 'string', 'max:60'],
            'dataset' => ['nullable', 'string', Rule::in(EventAnalyticsExport::DATASETS)],
            'format' => ['nullable', 'string', Rule::in(['csv', 'xlsx'])],
            'page' => ['nullable', 'integer', 'min:1', 'max:10000'],
            'share_page' => ['nullable', 'integer', 'min:1', 'max:10000'],
            'q' => ['nullable', 'string', 'max:100'],
        ];
    }

    /**
     * Il soggetto della pagina di dettaglio, dalla rotta
     * (`…/analytics/subjects/{type}/{id}`).
     *
     * @return array{0: string, 1: int}
     */
    public function subjectRoute(): array
    {
        return [(string) $this->route('type'), (int) $this->route('id')];
    }
}

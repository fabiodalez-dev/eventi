<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Editorial\Analytics;

use App\Models\User;
use App\Services\Analytics\AnalyticsWindow;
use App\Services\Analytics\EventAnalyticsDashboard;
use App\Services\Analytics\EventAnalyticsExport;
use App\Services\Editorial\EditorialPermissions;
use App\Support\Editorial\EditorialDesk;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Le domande di lettura delle statistiche della redazione (R-ST-01…03):
 * «Statistiche condivisioni» con le sue tabelle e le sue esportazioni, la
 * pagina di un evento, un locale o un organizzatore, le scelte dei filtri.
 *
 * Chi può: admin e super admin, come `EventShareAnalytics::canAccess` e
 * `AnalyticsDetailPage::canAccess` del pannello (`analytics_view`). Il
 * moderatore è dentro la redazione ma non qui: 403. I filtri passano poi
 * dalle regole di `EventAnalyticsDashboard::report`, che ricontrolla locale,
 * organizzatore, evento e canale come nel pannello.
 */
final class EditorialAnalyticsQuery extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        return EditorialDesk::active() && $user instanceof User && app(EditorialPermissions::class)->allows($user, 'analytics_view');
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        // Le date a mano hanno il tetto di `AnalyticsWindow`, nel fuso della città di lavoro.
        $dates = AnalyticsWindow::rules(app(EventAnalyticsDashboard::class)->timezone());

        return [
            'period' => ['nullable', 'string', Rule::in([...AnalyticsWindow::PERIODS, 'custom'])],
            'from' => $dates['from'],
            'until' => [...$dates['until'], 'required_if:period,custom'],
            'venue' => ['nullable', 'integer', 'min:1'],
            'organizer' => ['nullable', 'integer', 'min:1'],
            'event' => ['nullable', 'integer', 'min:1'],
            'channel' => ['nullable', 'string', 'max:60'],
            'dataset' => ['nullable', 'string', Rule::in(EventAnalyticsExport::DATASETS)],
            'format' => ['nullable', 'string', Rule::in(['csv', 'xlsx'])],
            'page' => ['nullable', 'integer', 'min:1', 'max:10000'],
            'q' => ['nullable', 'string', 'max:100'],
            'exclude' => ['nullable', 'integer', 'min:1'],
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

    /** Un intero validato o `null`. */
    public function integerOrNull(string $key): ?int
    {
        $value = $this->validated($key);

        return $value === null || $value === '' ? null : (int) $value;
    }
}

<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Management;

use App\Enums\StatsPeriod;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Management\Analytics\AnalyticsQuery;
use App\Services\Analytics\EventAnalyticsDashboard;
use App\Services\Analytics\EventAnalyticsExport;
use App\Services\Management\ManagedAnalytics;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Le statistiche del soggetto (P5, W-ST-01…05), come le tre pagine dei
 * pannelli:
 *
 * - `summary`: «Statistiche» (`ContentAnalyticsPage`);
 * - `report`, `reportTable`, `reportExport`: «Statistiche in dettaglio»
 *   (`EventAnalyticsPage`), con i filtri evento e canale, le tabelle da 20
 *   righe e i file CSV ed Excel prodotti dal server (decisione 2);
 * - `subject`, `subjectTable`, `subjectExport`: la pagina di un evento, un
 *   locale o un organizzatore (`AnalyticsDetailPage`, che è anche la prima
 *   pagina del pannello per il proprio locale o la propria organizzazione):
 *   il soggetto si ricontrolla a ogni richiesta, e i filtri sono solo
 *   periodo e canale;
 * - `events`: gli eventi del foglio «Scegli un evento».
 *
 * Il collaboratore vede quello che vede il referente, come nel pannello.
 */
final class AnalyticsController extends Controller
{
    public function __construct(
        private readonly ManagedAnalytics $analytics,
        private readonly EventAnalyticsDashboard $dashboard,
    ) {}

    public function summary(AnalyticsQuery $request): JsonResponse
    {
        $period = StatsPeriod::tryFrom((string) $request->validated('period')) ?? StatsPeriod::default();

        return response()->json(['data' => $this->analytics->summary(
            $period,
            (int) ($request->validated('page') ?? 1),
            (int) ($request->validated('share_page') ?? 1),
        )]);
    }

    public function report(AnalyticsQuery $request): JsonResponse
    {
        [$window, $report] = $this->overview($request);

        return response()->json(['data' => $this->analytics->report($window, $report, detail: false)]);
    }

    public function reportTable(AnalyticsQuery $request): JsonResponse
    {
        [, $report] = $this->overview($request);

        return response()->json(['data' => $this->analytics->table($report, $this->dataset($request), (int) ($request->validated('page') ?? 1))]);
    }

    public function reportExport(AnalyticsQuery $request, EventAnalyticsExport $export): BinaryFileResponse
    {
        [$window] = $this->overview($request, compute: false);

        return $export->download($this->overviewFilters($window, $request), $this->format($request), $this->dataset($request));
    }

    public function subject(AnalyticsQuery $request): JsonResponse
    {
        [$type, $id] = $request->subjectRoute();
        $subject = $this->analytics->subject($type, $id);
        $window = $this->analytics->window($request->validated());
        $report = $this->dashboard->report($this->detailFilters($window, $request, $type, $id));

        return response()->json(['data' => [
            ...$this->analytics->report($window, $report, detail: true),
            'subject' => $this->analytics->subjectHeader($type, $subject, $report),
        ]]);
    }

    public function subjectTable(AnalyticsQuery $request): JsonResponse
    {
        [$type, $id] = $request->subjectRoute();
        $this->analytics->subject($type, $id);
        $report = $this->dashboard->report($this->detailFilters($this->analytics->window($request->validated()), $request, $type, $id));

        return response()->json(['data' => $this->analytics->table($report, $this->dataset($request), (int) ($request->validated('page') ?? 1))]);
    }

    public function subjectExport(AnalyticsQuery $request, EventAnalyticsExport $export): BinaryFileResponse
    {
        [$type, $id] = $request->subjectRoute();
        $this->analytics->subject($type, $id);
        $filters = $this->detailFilters($this->analytics->window($request->validated()), $request, $type, $id);

        return $export->download($filters, $this->format($request), $this->dataset($request));
    }

    /** Gli eventi di cui il soggetto vede le statistiche, per «Guarda un altro evento» e per il filtro «Evento». */
    public function events(AnalyticsQuery $request): JsonResponse
    {
        $search = $request->validated('q');

        return response()->json(['data' => $this->analytics->choices($this->dashboard->events(), is_string($search) ? $search : null)]);
    }

    /**
     * @return array{0: array{period: string, from: ?string, until: string}, 1: array<string, mixed>}
     */
    private function overview(AnalyticsQuery $request, bool $compute = true): array
    {
        $window = $this->analytics->window($request->validated());

        return [$window, $compute ? $this->dashboard->report($this->overviewFilters($window, $request)) : []];
    }

    /**
     * I filtri della pagina d'insieme: periodo, evento e canale. Locale e
     * organizzatore il pannello del soggetto non li mostra (si guardano già i
     * propri eventi), e qui non si accettano.
     *
     * @param  array{period: string, from: ?string, until: string}  $window
     * @return array<string, mixed>
     */
    private function overviewFilters(array $window, AnalyticsQuery $request): array
    {
        $event = $request->validated('event');

        return ['from' => $window['from'], 'until' => $window['until'], 'event' => $event === null ? null : (int) $event, 'channel' => $request->validated('channel')];
    }

    /**
     * I filtri della pagina di un soggetto: solo periodo e canale, più il
     * soggetto della rotta, come `AnalyticsDetailPage::reportFilters`.
     *
     * @param  array{period: string, from: ?string, until: string}  $window
     * @return array<string, mixed>
     */
    private function detailFilters(array $window, AnalyticsQuery $request, string $type, int $id): array
    {
        return ['from' => $window['from'], 'until' => $window['until'], 'channel' => $request->validated('channel'), $type => $id];
    }

    private function dataset(AnalyticsQuery $request): string
    {
        $dataset = $request->validated('dataset');

        return is_string($dataset) ? $dataset : 'events';
    }

    private function format(AnalyticsQuery $request): string
    {
        $format = $request->validated('format');

        return is_string($format) ? $format : 'csv';
    }
}

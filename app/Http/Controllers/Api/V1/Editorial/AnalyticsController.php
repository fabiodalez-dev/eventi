<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Editorial;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Editorial\Analytics\EditorialAnalyticsQuery;
use App\Models\Organizer;
use App\Models\Venue;
use App\Services\Analytics\EventAnalyticsDashboard;
use App\Services\Analytics\EventAnalyticsExport;
use App\Services\Management\ManagedAnalytics;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Le statistiche della redazione (R10, R-ST-01…03), come le due pagine del
 * gruppo «Statistiche» del pannello:
 *
 * - `report`, `reportTable`, `reportExport`: «Statistiche condivisioni»
 *   (`EventShareAnalytics`, `EventAnalyticsPage`), su tutti gli eventi della
 *   piattaforma, con i filtri locale, organizzatore, evento, canale e periodo,
 *   le tabelle da 20 righe e i file CSV ed Excel prodotti dal server;
 * - `subject`, `subjectTable`, `subjectExport`: la pagina di un evento, un
 *   locale o un organizzatore (`EventAnalyticsDetail`), il soggetto
 *   ricontrollato a ogni richiesta, i filtri solo periodo e canale;
 * - `events`, `venues`, `organizers`: le scelte dei filtri e di «Guarda un
 *   altro evento», con le stesse query del pannello.
 *
 * I numeri li calcolano gli stessi servizi del pannello e dell'app di
 * gestione (`EventAnalyticsDashboard`, `ManagedAnalytics`): il banco della
 * redazione prende il posto del soggetto, il fuso è quello della città di
 * lavoro. Admin e super admin; il moderatore riceve 403
 * (`EditorialAnalyticsQuery`), come il pannello.
 */
final class AnalyticsController extends Controller
{
    /** Le voci di una lista di scelta, come `EventAnalyticsPage::options`. */
    public const CHOICES_LIMIT = 50;

    public function __construct(
        private readonly ManagedAnalytics $analytics,
        private readonly EventAnalyticsDashboard $dashboard,
    ) {}

    public function report(EditorialAnalyticsQuery $request): JsonResponse
    {
        $window = $this->analytics->window($request->validated());
        $filters = $this->overviewFilters($window, $request);
        $data = $this->analytics->report($window, $this->dashboard->report($filters), detail: false);
        // Il pannello della redazione filtra anche per locale e organizzatore.
        $data['filters'] = [...$data['filters'], 'venue' => $filters['venue'], 'organizer' => $filters['organizer']];

        return response()->json(['data' => $data]);
    }

    public function reportTable(EditorialAnalyticsQuery $request): JsonResponse
    {
        $report = $this->dashboard->report($this->overviewFilters($this->analytics->window($request->validated()), $request));

        return response()->json(['data' => $this->analytics->table($report, $this->dataset($request), $this->page($request))]);
    }

    public function reportExport(EditorialAnalyticsQuery $request, EventAnalyticsExport $export): BinaryFileResponse
    {
        return $export->download($this->overviewFilters($this->analytics->window($request->validated()), $request), $this->format($request), $this->dataset($request));
    }

    public function subject(EditorialAnalyticsQuery $request): JsonResponse
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

    public function subjectTable(EditorialAnalyticsQuery $request): JsonResponse
    {
        [$type, $id] = $request->subjectRoute();
        $this->analytics->subject($type, $id);
        $report = $this->dashboard->report($this->detailFilters($this->analytics->window($request->validated()), $request, $type, $id));

        return response()->json(['data' => $this->analytics->table($report, $this->dataset($request), $this->page($request))]);
    }

    public function subjectExport(EditorialAnalyticsQuery $request, EventAnalyticsExport $export): BinaryFileResponse
    {
        [$type, $id] = $request->subjectRoute();
        $this->analytics->subject($type, $id);

        return $export->download($this->detailFilters($this->analytics->window($request->validated()), $request, $type, $id), $this->format($request), $this->dataset($request));
    }

    /**
     * Gli eventi del filtro «Evento» e di «Guarda un altro evento»: prossimi
     * e passati, ricerca per titolo e locale, ristretti al locale e
     * all'organizzatore scelti (`EventAnalyticsPage::eventQuery`); `exclude`
     * toglie l'evento che si sta guardando (`otherEventForm`).
     */
    public function events(EditorialAnalyticsQuery $request): JsonResponse
    {
        $venue = $request->integerOrNull('venue');
        $organizer = $request->integerOrNull('organizer');
        $exclude = $request->integerOrNull('exclude');
        $events = $this->dashboard->events()
            ->when($venue !== null, fn (Builder $query) => $query->where('venue_id', $venue))
            ->when($organizer !== null, fn (Builder $query) => $query->where('organizer_id', $organizer))
            ->when($exclude !== null, fn (Builder $query) => $query->whereKeyNot($exclude));
        $search = $request->validated('q');

        return response()->json(['data' => $this->analytics->choices($events, is_string($search) ? $search : null)]);
    }

    /** I locali del filtro «Locale»: al massimo 50 per nome, con la ricerca. */
    public function venues(EditorialAnalyticsQuery $request): JsonResponse
    {
        return response()->json(['data' => $this->profileChoices($this->dashboard->venues(), $request)]);
    }

    /** Gli organizzatori del filtro «Organizzatore», come i locali. */
    public function organizers(EditorialAnalyticsQuery $request): JsonResponse
    {
        return response()->json(['data' => $this->profileChoices($this->dashboard->organizers(), $request)]);
    }

    /**
     * @template TModel of Venue|Organizer
     *
     * @param  Builder<TModel>  $query
     * @return list<array{id: int, name: string}>
     */
    private function profileChoices(Builder $query, EditorialAnalyticsQuery $request): array
    {
        $search = trim((string) ($request->validated('q') ?? ''));

        return $query->when($search !== '', fn (Builder $q) => $q->where('name', 'like', '%'.addcslashes($search, '%_\\').'%'))
            ->orderBy('name')->limit(self::CHOICES_LIMIT)->get(['id', 'name'])
            ->map(fn (Model $profile): array => ['id' => (int) $profile->getKey(), 'name' => (string) $profile->getAttribute('name')])
            ->values()->all();
    }

    /**
     * I filtri della pagina d'insieme: periodo, locale, organizzatore, evento
     * e canale, quelli del modulo del pannello.
     *
     * @param  array{period: string, from: ?string, until: string}  $window
     * @return array{from: ?string, until: string, venue: ?int, organizer: ?int, event: ?int, channel: mixed}
     */
    private function overviewFilters(array $window, EditorialAnalyticsQuery $request): array
    {
        return [
            'from' => $window['from'],
            'until' => $window['until'],
            'venue' => $request->integerOrNull('venue'),
            'organizer' => $request->integerOrNull('organizer'),
            'event' => $request->integerOrNull('event'),
            'channel' => $request->validated('channel'),
        ];
    }

    /**
     * I filtri della pagina di un soggetto: solo periodo e canale, più il
     * soggetto della rotta, come `AnalyticsDetailPage::reportFilters`.
     *
     * @param  array{period: string, from: ?string, until: string}  $window
     * @return array<string, mixed>
     */
    private function detailFilters(array $window, EditorialAnalyticsQuery $request, string $type, int $id): array
    {
        return ['from' => $window['from'], 'until' => $window['until'], 'channel' => $request->validated('channel'), $type => $id];
    }

    private function dataset(EditorialAnalyticsQuery $request): string
    {
        $dataset = $request->validated('dataset');

        return is_string($dataset) ? $dataset : 'events';
    }

    private function format(EditorialAnalyticsQuery $request): string
    {
        $format = $request->validated('format');

        return is_string($format) ? $format : 'csv';
    }

    private function page(EditorialAnalyticsQuery $request): int
    {
        return $request->integerOrNull('page') ?? 1;
    }
}

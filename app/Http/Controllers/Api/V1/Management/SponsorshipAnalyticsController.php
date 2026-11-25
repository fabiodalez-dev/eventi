<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Management;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Management\SponsorshipDeskRequest;
use App\Http\Resources\V1\Management\SponsorshipPayload;
use App\Models\Sponsorship;
use App\Models\SponsorshipClick;
use App\Services\Sponsorship\CampaignEconomics;
use App\Services\Sponsorship\SponsorshipAnalyticsReport;
use App\Services\Sponsorship\SponsorshipProgress;
use App\Services\Sponsorship\UnitProgress;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;

/**
 * Le statistiche delle sponsorizzazioni nell'app (P6, W-ST-08 e W-ST-09):
 * la pagina «Statistiche sponsorizzazioni» dei due pannelli e la scheda di
 * una campagna. Quali campagne si vedono lo decide `SponsorshipReport` con il
 * soggetto della richiesta: per un locale le campagne sui suoi eventi, per
 * un'organizzazione quelle sugli eventi che firma; mai quelle comprate da un
 * altro committente sullo stesso evento. Ogni membro le vede, come sul sito.
 */
final class SponsorshipAnalyticsController extends Controller
{
    /**
     * Il periodo (`days`: 7, 30, 90, 365, all, custom con `from` e `to`), i
     * totali, la serie giorno per giorno e, con `campaign`, l'avanzamento
     * dell'unità, le consegnate contro le attese e i conti economici.
     */
    public function show(SponsorshipDeskRequest $request, SponsorshipAnalyticsReport $reports): JsonResponse
    {
        $filters = $this->filters($request, $reports);
        $report = $reports->report($filters, 1);
        $tz = $reports->timezone();
        $rateReady = $report['impressions'] >= $report['min_ctr'];
        $selected = $report['selected'];

        return response()->json(['data' => [
            'timezone' => $tz,
            'periods' => collect((array) __('sponsorships.analytics.periods'))->map(fn ($label, $value): array => ['value' => (string) $value, 'label' => (string) $label])->values()->all(),
            'filters' => ['days' => $filters['days'], 'from' => $filters['from'], 'to' => $filters['to'], 'campaign' => $filters['campaign']],
            'window' => ['from' => $report['from']->toDateString(), 'to' => $report['to']->toDateString()],
            'totals' => [
                'impressions' => $report['impressions'],
                'clicks' => $report['clicks'],
                'rate' => $rateReady ? round(100 * $report['clicks'] / $report['impressions'], 2) : null,
                'rate_label' => $rateReady
                    ? number_format(100 * $report['clicks'] / $report['impressions'], 2, ',', '.').'%'
                    : __('sponsorship-panel.analytics.rate_pending', ['min' => UnitProgress::number($report['min_ctr'])]),
                'min_ctr' => $report['min_ctr'],
                'rate_threshold' => $rateReady ? null : __('sponsorship-panel.analytics.rate_threshold', ['min' => UnitProgress::number($report['min_ctr'])]),
                'explain' => SponsorshipPayload::explain($report['impressions'], $report['clicks'], $rateReady),
            ],
            'series' => array_map(fn (array $row): array => ['date' => $row['day']->toDateString(), 'impressions' => $row['impressions'], 'clicks' => $row['clicks']], $report['series']),
            'campaign' => $selected === null ? null : SponsorshipPayload::campaignOption($selected->loadMissing('event.venue', 'event.city', 'event.media')),
            'progress' => $report['progress'] === null ? null : SponsorshipPayload::progress($report['progress']),
            'progress_lead' => $selected === null ? null : __($report['grant_unit'] ? 'sponsorship-panel.analytics.progress_lead_grant' : 'sponsorship-panel.analytics.progress_lead_single'),
            'delivery' => $this->delivery($report['delivery']),
            'economics' => $report['economics'],
        ]]);
    }

    /** Il registro dei clic dello stesso periodo, 25 per pagina. */
    public function clicks(SponsorshipDeskRequest $request, SponsorshipAnalyticsReport $reports): JsonResponse
    {
        $filters = $this->filters($request, $reports);
        $records = $reports->report($filters, $request->integer('page', 1))['records'];
        $tz = $reports->timezone();

        return response()->json([
            'data' => $records->getCollection()->map(fn (SponsorshipClick $click): array => SponsorshipPayload::click($click, $tz))->values()->all(),
            'meta' => ['next_page' => $records->hasMorePages() ? $records->currentPage() + 1 : null, 'total' => $records->total()],
        ]);
    }

    /** Le campagne da scegliere nel filtro: le ultime cinquanta, cercando nel titolo o nel locale. */
    public function campaigns(SponsorshipDeskRequest $request, SponsorshipAnalyticsReport $reports): JsonResponse
    {
        return response()->json([
            'data' => $reports->campaignOptions((string) $request->validated('q', ''))
                ->map(fn (Sponsorship $campaign): array => SponsorshipPayload::campaignOption($campaign))->values()->all(),
        ]);
    }

    /**
     * Una campagna (la scheda «Campagna» di prima, ora per locali e
     * organizzazioni): evento, periodo, fase, avanzamento della sua unità,
     * consegnate e attese, conti economici.
     */
    public function campaign(SponsorshipDeskRequest $request, SponsorshipAnalyticsReport $reports, SponsorshipProgress $progress, CampaignEconomics $economics): JsonResponse
    {
        $campaign = $reports->campaign($request->routeId('campaign'));
        abort_if($campaign === null, 404);
        $unit = $progress->forRow($campaign);
        $phase = $campaign->phase();

        return response()->json(['data' => [
            ...SponsorshipPayload::campaignOption($campaign),
            'phase' => ['value' => $phase->value, 'label' => $phase->label(), 'color' => $phase->color()],
            'grant_unit' => $campaign->sponsorship_grant_id !== null,
            'progress_lead' => __($campaign->sponsorship_grant_id !== null ? 'sponsorship-panel.analytics.progress_lead_grant' : 'sponsorship-panel.analytics.progress_lead_single'),
            'impressions' => (int) $campaign->impressions,
            'clicks' => (int) $campaign->clicks,
            'progress' => SponsorshipPayload::progress($unit),
            'delivery' => $this->delivery($unit->hasGoal() ? $reports->delivery($unit, $campaign) : null),
            'economics' => $economics->for($campaign),
            'economics_help' => __('decision.cost_definition'),
        ]]);
    }

    /**
     * I filtri validati, con la campagna controllata prima: un id di un altro
     * soggetto risponde 404, come ogni altro id fuori dal soggetto.
     *
     * @return array{days: string, from: string|null, to: string|null, campaign: int|null}
     */
    private function filters(SponsorshipDeskRequest $request, SponsorshipAnalyticsReport $reports): array
    {
        $campaign = $request->integer('campaign') ?: null;
        abort_if($campaign !== null && $reports->campaign($campaign) === null, 404);
        $from = $request->validated('from');
        $to = $request->validated('to');

        return [
            'days' => (string) $request->validated('days', '30'),
            'from' => is_string($from) ? $from : null,
            'to' => is_string($to) ? $to : null,
            'campaign' => $campaign,
        ];
    }

    /**
     * @param  array{days: list<array{day: CarbonImmutable, delivered: int, expected: int}>, delivered: int, expected: int}|null  $delivery
     * @return array<string, mixed>|null
     */
    private function delivery(?array $delivery): ?array
    {
        if ($delivery === null) {
            return null;
        }

        return [
            'days' => array_map(fn (array $row): array => ['date' => $row['day']->toDateString(), 'delivered' => $row['delivered'], 'expected' => $row['expected']], $delivery['days']),
            'delivered' => $delivery['delivered'],
            'expected' => $delivery['expected'],
        ];
    }
}

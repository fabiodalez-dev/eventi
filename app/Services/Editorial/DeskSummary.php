<?php

declare(strict_types=1);

namespace App\Services\Editorial;

use App\DTOs\Editorial\DeskStat;
use App\DTOs\Editorial\DeskTarget;
use App\Enums\ApplicationStatus;
use App\Enums\EventStatus;
use App\Enums\VenueStatus;
use App\Models\City;
use App\Models\Sponsorship;
use App\Models\SponsorshipDailyStat;
use App\Models\SponsorshipGrant;
use App\Queries\EditorialDashboardQuery;
use App\Services\Analytics\ReturnMetrics;
use App\Support\Sponsorship\DashboardCity;
use Carbon\CarbonImmutable;
use Flowframe\Trend\Trend;
use Flowframe\Trend\TrendValue;
use Illuminate\Database\Eloquent\Builder;

/**
 * I riquadri del riepilogo della redazione (R-DB-02…09), per il pannello e
 * per l'app.
 *
 * Fino all'app i numeri, le frasi e i colori vivevano dentro i widget di
 * Filament (`EditorialQueueWidget`, `PublishingWidget`, `ContentQualityWidget`,
 * `ReturnRatesWidget`, `PromotionSummary`, `SponsorshipHealthWidget`,
 * `SponsorshipTrendWidget`). Adesso li calcola questa classe e i widget li
 * disegnano: il riquadro del pannello e quello del telefono sono la stessa
 * riga, con lo stesso numero, la stessa frase e la stessa destinazione.
 *
 * Le query restano quelle di sempre: le code e i controlli di qualità li dà
 * `EditorialDashboardQuery` (che serve anche i filtri degli elenchi), il
 * ritorno delle persone `ReturnMetrics`. Le iscrizioni non stanno qui perché
 * dipendono da `RegistrationState` (vedi `DeskRegistrations`).
 *
 * Chi vede quale sezione lo dice `DeskSummaryPayload`, con le stesse regole
 * dei `canView()` dei widget.
 */
final class DeskSummary
{
    public function __construct(private readonly ReturnMetrics $returns) {}

    /**
     * «Da lavorare adesso»: ciò che aspetta una decisione. Grigio quando non
     * aspetta niente, così l'occhio va solo dove serve.
     *
     * @return list<DeskStat>
     */
    public function queue(City $city): array
    {
        $dashboard = EditorialDashboardQuery::for($city);

        $events = $dashboard->pendingEvents()->count();
        $venues = $dashboard->pendingVenues()->count();
        $applications = $dashboard->pendingApplications()->count();
        $reports = $dashboard->openReports()->count();

        return [
            new DeskStat('pending_events', __('admin.dashboard.pending_events'), (string) $events, $events,
                trans_choice('pannelli-redazione.riepilogo.pending_events', $events), $events > 0 ? 'warning' : 'gray',
                DeskTarget::filtered('events', 'status', EventStatus::Pending->value)),
            new DeskStat('pending_venues', __('admin.dashboard.pending_venues'), (string) $venues, $venues,
                trans_choice('pannelli-redazione.riepilogo.pending_venues', $venues), $venues > 0 ? 'warning' : 'gray',
                DeskTarget::filtered('venues', 'status', VenueStatus::Pending->value)),
            new DeskStat('pending_applications', __('admin.dashboard.pending_applications'), (string) $applications, $applications,
                trans_choice('pannelli-redazione.riepilogo.pending_applications', $applications), $applications > 0 ? 'warning' : 'gray',
                DeskTarget::filtered('applications', 'status', ApplicationStatus::Pending->value)),
            new DeskStat('open_reports', __('admin.dashboard.open_reports'), (string) $reports, $reports,
                trans_choice('pannelli-redazione.riepilogo.open_reports', $reports), $reports > 0 ? 'danger' : 'gray',
                DeskTarget::filtered('reports', 'open', true)),
        ];
    }

    /**
     * «Pubblicazione»: l'atto della redazione (pubblicati oggi, in settimana)
     * accanto al cartellone della giornata (in programma oggi), che è un'altra
     * domanda e non apre un elenco.
     *
     * @return list<DeskStat>
     */
    public function publishing(City $city): array
    {
        $dashboard = EditorialDashboardQuery::for($city);

        $today = $dashboard->publishedToday()->count();
        $week = $dashboard->publishedThisWeek()->count();
        $scheduled = $dashboard->scheduledToday();
        $cancelled = $dashboard->cancelledEvents()->count();

        return [
            new DeskStat('published_today', __('admin.dashboard.published_today'), (string) $today, $today,
                __('admin.dashboard.descriptions.published_today', ['city' => (string) $city->name]), 'success',
                DeskTarget::filtered('events', 'published_today', true)),
            new DeskStat('published_this_week', __('admin.dashboard.published_this_week'), (string) $week, $week,
                __('admin.dashboard.descriptions.published_this_week'), null,
                DeskTarget::filtered('events', 'published_this_week', true)),
            new DeskStat('scheduled_today', __('admin.dashboard.scheduled_today'), (string) $scheduled, $scheduled,
                __('admin.dashboard.descriptions.scheduled_today')),
            new DeskStat('cancelled_events', __('admin.dashboard.cancelled_events'), (string) $cancelled, $cancelled,
                __('admin.dashboard.descriptions.cancelled_events'), 'danger',
                DeskTarget::filtered('events', 'status', EventStatus::Cancelled->value)),
        ];
    }

    /**
     * «Qualità dei contenuti»: candidati da guardare, nessuna azione automatica.
     *
     * @return list<DeskStat>
     */
    public function quality(City $city): array
    {
        $dashboard = EditorialDashboardQuery::for($city);

        $poster = $dashboard->eventsWithoutPoster()->count();
        $incomplete = $dashboard->incompleteEvents()->count();
        $duplicates = $dashboard->possibleDuplicates()->count();
        $inactive = $dashboard->inactiveVenues()->count();
        $imports = $dashboard->failedImports()->count();

        return [
            new DeskStat('missing_poster', __('admin.dashboard.missing_poster'), (string) $poster, $poster,
                __('admin.dashboard.descriptions.missing_poster'), null, DeskTarget::filtered('events', 'missing_poster', true)),
            new DeskStat('incomplete', __('admin.dashboard.incomplete'), (string) $incomplete, $incomplete,
                __('admin.dashboard.descriptions.incomplete'), 'warning', DeskTarget::filtered('events', 'incomplete', true)),
            new DeskStat('possible_duplicates', __('admin.dashboard.possible_duplicates'), (string) $duplicates, $duplicates,
                __('admin.dashboard.descriptions.possible_duplicates'), null, DeskTarget::filtered('events', 'possible_duplicates', true)),
            new DeskStat('inactive_venues', __('admin.dashboard.inactive_venues'), (string) $inactive, $inactive,
                __('admin.dashboard.descriptions.inactive_venues'), null, DeskTarget::filtered('venues', 'inactive', true)),
            new DeskStat('import_failures', __('admin.dashboard.import_failures'), (string) $imports, $imports,
                __('admin.dashboard.descriptions.import_failures'), 'danger', DeskTarget::filtered('import_sources', 'failed', true)),
        ];
    }

    /**
     * «Ritorno delle persone», con lo stesso confine di città degli altri
     * riquadri. Ogni numero porta la sua definizione.
     *
     * @return list<DeskStat>
     */
    public function returns(City $city): array
    {
        $metrics = $this->returns->summary($city);

        return [
            new DeskStat('returning', __('admin.dashboard.returning'), $metrics['returning']['rate'].'%', $metrics['returning']['rate'],
                __('admin.dashboard.descriptions.returning', [
                    'tornati' => $metrics['returning']['returned'],
                    'base' => $metrics['returning']['base'],
                    'giorni' => $metrics['window_days'],
                ])),
            new DeskStat('booked_people', __('admin.dashboard.booked_people'), (string) $metrics['booked'], $metrics['booked'],
                __('admin.dashboard.descriptions.booked_people', ['prenotazioni' => $metrics['bookings']])),
            new DeskStat('attendance', __('admin.dashboard.attendance'), $metrics['attendance'] === null ? '—' : $metrics['attendance'].'%', $metrics['attendance'],
                __('admin.dashboard.descriptions.attendance', ['ingressi' => $metrics['check_ins']])),
        ];
    }

    /**
     * «Abilitazioni e pagamenti» dei committenti della città: un locale o
     * un'organizzazione di quella città.
     *
     * @return list<DeskStat>
     */
    public function promotion(?City $city): array
    {
        $cityId = $city?->getKey() ?? 0;
        $inCity = fn (Builder $q): Builder => $q->where(fn (Builder $sponsor) => $sponsor
            ->whereHas('venue', fn (Builder $venue) => $venue->where('city_id', $cityId))
            ->orWhereHas('organizer', fn (Builder $organizer) => $organizer->where('city_id', $cityId)));

        $active = $inCity(SponsorshipGrant::active())->count();
        $expiring = $inCity(SponsorshipGrant::active())->where('ends_at', '<=', now()->addDays(7))->count();
        $income = $inCity(SponsorshipGrant::query())->where('complimentary', false)->where('paid_at', '<=', now())->sum('amount_cents') / 100;

        return [
            new DeskStat('active', __('promotions.active'), (string) $active, $active),
            new DeskStat('expiring', __('promotions.expiring'), (string) $expiring, $expiring),
            new DeskStat('income', __('promotions.income'), number_format($income, 2, ',', '.'), round((float) $income, 2)),
        ];
    }

    /**
     * Senza città (le pagine delle campagne quando non ne esiste ancora
     * nessuna) i numeri sono zero, come prima.
     *
     * Le quattro cose da sapere sulle campagne della città: in corso, in
     * scadenza, viste e aperture degli ultimi sette giorni (giorni della città).
     *
     * @return list<DeskStat>
     */
    public function sponsorships(?City $city): array
    {
        $adesso = CarbonImmutable::now('UTC');
        $cityId = $city?->getKey() ?? 0;
        $visibili = fn () => Sponsorship::query()->visible($adesso)->where('sponsorships.city_id', $cityId);

        $inCorso = $visibili()->count();
        $inScadenza = $visibili()->where('sponsorships.ends_at', '<=', $adesso->addDays(7))->count();

        $oggi = CarbonImmutable::now(DashboardCity::timezone($city));
        $settimana = SponsorshipDailyStat::query()
            ->whereIn('sponsorship_id', Sponsorship::withTrashed()->where('city_id', $cityId)->select('id'))
            ->where('day', '>=', $oggi->subDays(6)->toDateString())
            ->selectRaw('COALESCE(SUM(impressions), 0) as viste, COALESCE(SUM(clicks), 0) as aperture')
            ->first();

        $viste = (int) ($settimana?->getAttribute('viste') ?? 0);
        $aperture = (int) ($settimana?->getAttribute('aperture') ?? 0);

        /* `null` e non zero quando non c'è stata nessuna visualizzazione: un
           rapporto su zero è una domanda senza risposta, non «0%». */
        $rapporto = $viste > 0 ? round($aperture / $viste * 100, 1) : null;

        return [
            new DeskStat('running', __('sponsorships.widgets.running'), (string) $inCorso, $inCorso,
                __('sponsorships.widgets.running_lead'), $inCorso > 0 ? 'success' : 'gray'),
            new DeskStat('expiring', __('sponsorships.widgets.expiring'), (string) $inScadenza, $inScadenza,
                __('sponsorships.widgets.expiring_lead'), $inScadenza > 0 ? 'warning' : 'gray'),
            new DeskStat('week_impressions', __('sponsorships.widgets.week_impressions'), number_format($viste, 0, ',', '.'), $viste,
                __('sponsorships.widgets.week_lead')),
            new DeskStat('week_rate', __('sponsorships.widgets.week_rate'),
                $rapporto === null ? '—' : number_format($rapporto, 1, ',', '.').'%', $rapporto,
                $rapporto === null ? __('sponsorships.widgets.week_rate_none') : __('sponsorships.widgets.week_rate_lead', ['aperture' => $aperture]),
                match (true) {
                    $rapporto === null => 'gray',
                    $rapporto < 0.5 => 'danger',
                    $rapporto < 1.5 => 'warning',
                    default => 'success',
                }),
        ];
    }

    /**
     * L'andamento delle campagne della città negli ultimi trenta giorni,
     * giorno per giorno nel fuso della città: i giorni senza traffico sono
     * zeri, non buchi.
     *
     * @return array{days: list<string>, labels: list<string>, impressions: list<int>, clicks: list<int>}
     */
    public function sponsorshipTrend(?City $city): array
    {
        $oggi = CarbonImmutable::now(DashboardCity::timezone($city));
        $da = CarbonImmutable::parse($oggi->subDays(29)->toDateString());
        $a = CarbonImmutable::parse($oggi->toDateString())->endOfDay();
        $campagne = Sponsorship::withTrashed()->where('city_id', $city?->getKey() ?? 0)->select('id');

        $serie = static fn (): Trend => Trend::query(SponsorshipDailyStat::query()->whereIn('sponsorship_id', $campagne))
            ->dateColumn('day')
            ->between(start: $da, end: $a)
            ->perDay();

        $viste = $serie()->sum('impressions');
        $aperture = $serie()->sum('clicks');

        return [
            'days' => array_values($viste->map(static fn (TrendValue $v): string => CarbonImmutable::parse($v->date)->toDateString())->all()),
            /* Giorno e mese senza anno, come le etichette del grafico del pannello. */
            'labels' => array_values($viste->map(static fn (TrendValue $v): string => CarbonImmutable::parse($v->date)->translatedFormat('j M'))->all()),
            'impressions' => array_values($viste->map(static fn (TrendValue $v): int => (int) $v->aggregate)->all()),
            'clicks' => array_values($aperture->map(static fn (TrendValue $v): int => (int) $v->aggregate)->all()),
        ];
    }
}

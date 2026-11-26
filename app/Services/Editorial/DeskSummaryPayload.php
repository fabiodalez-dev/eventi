<?php

declare(strict_types=1);

namespace App\Services\Editorial;

use App\DTOs\Editorial\DeskStat;
use App\DTOs\Editorial\DeskTarget;
use App\Models\City;
use App\Models\SponsorshipGrant;
use App\Models\User;
use App\Support\Api\ApiDate;
use App\Support\Editorial\DeskRegistrations;
use Illuminate\Support\Facades\Gate;

/**
 * La risposta di `GET /api/v1/editorial/dashboard`: il riepilogo della
 * redazione (R-DB-01…09) come lo vede chi chiede, sezione per sezione,
 * nell'ordine del pannello (`Dashboard::getWidgets()`).
 *
 * Chi vede che cosa segue i `canView()` dei widget, non una regola nuova:
 *
 * - senza nessuna città non c'è nessun riquadro legato alla città, come nel
 *   pannello (§8.6); resta solo «Nuove iscrizioni»;
 * - «Nuove iscrizioni» a chi apre l'elenco degli utenti (`UserPolicy::viewAny`);
 * - «Abilitazioni e pagamenti» a chi vede le abilitazioni
 *   (`SponsorshipGrantPolicy::viewAny`, admin e super admin);
 * - le due sezioni delle sponsorizzazioni a tutta la redazione: nel pannello
 *   `SponsorshipHealthWidget` e `SponsorshipTrendWidget` guardano solo la
 *   città, e il moderatore le vede. L'app fa lo stesso (dubbio 2
 *   dell'inventario, da decidere a parte).
 *
 * «Social di oggi» (R-DB-10, pacchetto R9) e il calendario (R-EV-27, R14)
 * non sono qui: li portano i loro pacchetti.
 */
final class DeskSummaryPayload
{
    public function __construct(
        private readonly DeskSummary $summary,
        private readonly DeskRegistrations $registrations,
    ) {}

    /** @return array{city: array<string, mixed>|null, sections: list<array<string, mixed>>} */
    public function for(User $user, ?City $city): array
    {
        $gate = Gate::forUser($user);
        $sections = [];

        if ($city instanceof City) {
            $sections[] = self::stats('queue', __('admin.dashboard.queue_heading'), __('admin.dashboard.queue_description'), $this->summary->queue($city));
            $sections[] = self::stats('publishing', __('admin.dashboard.published_heading'), __('admin.dashboard.published_description'), $this->summary->publishing($city));
            $sections[] = self::stats('quality', __('admin.dashboard.quality_heading'), __('admin.dashboard.quality_description'), $this->summary->quality($city));
        }

        if ($gate->allows('viewAny', User::class)) {
            $sections[] = $this->registrations($user, $city);
        }

        if ($city instanceof City) {
            $sections[] = self::stats('returns', __('admin.dashboard.returns_heading'), __('admin.dashboard.returns_description'), $this->summary->returns($city));

            if ($gate->allows('viewAny', SponsorshipGrant::class)) {
                $sections[] = self::stats('promotion', __('promotions.title_city', ['city' => $city->name]), null, $this->summary->promotion($city));
            }

            $sections[] = self::stats('sponsorships', __('sponsorships.widgets.heading_city', ['city' => $city->name]), null, $this->summary->sponsorships($city));
            $sections[] = $this->trend($city);
        }

        return [
            'city' => $city instanceof City ? EditorialDeskPayload::city($city) : null,
            'sections' => $sections,
        ];
    }

    /**
     * @param  list<DeskStat>  $stats
     * @return array<string, mixed>
     */
    private static function stats(string $key, string $title, ?string $description, array $stats): array
    {
        return [
            'key' => $key,
            'title' => $title,
            'description' => $description,
            'stats' => array_map(static fn (DeskStat $stat): array => $stat->toArray(), $stats),
        ];
    }

    /**
     * Le ultime iscrizioni: la frase con i conteggi, i due collegamenti del
     * pannello («Solo in attesa» con il numero, «Tutti gli utenti») e le
     * righe. Una riga apre la modifica solo a chi può modificare quella
     * persona, come `recordUrl` del widget.
     *
     * @return array<string, mixed>
     */
    private function registrations(User $viewer, ?City $city): array
    {
        // L'ora dell'iscrizione nel fuso della città di lavoro, come il resto del riepilogo.
        $timezone = $city instanceof City ? (string) $city->timezone : config()->string('app.timezone');
        $recent = $this->registrations->recent();
        $pending = $this->registrations->pending();
        $gate = Gate::forUser($viewer);

        /* I ruoli servono a `UserPolicy::update` (non si modifica chi ha un rango più alto): caricati insieme. */
        $rows = $this->registrations->latest()->with('roles')->get()->map(function (User $user) use ($gate, $timezone): array {
            $update = $gate->allows('update', $user);
            $named = filled($user->name);

            return [
                'id' => (int) $user->getKey(),
                'name' => $named ? (string) $user->name : (string) $user->email,
                'detail' => $named ? (string) $user->email : __('users.no_name'),
                'state' => $this->registrations->state($user),
                'registered_at' => ApiDate::attribute($user, 'created_at', $timezone),
                'abilities' => ['update' => $update],
                'web_path' => $update ? 'admin/users/'.$user->getKey().'/edit' : null,
            ];
        })->values()->all();

        return [
            'key' => 'registrations',
            'title' => __('users.widget.heading'),
            'description' => $this->registrations->description($recent, $pending),
            'counts' => ['recent' => $recent, 'pending' => $pending],
            'links' => [
                ['key' => 'pending', 'label' => __('users.widget.show_pending'), 'badge' => $pending > 0 ? $pending : null, 'target' => DeskTarget::tab('users', 'pending')->toArray()],
                ['key' => 'all', 'label' => __('users.widget.all'), 'badge' => null, 'target' => (new DeskTarget('users'))->toArray()],
            ],
            'rows' => $rows,
            'empty' => ['title' => __('users.widget.empty'), 'description' => __('users.widget.empty_description')],
        ];
    }

    /** @return array<string, mixed> */
    private function trend(City $city): array
    {
        $trend = $this->summary->sponsorshipTrend($city);

        return [
            'key' => 'sponsorship_trend',
            'title' => __('sponsorships.widgets.trend_city', ['city' => $city->name]),
            'description' => __('sponsorships.widgets.trend_lead'),
            'chart' => [
                'days' => $trend['days'],
                'labels' => $trend['labels'],
                'series' => [
                    ['key' => 'impressions', 'label' => __('sponsorships.metrics.impressions'), 'values' => $trend['impressions']],
                    ['key' => 'clicks', 'label' => __('sponsorships.metrics.clicks'), 'values' => $trend['clicks']],
                ],
            ],
        ];
    }
}

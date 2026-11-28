<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Editorial;

use App\Enums\ApiErrorCode;
use App\Exceptions\ApiException;
use App\Http\Controllers\Api\V1\Management\SponsorshipAnalyticsController as ManagedAnalytics;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Management\SponsorshipDeskRequest;
use App\Services\Sponsorship\CampaignEconomics;
use App\Services\Sponsorship\SponsorshipAnalyticsReport;
use App\Services\Sponsorship\SponsorshipProgress;
use App\Support\Editorial\EditorialDesk;
use Illuminate\Http\JsonResponse;

/**
 * Le statistiche delle sponsorizzazioni della redazione (R-SP-15): la stessa
 * pagina dei pannelli, con tutte le campagne. Le risposte sono quelle
 * dell'API di gestione (stessa forma, stesse regole): quali campagne si
 * vedono lo decide `SponsorshipReport`, che nella redazione le dà tutte solo
 * ad admin e super admin. Il moderatore riceve 403, come nel pannello dove
 * la pagina non c'è.
 */
final class SponsorshipAnalyticsController extends Controller
{
    public function __construct(private readonly ManagedAnalytics $analytics) {}

    /** `days` (7, 30, 90, 365, all, custom con `from` e `to`), `campaign`. */
    public function show(SponsorshipDeskRequest $request, SponsorshipAnalyticsReport $reports): JsonResponse
    {
        $this->ensureAdmin();

        return $this->analytics->show($request, $reports);
    }

    /** Il registro dei clic dello stesso periodo, 25 per pagina. */
    public function clicks(SponsorshipDeskRequest $request, SponsorshipAnalyticsReport $reports): JsonResponse
    {
        $this->ensureAdmin();

        return $this->analytics->clicks($request, $reports);
    }

    /** Le campagne da scegliere nel filtro (`q`). */
    public function campaigns(SponsorshipDeskRequest $request, SponsorshipAnalyticsReport $reports): JsonResponse
    {
        $this->ensureAdmin();

        return $this->analytics->campaigns($request, $reports);
    }

    /** Una campagna: evento, fase, avanzamento della sua unità, consegnate e attese, conti economici. */
    public function campaign(SponsorshipDeskRequest $request, SponsorshipAnalyticsReport $reports, SponsorshipProgress $progress, CampaignEconomics $economics): JsonResponse
    {
        $this->ensureAdmin();

        return $this->analytics->campaign($request, $reports, $progress, $economics);
    }

    private function ensureAdmin(): void
    {
        if (! EditorialDesk::require()->isAdmin()) {
            throw new ApiException(ApiErrorCode::Forbidden);
        }
    }
}

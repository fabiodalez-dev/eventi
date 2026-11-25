<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Management;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Management\Analytics\AnalyticsQuery;
use App\Services\Management\ManagedAnalytics;
use App\Services\Management\ManagedContent;
use Illuminate\Http\JsonResponse;

/**
 * Le statistiche che stanno dentro la scheda di un evento:
 *
 * - `dates`: le date con visite, pulsanti toccati e salvataggi, le colonne
 *   dell'elenco delle date del pannello (W-ST-07);
 * - `occurrence`: il pannello laterale «Statistiche» di una data (W-DT-08),
 *   che chiede `update` sulla data come l'azione del pannello;
 * - `shareLinks`: i link brevi tracciati dell'evento, da condividere o da
 *   stampare come QR (W-PB-05).
 *
 * L'evento si cerca fra quelli del soggetto (`ManagedContent`), come la
 * scheda del pannello: un evento di un altro soggetto risponde 404.
 */
final class EventAnalyticsController extends Controller
{
    public function __construct(private readonly ManagedAnalytics $analytics) {}

    public function dates(AnalyticsQuery $request): JsonResponse
    {
        return response()->json(['data' => $this->analytics->dates(ManagedContent::current()->event((int) $request->route('event')))]);
    }

    public function occurrence(AnalyticsQuery $request): JsonResponse
    {
        $event = ManagedContent::current()->event((int) $request->route('event'));
        $date = $event->occurrences()->whereKey((int) $request->route('occurrence'))->firstOrFail();
        $event->loadMissing(['city', 'organizer']);
        $date->setRelation('event', $event);

        return response()->json(['data' => $this->analytics->occurrence($date)]);
    }

    public function shareLinks(AnalyticsQuery $request): JsonResponse
    {
        return response()->json(['data' => $this->analytics->shareLinks(ManagedContent::current()->event((int) $request->route('event')))]);
    }
}

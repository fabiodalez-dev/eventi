<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Editorial;

use App\Http\Controllers\Controller;
use App\Services\Editorial\DeskSummaryPayload;
use App\Support\Editorial\EditorialDesk;
use Illuminate\Http\JsonResponse;

/**
 * Il riepilogo della redazione nell'app (R-DB-01…09): le stesse sezioni e
 * gli stessi numeri della pagina iniziale di `/admin`, per la città di
 * lavoro (`?city=`, anche non attiva).
 */
final class DashboardController extends Controller
{
    /**
     * Il riepilogo della città di lavoro.
     *
     * Le sezioni nell'ordine del pannello: `queue`,
     * `publishing`, `quality`, `registrations`, `returns`, `promotion`,
     * `sponsorships`, `sponsorship_trend`. Ogni riquadro porta etichetta,
     * valore già scritto, frase, colore e, dove il pannello apre un elenco
     * filtrato, `target` (`area`, `tab`, `filters`, `web_path`). Una sezione
     * manca quando il pannello non la mostra a chi chiede.
     */
    public function show(DeskSummaryPayload $payload): JsonResponse
    {
        $desk = EditorialDesk::require();

        return response()->json(['data' => $payload->for($desk->user, $desk->city)]);
    }
}

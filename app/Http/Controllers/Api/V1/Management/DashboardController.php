<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Management;

use App\Enums\VenueStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Management\Dashboard\DashboardRequest;
use App\Services\Events\VenueProgramme;
use App\Services\Management\DashboardSummary;
use App\Support\Management\ManagementSubject;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

/**
 * Il riepilogo (P11, W-DB-01…08): la prima pagina dei due pannelli senza il
 * rapporto, che l'app legge da `GET S/analytics/summary` e dalle pagine delle
 * statistiche (P5).
 */
final class DashboardController extends Controller
{
    /**
     * Riepilogo del soggetto.
     *
     * Nell'ordine del pannello: l'avviso della mappa (solo locale), il
     * cartellone in tre numeri, le serate di oggi, i cinque prossimi eventi
     * (uno per evento, oggi escluso), le tre schede più viste negli ultimi
     * sette giorni, i due programmi in PDF (solo locale). Ogni card porta i
     * collegamenti che il pannello le mette, detti come permessi. Le ore sono
     * in UTC; `timezone` è il fuso con cui il pannello le scrive.
     */
    public function show(DashboardRequest $request, DashboardSummary $summary): JsonResponse
    {
        return response()->json(['data' => $summary->forApp(ManagementSubject::require())]);
    }

    /**
     * Programma della settimana in PDF (solo locale).
     *
     * Lo stesso foglio di `/locali/{slug}/programma.pdf` (W-DB-08, W-PB-02),
     * con lo stesso limite di richieste. `week` è un giorno qualsiasi della
     * settimana (`AAAA-MM-GG`); senza, questa settimana. Come la pagina
     * pubblica esiste solo per un locale approvato: altrimenti 404.
     */
    public function programme(DashboardRequest $request, VenueProgramme $programme): Response
    {
        $venue = ManagementSubject::require()->venue();
        abort_if($venue === null || $venue->status !== VenueStatus::Approved, 404);
        $city = $venue->city;
        $week = $request->validated('week');
        $monday = $programme->monday($city, is_string($week) ? $week : null);

        return response($programme->pdf($venue, $city, $monday, $programme->occurrences($venue, $city, $monday)), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$programme->filename($venue, $monday).'"',
        ]);
    }
}

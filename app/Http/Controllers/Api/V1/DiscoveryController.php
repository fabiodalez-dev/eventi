<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Enums\EventStatus;
use App\Http\Controllers\Api\V1\Concerns\InteractsWithApi;
use App\Http\Controllers\Controller;
use App\Models\City;
use App\Models\Venue;
use App\Queries\EventOccurrenceQuery;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Due letture che servono ad aprire un'applicazione senza mostrarla vuota.
 *
 * `/v1/areas` alimenta il filtro geografico: senza, un client dovrebbe
 * scaricare tutti i locali per sapere in quali comuni c'è qualcosa.
 *
 * `/v1/stats` porta le misure di §1 del piano — quelle con cui si risponde
 * alla domanda «quando un utente apre, trova davvero qualcosa da fare?».
 * Un'applicazione che sa dire «138 date in programma» prima ancora di
 * caricarle apre meglio di una che mostra un riquadro vuoto mentre attende.
 */
final class DiscoveryController extends Controller
{
    use InteractsWithApi;

    /**
     * I comuni in cui esiste almeno una data futura, con quante ne hanno.
     */
    public function areas(): JsonResponse
    {
        $city = $this->city();

        /* Si contano le OCCORRENZE e non i locali: a chi cerca interessa dove
           succede qualcosa, non dove esistono sale. Un comune con otto locali
           e nessuna data in programma non serve a nessun filtro. */
        $rows = DB::table('event_occurrences')
            ->join('events', 'events.id', '=', 'event_occurrences.event_id')
            ->join('venues', 'venues.id', '=', 'events.venue_id')
            ->where('events.city_id', $city->getKey())
            ->where('events.status', EventStatus::Published->value)
            ->whereNull('events.deleted_at')
            ->whereNull('venues.deleted_at')
            ->where('event_occurrences.starts_at', '>=', Carbon::now('UTC'))
            ->whereNotNull('venues.municipality')
            ->groupBy('venues.municipality')
            ->orderByDesc(DB::raw('COUNT(*)'))
            ->orderBy('venues.municipality')
            ->get(['venues.municipality as name', DB::raw('COUNT(*) as upcoming_count')]);

        $areas = $rows
            ->map(static fn (object $row): array => [
                'name' => (string) $row->name,
                'upcoming_count' => (int) $row->upcoming_count,
            ])
            ->all();

        return ApiResponse::collection($areas);
    }

    /**
     * Le misure di §1: non «quanti utenti abbiamo», ma «c'è qualcosa da fare».
     */
    public function stats(): JsonResponse
    {
        $city = $this->city();
        $query = EventOccurrenceQuery::for($city);

        return ApiResponse::item([
            'city' => $city->slug,
            'upcoming_occurrences' => $query->upcoming()->count(),
            'today' => EventOccurrenceQuery::for($city)->today()->count(),
            'tonight' => EventOccurrenceQuery::for($city)->tonight()->count(),
            'weekend' => EventOccurrenceQuery::for($city)->weekend()->count(),
            /* «% dei prossimi 14 giorni con almeno 3 date» è il primo indicatore
               di §1, ed è quello che dice se il catalogo regge davvero. */
            'covered_days_next_14' => $this->coveredDays($city),
            'active_venues' => Venue::query()
                ->inCity($city)
                ->whereHas('events.occurrences', static function ($occurrences): void {
                    $occurrences->where('starts_at', '>=', Carbon::now('UTC')->subDays(30));
                })
                ->count(),
        ]);
    }

    /**
     * Quanti dei prossimi quattordici giorni hanno almeno tre date.
     */
    private function coveredDays(City $city): int
    {
        $today = Carbon::now($city->timezone)->startOfDay();

        $counted = DB::table('event_occurrences')
            ->join('events', 'events.id', '=', 'event_occurrences.event_id')
            ->where('events.city_id', $city->getKey())
            ->where('events.status', EventStatus::Published->value)
            ->whereNull('events.deleted_at')
            ->whereBetween('event_occurrences.business_date', [
                $today->toDateString(),
                $today->copy()->addDays(13)->toDateString(),
            ])
            ->groupBy('event_occurrences.business_date')
            ->havingRaw('COUNT(*) >= ?', [3])
            ->get(['event_occurrences.business_date']);

        return $counted->count();
    }
}

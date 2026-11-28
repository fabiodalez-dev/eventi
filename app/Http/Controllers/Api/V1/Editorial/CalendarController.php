<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Editorial;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Editorial\Calendar\EditorialCalendarRequest;
use App\Services\Editorial\EditorialCalendar;
use App\Support\Editorial\EditorialDesk;
use Illuminate\Http\JsonResponse;

/**
 * Il calendario della redazione nell'app (R-EV-27), come la pagina
 * «Calendario» del pannello: le date di tutte le città nei giorni chiesti,
 * con il titolo «Evento · Locale», il colore dello stato dell'evento e
 * l'ora nel fuso della città dell'evento. L'app apre la scheda nativa
 * dell'evento (R2) con `event_id`.
 */
final class CalendarController extends Controller
{
    public function __construct(private readonly EditorialCalendar $calendar) {}

    /**
     * Le date fra `from` e `to` (compresi, nel fuso della città di lavoro).
     *
     * `meta.truncated` vero se le date erano più di
     * `EditorialCalendar::MAX_ROWS`.
     */
    public function index(EditorialCalendarRequest $request): JsonResponse
    {
        $desk = EditorialDesk::require();
        $timezone = $desk->timezone();
        $from = $request->fromDay($timezone);
        $to = $request->toDay($timezone);
        $result = $this->calendar->rows($desk->user, $from, $to, $timezone);

        return response()->json([
            'data' => $result['rows'],
            'meta' => [
                'from' => $from->toDateString(),
                'to' => $to->toDateString(),
                'timezone' => $timezone,
                'total' => count($result['rows']),
                'truncated' => $result['truncated'],
                'legend' => EditorialCalendar::legend(),
            ],
        ]);
    }
}

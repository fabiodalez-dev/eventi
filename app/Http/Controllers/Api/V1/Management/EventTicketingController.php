<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Management;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Management\EventTicketingRequest;
use App\Models\Booking;
use App\Models\EventOccurrence;
use App\Models\User;
use App\Services\Ticketing\TicketingService;
use Illuminate\Http\JsonResponse;

/**
 * Il riquadro «Ticketing inCittà» della modifica di un evento del locale
 * (`resources/views/filament/events/ticketing-links.blade.php`): se il locale
 * è abilitato dalla redazione e, per ogni data che la persona gestisce, lo
 * stato delle prenotazioni e i posti. Da ogni data l'app apre la sua scheda
 * della biglietteria (`/management/dates/{data}`), dove si configurano le
 * prenotazioni («Configura ticketing»).
 *
 * Come sul sito, il collaboratore vede il riquadro ma nessuna data: la
 * biglietteria è del referente (decisione 6). `dates_count` dice quante date
 * ha l'evento, per distinguere «aggiungi una data» da «non gestisci la
 * biglietteria».
 */
final class EventTicketingController extends Controller
{
    public function show(EventTicketingRequest $request, TicketingService $service): JsonResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);
        $record = $request->event();
        $record->loadMissing(['venue', 'city', 'media']);

        $dates = $record->occurrences()->with('venue')->orderBy('starts_at')->get()
            ->each(fn (EventOccurrence $date): EventOccurrence => $date->setRelation('event', $record));
        $managed = $dates->filter(fn (EventOccurrence $date): bool => $user->can('manage', [Booking::class, $date]))->values();
        $counts = $service->counts($managed);

        return response()->json(['data' => [
            'event_id' => $record->id,
            'title' => $record->title,
            'ticketing_enabled' => (bool) $record->venue?->ticketing_enabled,
            'timezone' => $record->city?->timezone,
            'dates_count' => $dates->count(),
            'dates' => $managed->map(fn (EventOccurrence $date): array => [
                'id' => $date->id,
                'starts_at' => $date->starts_at->toIso8601String(),
                'venue' => $date->effectiveVenue()?->name,
                'booking_enabled' => (bool) $date->booking_enabled,
                'ticketing_enabled' => (bool) $date->effectiveVenue()?->ticketing_enabled,
                'ended' => $service->hasEnded($date),
                'counts' => $counts[$date->id] ?? null,
            ])->all(),
        ]]);
    }
}

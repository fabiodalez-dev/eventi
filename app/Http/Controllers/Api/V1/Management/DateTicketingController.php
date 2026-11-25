<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Management;

use App\Actions\Ticketing\ConfigureBookings;
use App\Http\Controllers\Controller;
use App\Http\Requests\Ticketing\BookingSettingsRequest;
use App\Http\Requests\Ticketing\DateBookingCancelRequest;
use App\Http\Resources\V1\Management\ManagedBookingResource;
use App\Models\Booking;
use App\Models\BookingMessage;
use App\Models\EventOccurrence;
use App\Models\User;
use App\Services\Ticketing\MobileManagement;
use App\Services\Ticketing\TicketingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * La biglietteria di una data che sul sito sta in `/gestione-biglietti/{data}`
 * e nell'app mancava: impostazioni e domande, annullamento a nome del
 * locale, storico dei messaggi. Le rotte restano sulla data, come quelle di
 * prima: il permesso è `BookingPolicy::manage`, che guarda già il locale
 * attivo e l'organizzazione che firma l'evento. Il collaboratore del locale
 * non gestisce la biglietteria (decisione 6), il personale d'ingresso
 * nemmeno.
 */
final class DateTicketingController extends Controller
{
    /**
     * Salva le impostazioni delle prenotazioni e risponde con la scheda della
     * data aggiornata. È una sostituzione come il modulo del sito: le tre ore
     * che mancano tornano vuote. `booking_instructions`, `booking_fields` e
     * `booking_questions` che mancano restano come sono.
     */
    public function settings(BookingSettingsRequest $request, EventOccurrence $occurrence, ConfigureBookings $configure, MobileManagement $management): JsonResponse
    {
        $user = $this->user($request);
        $configure($occurrence, $request->validated(), $user);
        $fresh = $occurrence->fresh(['event.media', 'event.venue', 'event.city', 'venue']) ?? $occurrence;

        return response()->json(['data' => $management->date($fresh, $user, true)]);
    }

    /**
     * Annulla una prenotazione intera o un solo biglietto (`ticket_id`) a nome
     * del locale, con un motivo facoltativo che arriva a chi ha prenotato.
     * Come dal sito valgono anche oltre il termine di annullamento e per le
     * prenotazioni prese al banco. Il permesso sulla data lo chiede la
     * richiesta, prima di guardare la prenotazione; la prenotazione si cerca
     * poi solo fra quelle della data (404 altrimenti).
     */
    public function cancel(DateBookingCancelRequest $request, EventOccurrence $occurrence, int $booking, TicketingService $service): JsonResponse
    {
        $booking = $occurrence->bookings()->whereKey($booking)->firstOrFail();
        $ticket = $request->filled('ticket_id') ? $request->integer('ticket_id') : null;
        $booking = $service->cancel($booking, $this->user($request), $ticket, true, $request->validated('reason'));
        $booking->load(['user', 'tickets']);
        $booking->setRelation('occurrence', $occurrence);

        return response()->json(['data' => ManagedBookingResource::toArray($booking)]);
    }

    /**
     * Gli ultimi dieci messaggi scritti a chi ha prenotato, i più recenti
     * prima: lo storico che la scheda della data mostra sotto il modulo. Di
     * chi li ha mandati solo il nome, come sul sito.
     */
    public function messages(Request $request, EventOccurrence $occurrence): JsonResponse
    {
        Gate::authorize('manage', [Booking::class, $occurrence]);
        $sentToday = $occurrence->bookingMessages()->where('created_at', '>=', now()->subDay())->count();

        return response()->json([
            'data' => $occurrence->bookingMessages()->with('sender')->latest('id')->limit(10)->get()
                ->map(fn (BookingMessage $message): array => [
                    'id' => $message->id,
                    'subject' => $message->subject,
                    'body' => $message->body,
                    'include_waitlisted' => $message->include_waitlisted,
                    'recipients_count' => (int) $message->recipients_count,
                    'sender' => $message->sender?->name,
                    'created_at' => $message->created_at?->toIso8601String(),
                ])->values(),
            'meta' => ['daily_limit' => config()->integer('ticketing.messages.daily_limit'), 'sent_last_24h' => $sentToday],
        ]);
    }

    private function user(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        return $user;
    }
}

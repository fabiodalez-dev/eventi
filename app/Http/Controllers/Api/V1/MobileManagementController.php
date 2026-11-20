<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Actions\Account\AssignCheckinStaff;
use App\Enums\AdmissionStatus;
use App\Enums\BookingStatus;
use App\Http\Controllers\Controller;
use App\Http\Controllers\TicketingController;
use App\Http\Requests\Ticketing\DecisionDetailsRequest;
use App\Http\Requests\Ticketing\MobileManagementRequest;
use App\Http\Requests\Ticketing\StaffRequest;
use App\Http\Resources\V1\SponsorshipProgressResource;
use App\Models\AdmissionTicket;
use App\Models\Booking;
use App\Models\EventOccurrence;
use App\Services\Sponsorship\CampaignEconomics;
use App\Services\Ticketing\MobileManagement;
use App\Services\Ticketing\TicketingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

final class MobileManagementController extends Controller
{
    public function index(MobileManagementRequest $request, MobileManagement $management, TicketingService $service): JsonResponse
    {
        $past = $request->validated('period') === 'past';
        $search = $request->validated('q');
        $dates = $management->dates($request->user())->with(['event.venue', 'event.media', 'venue'])
            ->whereRaw('COALESCE(effective_ends_at, ends_at, starts_at) '.($past ? '<' : '>=').' ?', [now()])
            ->when($search, fn ($q) => $q->whereHas('event', fn ($e) => $e->where('title', 'like', '%'.addcslashes($search, '%_\\').'%')))
            ->orderBy('starts_at', $past ? 'desc' : 'asc')->orderBy('id')->paginate(30);

        $counts = $service->counts($dates->getCollection());

        return response()->json(['data' => $dates->getCollection()->map(fn ($date) => $management->date($date, $request->user(), false, $counts[$date->id] ?? null)), 'meta' => ['next_page' => $dates->hasMorePages() ? $dates->currentPage() + 1 : null]]);
    }

    public function show(Request $request, EventOccurrence $occurrence, MobileManagement $management): JsonResponse
    {
        Gate::authorize('checkIn', [Booking::class, $occurrence]);
        $occurrence->loadMissing(['event.media', 'event.venue', 'venue']);

        return response()->json(['data' => $management->date($occurrence, $request->user(), true)]);
    }

    /**
     * Prenotazioni di una data.
     *
     * Per chi gestisce la data: le stesse della pagina
     * `/gestione-biglietti/{data}`, con gli stessi filtri (`status`, `q`) e lo
     * stesso ordine (le più recenti prima), trenta per pagina.
     *
     * Il codice di un biglietto compare solo finché è valido: serve a
     * registrare l'ingresso dalla riga (`POST /ticketing/{data}/check-in`), come
     * il pulsante della pagina web. Chi può solo fare il controllo ingressi non
     * arriva qui: ha l'elenco di `manifest`, con le impronte e mai i codici.
     */
    public function bookings(Request $request, EventOccurrence $occurrence): JsonResponse
    {
        Gate::authorize('manage', [Booking::class, $occurrence]);

        $filters = $request->validate([
            'status' => ['nullable', 'string', Rule::in(TicketingController::BOOKING_FILTERS)],
            'q' => ['nullable', 'string', 'max:120'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);
        $status = $filters['status'] ?? null;
        $search = isset($filters['q']) && trim((string) $filters['q']) !== '' ? '%'.addcslashes(trim((string) $filters['q']), '%_\\').'%' : null;

        $bookings = Booking::query()->where('occurrence_id', $occurrence->id)->with(['user', 'tickets'])
            ->when($search !== null, fn ($q) => $q->where(fn ($q) => $q->whereHas('tickets', fn ($q) => $q->where('attendee_name', 'like', $search))
                ->orWhereHas('user', fn ($q) => $q->where('email', 'like', $search))))
            ->when($status === 'checked_in', fn ($q) => $q->whereHas('tickets', fn ($q) => $q->where('status', AdmissionStatus::CheckedIn->value)))
            ->when($status !== null && $status !== 'checked_in', fn ($q) => $q->where('status', $status))
            ->latest('id')->paginate(30);

        $byStatus = Booking::query()->where('occurrence_id', $occurrence->id)->selectRaw('status, COUNT(*) as total')->groupBy('status')->toBase()->pluck('total', 'status');

        return response()->json([
            'data' => $bookings->getCollection()->map(function (Booking $booking) use ($occurrence): array {
                $booking->setRelation('occurrence', $occurrence);

                return [
                    'id' => $booking->id,
                    'status' => $booking->status->value,
                    'created_at' => $booking->created_at?->toIso8601String(),
                    'staff_booking' => $booking->isStaffBooking(),
                    'email' => $booking->contactEmail(),
                    'booker' => $booking->booker_data,
                    'answers' => array_map(fn (array $answer): array => ['question' => (string) $answer['question'], 'answer' => (string) $answer['answer']], (array) ($booking->booking_answers ?? [])),
                    'privacy_accepted_at' => $booking->privacy_accepted_at?->toIso8601String(),
                    'tickets_count' => $booking->tickets->count(),
                    'tickets' => $booking->tickets->map(fn (AdmissionTicket $ticket): array => [
                        'id' => $ticket->id,
                        'attendee_name' => $ticket->attendee_name,
                        'first_name' => $ticket->first_name,
                        'last_name' => $ticket->last_name,
                        'status' => $ticket->displayStatus()->value,
                        'checked_in_at' => $ticket->checked_in_at?->toIso8601String(),
                        'code' => $ticket->displayStatus() === AdmissionStatus::Valid ? $ticket->code : null,
                    ])->values()->all(),
                ];
            })->values(),
            'meta' => [
                'next_page' => $bookings->hasMorePages() ? $bookings->currentPage() + 1 : null,
                'status' => $status,
                'counts' => [
                    'all' => (int) $byStatus->sum(),
                    'confirmed' => (int) ($byStatus[BookingStatus::Confirmed->value] ?? 0),
                    'checked_in' => Booking::query()->where('occurrence_id', $occurrence->id)->whereHas('tickets', fn ($q) => $q->where('status', AdmissionStatus::CheckedIn->value))->count(),
                    'waitlisted' => (int) ($byStatus[BookingStatus::Waitlisted->value] ?? 0),
                    'cancelled' => (int) ($byStatus[BookingStatus::Cancelled->value] ?? 0),
                ],
            ],
        ]);
    }

    public function staff(StaffRequest $request, EventOccurrence $occurrence, AssignCheckinStaff $assign): JsonResponse
    {
        $assign($occurrence, $request->validated('email'), $request->boolean('remove'), $request->user());

        return response()->json(['data' => ['message' => __('decision.staff_saved')]]);
    }

    public function details(DecisionDetailsRequest $request, EventOccurrence $occurrence, MobileManagement $management): JsonResponse
    {
        $management->updateDetails($occurrence, $request->user(), $request->validated());

        return response()->json(['data' => ['message' => __('ticketing.updated')]]);
    }

    public function campaigns(MobileManagementRequest $request, MobileManagement $management): JsonResponse
    {
        $campaigns = $management->campaigns($request->user())->with(['event', 'grant', 'city'])->latest('id')->paginate(30);
        /* L'avanzamento dell'unità di consegna (D70): solo campi aggiunti. */
        $progress = SponsorshipProgressResource::many($campaigns->getCollection());

        return response()->json(['data' => $campaigns->getCollection()->map(fn ($c) => ['id' => $c->id, 'title' => $c->event?->title, 'starts_at' => $c->starts_at->toIso8601String(), 'ends_at' => $c->ends_at->toIso8601String(), ...$progress[$c->id]]), 'meta' => ['next_page' => $campaigns->hasMorePages() ? $campaigns->currentPage() + 1 : null]]);
    }

    public function economics(Request $request, int $campaign, MobileManagement $management, CampaignEconomics $economics): JsonResponse
    {
        $record = $management->campaigns($request->user())->with(['event', 'grant', 'city'])->findOrFail($campaign);

        return response()->json(['data' => ['id' => $record->id, 'title' => $record->event?->title, 'starts_at' => $record->starts_at->toIso8601String(), 'ends_at' => $record->ends_at->toIso8601String(), 'economics' => $economics->for($record), ...SponsorshipProgressResource::toArray($record)]]);
    }
}

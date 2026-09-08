<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\DTOs\PageMeta;
use App\Enums\EventStatus;
use App\Enums\UserRole;
use App\Http\Requests\Ticketing\CancelRequest;
use App\Http\Requests\Ticketing\CheckInRequest;
use App\Http\Requests\Ticketing\ManageRequest;
use App\Http\Requests\Ticketing\ReserveRequest;
use App\Http\Resources\V1\BookingResource;
use App\Models\AdmissionTicket;
use App\Models\Booking;
use App\Models\EventOccurrence;
use App\Notifications\BookingChanged;
use App\Services\Ticketing\BookingForm;
use App\Services\Ticketing\TicketingService;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\Carbon;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class TicketingController extends Controller
{
    public function availability(EventOccurrence $occurrence, TicketingService $service): JsonResponse
    {
        abort_unless($occurrence->event?->status === EventStatus::Published && ! $occurrence->effectiveVenue()?->status?->isProvvedimento(), 404);

        return response()->json(['data' => $service->availability($occurrence)])->header('Cache-Control', 'no-store');
    }

    public function index(Request $request): JsonResponse|View
    {
        $bookings = Booking::query()->where('user_id', $request->user()->id)
            ->with(['tickets', 'occurrence.event.venue'])->orderByRaw("CASE WHEN status = 'cancelled' THEN 1 ELSE 0 END")->latest('id')->paginate(30);

        return $request->is('api/*')
            ? response()->json(['data' => $bookings->getCollection()->map(fn ($b) => BookingResource::toArray($b)), 'meta' => ['next_page' => $bookings->hasMorePages() ? $bookings->currentPage() + 1 : null]])
            : view('ticketing.index', ['bookings' => $bookings, 'meta' => $this->meta('title')]);
    }

    public function show(Booking $booking): View
    {
        Gate::authorize('view', $booking);

        return view('ticketing.index', [
            'bookings' => Booking::query()->whereKey($booking->id)->with(['tickets', 'occurrence.event.venue'])->paginate(1),
            'meta' => $this->meta('manage_booking'),
        ]);
    }

    public function create(Request $request, EventOccurrence $occurrence, TicketingService $service): View|RedirectResponse
    {
        abort_unless($occurrence->event?->status === EventStatus::Published && ! $occurrence->effectiveVenue()?->status?->isProvvedimento(), 404);

        if ($booking = $service->activeBooking($request->user(), $occurrence)) {
            return redirect()->route('tickets.show', $booking);
        }

        return view('ticketing.reserve', ['date' => $occurrence, 'availability' => $service->availability($occurrence), 'meta' => $this->meta('reserve')]);
    }

    public function store(ReserveRequest $request, EventOccurrence $occurrence, TicketingService $service): JsonResponse|RedirectResponse
    {
        $booking = $service->reserve($request->user(), $occurrence, $request->validated('attendees'), $request->validated('request_key'), $request->boolean('waitlist'), $request->validated('booker', []));

        return $request->is('api/*')
            ? response()->json(['data' => BookingResource::toArray($booking)], 201)
            : redirect()->route('tickets.index')->with('status', __('ticketing.mail.'.$booking->status->value));
    }

    public function cancel(CancelRequest $request, Booking $booking, TicketingService $service): JsonResponse|RedirectResponse
    {
        $staff = $request->routeIs('ticketing.manage.*', 'api.ticketing.manage.*');
        $staff ? Gate::authorize('manage', [Booking::class, $booking->occurrence]) : Gate::authorize('view', $booking);
        $booking = $service->cancel($booking, $request->user(), $request->filled('ticket_id') ? $request->integer('ticket_id') : null, $staff, $request->validated('reason'));

        return $request->is('api/*') ? response()->json(['data' => BookingResource::toArray($booking)])
            : back()->with('status', __('ticketing.mail.cancelled'));
    }

    public function pdf(Request $request, AdmissionTicket $ticket): Response
    {
        Gate::authorize('view', $ticket->booking);

        return Pdf::loadView('ticketing.pdf', ['ticket' => $ticket, 'booking' => BookingResource::toArray($ticket->booking)])
            ->setOption('isRemoteEnabled', false)->download('inCitta-ticket-'.$ticket->id.'.pdf');
    }

    public function resend(Request $request, Booking $booking): JsonResponse|RedirectResponse
    {
        Gate::authorize('view', $booking);
        $request->user()->notify(new BookingChanged($booking->id, $booking->status->value));

        return $request->is('api/*') ? response()->json(['data' => ['message' => __('ticketing.email_queued')]])
            : back()->with('status', __('ticketing.email_queued'));
    }

    public function dashboard(Request $request): View
    {
        $user = $request->user();
        $admin = $user->hasAnyRole([UserRole::Admin->value, UserRole::SuperAdmin->value]);
        abort_unless($admin || $user->ownedVenues()->exists() || $user->managedOrganizers()->exists(), 403);
        $dates = EventOccurrence::query()->when(! $admin, fn ($query) => $query->where(fn ($allowed) => $allowed
            ->whereIn('venue_id', $user->ownedVenues()->select('venues.id'))
            ->orWhere(fn ($fallback) => $fallback->whereNull('venue_id')->whereHas('event', fn ($event) => $event->whereIn('venue_id', $user->ownedVenues()->select('venues.id'))))
            ->orWhereHas('event', fn ($event) => $event->whereIn('organizer_id', $user->managedOrganizers()->select('organizers.id')))))
            ->with('event.venue')->orderByDesc('starts_at')->paginate(30);

        return view('ticketing.dashboard', ['dates' => $dates, 'meta' => $this->meta('manage')]);
    }

    public function manage(ManageRequest $request, EventOccurrence $occurrence, TicketingService $service): View
    {
        Gate::authorize('manage', [Booking::class, $occurrence]);
        $search = $request->validated('q');
        $bookings = Booking::query()->where('occurrence_id', $occurrence->id)->with(['user', 'tickets'])
            ->when($search, fn ($q) => $q->where(fn ($q) => $q->whereHas('tickets', fn ($q) => $q->where('attendee_name', 'like', '%'.$search.'%'))->orWhereHas('user', fn ($q) => $q->where('email', 'like', '%'.$search.'%'))))
            ->latest('id')->paginate(30)->withQueryString();

        return view('ticketing.manage', ['date' => $occurrence, 'bookings' => $bookings, 'availability' => $service->availability($occurrence), 'statistics' => $service->statistics($occurrence), 'meta' => $this->meta('manage')]);
    }

    public function configure(ManageRequest $request, EventOccurrence $occurrence, TicketingService $service): RedirectResponse
    {
        Gate::authorize('manage', [Booking::class, $occurrence]);
        $settings = $request->validated();
        foreach (['booking_opens_at', 'booking_closes_at', 'cancellation_closes_at'] as $field) {
            $settings[$field] = empty($settings[$field]) ? null : Carbon::parse($settings[$field], $occurrence->event->city->timezone)->utc();
        }
        $service->configure($occurrence, $settings, $request->user());

        return back()->with('status', __('ticketing.updated'));
    }

    public function checkIn(CheckInRequest $request, EventOccurrence $occurrence, TicketingService $service): JsonResponse|RedirectResponse
    {
        Gate::authorize('manage', [Booking::class, $occurrence]);
        $ticket = $service->checkIn($occurrence, $request->validated('code'), $request->user());

        return $request->expectsJson() ? response()->json(['data' => ['id' => $ticket->id, 'attendee_name' => $ticket->attendee_name, 'status' => $ticket->status->value]])
            : back()->with('status', __('ticketing.checkin_success', ['name' => $ticket->attendee_name]));
    }

    public function export(Request $request, EventOccurrence $occurrence): StreamedResponse
    {
        Gate::authorize('manage', [Booking::class, $occurrence]);
        activity('ticketing')->causedBy($request->user())->performedOn($occurrence)->log('participants_exported');

        return response()->streamDownload(function () use ($occurrence): void {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            $bookerFields = ['first_name', 'last_name', ...BookingForm::FIELDS];
            fputcsv($out, [__('ticketing.ticket'), __('ticketing.name'), __('ticketing.email'), __('ticketing.status'), __('ticketing.checked_in_at'), __('ticketing.fields.first_name'), __('ticketing.fields.last_name'), ...array_map(fn ($field) => __('ticketing.booker').' - '.__('ticketing.fields.'.$field), $bookerFields), __('ticketing.privacy_record')], ';', '"', '');
            AdmissionTicket::query()->whereHas('booking', fn ($q) => $q->where('occurrence_id', $occurrence->id))
                ->with('booking.user')->chunkById(200, function ($tickets) use ($out, $bookerFields): void {
                    foreach ($tickets as $ticket) {
                        $safe = fn ($value) => preg_match('/^[\s]*[=+@\-\t\r\n]/u', (string) $value) ? "'".$value : $value;
                        fputcsv($out, [$ticket->id, $safe($ticket->attendee_name), $safe($ticket->booking->user?->email), __('ticketing.statuses.'.$ticket->status->value), $ticket->checked_in_at?->toIso8601String(), $safe($ticket->first_name), $safe($ticket->last_name), ...array_map(fn ($field) => $safe($ticket->booking->booker_data[$field] ?? ''), $bookerFields), $ticket->booking->privacy_accepted_at?->toIso8601String()], ';', '"', '');
                    }
                });
            fclose($out);
        }, 'partecipanti-'.$occurrence->id.'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    private function meta(string $key): PageMeta
    {
        return new PageMeta(title: __('ticketing.'.$key), heading: __('ticketing.'.$key), indexable: false);
    }
}

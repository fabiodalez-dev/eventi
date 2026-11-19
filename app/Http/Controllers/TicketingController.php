<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\Account\AssignCheckinStaff;
use App\DTOs\PageMeta;
use App\Enums\AdmissionStatus;
use App\Enums\BookingStatus;
use App\Enums\EventStatus;
use App\Enums\UserRole;
use App\Http\Requests\Ticketing\CancelRequest;
use App\Http\Requests\Ticketing\CheckInRequest;
use App\Http\Requests\Ticketing\GuestBookingRequest;
use App\Http\Requests\Ticketing\ManageRequest;
use App\Http\Requests\Ticketing\MessageAttendeesRequest;
use App\Http\Requests\Ticketing\ReserveRequest;
use App\Http\Requests\Ticketing\StaffRequest;
use App\Http\Resources\V1\BookingResource;
use App\Models\AdmissionTicket;
use App\Models\Booking;
use App\Models\EventOccurrence;
use App\Models\User;
use App\Notifications\BookingChanged;
use App\Services\Ticketing\BookingForm;
use App\Services\Ticketing\MobileManagement;
use App\Services\Ticketing\TicketingService;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class TicketingController extends Controller
{
    /** Quello che serve a disegnare una prenotazione: data, evento, locandina, sede. */
    private const BOOKING_RELATIONS = ['tickets', 'occurrence.venue', 'occurrence.event.venue', 'occurrence.event.city', 'occurrence.event.media'];

    /** I filtri dell'elenco prenotazioni nella gestione della data (`?status=`). */
    public const BOOKING_FILTERS = ['confirmed', 'checked_in', 'waitlisted', 'cancelled'];

    public function availability(EventOccurrence $occurrence, TicketingService $service): JsonResponse
    {
        abort_unless($occurrence->event?->status === EventStatus::Published && ! $occurrence->effectiveVenue()?->status?->isProvvedimento(), 404);

        return response()->json(['data' => $service->availability($occurrence)])->header('Cache-Control', 'no-store');
    }

    public function index(Request $request, TicketingService $service): JsonResponse|View
    {
        $noShows = $service->noShowCount($request->user());
        if ($request->is('api/*')) {
            $bookings = Booking::query()->where('user_id', $request->user()->id)
                ->with(['tickets', 'occurrence.event.venue', 'occurrence.event.media'])->orderByRaw("CASE WHEN status = 'cancelled' THEN 1 ELSE 0 END")->latest('id')->paginate(30);

            return response()->json(['data' => $bookings->getCollection()->map(fn ($b) => BookingResource::toArray($b)), 'meta' => ['next_page' => $bookings->hasMorePages() ? $bookings->currentPage() + 1 : null, 'no_shows' => $noShows]]);
        }

        /* Due elenchi: le prenotazioni che contano adesso, in ordine di data,
           e lo storico (date passate e annullate), dal più recente. Una data
           in corso resta fra le prossime finché non finisce: il QR serve
           proprio lì. */
        $upcoming = fn ($query) => $query->whereRaw('COALESCE(effective_ends_at, ends_at, starts_at) >= ?', [now()]);
        $startsAt = EventOccurrence::withTrashed()->select('starts_at')->whereColumn('event_occurrences.id', 'bookings.occurrence_id');
        $base = fn () => Booking::query()->where('user_id', $request->user()->id)->with(self::BOOKING_RELATIONS);
        $current = $base()->where('status', '!=', BookingStatus::Cancelled->value)->whereHas('occurrence', $upcoming)
            ->orderBy($startsAt)->orderBy('id')->limit(50)->get();
        $past = $base()->where(fn ($query) => $query->where('status', BookingStatus::Cancelled->value)->orWhereDoesntHave('occurrence', $upcoming))
            ->orderByDesc($startsAt)->latest('id')->paginate(20);
        self::linkTickets($current->concat($past->getCollection()));

        return view('ticketing.index', ['upcoming' => $current, 'past' => $past, 'noShows' => $noShows, 'detail' => false, 'meta' => $this->meta('title')]);
    }

    public function show(Booking $booking): View
    {
        Gate::authorize('view', $booking);
        $booking->load(self::BOOKING_RELATIONS);
        self::linkTickets([$booking]);

        return view('ticketing.index', [
            'booking' => $booking,
            'detail' => true,
            'meta' => new PageMeta(title: __('ticketing.manage_booking'), heading: __('ticketing.booking_title'), indexable: false),
        ]);
    }

    public function create(Request $request, EventOccurrence $occurrence, TicketingService $service): View|RedirectResponse
    {
        abort_unless($occurrence->event?->status === EventStatus::Published && ! $occurrence->effectiveVenue()?->status?->isProvvedimento(), 404);

        if ($booking = $service->activeBooking($request->user(), $occurrence)) {
            return redirect()->route('tickets.show', $booking);
        }
        $occurrence->loadMissing(['event.media', 'event.city', 'event.venue', 'venue']);

        return view('ticketing.reserve', [
            'date' => $occurrence,
            'availability' => $service->availability($occurrence),
            'prefill' => $this->prefill($request->user()),
            'meta' => $this->meta('reserve'),
        ]);
    }

    public function store(ReserveRequest $request, EventOccurrence $occurrence, TicketingService $service): JsonResponse|RedirectResponse
    {
        $booking = $service->reserve($request->user(), $occurrence, $request->validated('attendees'), $request->validated('request_key'), $request->boolean('waitlist'), $request->validated('booker', []), $request->validated('answers', []));

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
            : redirect()->route($staff ? 'ticketing.manage.show' : 'tickets.show', $staff ? $booking->occurrence : $booking)->with('status', __('ticketing.mail.cancelled'));
    }

    /**
     * «Confermo il posto» di chi è stato promosso dalla lista d'attesa.
     * Senza questo gesto il posto scade e torna a chi è in coda.
     */
    public function confirmPromotion(Request $request, Booking $booking, TicketingService $service): JsonResponse|RedirectResponse
    {
        Gate::authorize('view', $booking);
        $booking = $service->confirmPromotion($booking, $request->user());

        return $request->is('api/*') ? response()->json(['data' => BookingResource::toArray($booking)])
            : back()->with('status', __('ticketing.promotion_confirmed'));
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
            : redirect()->route('tickets.show', $booking)->with('status', __('ticketing.email_queued'));
    }

    public function dashboard(Request $request, TicketingService $service): View
    {
        $filters = $request->validate(['period' => ['nullable', 'in:upcoming,past'], 'q' => ['nullable', 'string', 'max:120']]);
        $period = $filters['period'] ?? 'upcoming';
        $search = $filters['q'] ?? '';
        $user = $request->user();
        $admin = $user->hasAnyRole([UserRole::Admin->value, UserRole::SuperAdmin->value]);
        abort_unless($admin || $user->activeOwnedVenues()->exists() || $user->managedOrganizers()->exists() || EventOccurrence::whereHas('checkinStaff', fn ($q) => $q->whereKey($user->id))->exists(), 403);
        $dates = EventOccurrence::query()->when(! $admin, fn ($query) => $query->where(fn ($allowed) => $allowed
            ->whereHas('checkinStaff', fn ($q) => $q->whereKey($user->id))
            ->orWhereIn('venue_id', $user->activeOwnedVenues()->select('venues.id'))
            ->orWhere(fn ($fallback) => $fallback->whereNull('venue_id')->whereHas('event', fn ($event) => $event->whereIn('venue_id', $user->activeOwnedVenues()->select('venues.id'))))
            ->orWhereHas('event', fn ($event) => $event->whereIn('organizer_id', $user->managedOrganizers()->select('organizers.id')))))
            ->when($period === 'past', fn ($q) => $q->whereRaw('COALESCE(effective_ends_at, ends_at, starts_at) < ?', [now()]), fn ($q) => $q->whereRaw('COALESCE(effective_ends_at, ends_at, starts_at) >= ?', [now()]))
            ->when($search !== '', fn ($q) => $q->where(fn ($q) => $q
                ->whereHas('event', fn ($event) => $event->where('title', 'like', '%'.$search.'%'))
                ->orWhereHas('venue', fn ($venue) => $venue->where('name', 'like', '%'.$search.'%'))
                ->orWhere(fn ($fallback) => $fallback->whereNull('venue_id')->whereHas('event.venue', fn ($venue) => $venue->where('name', 'like', '%'.$search.'%')))))
            ->with(['event.venue', 'event.city', 'event.media', 'venue'])->orderBy('starts_at', $period === 'past' ? 'desc' : 'asc')->paginate(30)->withQueryString();

        return view('ticketing.dashboard', [
            'dates' => $dates, 'period' => $period, 'search' => $search,
            'counts' => $service->counts($dates->getCollection()),
            'abilities' => $this->abilities($user, $admin, $dates->getCollection()),
            'meta' => $this->meta('manage'),
        ]);
    }

    public function manage(ManageRequest $request, EventOccurrence $occurrence, TicketingService $service): View
    {
        Gate::authorize('manage', [Booking::class, $occurrence]);
        $occurrence->loadMissing(['event.media', 'event.city', 'event.venue', 'venue']);
        $search = $request->validated('q');
        $status = in_array($request->query('status'), self::BOOKING_FILTERS, true) ? (string) $request->query('status') : null;
        $bookings = Booking::query()->where('occurrence_id', $occurrence->id)->with(['user', 'tickets'])
            ->when($search, fn ($q) => $q->where(fn ($q) => $q->whereHas('tickets', fn ($q) => $q->where('attendee_name', 'like', '%'.$search.'%'))->orWhereHas('user', fn ($q) => $q->where('email', 'like', '%'.$search.'%'))))
            ->when($status === 'checked_in', fn ($q) => $q->whereHas('tickets', fn ($q) => $q->where('status', AdmissionStatus::CheckedIn->value)))
            ->when($status !== null && $status !== 'checked_in', fn ($q) => $q->where('status', $status))
            ->latest('id')->paginate(30)->withQueryString();
        foreach ($bookings as $booking) {
            $booking->setRelation('occurrence', $occurrence);
        }
        self::linkTickets($bookings->getCollection());
        $byStatus = Booking::query()->where('occurrence_id', $occurrence->id)->selectRaw('status, COUNT(*) as total')->groupBy('status')->toBase()->pluck('total', 'status');
        $filterCounts = [
            'all' => (int) $byStatus->sum(),
            'confirmed' => (int) ($byStatus[BookingStatus::Confirmed->value] ?? 0),
            'checked_in' => Booking::query()->where('occurrence_id', $occurrence->id)->whereHas('tickets', fn ($q) => $q->where('status', AdmissionStatus::CheckedIn->value))->count(),
            'waitlisted' => (int) ($byStatus[BookingStatus::Waitlisted->value] ?? 0),
            'cancelled' => (int) ($byStatus[BookingStatus::Cancelled->value] ?? 0),
        ];

        return view('ticketing.manage', [
            'date' => $occurrence, 'bookings' => $bookings, 'status' => $status, 'filterCounts' => $filterCounts,
            'availability' => $service->availability($occurrence), 'statistics' => $service->statistics($occurrence),
            'counts' => $service->counts([$occurrence])[$occurrence->id],
            'ended' => $service->hasEnded($occurrence),
            'staff' => app(MobileManagement::class)->staff($occurrence),
            'messages' => $occurrence->bookingMessages()->with('sender')->latest('id')->limit(10)->get(), 'meta' => $this->meta('manage'),
        ]);
    }

    public function configure(ManageRequest $request, EventOccurrence $occurrence, TicketingService $service): RedirectResponse
    {
        Gate::authorize('manage', [Booking::class, $occurrence]);
        $settings = $request->validated();
        foreach (['booking_opens_at', 'booking_closes_at', 'cancellation_closes_at'] as $field) {
            $settings[$field] = empty($settings[$field]) ? null : Carbon::parse($settings[$field], $occurrence->event->city->timezone)->utc();
        }
        $service->configure($occurrence, $settings, $request->user());

        return redirect()->route('ticketing.manage.show', $occurrence)->with('status', __('ticketing.updated'));
    }

    public function checkIn(CheckInRequest $request, EventOccurrence $occurrence, TicketingService $service): JsonResponse|RedirectResponse
    {
        Gate::authorize('checkIn', [Booking::class, $occurrence]);
        $scanned = $request->filled('scanned_at') ? CarbonImmutable::parse((string) $request->validated('scanned_at')) : null;
        $ticket = $service->checkIn($occurrence, $request->validated('code'), $request->user(), $request->validated('request_key'), $scanned);

        return $request->expectsJson() ? response()->json(['data' => ['id' => $ticket->id, 'attendee_name' => $ticket->attendee_name, 'status' => $ticket->status->value]])
            : redirect()->route($request->user()->can('manage', [Booking::class, $occurrence]) ? 'ticketing.manage.show' : 'ticketing.manage.scanner', $occurrence)->with('status', __('ticketing.checkin_success', ['name' => $ticket->attendee_name]));
    }

    /**
     * L'elenco per il controllo ingressi senza rete: impronte dei codici e
     * nomi, mai i codici. Lo scarica la pagina dello scanner all'apertura e
     * lo tiene nel browser; vedi `TicketingService::manifest()`.
     */
    public function manifest(EventOccurrence $occurrence, TicketingService $service): JsonResponse
    {
        Gate::authorize('checkIn', [Booking::class, $occurrence]);

        return response()->json(['data' => $service->manifest($occurrence)])->header('Cache-Control', 'private, no-store');
    }

    public function message(MessageAttendeesRequest $request, EventOccurrence $occurrence, TicketingService $service): JsonResponse|RedirectResponse
    {
        Gate::authorize('manage', [Booking::class, $occurrence]);
        $message = $service->message($occurrence, $request->user(), (string) $request->validated('subject'), (string) $request->validated('body'), $request->boolean('include_waitlisted'));

        return $request->is('api/*')
            ? response()->json(['data' => ['id' => $message->id, 'recipients_count' => $message->recipients_count, 'created_at' => $message->created_at?->toIso8601String()]], 201)
            : redirect()->route('ticketing.manage.show', $occurrence)->with('status', __('ticketing.messages.sent', ['count' => $message->recipients_count]));
    }

    public function guestBooking(GuestBookingRequest $request, EventOccurrence $occurrence, TicketingService $service): JsonResponse|RedirectResponse
    {
        Gate::authorize('manage', [Booking::class, $occurrence]);
        $booking = $service->reserveForGuest($occurrence, $request->names(), [
            'email' => $request->validated('email'), 'phone' => $request->validated('phone'), 'note' => $request->validated('note'),
        ], $request->user());

        return $request->is('api/*')
            ? response()->json(['data' => BookingResource::toArray($booking)], 201)
            : redirect()->route('ticketing.manage.show', $occurrence)->with('status', __('ticketing.guest.saved', ['count' => $booking->tickets->count()]));
    }

    public function scanner(Request $request, EventOccurrence $occurrence, TicketingService $service): View
    {
        // La stessa regola di `BookingPolicy::checkIn()`, valutata una volta sola:
        // serve anche sapere se chi guarda gestisce la data, per il ritorno.
        $canManage = $request->user()->can('manage', [Booking::class, $occurrence]);
        abort_unless($canManage || $occurrence->checkinStaff()->whereKey($request->user()->id)->exists(), 403);
        $occurrence->loadMissing(['event.media', 'event.city', 'event.venue', 'venue']);

        return view('ticketing.scanner', [
            'date' => $occurrence,
            'canManage' => $canManage,
            'ended' => $service->hasEnded($occurrence),
            'meta' => $this->meta('checkin'),
        ]);
    }

    public function staff(StaffRequest $request, EventOccurrence $occurrence, AssignCheckinStaff $assign): RedirectResponse
    {
        $assign($occurrence, $request->validated('email'), $request->boolean('remove'), $request->user());

        return redirect()->route('ticketing.manage.show', $occurrence)->with('status', __('decision.staff_saved'));
    }

    public function export(Request $request, EventOccurrence $occurrence): StreamedResponse
    {
        Gate::authorize('manage', [Booking::class, $occurrence]);
        activity('ticketing')->causedBy($request->user())->performedOn($occurrence)->log('participants_exported');

        return response()->streamDownload(function () use ($occurrence): void {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            $bookerFields = ['first_name', 'last_name', ...BookingForm::FIELDS];
            // Una colonna per ciascuna domanda di adesso; le risposte a domande poi tolte restano nella scheda della prenotazione.
            $questions = app(BookingForm::class)->questions($occurrence);
            fputcsv($out, [__('ticketing.ticket'), __('ticketing.name'), __('ticketing.email'), __('ticketing.status'), __('ticketing.checked_in_at'), __('ticketing.fields.first_name'), __('ticketing.fields.last_name'), ...array_map(fn ($field) => __('ticketing.booker').' - '.__('ticketing.fields.'.$field), $bookerFields), __('ticketing.privacy_record'), ...array_map(fn (string $label) => preg_match('/^[\s]*[=+@\-\t\r\n]/u', $label) ? "'".$label : $label, array_column($questions, 'label'))], ';', '"', '');
            AdmissionTicket::query()->whereHas('booking', fn ($q) => $q->where('occurrence_id', $occurrence->id))
                ->with('booking.user')->chunkById(200, function ($tickets) use ($out, $bookerFields, $questions): void {
                    foreach ($tickets as $ticket) {
                        $safe = fn ($value) => preg_match('/^[\s]*[=+@\-\t\r\n]/u', (string) $value) ? "'".$value : $value;
                        $answers = array_column((array) ($ticket->booking->booking_answers ?? []), 'answer', 'id');
                        fputcsv($out, [$ticket->id, $safe($ticket->attendee_name), $safe($ticket->booking->contactEmail()), __('ticketing.statuses.'.$ticket->status->value), $ticket->checked_in_at?->toIso8601String(), $safe($ticket->first_name), $safe($ticket->last_name), ...array_map(fn ($field) => $safe($ticket->booking->booker_data[$field] ?? ''), $bookerFields), $ticket->booking->privacy_accepted_at?->toIso8601String(), ...array_map(fn (array $question) => $safe($answers[$question['id']] ?? ''), $questions)], ';', '"', '');
                    }
                });
            fclose($out);
        }, 'partecipanti-'.$occurrence->id.'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /**
     * La lista d'ingresso da stampare, per una porta senza telefono: i biglietti
     * validi e quelli già passati, in ordine di cognome, con le risposte alle
     * domande del gestore e la nota del banco. Chi è in lista d'attesa non
     * entra e compare solo nel conteggio.
     */
    public function printList(Request $request, EventOccurrence $occurrence): Response
    {
        Gate::authorize('manage', [Booking::class, $occurrence]);
        activity('ticketing')->causedBy($request->user())->performedOn($occurrence)->log('participants_printed');
        $occurrence->loadMissing('event.city', 'event.venue', 'venue');
        $tickets = AdmissionTicket::query()->whereHas('booking', fn ($q) => $q->where('occurrence_id', $occurrence->id))
            ->with('booking')->get();
        $admitted = $tickets->filter(fn (AdmissionTicket $ticket): bool => in_array($ticket->status, [AdmissionStatus::Valid, AdmissionStatus::CheckedIn], true))
            ->sortBy(fn (AdmissionTicket $ticket): string => mb_strtolower(trim(($ticket->last_name ?? '').' '.($ticket->first_name ?? '').' '.$ticket->attendee_name)))->values();
        $timezone = $occurrence->event->city->timezone ?? config()->string('app.timezone');

        return Pdf::loadView('ticketing.print', [
            'date' => $occurrence, 'tickets' => $admitted, 'timezone' => $timezone,
            'checkedIn' => $admitted->where('status', AdmissionStatus::CheckedIn)->count(),
            'waitlisted' => $tickets->where('status', AdmissionStatus::Waitlisted)->count(),
        ])->setOption('isRemoteEnabled', false)->download('lista-ingressi-'.$occurrence->id.'.pdf');
    }

    /**
     * Quello che chi prenota ha già scritto: nome e cognome dal profilo (o
     * dal nome dell'account, diviso alla prima parola: è solo un suggerimento
     * che si corregge nel modulo) e i recapiti dell'ultima prenotazione.
     * Sempre e solo dati della stessa persona.
     *
     * @return array<string, string>
     */
    private function prefill(User $user): array
    {
        $last = Booking::query()->where('user_id', $user->id)->whereNotNull('booker_data')->latest('id')->first();
        $booker = array_filter((array) ($last->booker_data ?? []), fn ($value): bool => is_string($value) && trim($value) !== '');
        $parts = preg_split('/\s+/u', trim((string) $user->name), 2) ?: [];
        $prefill = array_map('strval', array_intersect_key($booker, array_flip(BookingForm::FIELDS)));
        $prefill['first_name'] = (string) ($user->first_name ?: ($booker['first_name'] ?? ($parts[0] ?? '')));
        $prefill['last_name'] = (string) ($user->last_name ?: ($booker['last_name'] ?? ($parts[1] ?? '')));

        return $prefill;
    }

    /**
     * Cosa può fare chi guarda il riepilogo, data per data, calcolato una
     * volta sola con le stesse regole di `BookingPolicy`: un locale attivo di
     * cui è proprietario, un organizzatore che gestisce, oppure il ruolo di
     * personale all'ingresso di quella data.
     *
     * @param  Collection<int, EventOccurrence>  $dates
     * @return array<int, array{manage: bool, check_in: bool}>
     */
    private function abilities(User $user, bool $admin, Collection $dates): array
    {
        $venues = $user->activeOwnedVenues()->pluck('venues.id')->map(fn ($id): int => (int) $id)->all();
        $organizers = $user->managedOrganizers()->pluck('id')->map(fn ($id): int => (int) $id)->all();
        $staff = $dates->isEmpty() ? [] : EventOccurrence::query()->whereKey($dates->pluck('id')->all())
            ->whereHas('checkinStaff', fn ($q) => $q->whereKey($user->id))->pluck('id')->map(fn ($id): int => (int) $id)->all();
        $abilities = [];
        foreach ($dates as $date) {
            $venue = $date->effectiveVenue()?->id;
            $manage = in_array((int) $date->event?->organizer_id, $organizers, true)
                || ($venue !== null && ($admin || in_array((int) $venue, $venues, true)));
            $abilities[$date->id] = ['manage' => $manage, 'check_in' => $manage || in_array($date->id, $staff, true)];
        }

        return $abilities;
    }

    /**
     * Ogni biglietto conosce la sua prenotazione già caricata: lo stato
     * mostrato (`displayStatus()`) guarda la data della prenotazione, e senza
     * questo legame farebbe due query per biglietto.
     *
     * @param  iterable<Booking>  $bookings
     */
    private static function linkTickets(iterable $bookings): void
    {
        foreach ($bookings as $booking) {
            foreach ($booking->tickets as $ticket) {
                $ticket->setRelation('booking', $booking);
            }
        }
    }

    private function meta(string $key): PageMeta
    {
        return new PageMeta(title: __('ticketing.'.$key), heading: __('ticketing.'.$key), indexable: false);
    }
}

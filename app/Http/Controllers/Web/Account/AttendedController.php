<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web\Account;

use App\DTOs\PageMeta;
use App\Enums\AdmissionStatus;
use App\Http\Controllers\Controller;
use App\Models\EventOccurrence;
use App\Models\User;
use App\Support\EventUrl;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * «Le mie serate»: le date passate in cui almeno un biglietto della persona
 * è passato dalla porta. Non i salvati, non le prenotazioni: le serate in cui
 * c'era davvero, che è anche la base per ritrovare locali e organizzatori.
 */
final class AttendedController extends Controller
{
    public function __invoke(Request $request): View|JsonResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        $attended = static fn (Builder $tickets) => $tickets->where('status', AdmissionStatus::CheckedIn);
        $dates = EventOccurrence::withTrashed()
            ->whereHas('bookings', fn (Builder $booking) => $booking->where('user_id', $user->getKey())->whereHas('tickets', $attended))
            ->where('starts_at', '<', now())
            ->with(['event' => fn ($event) => $event->withTrashed()->with(['venue', 'organizer']), 'venue'])
            ->withCount(['bookings as checked_in' => fn (Builder $booking) => $booking->where('user_id', $user->getKey())
                ->join('admission_tickets', 'admission_tickets.booking_id', '=', 'bookings.id')
                ->where('admission_tickets.status', AdmissionStatus::CheckedIn->value)])
            ->orderByDesc('starts_at')
            ->paginate(20)->withQueryString();

        if ($request->is('api/*')) {
            return response()->json([
                'data' => collect($dates->items())->map(static fn (EventOccurrence $date): array => self::toArray($date))->all(),
                'meta' => ['next_page' => $dates->hasMorePages() ? $dates->currentPage() + 1 : null],
            ]);
        }

        return view('account.attended', [
            'dates' => $dates,
            'meta' => new PageMeta(title: __('account.attended.title'), heading: __('account.attended.title'), indexable: false),
        ]);
    }

    /** @return array<string, mixed> */
    public static function toArray(EventOccurrence $date): array
    {
        $venue = $date->effectiveVenue();
        $organizer = $date->event?->organizer?->is_active ? $date->event->organizer : null;

        return [
            'occurrence_id' => (int) $date->getKey(),
            'title' => (string) $date->event?->title,
            'event_slug' => $date->event?->slug,
            'starts_at' => $date->starts_at->toIso8601String(),
            'url' => $date->event !== null && ! $date->event->trashed() ? EventUrl::occurrence($date) : null,
            'venue' => $venue === null ? null : ['name' => $venue->name, 'slug' => $venue->slug],
            'organizer' => $organizer === null ? null : ['name' => $organizer->name, 'slug' => $organizer->slug],
            'checked_in' => (int) ($date->checked_in ?? 0),
        ];
    }
}

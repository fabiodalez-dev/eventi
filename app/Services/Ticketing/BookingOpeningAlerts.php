<?php

declare(strict_types=1);

namespace App\Services\Ticketing;

use App\Enums\NotificationType;
use App\Enums\OccurrenceStatus;
use App\Models\BookingOpeningAlert;
use App\Models\EventOccurrence;
use App\Models\User;
use App\Services\Notifications\NotificationScheduler;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

/**
 * «Avvisami quando aprono le prenotazioni».
 *
 * Si chiede su una data con la biglietteria attiva e l'apertura ancora
 * davanti. Un controllo periodico (`ticketing:announce-openings`) avvisa chi
 * aspettava appena la finestra si apre davvero e chiude le richieste; quelle
 * di date annullate o passate si chiudono in silenzio.
 */
final class BookingOpeningAlerts
{
    public function __construct(private readonly NotificationScheduler $scheduler, private readonly TicketingService $ticketing) {}

    public function opensLater(EventOccurrence $date): bool
    {
        return $date->starts_at->isFuture() && $date->status !== OccurrenceStatus::Cancelled
            && $date->booking_enabled && (bool) $date->effectiveVenue()?->ticketing_enabled
            && $date->booking_opens_at !== null && $date->booking_opens_at->isFuture();
    }

    public function subscribe(User $user, EventOccurrence $date): void
    {
        if (! $this->opensLater($date)) {
            throw ValidationException::withMessages(['occurrence' => __('ticketing.opening.not_pending')]);
        }
        BookingOpeningAlert::query()->firstOrCreate(['user_id' => $user->getKey(), 'occurrence_id' => $date->getKey()]);
    }

    public function unsubscribe(User $user, int $occurrenceId): void
    {
        BookingOpeningAlert::query()->where('user_id', $user->getKey())->where('occurrence_id', $occurrenceId)->delete();
    }

    public function active(User $user, EventOccurrence $date): bool
    {
        return BookingOpeningAlert::query()->where('user_id', $user->getKey())->where('occurrence_id', $date->getKey())->exists();
    }

    /** @return list<int> */
    public function ids(User $user): array
    {
        return BookingOpeningAlert::query()->where('user_id', $user->getKey())->orderBy('occurrence_id')
            ->pluck('occurrence_id')->map(static fn (mixed $id): int => (int) $id)->all();
    }

    /** Avvisa per le date la cui finestra si è aperta; restituisce quanti avvisi sono partiti. */
    public function announce(): int
    {
        $now = CarbonImmutable::now();
        $sent = 0;
        $dates = EventOccurrence::query()->whereIn('id', BookingOpeningAlert::query()->select('occurrence_id'))
            ->where(fn ($query) => $query->whereNull('booking_opens_at')->orWhere('booking_opens_at', '<=', $now))
            ->with('event.venue', 'venue')->get();
        foreach ($dates as $date) {
            $open = $this->ticketing->isOpen($date);
            // Spenta per un momento, esaurita, chiusa in anticipo: si aspetta ancora. Si chiude solo a data passata o annullata.
            if (! $open && $date->starts_at->isFuture() && $date->status !== OccurrenceStatus::Cancelled) {
                continue;
            }
            $alerts = BookingOpeningAlert::query()->where('occurrence_id', $date->getKey())->get();
            foreach ($open ? $alerts : [] as $alert) {
                $sent += (int) $this->scheduler->queue((int) $alert->user_id, NotificationType::BookingsOpen,
                    sprintf('bookings_open:user_%d:occ_%d', $alert->user_id, $date->getKey()), $now, $date);
            }
            BookingOpeningAlert::query()->whereKey($alerts->modelKeys())->delete();
        }

        return $sent;
    }
}

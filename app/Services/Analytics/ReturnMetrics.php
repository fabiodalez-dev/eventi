<?php

declare(strict_types=1);

namespace App\Services\Analytics;

use App\Enums\AdmissionStatus;
use App\Enums\BookingStatus;
use App\Models\AdmissionTicket;
use App\Models\Booking;
use App\Models\SavedEvent;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * I numeri di ritorno: quante persone tornano, quante arrivano a prenotare,
 * quante si presentano davvero.
 *
 * **Perché non bastano le visite.** Il pannello misura molto bene il lato dei
 * locali — aperture, salvataggi, click — e quasi niente dell'abitudine delle
 * persone. Senza questi tre numeri non si sa se una funzione nuova ha spostato
 * qualcosa: si sa solo che il traffico esiste.
 *
 * **Cosa conta come «azione».** Salvare una data o prenotare un posto: gesti
 * deliberati e legati a un account. Non le visite, che non distinguono una
 * persona da un passaggio di un motore di ricerca, e che per di più si contano
 * dal browser.
 *
 * Nessuna di queste misure è una persona reale certificata: sono account. È
 * scritto accanto al numero, perché un numero di cui non si dichiara il limite
 * viene letto come esatto.
 */
final class ReturnMetrics
{
    /**
     * @return array{returning: array{rate: float, base: int, returned: int}, booked: int, bookings: int, check_ins: int, attendance: ?float, window_days: int}
     */
    public function summary(int $windowDays = 7): array
    {
        $now = CarbonImmutable::now();
        $recent = [$now->subDays($windowDays), $now];
        $earlier = [$now->subDays($windowDays * 2), $now->subDays($windowDays)];

        $before = $this->actors($earlier[0], $earlier[1]);
        $after = $this->actors($recent[0], $recent[1]);
        $returned = count(array_intersect($before, $after));

        $bookings = Booking::query()->where('status', '!=', BookingStatus::Cancelled)
            ->whereBetween('created_at', [$now->subDays(30), $now]);
        $checkIns = AdmissionTicket::query()->where('status', AdmissionStatus::CheckedIn)
            ->whereBetween('checked_in_at', [$now->subDays(30), $now])->count();
        $bookingsCount = (clone $bookings)->count();

        return [
            'returning' => [
                'base' => count($before),
                'returned' => $returned,
                'rate' => count($before) === 0 ? 0.0 : round($returned / count($before) * 100, 1),
            ],
            'booked' => (clone $bookings)->distinct()->count('user_id'),
            'bookings' => $bookingsCount,
            'check_ins' => $checkIns,
            // Le presenze si confrontano con i posti confermati, non con le prenotazioni
            // fatte nello stesso periodo: una serata prenotata a settembre si vive a ottobre.
            'attendance' => $bookingsCount === 0 ? null : round($checkIns / max($this->seats($now), 1) * 100, 1),
            'window_days' => $windowDays,
        ];
    }

    /**
     * Gli account che hanno compiuto almeno un gesto deliberato nella finestra.
     *
     * @return list<int>
     */
    private function actors(CarbonImmutable $from, CarbonImmutable $until): array
    {
        $saved = SavedEvent::query()->whereBetween('created_at', [$from, $until])->distinct()->pluck('user_id');
        $booked = Booking::query()->whereBetween('created_at', [$from, $until])->distinct()->pluck('user_id');

        return $saved->concat($booked)->unique()->map(intval(...))->values()->all();
    }

    /** I posti confermati per serate già cominciate nell'ultimo mese: il denominatore onesto delle presenze. */
    private function seats(CarbonImmutable $now): int
    {
        return (int) AdmissionTicket::query()
            ->whereIn('status', [AdmissionStatus::Valid, AdmissionStatus::CheckedIn])
            ->whereIn('booking_id', Booking::query()->where('status', BookingStatus::Confirmed)
                ->whereIn('occurrence_id', DB::table('event_occurrences')
                    ->whereBetween('starts_at', [$now->subDays(30), $now])->select('id'))->select('id'))
            ->count();
    }
}

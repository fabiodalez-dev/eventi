<?php

declare(strict_types=1);

namespace App\Services\Analytics;

use App\Enums\AdmissionStatus;
use App\Enums\BookingStatus;
use App\Models\AdmissionTicket;
use App\Models\Booking;
use App\Models\Event;
use App\Models\EventViewDaily;
use App\Models\Follow;
use App\Models\SavedEvent;
use App\Models\Venue;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * I numeri del mese di un locale, per il rapporto che arriva via email.
 *
 * Non riusa `ManagementAnalytics` per una ragione precisa: quello legge il
 * locale dal pannello e l'utente dalla sessione, e un comando pianificato non
 * ha né l'uno né l'altro. Qui il locale è un parametro, e questo rende anche
 * impossibile il tipo di errore che quel servizio evita con i controlli di
 * accesso: senza sessione non c'è un «locale corrente» da sbagliare.
 *
 * I numeri sono cinque, e sono quelli che una persona che gestisce un locale
 * userebbe per decidere: quante volte i suoi eventi sono stati aperti, quante
 * volte la sua pagina, quante persone hanno salvato una data, quante hanno
 * prenotato e quante si sono presentate davvero. Nessuna stima, nessun ricavo
 * dedotto: solo ciò che è stato contato.
 */
final class VenueMonthlyReport
{
    /**
     * @return array{from: CarbonImmutable, until: CarbonImmutable, label: string, totals: array<string, int>, previous: array<string, int>, empty: bool}
     */
    public function forMonth(Venue $venue, CarbonImmutable $monthStart): array
    {
        $from = $monthStart->startOfMonth();
        $until = $from->endOfMonth();
        $totals = $this->totals($venue, $from, $until);
        $previousStart = $from->subMonthNoOverflow()->startOfMonth();
        $previous = $this->totals($venue, $previousStart, $previousStart->endOfMonth());

        return ['from' => $from, 'until' => $until, 'label' => $from->locale('it')->isoFormat('MMMM YYYY'),
            'totals' => $totals, 'previous' => $previous, 'empty' => array_sum($totals) === 0];
    }

    /**
     * @return array<string, int>
     */
    public function totals(Venue $venue, CarbonImmutable $from, CarbonImmutable $until): array
    {
        $timezone = $venue->city->timezone;
        $localFrom = $from->setTimezone($timezone)->startOfDay();
        $localUntil = $until->setTimezone($timezone)->endOfDay();
        $dates = [$localFrom->toDateString(), $localUntil->toDateString()];
        $instants = [$localFrom->utc(), $localUntil->utc()];
        $events = Event::query()->where('venue_id', $venue->getKey())->select('events.id');
        $occurrences = DB::table('event_occurrences')->whereIn('event_id', clone $events)->select('id');

        return [
            'views' => (int) EventViewDaily::query()->whereIn('event_id', clone $events)
                ->whereBetween('date', $dates)->sum('views'),
            'profile_views' => (int) DB::table('profile_views_daily')->where('profile_type', 'venue')
                ->where('profile_id', $venue->getKey())->whereBetween('date', $dates)->sum('views'),
            'saves' => SavedEvent::query()->whereHas('occurrence', fn (Builder $query) => $query->whereIn('event_id', clone $events))
                ->whereBetween('created_at', $instants)->count(),
            'new_followers' => Follow::query()->where('followable_type', 'venue')->where('followable_id', $venue->getKey())
                ->whereBetween('created_at', $instants)->count(),
            'bookings' => Booking::query()->whereIn('occurrence_id', clone $occurrences)
                ->where('status', BookingStatus::Confirmed)->whereBetween('created_at', $instants)->count(),
            // Le presenze si contano quando sono avvenute, non quando il posto è stato prenotato.
            'check_ins' => AdmissionTicket::query()->where('status', AdmissionStatus::CheckedIn)
                ->whereBetween('checked_in_at', $instants)
                ->whereHas('booking', fn (Builder $query) => $query->whereIn('occurrence_id', clone $occurrences))->count(),
        ];
    }
}

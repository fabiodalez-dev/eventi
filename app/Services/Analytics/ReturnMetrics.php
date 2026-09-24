<?php

declare(strict_types=1);

namespace App\Services\Analytics;

use App\Enums\AdmissionStatus;
use App\Enums\BookingStatus;
use App\Models\AdmissionTicket;
use App\Models\Booking;
use App\Models\City;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use stdClass;

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
 * **Cosa vuol dire «tornata».** Il conto parte da chi ha fatto la **prima**
 * azione in assoluto dentro la settimana osservata, e guarda se quella stessa
 * persona ne ha fatta un'altra entro sette giorni **dalla sua**. Non dal
 * calendario: confrontare due settimane fisse conta come tornato chi ha agito
 * tredici giorni fa e oggi, e non conta chi ha agito sei giorni fa e ieri —
 * cioè esattamente al contrario di quello che la parola promette.
 *
 * **Tutto è ristretto a una città**, quella della dashboard che lo mostra:
 * accanto ci sono numeri di quella città, e due ambiti diversi nella stessa
 * schermata si confrontano senza che nessuno se ne accorga.
 *
 * Nessuna di queste misure è una persona reale certificata: sono account. È
 * scritto accanto al numero, perché un numero di cui non si dichiara il limite
 * viene letto come esatto.
 */
final class ReturnMetrics
{
    private const ATTENDANCE_DAYS = 30;

    /**
     * @return array{returning: array{rate: float, base: int, returned: int}, booked: int, bookings: int, check_ins: int, attendance: ?float, window_days: int}
     */
    public function summary(City $city, int $windowDays = 7): array
    {
        $now = CarbonImmutable::now();
        /* Mezzo aperto a destra: con due estremi inclusi un'azione esattamente
           sul confine finirebbe in entrambe le finestre e si conterebbe due volte. */
        $from = $now->subDays($windowDays * 2);
        $until = $now->subDays($windowDays);

        $arrivate = DB::query()->fromSub($this->actions($city), 'azioni')
            ->select('user_id')->selectRaw('MIN(created_at) as prima')
            ->groupBy('user_id')
            ->havingRaw('MIN(created_at) >= ? and MIN(created_at) < ?', [$from, $until])
            ->get();

        $bookings = Booking::query()->where('status', '!=', BookingStatus::Cancelled)
            ->whereIn('occurrence_id', $this->occurrences($city))
            ->whereBetween('created_at', [$now->subDays(self::ATTENDANCE_DAYS), $now]);
        $presenze = $this->attendance($city, $now);

        return [
            'returning' => $this->returning($city, $arrivate, $windowDays),
            'booked' => (clone $bookings)->distinct()->count('user_id'),
            'bookings' => (clone $bookings)->count(),
            'check_ins' => $presenze['check_ins'],
            'attendance' => $presenze['rate'],
            'window_days' => $windowDays,
        ];
    }

    /**
     * Di chi è arrivato nella settimana osservata, chi è tornato entro sette
     * giorni dalla propria prima volta.
     *
     * @param  Collection<int, stdClass>  $arrivate
     * @return array{rate: float, base: int, returned: int}
     */
    private function returning(City $city, Collection $arrivate, int $windowDays): array
    {
        $base = $arrivate->count();

        if ($base === 0) {
            return ['base' => 0, 'returned' => 0, 'rate' => 0.0];
        }

        $prime = $arrivate->mapWithKeys(fn (stdClass $riga): array => [
            (int) $riga->user_id => CarbonImmutable::parse((string) $riga->prima),
        ]);

        /* Una seconda interrogazione e non una per persona: la dashboard si
           apre spesso, e una query dentro un ciclo è il modo più semplice di
           rendere lenta una pagina che nessuno sospetterà. */
        $tornate = DB::query()->fromSub($this->actions($city), 'azioni')
            ->whereIn('user_id', $prime->keys()->all())
            ->get()
            ->filter(function (stdClass $riga) use ($prime, $windowDays): bool {
                $prima = $prime->get((int) $riga->user_id);
                $quando = CarbonImmutable::parse((string) $riga->created_at);

                return $prima !== null && $quando->gt($prima) && $quando->lte($prima->addDays($windowDays));
            })
            ->pluck('user_id')->unique()->count();

        return ['base' => $base, 'returned' => $tornate, 'rate' => round($tornate / $base * 100, 1)];
    }

    /**
     * Presenze e posti sulla **stessa coorte**.
     *
     * Contarli separatamente — gli ingressi per data di check-in, i posti per
     * data della serata — produceva percentuali oltre il cento: il check-in
     * può avvenire prima dell'inizio, e in quel momento l'ingresso stava nel
     * numeratore mentre il posto non era ancora nel denominatore.
     *
     * @return array{check_ins: int, rate: ?float}
     */
    private function attendance(City $city, CarbonImmutable $now): array
    {
        $serate = $this->occurrences($city)
            ->whereBetween('event_occurrences.starts_at', [$now->subDays(self::ATTENDANCE_DAYS), $now]);

        $biglietti = AdmissionTicket::query()
            ->whereIn('status', [AdmissionStatus::Valid, AdmissionStatus::CheckedIn])
            ->whereIn('booking_id', Booking::query()->where('status', BookingStatus::Confirmed)
                ->whereIn('occurrence_id', $serate)->select('id'));

        $posti = (clone $biglietti)->count();
        $ingressi = (clone $biglietti)->where('status', AdmissionStatus::CheckedIn)->count();

        /* Il tasso esiste quando esistono i posti, non quando esistono
           prenotazioni recenti: una serata di stasera i cui posti sono stati
           presi due mesi fa ha presenze vere e nessuna prenotazione nel mese. */
        return ['check_ins' => $ingressi, 'rate' => $posti === 0 ? null : round($ingressi / $posti * 100, 1)];
    }

    /** Le serate della città: è da qui che ogni numero prende il proprio confine. */
    private function occurrences(City $city): Builder
    {
        return DB::table('event_occurrences')
            ->join('events', 'events.id', '=', 'event_occurrences.event_id')
            ->where('events.city_id', $city->getKey())
            ->select('event_occurrences.id');
    }

    /** Ogni gesto deliberato della città: un salvataggio o una prenotazione. */
    private function actions(City $city): Builder
    {
        return DB::table('saved_events')
            ->whereIn('occurrence_id', $this->occurrences($city))
            ->select('user_id', 'created_at')
            ->unionAll(
                DB::table('bookings')
                    ->whereIn('occurrence_id', $this->occurrences($city))
                    ->select('user_id', 'created_at'),
            );
    }
}

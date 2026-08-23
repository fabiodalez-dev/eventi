<?php

declare(strict_types=1);

namespace App\Observers;

use App\Models\Category;
use App\Models\City;
use App\Models\Event;
use App\Models\EventOccurrence;
use App\Models\Venue;
use App\Services\Calendar\MonthCalendar;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use DateTimeInterface;

/**
 * Le due colonne calcolate di `event_occurrences` (§8.2 e §8.3) nascono qui e
 * solo qui: sono persistite e indicizzate, e nessuna parte dell'applicazione le
 * scrive a mano.
 *
 * ```
 * business_date =
 *     data locale - 1 giorno   se l'ora locale di starts_at sta in [00:00, city.night_cutoff_time)
 *                              E la categoria dell'evento ha is_nightlife = true
 *     data locale              altrimenti
 *
 * effective_ends_at =
 *     ends_at                                        se presente
 *     fine dell'orario di apertura del giorno        se is_all_day
 *     starts_at + category.default_duration_minutes  altrimenti
 *     fine della giornata locale                     se la categoria non dichiara una durata
 * ```
 *
 * Il doppio vincolo di §8.2 è voluto: un cutoff applicato alla sola città non
 * distinguerebbe un after techno da un convegno mattutino.
 */
final class EventOccurrenceObserver
{
    /**
     * Chiavi accettate in `venues.opening_hours`, nell'ordine ISO della
     * settimana. Il formato è
     * `{"mon": [{"open": "10:00", "close": "18:00"}], ...}`; una fascia che
     * chiude prima di aprire attraversa la mezzanotte.
     */
    private const WEEKDAY_KEYS = ['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'];

    /**
     * Gli attributi che una data generata eredita dalla serie: se uno di questi
     * viene modificato a mano, quella data non segue più la regola.
     */
    private const SERIES_ATTRIBUTES = ['starts_at', 'ends_at', 'doors_at', 'is_all_day', 'status'];

    /**
     * Una data aggiunta, spostata o annullata cambia i conteggi del calendario
     * mensile, che §12.3 tiene in cache per mezz'ora: la si invalida qui.
     */
    public function saved(EventOccurrence $occurrence): void
    {
        $this->forgetCalendar($occurrence);
    }

    public function deleted(EventOccurrence $occurrence): void
    {
        $this->forgetCalendar($occurrence);
    }

    public function saving(EventOccurrence $occurrence): void
    {
        // L'observer scatta su qualunque salvataggio, anche su un modello
        // incompleto: si legge l'attributo grezzo invece di darlo per buono.
        $startsAt = $occurrence->getAttribute('starts_at');

        if (! $startsAt instanceof DateTimeInterface) {
            return;
        }

        $event = $this->resolveEvent($occurrence);

        if (! $event instanceof Event) {
            return;
        }

        $city = $event->city;
        $category = $event->category;

        if (! $city instanceof City || ! $category instanceof Category) {
            return;
        }

        $startsAtLocal = CarbonImmutable::instance($startsAt)->setTimezone($city->timezone);

        $occurrence->business_date = Carbon::instance($this->businessDate($startsAtLocal, $city, $category));
        $occurrence->effective_ends_at = Carbon::instance($this->effectiveEndsAt($occurrence, $startsAtLocal, $category, $event->venue));
    }

    /**
     * Una data della serie che viene annullata, spostata o allungata smette di
     * seguire la regola e diventa un'**eccezione** (§7.8): è il flag che dice a
     * chi legge la serie che quella data non è più deducibile dalla RRULE, e che
     * `GenerateOccurrencesAction` non deve considerare rigenerabile.
     *
     * Le altre date della serie non vengono toccate: qui si scrive soltanto sul
     * modello che sta già per essere salvato.
     */
    public function updating(EventOccurrence $occurrence): void
    {
        if ($occurrence->recurrence_id === null || $occurrence->is_exception) {
            return;
        }

        foreach (self::SERIES_ATTRIBUTES as $attribute) {
            if ($occurrence->isDirty($attribute)) {
                $occurrence->is_exception = true;

                return;
            }
        }
    }

    /**
     * Giornata evento: un concerto che comincia venerdì alle 23:30 e uno che
     * comincia sabato alle 2:00 appartengono entrambi a venerdì.
     */
    private function businessDate(CarbonImmutable $startsAtLocal, City $city, Category $category): CarbonImmutable
    {
        $secondsOfDay = $startsAtLocal->hour * 3600 + $startsAtLocal->minute * 60 + $startsAtLocal->second;

        if ($category->is_nightlife && $secondsOfDay < $this->secondsOfDay($city->night_cutoff_time)) {
            return $startsAtLocal->subDay()->startOfDay();
        }

        return $startsAtLocal->startOfDay();
    }

    /**
     * Restituisce sempre un istante in UTC: il cast `datetime` di Eloquent
     * scrive il valore con il fuso che l'oggetto porta con sé, non con quello
     * dell'applicazione. Passargli un orario locale significherebbe salvare
     * un'ora sbagliata di uno o due fusi.
     */
    private function effectiveEndsAt(
        EventOccurrence $occurrence,
        CarbonImmutable $startsAtLocal,
        Category $category,
        ?Venue $venue,
    ): CarbonImmutable {
        $endsAt = $occurrence->ends_at;

        if ($endsAt !== null) {
            return CarbonImmutable::instance($endsAt)->utc();
        }

        if ($occurrence->is_all_day) {
            return $this->closingTime($startsAtLocal, $venue)->utc();
        }

        $duration = $category->default_duration_minutes;

        if ($duration !== null && $duration > 0) {
            return $startsAtLocal->addMinutes($duration)->utc();
        }

        return $startsAtLocal->endOfDay()->utc();
    }

    /**
     * Orario di chiusura del locale nel giorno dell'occorrenza. Senza locale o
     * senza orari dichiarati vale la fine della giornata locale.
     */
    private function closingTime(CarbonImmutable $startsAtLocal, ?Venue $venue): CarbonImmutable
    {
        $openingHours = $venue?->opening_hours;

        if (! is_array($openingHours)) {
            return $startsAtLocal->endOfDay();
        }

        $ranges = $openingHours[self::WEEKDAY_KEYS[$startsAtLocal->dayOfWeekIso - 1]] ?? null;

        if (! is_array($ranges)) {
            return $startsAtLocal->endOfDay();
        }

        $closing = null;

        foreach ($ranges as $range) {
            if (! is_array($range) || ! is_string($range['open'] ?? null) || ! is_string($range['close'] ?? null)) {
                continue;
            }

            $open = $this->secondsOfDay($range['open']);
            $close = $this->secondsOfDay($range['close']);
            $candidate = $startsAtLocal->startOfDay()->addSeconds($close <= $open ? $close + 86400 : $close);

            if ($closing === null || $candidate->greaterThan($closing)) {
                $closing = $candidate;
            }
        }

        return $closing ?? $startsAtLocal->endOfDay();
    }

    private function secondsOfDay(string $time): int
    {
        $parts = array_map(intval(...), explode(':', $time));

        return $parts[0] * 3600 + ($parts[1] ?? 0) * 60 + ($parts[2] ?? 0);
    }

    /**
     * Preferisce la relazione già caricata — è così che
     * `GenerateOccurrencesAction` evita una query per ogni data generata —
     * e altrimenti la carica, evento cestinato compreso.
     */
    private function resolveEvent(EventOccurrence $occurrence): ?Event
    {
        if ($occurrence->relationLoaded('event')) {
            $event = $occurrence->getRelation('event');

            if ($event instanceof Event && $event->relationLoaded('city') && $event->relationLoaded('category')) {
                return $event;
            }
        }

        return Event::withTrashed()
            ->with(['city', 'category', 'venue'])
            ->find($occurrence->getAttribute('event_id'));
    }

    /**
     * L'evento si prende da `resolveEvent()`, che preferisce la relazione già
     * caricata: una serie generata a blocchi non deve pagare una query in più
     * per ogni data solo per sapere di quale città sia il calendario.
     */
    private function forgetCalendar(EventOccurrence $occurrence): void
    {
        $event = $this->resolveEvent($occurrence);

        if ($event instanceof Event) {
            MonthCalendar::bump((int) $event->city_id);
        }
    }
}

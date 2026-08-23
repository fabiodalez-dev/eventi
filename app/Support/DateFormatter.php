<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\City;
use Carbon\CarbonImmutable;
use DateTimeInterface;

/**
 * Formattazione umana di date e orari, nel fuso della città.
 *
 * **Non decide finestre temporali.** Sapere se un'occorrenza è "in corso",
 * "stasera" o "inizia tra poco" è compito esclusivo di
 * `App\Queries\EventOccurrenceQuery` (§8): chi disegna una sezione sa già da
 * quale finestra vengono le occorrenze che ha in mano e passa quel contesto
 * alla card. Qui dentro si scelgono soltanto le parole con cui una data si
 * scrive in italiano.
 *
 * L'unico confronto sul calendario che questa classe fa è "che giorno è" —
 * oggi, domani, ieri — per non stampare "venerdì 23 agosto" quando è appunto
 * oggi. Non classifica nulla e non filtra nulla.
 *
 * ## Giornate e istanti non si trattano allo stesso modo
 *
 * - `starts_at`, `ends_at`, `doors_at` sono **istanti** salvati in UTC: vanno
 *   convertiti nel fuso della città prima di essere letti.
 * - `business_date` è una **giornata**, non un istante. Il cast `date` di
 *   Eloquent la restituisce a mezzanotte nel fuso dell'applicazione (UTC):
 *   convertirla in `Europe/Rome` la farebbe scivolare al giorno precedente
 *   alle 22:00. I metodi che ricevono una giornata (`day`, `weekdayDate`,
 *   `isoDay`) ne leggono quindi la sola parte di data, senza conversione.
 */
final readonly class DateFormatter
{
    public function __construct(private string $timezone) {}

    public static function for(City $city): self
    {
        return new self($city->timezone);
    }

    public static function forTimezone(string $timezone): self
    {
        return new self($timezone);
    }

    // ------------------------------------------------------------- giornate

    /**
     * Nome della giornata: "oggi", "domani", "ieri" oppure
     * "venerdì 5 settembre" (con l'anno se non è quello corrente).
     */
    public function day(DateTimeInterface $day): string
    {
        $distance = (int) $this->today()->diffInDays($this->asDay($day), absolute: false);

        return match ($distance) {
            0 => __('dates.today'),
            1 => __('dates.tomorrow'),
            -1 => __('dates.yesterday'),
            default => $this->weekdayDate($day),
        };
    }

    /**
     * "venerdì 5 settembre", sempre per esteso: è la forma dell'intestazione
     * e delle schede, dove "oggi" non basta a orientare chi legge.
     */
    public function weekdayDate(DateTimeInterface $day): string
    {
        $date = $this->asDay($day);

        $replacements = [
            'weekday' => $date->isoFormat('dddd'),
            'day' => $date->format(__('dates.formats.day_number')),
            'month' => $date->isoFormat('MMMM'),
            'year' => $date->format('Y'),
        ];

        return $date->year === $this->today()->year
            ? __('dates.weekday_day_month', $replacements)
            : __('dates.weekday_day_month_year', $replacements);
    }

    /**
     * "5 settembre", senza il giorno della settimana.
     */
    public function shortDate(DateTimeInterface $day): string
    {
        $date = $this->asDay($day);

        $replacements = [
            'day' => $date->format(__('dates.formats.day_number')),
            'month' => $date->isoFormat('MMMM'),
            'year' => $date->format('Y'),
        ];

        return $date->year === $this->today()->year
            ? __('dates.day_month', $replacements)
            : __('dates.day_month_year', $replacements);
    }

    /**
     * "ven" — per gli scroller di giorni.
     */
    public function weekdayShort(DateTimeInterface $day): string
    {
        return $this->asDay($day)->isoFormat('ddd');
    }

    /**
     * "5" — il numero del giorno nel mese.
     */
    public function dayNumber(DateTimeInterface $day): string
    {
        return $this->asDay($day)->format(__('dates.formats.day_number'));
    }

    /**
     * "settembre" — per le intestazioni del calendario mensile.
     */
    public function monthName(DateTimeInterface $day): string
    {
        return $this->asDay($day)->isoFormat('MMMM');
    }

    // -------------------------------------------------------------- istanti

    /**
     * "21:30".
     */
    public function time(DateTimeInterface $instant): string
    {
        return $this->asInstant($instant)->format(__('dates.formats.time'));
    }

    /**
     * "oggi alle 21:30", "venerdì 5 settembre alle 01:30".
     *
     * La giornata e l'orario sono due dati distinti: un after che comincia
     * all'una di notte appartiene alla serata del giorno prima
     * (`business_date`, §8.2) ma l'orario da stampare resta l'01:30.
     */
    public function dayAndTime(DateTimeInterface $day, ?DateTimeInterface $instant = null): string
    {
        if ($instant === null) {
            return $this->day($day);
        }

        return __('dates.day_at_time', [
            'date' => $this->day($day),
            'time' => $this->time($instant),
        ]);
    }

    /**
     * "stasera alle 21:30". Da usare **solo** sulle occorrenze che vengono
     * dalla finestra `tonight()`: questa classe non stabilisce da sé se sia
     * sera.
     */
    public function tonightAt(DateTimeInterface $instant): string
    {
        return __('dates.tonight_at_time', ['time' => $this->time($instant)]);
    }

    /**
     * "21:30 – 23:30", oppure "dalle 21:30" se la fine non è nota.
     */
    public function timeRange(DateTimeInterface $start, ?DateTimeInterface $end = null): string
    {
        if ($end === null) {
            return __('dates.from_time', ['time' => $this->time($start)]);
        }

        return __('dates.time_range', [
            'start' => $this->time($start),
            'end' => $this->time($end),
        ]);
    }

    /**
     * Forma breve del conto alla rovescia, per i badge: "25 min", "2 h",
     * "1 h 20 min". Restituisce la distanza fra adesso e l'istante dato: non
     * decide se quell'istante sia "vicino".
     */
    public function countdown(DateTimeInterface $instant): string
    {
        $minutes = $this->minutesUntil($instant);

        if ($minutes < 60) {
            return __('dates.countdown_minutes', ['count' => max($minutes, 0)]);
        }

        $hours = intdiv($minutes, 60);
        $rest = $minutes % 60;

        return $rest === 0
            ? __('dates.countdown_hours', ['count' => $hours])
            : __('dates.countdown_hours_minutes', ['hours' => $hours, 'minutes' => $rest]);
    }

    /**
     * Forma estesa: "adesso", "tra 25 minuti", "tra 2 ore", "tra 3 giorni".
     */
    public function relative(DateTimeInterface $instant): string
    {
        $minutes = $this->minutesUntil($instant);

        if ($minutes <= 0) {
            return __('dates.now');
        }

        if ($minutes < 60) {
            return trans_choice('dates.in_minutes', $minutes);
        }

        if ($minutes < 1440) {
            return trans_choice('dates.in_hours', intdiv($minutes, 60));
        }

        return trans_choice('dates.in_days', intdiv($minutes, 1440));
    }

    /**
     * Minuti che mancano all'istante dato, negativi se è già passato.
     */
    public function minutesUntil(DateTimeInterface $instant): int
    {
        return (int) $this->now()->diffInMinutes($this->asInstant($instant), absolute: false);
    }

    // ------------------------------------------------------ forme leggibili
    //                                                          dalle macchine

    /**
     * Valore per l'attributo `datetime` di `<time>`: ISO 8601 con lo scarto
     * del fuso della città (`2026-09-05T21:30:00+02:00`).
     */
    public function iso(DateTimeInterface $instant): string
    {
        return $this->asInstant($instant)->toIso8601String();
    }

    /**
     * Valore per l'attributo `datetime` di una giornata: `2026-09-05`.
     */
    public function isoDay(DateTimeInterface $day): string
    {
        return $this->asDay($day)->format('Y-m-d');
    }

    // ---------------------------------------------------------------- interno

    private function now(): CarbonImmutable
    {
        return CarbonImmutable::now($this->timezone)->locale($this->locale());
    }

    private function today(): CarbonImmutable
    {
        return $this->now()->startOfDay();
    }

    /**
     * Un istante, riportato all'ora della città.
     */
    private function asInstant(DateTimeInterface $value): CarbonImmutable
    {
        return CarbonImmutable::instance($value)
            ->setTimezone($this->timezone)
            ->locale($this->locale());
    }

    /**
     * Una giornata: se ne prende la sola parte di data, senza conversione di
     * fuso, e la si riancora a mezzanotte locale.
     */
    private function asDay(DateTimeInterface $value): CarbonImmutable
    {
        return CarbonImmutable::parse($value->format('Y-m-d'), $this->timezone)
            ->locale($this->locale());
    }

    private function locale(): string
    {
        return app()->getLocale();
    }
}

<?php

declare(strict_types=1);

namespace App\Filament\Support;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use Filament\Support\Facades\FilamentTimezone;

/**
 * Le date dei pannelli, scritte in un modo solo: «sab 22 nov, 21:00».
 *
 * Prima ogni pagina aveva il suo formato (`d/m/Y H:i`, date ISO, il formato di
 * serie di Filament in inglese): chi gestisce un locale leggeva «22/11/2026
 * 21:00» in un posto e «Nov 22, 2026» in un altro. Qui c'è una sola scrittura
 * per ogni bisogno, sempre nel fuso della città dell'evento.
 *
 * **Il fuso.** Chi chiama passa quello della città (`$event->city?->timezone`);
 * senza, vale quello dei pannelli (`FilamentTimezone`, Europe/Rome), che a sua
 * volta ripiega su quello dell'applicazione. L'applicazione lavora in UTC: un
 * ripiego diretto su `config('app.timezone')` mostrerebbe le 20:00 per un
 * evento che comincia alle 21:00.
 *
 * **L'anno** compare solo quando non è quello in corso: «sab 22 nov» per
 * quest'anno, «sab 22 nov 2025» per un'altra stagione.
 */
final class PanelDate
{
    /** «sab 22 nov, 21:00» (con l'anno se diverso da quello in corso). */
    public static function event(DateTimeInterface|string|null $instant, ?string $timezone = null): string
    {
        $date = self::parse($instant, $timezone);

        return $date === null ? self::empty() : self::dayLabel($date).', '.$date->isoFormat('HH:mm');
    }

    /** «sab 22 nov, 21:00–23:30»; senza fine è uguale a `event()`. */
    public static function range(DateTimeInterface|string|null $start, DateTimeInterface|string|null $end, ?string $timezone = null): string
    {
        $from = self::parse($start, $timezone);
        $until = self::parse($end, $timezone);

        if ($from === null) {
            return self::empty();
        }

        if ($until === null || $until->lessThanOrEqualTo($from)) {
            return self::event($from, $timezone);
        }

        /* Una serata che finisce dopo mezzanotte resta una serata: «21:00–02:00».
           Solo quando dura più di un giorno si scrive anche il giorno della fine. */
        if ($from->diffInHours($until) < 24) {
            return self::event($from, $timezone).'–'.$until->isoFormat('HH:mm');
        }

        return self::event($from, $timezone).' – '.self::event($until, $timezone);
    }

    /** «sab 22 nov» (con l'anno se diverso da quello in corso). */
    public static function day(DateTimeInterface|string|null $day, ?string $timezone = null): string
    {
        $date = self::parse($day, $timezone);

        return $date === null ? self::empty() : self::dayLabel($date);
    }

    /**
     * Per le cose già successe: «adesso», «5 minuti fa», «2 ore fa», «ieri alle
     * 14:30», «3 giorni fa»; oltre la settimana «12 nov, 14:30».
     */
    public static function moment(DateTimeInterface|string|null $instant, ?string $timezone = null): string
    {
        $date = self::parse($instant, $timezone);

        if ($date === null) {
            return self::empty();
        }

        $now = CarbonImmutable::now($date->getTimezone());
        $seconds = $now->getTimestamp() - $date->getTimestamp();

        if ($seconds < 0 || $seconds >= 7 * 86400) {
            return self::shortDay($date).', '.$date->isoFormat('HH:mm');
        }

        if ($seconds < 60) {
            return __('pannelli.date.now');
        }

        if ($seconds < 3600) {
            return trans_choice('pannelli.date.minutes_ago', intdiv($seconds, 60), ['count' => intdiv($seconds, 60)]);
        }

        if ($date->isSameDay($now)) {
            return trans_choice('pannelli.date.hours_ago', intdiv($seconds, 3600), ['count' => intdiv($seconds, 3600)]);
        }

        if ($date->isSameDay($now->subDay())) {
            return __('pannelli.date.yesterday', ['time' => $date->isoFormat('HH:mm')]);
        }

        $days = (int) $date->startOfDay()->diffInDays($now->startOfDay());

        return __('pannelli.date.days_ago', ['count' => $days]);
    }

    /** «dal 21 ott al 19 nov»; «dall'inizio al 19 nov» se manca l'inizio. */
    public static function period(DateTimeInterface|string|null $from, DateTimeInterface|string|null $until, ?string $timezone = null): string
    {
        $start = self::parse($from, $timezone);
        $end = self::parse($until, $timezone);

        if ($start === null && $end === null) {
            return self::empty();
        }

        $parts = [$start === null ? __('pannelli.date.from_start') : self::preposition('from', $start)];

        if ($end !== null) {
            $parts[] = self::preposition('until', $end);
        }

        return implode(' ', $parts);
    }

    /** L'etichetta di un asse: «21 ott», oppure «ott 2026» per i grafici a mesi. */
    public static function axis(DateTimeInterface|string|null $date, bool $monthly = false): string
    {
        $value = self::parse($date, null);

        if ($value === null) {
            return self::empty();
        }

        return $monthly ? $value->isoFormat('MMM YYYY') : $value->isoFormat('D MMM');
    }

    /** «settembre 2026». */
    public static function month(DateTimeInterface|string|null $date): string
    {
        $value = self::parse($date, null);

        return $value === null ? self::empty() : $value->isoFormat('MMMM YYYY');
    }

    /** La data per intero, per i suggerimenti al passaggio del mouse: «sabato 22 novembre 2026, 21:00». */
    public static function full(DateTimeInterface|string|null $instant, ?string $timezone = null): string
    {
        $date = self::parse($instant, $timezone);

        return $date === null ? self::empty() : $date->isoFormat('dddd D MMMM YYYY, HH:mm');
    }

    /** Il fuso da usare quando chi chiama non ne indica uno. */
    public static function defaultTimezone(): string
    {
        return FilamentTimezone::get();
    }

    private static function parse(DateTimeInterface|string|null $value, ?string $timezone): ?CarbonImmutable
    {
        if ($value === null || $value === '') {
            return null;
        }

        $date = $value instanceof DateTimeInterface ? CarbonImmutable::instance($value) : CarbonImmutable::parse($value);

        return $date->setTimezone(filled($timezone) ? $timezone : self::defaultTimezone())->locale(app()->getLocale());
    }

    /** «sab 22 nov», con l'anno se non è quello in corso. */
    private static function dayLabel(CarbonImmutable $date): string
    {
        return $date->isoFormat(self::isThisYear($date) ? 'ddd D MMM' : 'ddd D MMM YYYY');
    }

    /** «22 nov», con l'anno se non è quello in corso. */
    private static function shortDay(CarbonImmutable $date): string
    {
        return $date->isoFormat(self::isThisYear($date) ? 'D MMM' : 'D MMM YYYY');
    }

    private static function isThisYear(CarbonImmutable $date): bool
    {
        return $date->year === CarbonImmutable::now($date->getTimezone())->year;
    }

    /**
     * «dal 21 ott», «al 19 nov»; davanti a 1, 8 e 11 l'articolo si elide come
     * si legge: «dall'8 nov», «all'11 dic».
     */
    private static function preposition(string $kind, CarbonImmutable $date): string
    {
        $elided = in_array($date->day, [1, 8, 11], true);

        return __('pannelli.date.'.$kind.($elided ? '_elided' : ''), ['date' => self::shortDay($date)]);
    }

    private static function empty(): string
    {
        return __('pannelli.date.empty');
    }
}

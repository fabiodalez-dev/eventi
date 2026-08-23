<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * I giorni della settimana come li sceglie un gestore («il giovedì») e come
 * li scrive RFC 5545 («TH»).
 *
 * Esiste per una sola ragione: §10.4 impone che «il gestore non deve mai
 * vedere la sintassi RRULE». Qualcuno però quella sintassi deve scriverla, e
 * il punto in cui italiano e standard si incontrano è questo enum — non tre
 * array associativi sparsi fra un modulo, una vista e un test.
 *
 * I valori sono gli stessi abbreviati inglesi già usati da
 * `venues.opening_hours` (D19) e da `lang/it/dates.php`: un solo vocabolario
 * per i giorni in tutto il progetto.
 */
enum Weekday: string
{
    case Monday = 'mon';
    case Tuesday = 'tue';
    case Wednesday = 'wed';
    case Thursday = 'thu';
    case Friday = 'fri';
    case Saturday = 'sat';
    case Sunday = 'sun';

    /**
     * Il codice di due lettere che RFC 5545 usa in `BYDAY`.
     */
    public function rruleCode(): string
    {
        return match ($this) {
            self::Monday => 'MO',
            self::Tuesday => 'TU',
            self::Wednesday => 'WE',
            self::Thursday => 'TH',
            self::Friday => 'FR',
            self::Saturday => 'SA',
            self::Sunday => 'SU',
        };
    }

    /**
     * L'indice ISO-8601 (lunedì = 1), che è quello di `Carbon::dayOfWeekIso`.
     */
    public function isoNumber(): int
    {
        return match ($this) {
            self::Monday => 1,
            self::Tuesday => 2,
            self::Wednesday => 3,
            self::Thursday => 4,
            self::Friday => 5,
            self::Saturday => 6,
            self::Sunday => 7,
        };
    }

    public static function fromRruleCode(string $code): ?self
    {
        foreach (self::cases() as $case) {
            if ($case->rruleCode() === strtoupper(trim($code))) {
                return $case;
            }
        }

        return null;
    }

    public static function fromIsoNumber(int $number): ?self
    {
        foreach (self::cases() as $case) {
            if ($case->isoNumber() === $number) {
                return $case;
            }
        }

        return null;
    }

    public function label(): string
    {
        return __('dates.weekdays.'.$this->value);
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        $options = [];

        foreach (self::cases() as $case) {
            $options[$case->value] = $case->label();
        }

        return $options;
    }

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}

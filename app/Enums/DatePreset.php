<?php

declare(strict_types=1);

namespace App\Enums;

use App\Queries\EventOccurrenceQuery;

/**
 * Finestra temporale scelta dal filtro pubblico (§11.3) e dal parametro
 * `preset` dell'API (§13.2).
 *
 * L'enum non calcola nulla: dice soltanto **quale** metodo di
 * `EventOccurrenceQuery` va chiamato. Le date le decide il motore temporale e
 * nient'altro (§8).
 */
enum DatePreset: string
{
    case Today = 'today';
    case Tonight = 'tonight';
    case Tomorrow = 'tomorrow';
    case Weekend = 'weekend';
    case Week = 'week';

    /**
     * Applica la finestra al motore temporale.
     *
     * "Questa settimana" è l'unica che non ha un metodo dedicato in §8: è
     * l'intervallo dei prossimi sette giorni evento, estremi compresi.
     */
    public function applyTo(EventOccurrenceQuery $query): EventOccurrenceQuery
    {
        return match ($this) {
            self::Today => $query->today(),
            self::Tonight => $query->tonight(),
            self::Tomorrow => $query->tomorrow(),
            self::Weekend => $query->weekend(),
            self::Week => $query->nextDays(7),
        };
    }

    public function label(): string
    {
        return __('enums.date_preset.'.$this->value);
    }

    /**
     * Forma minuscola da infilare in un titolo: "Eventi gratis stasera".
     */
    public function phrase(): string
    {
        return __('enums.date_preset_phrase.'.$this->value);
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

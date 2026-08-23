<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Fascia oraria del filtro pubblico (§11.3) e del parametro API
 * `time_of_day=day|evening|night` (§13.2).
 *
 * Le fasce sono contigue e coprono le 24 ore: giorno `[06:00, 17:00)`,
 * sera `[17:00, 22:00)`, notte `[22:00, 06:00)`. La sera comincia alle 17:00
 * perché è la stessa soglia di "stasera" in §8.4: due definizioni diverse
 * darebbero due risultati diversi sulla stessa pagina.
 */
enum TimeOfDay: string
{
    case Day = 'day';
    case Evening = 'evening';
    case Night = 'night';

    /**
     * Prima ora locale inclusa nella fascia.
     */
    public function startHour(): int
    {
        return match ($this) {
            self::Day => 6,
            self::Evening => 17,
            self::Night => 22,
        };
    }

    /**
     * Prima ora locale esclusa dalla fascia. Se è minore o uguale a
     * `startHour()` la fascia attraversa la mezzanotte.
     */
    public function endHour(): int
    {
        return match ($this) {
            self::Day => 17,
            self::Evening => 22,
            self::Night => 6,
        };
    }

    public function crossesMidnight(): bool
    {
        return $this->endHour() <= $this->startHour();
    }

    public function label(): string
    {
        return __('enums.time_of_day.'.$this->value);
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

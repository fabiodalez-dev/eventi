<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Il tipo di avviso che un locale manda a chi lo segue. Pochi casi, tutti
 * concreti: la pausa estiva, la chiusura, la riapertura, e una voce libera
 * per il resto («cambia gestione», «nuovi orari»).
 */
enum VenueNoticeKind: string
{
    case Pause = 'pause';
    case Closure = 'closure';
    case Reopening = 'reopening';
    case Info = 'info';

    public function label(): string
    {
        return __('enums.venue_notice_kind.'.$this->value);
    }

    /** @return array<string, string> */
    public static function options(): array
    {
        $options = [];
        foreach (self::cases() as $case) {
            $options[$case->value] = $case->label();
        }

        return $options;
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}

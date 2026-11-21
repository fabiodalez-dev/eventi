<?php

declare(strict_types=1);

namespace App\Enums;

enum OccurrenceStatus: string
{
    case Scheduled = 'scheduled';
    case Cancelled = 'cancelled';
    case SoldOut = 'sold_out';
    case Postponed = 'postponed';
    case Moved = 'moved';

    /**
     * La data **non si terrà** come era stata annunciata: annullata, oppure
     * rinviata senza una data nuova. Su queste date non ha senso offrire
     * passaggi, biglietti, calendario o «ci vado».
     */
    public function isCalledOff(): bool
    {
        return $this === self::Cancelled || $this === self::Postponed;
    }

    public function label(): string
    {
        return __('enums.occurrence_status.'.$this->value);
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

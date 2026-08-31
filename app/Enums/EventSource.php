<?php

declare(strict_types=1);

namespace App\Enums;

enum EventSource: string
{
    case Manual = 'manual';
    case Venue = 'venue';
    case Submission = 'submission';
    case ImportIcs = 'import_ics';
    case ImportApi = 'import_api';

    /**
     * Se l'evento e arrivato da un canale automatico.
     *
     * Serve nei pannelli, dove chi modera deve distinguere cio che una persona
     * ha scritto da cio che una macchina ha letto da un calendario altrui.
     * Nel sito pubblico la distinzione non esiste: un evento e un evento.
     */
    public function isImported(): bool
    {
        return in_array($this, [self::ImportIcs, self::ImportApi], strict: true);
    }

    public function label(): string
    {
        return __('enums.event_source.'.$this->value);
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

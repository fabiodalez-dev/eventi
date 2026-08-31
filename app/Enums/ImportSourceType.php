<?php

declare(strict_types=1);

namespace App\Enums;

enum ImportSourceType: string
{
    case Ics = 'ics';
    case Json = 'json';
    case Rss = 'rss';
    case Api = 'api';
    case Manual = 'manual';

    public function label(): string
    {
        return __('enums.import_source_type.'.$this->value);
    }

    /**
     * La provenienza che gli eventi di questa sorgente portano scritta in
     * `events.source` (§14.1).
     *
     * `EventSource` ne distingue due sole — il calendario e tutto il resto —
     * ed è la distinzione che conta per chi legge: un ICS è un file che
     * qualcuno pubblica, un'API è un dialogo con un sistema. Farla qui evita
     * che ogni driver decida per conto proprio.
     */
    public function eventSource(): EventSource
    {
        return $this === self::Ics ? EventSource::ImportIcs : EventSource::ImportApi;
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

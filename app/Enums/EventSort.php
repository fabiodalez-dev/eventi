<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Ordinamento offerto dalle liste pubbliche (§11.3) e dal parametro `sort`
 * dell'API (§13.2).
 *
 * `Distance` ha effetto solo se la richiesta porta una posizione: senza
 * coordinate non esiste alcuna distanza da ordinare e la lista resta
 * cronologica.
 */
enum EventSort: string
{
    case Time = 'time';
    case Relevance = 'relevance';
    case Distance = 'distance';

    public function label(): string
    {
        return __('enums.event_sort.'.$this->value);
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

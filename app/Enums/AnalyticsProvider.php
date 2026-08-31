<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Gli strumenti di statistica riconosciuti (§12 dello stack tecnologico).
 *
 * Entrambi contano le pagine viste **senza cookie e senza identificativi
 * persistenti**: è la ragione per cui compaiono nel piano al posto di Google
 * Analytics, ed è ciò che rende la Cookie Policy di questo sito corta.
 *
 * L'unica differenza che il codice deve conoscere è il nome dell'attributo con
 * cui lo script riconosce il sito: la sa questo enum, non il file `.env` e non
 * la vista.
 */
enum AnalyticsProvider: string
{
    case Plausible = 'plausible';
    case Umami = 'umami';

    public function label(): string
    {
        return __('enums.analytics_provider.'.$this->value);
    }

    /**
     * L'attributo `data-*` che porta il valore di `ANALYTICS_DOMAIN`.
     */
    public function siteAttribute(): string
    {
        return match ($this) {
            self::Plausible => 'data-domain',
            self::Umami => 'data-website-id',
        };
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

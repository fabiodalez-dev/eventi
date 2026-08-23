<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * A che cosa si applica una modifica su una data: a quella sola, oppure a
 * tutta la serie che la ricorrenza genera (§9.2, «modifica singola occorrenza
 * vs modifica intera serie»).
 *
 * Non è uno stato salvato su nessuna colonna: è la domanda che il pannello
 * pone prima di agire. Vive fra gli enum lo stesso, perché è un valore di
 * dominio che comparirà anche nel pannello dei locali e nell'API, e una
 * stringa magica ripetuta in tre punti diverge sempre.
 */
enum OccurrenceScope: string
{
    case Single = 'single';
    case Series = 'series';

    public function label(): string
    {
        return __('admin.scopes.'.$this->value);
    }

    public function helpText(): string
    {
        return __('admin.scopes.'.$this->value.'_help');
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

<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Come un'unità a visualizzazioni spende il suo obiettivo nel tempo.
 *
 * Vale solo per i prodotti a visualizzazioni (`impressions_goal` valorizzato):
 * per quelli a periodo non c'è niente da distribuire, e il valore resta quello
 * predefinito senza effetti.
 *
 * Nel database è una stringa e non un ENUM, per la stessa ragione delle altre
 * tabelle nuove: aggiungere un caso a un ENUM MySQL vuol dire una migrazione.
 */
enum SponsorshipPacing: string
{
    /** Distribuita sul periodo, seguendo il traffico previsto ora per ora. */
    case Even = 'even';

    /** Il prima possibile, con la quota massima fino all'obiettivo. */
    case Asap = 'asap';

    public function label(): string
    {
        return __('enums.sponsorship_pacing.'.$this->value);
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
}

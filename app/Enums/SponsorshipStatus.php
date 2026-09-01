<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Lo stato di una campagna.
 *
 * **`Scheduled` e `Active` non sono due stati diversi ma lo stesso stato letto
 * in momenti diversi**, ed è di proposito che qui ce n'è uno solo — `Active` —
 * mentre «è in corso adesso» lo decide la finestra temporale della campagna.
 * Uno stato che deve essere aggiornato da un processo notturno per restare
 * vero è uno stato che prima o poi mente: basta che il processo non giri.
 */
enum SponsorshipStatus: string
{
    /** In lavorazione: non compare mai, qualunque cosa dica la finestra. */
    case Draft = 'draft';

    /** Approvata: compare quando la finestra temporale è aperta. */
    case Active = 'active';

    /** Sospesa a metà campagna, per esempio per un pagamento non arrivato. */
    case Paused = 'paused';

    public function label(): string
    {
        return __('enums.sponsorship_status.'.$this->value);
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

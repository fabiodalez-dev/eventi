<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Le tre finestre delle statistiche del locale: 7, 30 e 90 giorni (§10.5).
 *
 * Sono un enum e non tre numeri sparsi perché compaiono in tre posti — i
 * pulsanti della pagina, la query che somma le righe e l'indirizzo che il
 * gestore può copiare — e tre elenchi separati prima o poi divergono.
 *
 * I valori sono parole e non cifre: una chiave numerica in un array PHP
 * diventa un intero, e l'elenco delle opzioni smetterebbe di essere la mappa
 * `stringa => etichetta` che ogni altro enum del progetto restituisce.
 */
enum StatsPeriod: string
{
    case Week = 'week';
    case Month = 'month';
    case Quarter = 'quarter';

    public function days(): int
    {
        return match ($this) {
            self::Week => 7,
            self::Month => 30,
            self::Quarter => 90,
        };
    }

    public static function default(): self
    {
        return self::Month;
    }

    /**
     * Una sola chiave per tutte e tre le voci: cambiare i periodi non deve
     * costringere ad aggiungere una traduzione.
     */
    public function label(): string
    {
        return __('enums.stats_period.days', ['days' => $this->days()]);
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

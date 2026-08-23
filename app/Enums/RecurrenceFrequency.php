<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Ogni quanto si ripete una serata, detto come lo direbbe chi la organizza:
 * *ogni settimana*, *una settimana sì e una no*, *ogni mese* (§10.4).
 *
 * Il gestore sceglie una di queste voci; la stringa RFC 5545 la costruisce
 * `App\Support\RecurrenceRule`, che è l'unico punto del progetto autorizzato a
 * conoscerla.
 */
enum RecurrenceFrequency: string
{
    case Daily = 'daily';
    case Weekly = 'weekly';
    case Biweekly = 'biweekly';
    case Monthly = 'monthly';

    /**
     * La parte `FREQ=` della regola.
     */
    public function rruleFrequency(): string
    {
        return match ($this) {
            self::Daily => 'DAILY',
            self::Weekly, self::Biweekly => 'WEEKLY',
            self::Monthly => 'MONTHLY',
        };
    }

    /**
     * Ogni quante ripetizioni: due per «una settimana sì e una no», una per
     * tutto il resto.
     */
    public function interval(): int
    {
        return $this === self::Biweekly ? 2 : 1;
    }

    /**
     * Vero se ha senso chiedere *in quali giorni*. Per «ogni mese» la data la
     * dà il giorno di partenza, e chiedere un giorno della settimana
     * porterebbe a una regola che il gestore non ha inteso.
     */
    public function acceptsWeekdays(): bool
    {
        return $this === self::Weekly || $this === self::Biweekly;
    }

    public static function fromRrule(string $frequency, int $interval): self
    {
        return match (strtoupper(trim($frequency))) {
            'DAILY' => self::Daily,
            'MONTHLY' => self::Monthly,
            default => $interval >= 2 ? self::Biweekly : self::Weekly,
        };
    }

    public function label(): string
    {
        return __('enums.recurrence_frequency.'.$this->value);
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

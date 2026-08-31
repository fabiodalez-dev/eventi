<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Come è stata espressa la scelta, scritto in `consent_logs.action`.
 *
 * Serve alla prova richiesta da §16 («log del consenso»): un registro che dice
 * *cosa* è stato scelto ma non *come*, non distingue chi ha premuto un pulsante
 * da chi ha aperto il pannello e spuntato le caselle una per una — e la
 * seconda è la prova più solida delle due.
 */
enum ConsentAction: string
{
    case AcceptAll = 'accept_all';
    case RejectAll = 'reject_all';
    case Custom = 'custom';

    public function label(): string
    {
        return __('enums.consent_action.'.$this->value);
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

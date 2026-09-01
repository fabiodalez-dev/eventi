<?php

declare(strict_types=1);

namespace App\Filament\Support;

use App\Enums\AccessibilityFeature;
use Filament\Forms\Components\Select;

/**
 * Le sei voci di accessibilità di un locale, una scelta a tre valori
 * ciascuna: **sì**, **no**, **non dichiarato**.
 *
 * Non sono caselle da spuntare, e la ragione è tutta qui: una casella ha due
 * stati, e schiaccerebbe «non lo sappiamo» su «no». Chi ha bisogno di sapere
 * se si entra senza scalini merita di leggere che il locale non l'ha detto,
 * invece di ricevere un no che nessuno ha mai scritto.
 *
 * Il segnaposto resta il valore predefinito: un modulo appena aperto non
 * dichiara niente per conto del locale.
 */
final class AccessibilityField
{
    /**
     * @param  'admin'|'manage'  $dictionary  il file di `lang/it` da cui prendere le parole
     * @return list<Select>
     */
    public static function make(string $dictionary): array
    {
        return array_map(
            static fn (AccessibilityFeature $feature): Select => Select::make('accessibility.'.$feature->value)
                ->label($feature->label())
                ->boolean(
                    __($dictionary.'.accessibility.yes'),
                    __($dictionary.'.accessibility.no'),
                    __($dictionary.'.accessibility.undeclared'),
                )
                ->native(false),
            AccessibilityFeature::cases(),
        );
    }
}

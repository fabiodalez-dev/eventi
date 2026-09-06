<?php

declare(strict_types=1);

namespace App\Filament\Support;

use App\DTOs\Fact;
use App\DTOs\FactList;
use App\Rules\FactRows;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\TextInput;

/**
 * Il ripetitore di una scheda tecnica — `events.facts`, `venues.info` — uno
 * per entrambi i pannelli.
 *
 * Vale la stessa impostazione di `ExternalLinksField`: **la struttura è
 * comune, le parole no**, perché `lang/it/admin.php` e `lang/it/manage.php`
 * restano separati di proposito. E la validazione è una sola — `FactRows` —
 * invece di due messaggi diversi per lo stesso difetto.
 */
final class FactsField
{
    /**
     * @param  'admin'|'manage'  $dictionary  il file di `lang/it` da cui prendere le parole
     * @param  string  $name  `facts` sull'evento, `info` sul locale
     */
    public static function make(string $dictionary, string $name = 'facts'): Repeater
    {
        return Repeater::make($name)
            ->columnSpanFull()
            ->hiddenLabel()
            ->helperText(__($dictionary.'.hints.facts', ['max' => FactList::MAX_FACTS]))
            ->addActionLabel(__($dictionary.'.actions.add_fact'))
            ->defaultItems(0)
            ->maxItems(FactList::MAX_FACTS)
            ->reorderableWithButtons()
            ->collapsible()
            ->itemLabel(static fn (array $state): ?string => self::itemLabel($state))
            ->columns(2)
            ->rules([new FactRows])
            ->schema([
                TextInput::make('label')
                    ->label(__($dictionary.'.fields.fact_label'))
                    ->extraInputAttributes(['maxlength' => Fact::MAX_LABEL_LENGTH]),

                TextInput::make('value')
                    ->label(__($dictionary.'.fields.fact_value'))
                    ->extraInputAttributes(['maxlength' => Fact::MAX_VALUE_LENGTH]),
            ]);
    }

    /**
     * @param  array<string, mixed>  $state
     */
    private static function itemLabel(array $state): ?string
    {
        $label = is_scalar($state['label'] ?? null) ? trim((string) $state['label']) : '';

        return $label === '' ? null : $label;
    }
}

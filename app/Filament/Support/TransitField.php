<?php

declare(strict_types=1);

namespace App\Filament\Support;

use App\DTOs\TransitGuide;
use App\DTOs\TransitLine;
use App\Enums\TransitMode;
use App\Rules\TransitRows;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;

/**
 * Il ripetitore di «Come arrivare» (`venues.transit`), uno per entrambi i
 * pannelli.
 *
 * Il mezzo è una scelta e non un campo libero: quella colonna è un'etichetta
 * di sei caratteri, e senza un elenco chiuso in sei mesi conterrebbe «Metro»,
 * «metropolitana», «MM» e «M2». Il numero della linea e la fermata stanno nel
 * testo, che è dove una persona li cerca.
 */
final class TransitField
{
    /**
     * @param  'admin'|'manage'  $dictionary  il file di `lang/it` da cui prendere le parole
     */
    public static function make(string $dictionary): Repeater
    {
        return Repeater::make('transit')
            ->columnSpanFull()
            ->hiddenLabel()
            ->helperText(__($dictionary.'.hints.transit', ['max' => TransitGuide::MAX_LINES]))
            ->addActionLabel(__($dictionary.'.actions.add_transit'))
            ->defaultItems(0)
            ->maxItems(TransitGuide::MAX_LINES)
            ->reorderableWithButtons()
            ->collapsible()
            ->itemLabel(static fn (array $state): ?string => self::itemLabel($state))
            ->columns(['default' => 1, 'lg' => 2])
            ->rules([new TransitRows])
            ->schema([
                Select::make('mode')
                    ->label(__($dictionary.'.fields.transit_mode'))
                    ->options(TransitMode::options())
                    ->default(TransitMode::Bus->value),

                TextInput::make('text')
                    ->label(__($dictionary.'.fields.transit_text'))
                    ->extraInputAttributes(['maxlength' => TransitLine::MAX_TEXT_LENGTH]),
            ]);
    }

    /**
     * @param  array<string, mixed>  $state
     */
    private static function itemLabel(array $state): ?string
    {
        $mode = is_string($state['mode'] ?? null) ? TransitMode::tryFrom($state['mode']) : null;

        return $mode?->label();
    }
}

<?php

declare(strict_types=1);

namespace App\Filament\Support;

use App\Enums\TicketTierStatus;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Illuminate\Database\Eloquent\Builder;

/**
 * Il listino di un evento — settore, prezzo, stato — uno per entrambi i
 * pannelli.
 *
 * **Il ripetitore vede soltanto le fasce dell'evento** (`occurrence_id` nullo):
 * quelle di una singola data sono un'eccezione a quella serata e si governano
 * dall'elenco delle date, non da qui. La restrizione conta anche per la
 * cancellazione — Filament elimina le righe scomparse dallo **stesso insieme
 * filtrato**, quindi il listino di una serata non sparisce perché qualcuno ha
 * salvato la scheda dell'evento.
 *
 * Il prezzo che finisce sulla card lo ricalcola `TicketTierObserver` da queste
 * righe: chi compila qui non deve toccare anche `price_min`.
 */
final class TicketTiersField
{
    /**
     * Oltre una dozzina di settori non è più un listino ma una mappa della
     * sala, e questa non è la piattaforma che la pubblica.
     */
    public const int MAX_TIERS = 12;

    /**
     * @param  'admin'|'manage'  $dictionary  il file di `lang/it` da cui prendere le parole
     */
    public static function make(string $dictionary): Repeater
    {
        return Repeater::make('ticketTiers')
            ->columnSpanFull()
            ->hiddenLabel()
            ->relationship(
                'ticketTiers',
                fn (Builder $query): Builder => $query->whereNull('occurrence_id'),
            )
            ->helperText(__($dictionary.'.hints.ticket_tiers'))
            ->addActionLabel(__($dictionary.'.actions.add_ticket_tier'))
            ->defaultItems(0)
            ->maxItems(self::MAX_TIERS)
            ->orderColumn('sort_order')
            ->reorderableWithButtons()
            ->collapsible()
            ->itemLabel(static fn (array $state): ?string => self::itemLabel($state))
            ->columns(['default' => 1, 'lg' => 2])
            ->schema([
                TextInput::make('name')
                    ->label(__($dictionary.'.fields.tier_name'))
                    ->required()
                    ->maxLength(255),

                TextInput::make('price')
                    ->label(__($dictionary.'.fields.tier_price'))
                    ->helperText(__($dictionary.'.hints.tier_price'))
                    ->numeric()
                    ->minValue(0)
                    ->maxValue(999999),

                Select::make('status')
                    ->label(__($dictionary.'.fields.tier_status'))
                    ->options(TicketTierStatus::options())
                    ->required()
                    ->default(TicketTierStatus::Available->value),

                TextInput::make('url')
                    ->label(__($dictionary.'.fields.tier_url'))
                    ->url()
                    ->maxLength(255)
                    ->columnSpan(['default' => 1, 'lg' => 2]),

                TextInput::make('note')
                    ->label(__($dictionary.'.fields.tier_note'))
                    ->maxLength(255),
            ]);
    }

    /**
     * @param  array<string, mixed>  $state
     */
    private static function itemLabel(array $state): ?string
    {
        $name = is_scalar($state['name'] ?? null) ? trim((string) $state['name']) : '';

        return $name === '' ? null : $name;
    }
}

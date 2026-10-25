<?php

declare(strict_types=1);

namespace App\Filament\Support;

use App\Support\VenueClosures;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\TextInput;

/**
 * Le chiusure straordinarie (`venues.closures`), uguali nel pannello del
 * locale e in redazione. Le date sono giorni di calendario: niente fuso da
 * convertire. Al salvataggio passano da `VenueClosures::normalize()`, che
 * toglie quelle finite e le mette in ordine.
 */
final class VenueClosuresField
{
    public static function make(): Repeater
    {
        return Repeater::make('closures')
            ->label(__('manage.closures.title'))
            ->helperText(__('manage.closures.hint'))
            ->columnSpanFull()
            ->columns(['default' => 1, 'lg' => 3])
            ->defaultItems(0)
            ->maxItems(VenueClosures::MAX)
            ->addActionLabel(__('manage.closures.add'))
            ->schema([
                DatePicker::make('from')->label(__('manage.closures.from'))->native(false)->displayFormat('d/m/Y')->format('Y-m-d')->required(),
                DatePicker::make('to')->label(__('manage.closures.to'))->native(false)->displayFormat('d/m/Y')->format('Y-m-d')->afterOrEqual('from')->required(),
                TextInput::make('note')->label(__('manage.closures.note'))->placeholder(__('manage.closures.note_placeholder'))->maxLength(120),
            ]);
    }
}

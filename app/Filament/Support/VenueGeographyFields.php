<?php

namespace App\Filament\Support;

use App\Models\City;
use App\Models\Venue;
use App\Support\CurrentCity;
use Filament\Forms\Components\Select;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;

final class VenueGeographyFields
{
    public static function municipality(): Select
    {
        return Select::make('municipality')->label(__('tonight.municipality'))
            ->options(function (Get $get, ?Venue $record): array {
                $city = City::find($get('city_id')) ?? $record->city ?? app(CurrentCity::class)->get();
                // The municipal catalog belongs to the province, not to a unique editorial slug.
                $catalog = $city?->province_code === 'PD' ? 'padova' : $city?->slug;
                $names = config()->array('discovery-geography.'.$catalog.'.municipalities', $city ? [$city->name] : []);

                return array_combine($names, $names);
            })
            ->searchable()->required()->live()
            ->afterStateUpdated(fn (Set $set) => $set('zone', null));
    }

    public static function district(): Select
    {
        return Select::make('zone')->label(__('tonight.district'))
            ->options(array_combine(config('discovery-geography.padova.districts'), config('discovery-geography.padova.districts')))
            ->helperText(__('tonight.district_help'))->searchable()
            ->visible(fn (Get $get): bool => $get('municipality') === 'Padova')
            ->required(fn (Get $get): bool => $get('municipality') === 'Padova')
            ->dehydratedWhenHidden()->dehydrateStateUsing(fn ($state, Get $get) => $get('municipality') === 'Padova' ? $state : null);
    }
}

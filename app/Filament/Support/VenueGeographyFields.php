<?php

namespace App\Filament\Support;

use App\Models\City;
use App\Models\Venue;
use App\Support\CurrentCity;
use App\Support\VenueGeography;
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
                // Il catalogo dei comuni è della provincia, non di uno slug editoriale: la regola è in VenueGeography.
                $names = VenueGeography::municipalities($city);

                return array_combine($names, $names);
            })
            ->searchable()->required()->live()
            ->afterStateUpdated(fn (Set $set) => $set('zone', null));
    }

    public static function district(): Select
    {
        return Select::make('zone')->label(__('tonight.district'))
            ->options(array_combine(VenueGeography::districts(), VenueGeography::districts()))
            ->helperText(__('tonight.district_help'))->searchable()
            ->visible(fn (Get $get): bool => VenueGeography::hasDistricts($get('municipality')))
            ->required(fn (Get $get): bool => VenueGeography::hasDistricts($get('municipality')))
            ->dehydratedWhenHidden()->dehydrateStateUsing(fn ($state, Get $get) => VenueGeography::hasDistricts($get('municipality')) ? $state : null);
    }
}

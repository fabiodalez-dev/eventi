<?php

namespace App\Filament\Admin\Resources\Organizers;

use App\Models\Organizer;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class OrganizerResource extends Resource
{
    protected static ?string $model = Organizer::class;

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedUserGroup;

    protected static ?string $modelLabel = 'organizzatore';

    protected static ?string $pluralModelLabel = 'Organizzatori';

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(1)->components([
            TextInput::make('name')->label('Nome')->required()->maxLength(255),
            Select::make('city_id')->label('Città di riferimento')->relationship('city', 'name')->required()->searchable(),
            Select::make('owner_id')->label('Responsabile')->relationship('owner', 'email')->searchable()->required(),
            Select::make('users')->label('Collaboratori: possono gestire tutti gli eventi di questo organizzatore')->relationship('users', 'email')->multiple()->searchable(),
            Textarea::make('description')->label('Descrizione')->maxLength(20000),
            TextInput::make('website')->label('Sito web')->url()->maxLength(2048),
            TextInput::make('email')->label('Email pubblica')->email()->maxLength(255),
            Toggle::make('is_active')->label('Attivo: profilo pubblico e accesso alla gestione')->default(false),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([TextColumn::make('name')->label('Organizzatore')->searchable(), TextColumn::make('city.name')->label('Città'), TextColumn::make('owner.email')->label('Responsabile'), IconColumn::make('is_active')->label('Attivo')->boolean()])->recordActions([EditAction::make()]);
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ManageOrganizers::route('/')];
    }
}

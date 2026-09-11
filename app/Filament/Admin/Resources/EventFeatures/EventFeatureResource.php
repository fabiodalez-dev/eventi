<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\EventFeatures;

use App\Models\EventFeature;
use App\Support\PracticalIcons;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Field;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\Rule;

class EventFeatureResource extends Resource
{
    protected static ?string $model = EventFeature::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-information-circle';

    protected static ?int $navigationSort = 5;

    public static function getNavigationGroup(): ?string
    {
        return __('admin.navigation.content');
    }

    public static function getModelLabel(): string
    {
        return 'caratteristica evento';
    }

    public static function getPluralModelLabel(): string
    {
        return 'Prima di andare';
    }

    /** @return list<Field> */
    public static function fields(): array
    {
        return [
            TextInput::make('name')->label('Nome visibile')->required()->maxLength(120),
            Select::make('group')->label('Gruppo')->options(array_combine($groups = ['Accessibilità', 'Ingresso', 'Pubblico', 'Servizi', 'Mobilità', 'Regole', 'Informazioni'], $groups))->required()->default('Informazioni'),
            Select::make('icon')->label('Icona')->options(PracticalIcons::previews())->allowHtml()->searchable()->required()->default('check-circle')->rules([Rule::in(array_keys(PracticalIcons::options()))]),
            Textarea::make('description')->label('Spiegazione breve')->helperText('Appare sotto il nome in tutti gli eventi che selezionano questa voce.')->maxLength(500)->rows(2),
            TextInput::make('sort_order')->label('Ordine')->numeric()->integer()->minValue(0)->default(0),
            Toggle::make('is_active')->label('Disponibile')->default(true)->disabled(fn (?Model $record): bool => $record instanceof EventFeature && $record->is_system)->dehydrated(fn (?Model $record): bool => ! ($record instanceof EventFeature && $record->is_system)),
        ];
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([Section::make('Caratteristica riutilizzabile')->description('Le voci di sistema (tessera e accessibilità) si attivano dal dato dell’evento. Le altre si scelgono come tag.')->columns(2)->schema(self::fields())]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('name')->label('Caratteristica')->icon(fn (EventFeature $record): string => 'heroicon-o-'.PracticalIcons::safe($record->icon))->searchable()->sortable(),
            TextColumn::make('group')->label('Gruppo')->sortable(),
            IconColumn::make('is_active')->label('Disponibile')->boolean(),
            IconColumn::make('is_system')->label('Automatica')->boolean(),
        ])->recordActions([EditAction::make()])->defaultSort('group');
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ListEventFeatures::route('/'), 'create' => Pages\CreateEventFeature::route('/create'), 'edit' => Pages\EditEventFeature::route('/{record}/edit')];
    }
}

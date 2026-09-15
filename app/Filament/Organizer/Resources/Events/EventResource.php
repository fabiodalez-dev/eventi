<?php

namespace App\Filament\Organizer\Resources\Events;

use App\Enums\PriceType;
use App\Filament\Support\BeforeGoingFields;
use App\Filament\Support\DescriptionEditor;
use App\Filament\Support\EditorialFields;
use App\Filament\Support\ImageUpload;
use App\Models\Category;
use App\Models\Event;
use App\Models\Organizer;
use Filament\Actions\EditAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Auth\Access\Response;

class EventResource extends Resource
{
    protected static ?string $model = Event::class;

    protected static ?string $slug = 'eventi';

    protected static ?string $tenantOwnershipRelationshipName = 'organizer';

    protected static ?string $modelLabel = 'evento';

    protected static ?string $pluralModelLabel = 'I tuoi eventi';

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedCalendarDays;

    public static function getCreateAuthorizationResponse(): Response
    {
        $tenant = Filament::getTenant();

        return $tenant instanceof Organizer && auth()->user()?->can('create', [Event::class, $tenant]) ? Response::allow() : Response::deny();
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(1)->components([
            TextInput::make('title')->label('Titolo')->required()->maxLength(255),
            ImageUpload::make('poster')->label('Locandina')->collection('poster'),
            DescriptionEditor::make('description')->label('Descrizione completa')->required()->maxLength(50000),
            Select::make('city_id')->label('Città dell’evento')->relationship('city', 'name')->required()->searchable(),
            Select::make('venue_id')->label('Locale principale')->relationship('venue', 'name', fn ($query) => $query->approved())->searchable()->required()->helperText('Le singole date possono svolgersi in altri locali.'),
            Select::make('category_id')->label('Categoria')->options(fn () => Category::query()->active()->pluck('name', 'id'))->required()->searchable(),
            Select::make('tags')->label('Tag')->relationship('tags', 'name')->multiple()->searchable(),
            Select::make('price_type')->label('Prezzo')->options(PriceType::options())->required()->default('free'),
            TextInput::make('price_min')->label('Prezzo da (€)')->numeric()->minValue(0),
            TextInput::make('price_max')->label('Prezzo fino a (€)')->numeric()->minValue(0),
            TextInput::make('ticket_url')->label('Link biglietti')->url()->maxLength(2048),
            Section::make(__('facebook_import.first_date'))->visibleOn('create')->columns(2)->schema([
                DateTimePicker::make('starts_at')->label(__('facebook_import.starts_at'))->seconds(false)->requiredWith('ends_at')->dehydrated(false),
                DateTimePicker::make('ends_at')->label(__('facebook_import.ends_at'))->seconds(false)->after('starts_at')->dehydrated(false),
            ]),
            Section::make(__('facebook_import.location'))->description(__('facebook_import.organizer_location'))->visibleOn('create')->schema([
                TextInput::make('custom_location.name')->label(__('facebook_import.place_name'))->maxLength(255),
                TextInput::make('custom_location.address')->label(__('facebook_import.address'))->maxLength(255),
                TextInput::make('custom_location.lat')->label(__('facebook_import.latitude'))->numeric()->minValue(-90)->maxValue(90),
                TextInput::make('custom_location.lng')->label(__('facebook_import.longitude'))->numeric()->minValue(-180)->maxValue(180),
            ]),
            BeforeGoingFields::make(),
            EditorialFields::content(event: true),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([TextColumn::make('title')->label('Evento')->searchable()->wrap(), TextColumn::make('venue.name')->label('Locale principale')->wrap(), TextColumn::make('status')->label('Stato')->badge()])->recordActions([EditAction::make()]);
    }

    public static function getRelations(): array
    {
        return [DatesRelationManager::class];
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ListEvents::route('/'), 'create' => Pages\CreateEvent::route('/create'), 'edit' => Pages\EditEvent::route('/{record}/edit')];
    }
}

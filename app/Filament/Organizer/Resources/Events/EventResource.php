<?php

namespace App\Filament\Organizer\Resources\Events;

use App\Enums\EventStatus;
use App\Enums\PriceType;
use App\Filament\Concerns\HasSentenceCaseNavigationLabel;
use App\Filament\Organizer\Pages\EventAnalyticsDetail;
use App\Filament\Organizer\Pages\Sponsorships;
use App\Filament\Support\BeforeGoingFields;
use App\Filament\Support\DescriptionEditor;
use App\Filament\Support\EditorialFields;
use App\Filament\Support\ImageUpload;
use App\Filament\Support\SameNightNearbyField;
use App\Filament\Support\VenueClosureWarning;
use App\Filament\Venue\Resources\Events\EventResource as VenueEventResource;
use App\Filament\Venue\Support\EventActions;
use App\Models\Category;
use App\Models\City;
use App\Models\Event;
use App\Models\Organizer;
use App\Models\Venue;
use App\Services\Management\ManagedContent;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Auth\Access\Response;
use Illuminate\Database\Eloquent\Builder;

class EventResource extends Resource
{
    use HasSentenceCaseNavigationLabel;

    protected static ?string $model = Event::class;

    protected static ?string $slug = 'eventi';

    protected static ?string $tenantOwnershipRelationshipName = 'organizer';

    /*
     * L'elenco non si limita a `organizer_id`: un evento co-organizzato sta
     * nel pannello di tutti quelli che lo fanno. La creazione resta a nome
     * dell'organizzatore corrente (vedi `CreateEvent`).
     */
    protected static bool $isScopedToTenant = false;

    protected static ?string $modelLabel = 'evento';

    protected static ?string $pluralModelLabel = 'I tuoi eventi';

    protected static bool $hasTitleCaseModelLabel = false;

    protected static ?string $recordTitleAttribute = 'title';

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedCalendarDays;

    protected static ?int $navigationSort = 1;

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
                DateTimePicker::make('starts_at')->label(__('facebook_import.starts_at'))->seconds(false)->requiredWith('ends_at')->dehydrated(false)->live(onBlur: true),
                DateTimePicker::make('ends_at')->label(__('facebook_import.ends_at'))->seconds(false)->after('starts_at')->dehydrated(false)->live(onBlur: true),
                SameNightNearbyField::make(fn (Get $get): ?array => self::sameNightContext($get)),
                VenueClosureWarning::make(fn (Get $get): array => ['venue' => filled($get('custom_location.address')) ? null : Venue::find($get('venue_id')), 'start' => ($city = City::find($get('city_id'))) === null ? null : SameNightNearbyField::time($get('starts_at'), $city->timezone)]),
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

    /**
     * Nel modulo di creazione la prima data è scritta nell'ora della città
     * scelta (vedi `CreateEvent::afterCreate`); il luogo è l'indirizzo a mano,
     * se ha le coordinate, altrimenti il locale principale.
     *
     * @return array<string, mixed>|null
     */
    private static function sameNightContext(Get $get): ?array
    {
        $city = City::find($get('city_id'));

        return SameNightNearbyField::context($city, [(array) $get('custom_location'), Venue::find($get('venue_id'))], $get('starts_at'), $get('ends_at'), $city->timezone ?? 'UTC', categoryId: $get('category_id'));
    }

    public static function getEloquentQuery(): Builder
    {
        $tenant = Filament::getTenant();
        $query = parent::getEloquentQuery();
        if (! $tenant instanceof Organizer) {
            return $query->whereRaw('1 = 0');
        }

        // La regola sta in `ManagedContent`, la stessa che usa l'API di gestione.
        return ManagedContent::for($tenant)->restrictEvents($query);
    }

    /**
     * Lo stesso elenco a card di `/gestione` (locandina, data che conta, stato
     * in italiano), con in più il locale principale: un organizzatore lavora
     * in posti diversi. La card apre la scheda in lettura; «Modifica» è il
     * pulsante, il resto sta nel menu della riga.
     */
    public static function table(Table $table): Table
    {
        $tenant = Filament::getTenant();
        $city = $tenant instanceof Organizer ? $tenant->city : null;

        return VenueEventResource::listTable($table, $city ?? City::query()->firstOrFail(), showVenue: true)
            ->recordUrl(fn (Event $record): string => static::getUrl('view', ['record' => $record]))
            ->recordActions([
                EditAction::make()->label(__('manage.actions.edit'))->button(),
                ActionGroup::make([
                    ViewAction::make()->label(__('analytics-dashboard.view_event')),
                    // Le stesse pagine collegate di `/gestione`, già filtrate sull'evento.
                    Action::make('statistics')
                        ->label(__('manage.event_links.statistics'))
                        ->icon(Heroicon::OutlinedChartBar)
                        ->url(fn (Event $record): string => EventAnalyticsDetail::getUrl(['subjectType' => 'event', 'subjectId' => $record->getKey()]))
                        ->visible(fn (): bool => EventAnalyticsDetail::canAccess()),
                    Action::make('sponsor')
                        ->label(__('manage.event_links.sponsor'))
                        ->icon(Heroicon::OutlinedMegaphone)
                        ->url(fn (Event $record): string => Sponsorships::getUrl(['event' => $record->getKey()]))
                        ->visible(fn (Event $record): bool => $record->status === EventStatus::Published && Sponsorships::canAccess()),
                    Action::make('tickets')
                        ->label(__('manage.event_links.tickets'))
                        ->icon(Heroicon::OutlinedTicket)
                        ->url(fn (Event $record): ?string => ($date = VenueEventResource::ticketDate($record)) === null ? null : route('ticketing.manage.show', $date))
                        ->visible(fn (Event $record): bool => VenueEventResource::ticketDate($record) !== null),
                    EventActions::viewOnSite(),
                ])->label(__('manage.event_list.more_actions'))->tooltip(__('manage.event_list.more_actions')),
            ]);
    }

    public static function getRelations(): array
    {
        return [DatesRelationManager::class];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListEvents::route('/'),
            'create' => Pages\CreateEvent::route('/create'),
            'view' => Pages\ViewEvent::route('/{record}'),
            'edit' => Pages\EditEvent::route('/{record}/edit'),
        ];
    }
}

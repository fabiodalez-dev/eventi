<?php

declare(strict_types=1);

namespace App\Filament\Venue\Pages;

use App\Enums\VenueType;
use App\Filament\Admin\Support\StructuredFields;
use App\Filament\Support\AccessibilityField;
use App\Filament\Support\FactsField;
use App\Filament\Support\TransitField;
use App\Filament\Venue\Support\CurrentVenue;
use App\Models\Venue;
use BackedEnum;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Forms\Components\KeyValue;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\TimePicker;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;

/**
 * «Il tuo locale» — i dati della **scheda pubblica** del locale, compilati da
 * chi li conosce.
 *
 * §10 li dava per scontati e non esistevano: undici campi vivevano nello
 * schema senza che nessun modulo li mostrasse a chi avrebbe dovuto scriverli.
 * Orari, contatti, accessibilità e «come arrivare» finivano così o vuoti o
 * scritti dalla redazione a orecchio — cioè le quattro cose che sulla scheda
 * di un locale valgono più della descrizione.
 *
 * **Solo il referente.** `VenuePolicy::update()` richiede `requireOwner`: un
 * collaboratore pubblica gli eventi, non cambia l'indirizzo del locale (§3,
 * §18 scenario F). La pagina non compare nel menu a chi non può, e non si apre
 * scrivendone l'indirizzo.
 *
 * **Nome, slug, tipo e coordinate non ci sono.** Sono identità del locale e
 * chiavi della mappa: le cambia la redazione, perché una modifica lì sposta un
 * punto sulla mappa e rompe indirizzi già condivisi.
 */
class VenueProfile extends Page implements HasSchemas
{
    use InteractsWithSchemas;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingStorefront;

    protected static ?int $navigationSort = 2;

    protected static ?string $slug = 'locale';

    protected string $view = 'filament.venue.pages.venue-profile';

    /**
     * Lo stato del modulo. Pubblico perché è Livewire a tenerlo.
     *
     * @var array<string, mixed>
     */
    public array $data = [];

    public static function getNavigationLabel(): string
    {
        return __('manage.venue.title');
    }

    public function getTitle(): string
    {
        return __('manage.venue.title');
    }

    public function getSubheading(): ?string
    {
        return __('manage.venue.subheading');
    }

    public static function canAccess(): bool
    {
        return auth()->user()?->can('update', CurrentVenue::get()) ?? false;
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);

        $venue = CurrentVenue::get();

        $this->venueForm()->fill([
            ...$venue->only([
                'short_description', 'description', 'address', 'address_extra',
                'postal_code', 'municipality', 'zone', 'phone', 'email', 'website',
                'capacity', 'requires_membership', 'membership_notes',
            ]),
            'socials' => $venue->socials ?? [],
            'accessibility' => $venue->accessibility->toArray(),
            'transit' => $venue->transit->toArray(),
            'info' => $venue->info->toArray(),
            // Gli orari si compilano come righe piatte e si salvano come mappa
            // per giorno (D19): la traduzione sta al confine del modulo.
            'opening_hours' => StructuredFields::openingHoursToRows($venue->opening_hours),
        ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->statePath('data')
            ->components([
                Section::make(__('manage.sections.venue_identity'))
                    ->schema([
                        // Il nome e il tipo si leggono e non si toccano: sono
                        // identità, e la cambia la redazione.
                        TextInput::make('venue_name')
                            ->label(__('manage.fields.venue_name'))
                            ->helperText(__('manage.hints.venue_name'))
                            ->disabled()
                            ->dehydrated(false)
                            ->default(fn (): string => (string) CurrentVenue::get()->name),

                        TextInput::make('venue_type')
                            ->label(__('manage.fields.venue_type'))
                            ->disabled()
                            ->dehydrated(false)
                            ->default(fn (): string => CurrentVenue::get()->type->label()),

                        Textarea::make('short_description')
                            ->label(__('manage.fields.short_description'))
                            ->maxLength(500)
                            ->rows(2)
                            ->columnSpanFull(),

                        Textarea::make('description')
                            ->label(__('manage.fields.description'))
                            ->rows(5)
                            ->columnSpanFull(),
                    ])
                    ->columns(2),

                Section::make(__('manage.sections.venue_where'))
                    ->columns(2)
                    ->schema([
                        TextInput::make('address')
                            ->label(__('manage.fields.address'))
                            ->required()
                            ->maxLength(255),

                        TextInput::make('address_extra')
                            ->label(__('manage.fields.address_extra'))
                            ->maxLength(255),

                        TextInput::make('postal_code')
                            ->label(__('manage.fields.postal_code'))
                            ->maxLength(10),

                        TextInput::make('municipality')
                            ->label(__('manage.fields.municipality'))
                            ->required()
                            ->maxLength(255),

                        TextInput::make('zone')
                            ->label(__('manage.fields.zone'))
                            ->helperText(__('manage.hints.zone'))
                            ->maxLength(255)
                            ->columnSpanFull(),
                    ]),

                Section::make(__('manage.sections.venue_hours'))
                    ->schema([
                        Repeater::make('opening_hours')
                            ->hiddenLabel()
                            ->columns(3)
                            ->defaultItems(0)
                            ->addActionLabel(__('manage.actions.add_opening_hours'))
                            ->schema([
                                Select::make('day')
                                    ->label(__('manage.fields.opening_day'))
                                    ->options(self::weekdays())
                                    ->required(),

                                TimePicker::make('open')
                                    ->label(__('manage.fields.opening_from'))
                                    ->seconds(false)
                                    ->required(),

                                TimePicker::make('close')
                                    ->label(__('manage.fields.opening_to'))
                                    ->seconds(false)
                                    ->required(),
                            ]),

                        TextInput::make('capacity')
                            ->label(__('manage.fields.capacity'))
                            ->helperText(__('manage.hints.capacity'))
                            ->numeric()
                            ->minValue(0),
                    ]),

                Section::make(__('manage.sections.venue_contacts'))
                    ->columns(3)
                    ->schema([
                        TextInput::make('phone')
                            ->label(__('manage.fields.phone'))
                            ->tel()
                            ->maxLength(40),

                        TextInput::make('email')
                            ->label(__('manage.fields.email'))
                            ->email()
                            ->maxLength(255),

                        TextInput::make('website')
                            ->label(__('manage.fields.website'))
                            ->url()
                            ->maxLength(255),

                        KeyValue::make('socials')
                            ->label(__('manage.fields.socials'))
                            ->columnSpanFull(),
                    ]),

                Section::make(__('manage.sections.venue_transit'))
                    ->schema([
                        TransitField::make('manage'),
                    ]),

                Section::make(__('manage.sections.venue_accessibility'))
                    ->columns(3)
                    ->schema(AccessibilityField::make('manage')),

                Section::make(__('manage.sections.venue_info'))
                    ->schema([
                        FactsField::make('manage', 'info'),
                    ]),

                Section::make(__('manage.sections.venue_membership'))
                    ->columns(2)
                    ->schema([
                        Toggle::make('requires_membership')
                            ->label(__('manage.fields.requires_membership')),

                        Textarea::make('membership_notes')
                            ->label(__('manage.fields.membership_notes'))
                            ->rows(2),
                    ]),
            ]);
    }

    public function save(): void
    {
        $venue = CurrentVenue::get();

        abort_unless(auth()->user()?->can('update', $venue) ?? false, 403);

        /** @var array<string, mixed> $state */
        $state = $this->venueForm()->getState();

        $state['opening_hours'] = StructuredFields::openingHoursToMap($state['opening_hours'] ?? null);

        $venue->fill($state)->save();

        Notification::make()
            ->title(__('manage.venue.saved'))
            ->body(__('manage.venue.saved_body'))
            ->success()
            ->send();
    }

    /**
     * Il modulo, preso per nome invece che dalla proprietà magica: è lo stesso
     * oggetto, ma dichiarato — e senza dichiararlo l'analisi statica non
     * saprebbe che esiste.
     */
    private function venueForm(): Schema
    {
        $form = $this->getSchema('form');

        abort_if($form === null, 500);

        return $form;
    }

    /**
     * @return array<mixed>
     */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('save')
                ->label(__('manage.actions.save_venue'))
                ->icon(Heroicon::OutlinedCheck)
                ->action('save'),
        ];
    }

    /**
     * I giorni della settimana come li scrive `lang/it`: le chiavi restano
     * quelle inglesi di D19, che sono ciò che finisce nel database.
     *
     * @return array<string, string>
     */
    private static function weekdays(): array
    {
        $monday = CarbonImmutable::now()->startOfWeek();
        $days = [];

        foreach (['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'] as $index => $key) {
            $days[$key] = ucfirst($monday->addDays($index)->locale(app()->getLocale())->isoFormat('dddd'));
        }

        return $days;
    }

    /**
     * Il locale su cui si sta lavorando, per la vista.
     */
    public function getVenue(): Venue
    {
        return CurrentVenue::get();
    }

    /**
     * Il tipo del locale, in chiaro: serve alla sola riga di sola lettura.
     */
    public function venueType(): VenueType
    {
        return $this->getVenue()->type;
    }
}

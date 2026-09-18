<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\Users;

use App\Enums\UserRole;
use App\Enums\VenueRole;
use App\Filament\Admin\Resources\Users\Pages\CreateUser;
use App\Filament\Admin\Resources\Users\Pages\EditUser;
use App\Filament\Admin\Resources\Users\Pages\ListUsers;
use App\Filament\Support\BulkActions;
use App\Filament\Support\RoleField;
use App\Models\City;
use App\Models\User;
use App\Models\Venue;
use App\Models\WhatsappChallenge;
use App\Services\Community\CommunityModeration;
use App\Services\Community\WhatsappVerification;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Support\Facades\Hash;

/**
 * §9.2 — gli utenti, i loro ruoli e l'impersonificazione.
 *
 * **I ruoli sono due cose diverse e restano separate.** Qui si assegnano i
 * ruoli *globali* di `spatie/laravel-permission` (utente, moderatore,
 * amministratore…). L'appartenenza a un locale — referente o collaboratore —
 * vive invece nella pivot `venue_user` e si gestisce dalla scheda del locale:
 * è quella riga, non il ruolo globale, a dire *quale* locale una persona può
 * toccare (§7 delle convenzioni).
 *
 * **La password non si legge e non si conserva nel modulo.** Alla modifica il
 * campo resta vuoto e viene ignorato se lasciato tale: si cambia soltanto
 * scrivendone una nuova.
 */
class UserResource extends Resource
{
    protected static ?string $model = User::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUsers;

    protected static ?int $navigationSort = 1;

    protected static ?string $recordTitleAttribute = 'name';

    public static function getNavigationGroup(): ?string
    {
        return __('admin.navigation.people');
    }

    public static function getModelLabel(): string
    {
        return __('admin.resources.user.label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('admin.resources.user.plural');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(1)
            ->components([
                Section::make(__('admin.sections.account'))
                    ->columns(2)
                    ->schema([
                        Select::make('city_id')
                            ->label(__('users.city'))
                            ->relationship('city', 'name')
                            ->searchable()
                            ->preload()
                            ->helperText(__('users.city_help')),
                        TextInput::make('name')
                            ->label(__('admin.fields.name'))
                            ->required()
                            ->maxLength(255),

                        TextInput::make('email')
                            ->label(__('admin.fields.email'))
                            ->email()
                            ->required()
                            ->maxLength(255)
                            ->unique(ignoreRecord: true),

                        TextInput::make('password')
                            ->label(__('admin.fields.password'))
                            ->password()
                            ->revealable()
                            ->minLength(8)
                            ->required(fn (string $operation): bool => $operation === 'create')
                            ->dehydrated(fn (?string $state): bool => filled($state))
                            ->dehydrateStateUsing(fn (string $state): string => Hash::make($state)),

                        DateTimePicker::make('email_verified_at')
                            ->label(__('admin.fields.email_verified_at'))
                            ->seconds(false),

                        Select::make('timezone')
                            ->label(__('admin.fields.timezone'))
                            ->searchable()
                            ->options(array_combine(
                                timezone_identifiers_list(),
                                timezone_identifiers_list(),
                            ))
                            ->default('Europe/Rome'),

                        Select::make('locale')
                            ->label(__('admin.fields.locale'))
                            ->options(['it' => 'it', 'en' => 'en', 'de' => 'de'])
                            ->default('it'),
                    ]),

                Section::make(__('community.moderation'))->schema([
                    TextInput::make('community_handle')->label(__('community.handle'))->afterStateHydrated(fn (TextInput $component, ?User $record) => $component->state($record?->communityProfile?->handle))->disabled()->dehydrated(false),
                    DateTimePicker::make('whatsapp_verified_at')->label(__('community.whatsapp.title'))->disabled()->dehydrated(false),
                    TextInput::make('whatsapp_masked')->label(__('community.whatsapp.phone'))->afterStateHydrated(fn (TextInput $component, ?User $record) => $component->state($record?->whatsapp_phone ? '••••'.substr($record->whatsapp_phone, -4) : '—'))->disabled()->dehydrated(false),
                    DateTimePicker::make('community_suspended_at')->label(__('community.suspend'))->disabled()->dehydrated(false),
                ]),

                Section::make(__('admin.sections.roles'))
                    ->description(__('users.roles_help'))
                    ->schema([
                        RoleField::make(),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label(__('admin.fields.name'))
                    ->description(fn (User $record): string => $record->email)
                    ->searchable(['name', 'email'])
                    ->wrap()
                    ->sortable(),

                TextColumn::make('email')
                    ->label(__('admin.fields.email'))
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->searchable(),

                TextColumn::make('roles.name')
                    ->label(__('users.platform_roles'))
                    ->badge()
                    ->placeholder(UserRole::User->label())
                    ->formatStateUsing(fn (string $state): string => self::roleLabel($state)),

                TextColumn::make('memberships')
                    ->label(__('users.memberships'))
                    ->searchable(query: fn (Builder $query, string $search): Builder => $query->whereHas('venues', fn (Builder $venues): Builder => $venues->where('name', 'like', "%{$search}%")))
                    ->state(fn (User $record): array => $record->venues->map(fn (Venue $venue): string => $venue->name.' ('.$venue->city?->name.') — '.(VenueRole::tryFrom((string) $venue->getRelation('pivot')->getAttribute('role'))?->label() ?? '—'))->all())
                    ->listWithLineBreaks()
                    ->wrap()
                    ->placeholder(__('users.no_venues')),

                TextColumn::make('city.name')
                    ->label(__('users.city'))
                    ->placeholder(__('users.unknown_city'))
                    ->sortable(),

                TextColumn::make('managed_city_names')
                    ->state(fn (User $record): array => $record->venues->pluck('city.name')->filter()->unique()->values()->all())
                    ->label(__('users.venue_cities'))
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->distinctList()
                    ->listWithLineBreaks(),

                TextColumn::make('email_verified_at')
                    ->label(__('admin.fields.email_verified_at'))
                    ->dateTime('d/m/Y H:i')
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->placeholder(__('admin.placeholders.never')),

                TextColumn::make('whatsapp_verified_at')->label(__('community.whatsapp.title'))->dateTime('d/m/Y H:i')->placeholder(__('admin.placeholders.never'))->sortable(),
                TextColumn::make('community_suspended_at')->label(__('community.suspend'))->dateTime('d/m/Y H:i')->placeholder('—')->sortable(),
                TextColumn::make('communityProfile.handle')->label(__('community.handle'))->searchable()->toggleable(),
                TextColumn::make('last_active_at')
                    ->label(__('admin.fields.last_active_at'))
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->dateTime('d/m/Y H:i')
                    ->placeholder(__('admin.placeholders.never'))
                    ->sortable(),
            ])
            ->defaultSort('name')
            ->filters([
                SelectFilter::make('city_id')
                    ->label(__('users.city'))
                    ->relationship('city', 'name')->searchable()->preload(),
                SelectFilter::make('venue_city')
                    ->label(__('users.venue_cities'))
                    ->options(fn (): array => City::query()->orderBy('name')->pluck('name', 'id')->all())
                    ->query(fn (Builder $query, array $data): Builder => $query->when($data['value'] ?? null, fn (Builder $query, $city): Builder => $query->whereHas('venues', fn (Builder $venues): Builder => $venues->where('city_id', $city)))),
                SelectFilter::make('venues')->label(__('users.venue'))
                    ->relationship('venues', 'name')->searchable()->preload(),
                TernaryFilter::make('missing_city')->label(__('users.missing_city'))
                    ->queries(true: fn (Builder $query): Builder => $query->whereNull('city_id'), false: fn (Builder $query): Builder => $query->whereNotNull('city_id')),
                SelectFilter::make('roles')
                    ->label(__('admin.fields.roles'))
                    ->relationship('roles', 'name')
                    ->options(UserRole::options()),

                TernaryFilter::make('email_verified_at')
                    ->label(__('admin.fields.email_verified_at'))
                    ->nullable(),

                TernaryFilter::make('whatsapp_verified_at')->label(__('community.whatsapp.title'))->nullable(),
                TernaryFilter::make('community_suspended_at')->label(__('community.suspend'))->nullable(),
                TrashedFilter::make(),
            ])
            ->recordActions([
                EditAction::make(),
                self::impersonateAction(),
                Action::make('community_suspend')->label(fn (User $record): string => __($record->community_suspended_at ? 'community.unsuspend' : 'community.suspend'))
                    ->visible(fn (User $record): bool => ! $record->trashed() && auth()->user()?->isEditorialStaff() === true)->requiresConfirmation()
                    ->action(fn (User $record) => app(CommunityModeration::class)->apply(auth()->user(), 'user', $record->id, $record->community_suspended_at !== null)),
                Action::make('whatsapp_revoke')->label(__('community.whatsapp.revoke'))->requiresConfirmation()
                    ->visible(fn (User $record): bool => $record->whatsapp_verified_at !== null && auth()->user()?->hasAnyRole(['admin', 'super_admin']) === true)
                    ->action(function (User $record): void {
                        abort_unless(auth()->user()?->hasAnyRole(['admin', 'super_admin']), 403);
                        app(WhatsappVerification::class)->revoke($record, auth()->user());
                    }),
                Action::make('whatsapp_history')->label(__('community.verification_history'))
                    ->visible(fn (): bool => auth()->user()?->isEditorialStaff() === true)
                    ->modalContent(fn (User $record) => view('filament.admin.pages.whatsapp-history', ['challenges' => WhatsappChallenge::query()->where('user_id', $record->id)->latest()->limit(30)->get()]))
                    ->modalSubmitAction(false)->modalCancelActionLabel(__('community.close')),

            ])
            ->toolbarActions([
                BulkActions::make(),
            ]);
    }

    private static function impersonateAction(): Action
    {
        return Action::make('impersonate')
            ->label(__('admin.actions.impersonate'))
            ->icon(Heroicon::OutlinedUserCircle)
            ->requiresConfirmation()
            ->modalDescription(__('admin.confirmations.impersonate'))
            ->authorize(fn (User $record): bool => auth()->user()?->can('impersonate', $record) ?? false)
            // Il passaggio di identità avviene su una rotta propria e non
            // dentro l'azione: una richiesta Livewire che cambia l'utente
            // autenticato a metà del proprio ciclo lascerebbe la pagina
            // aperta con i dati di prima.
            ->action(fn (User $record) => redirect()->route('impersonate.start', ['user' => $record->getKey()]));
    }

    private static function roleLabel(string $name): string
    {
        return UserRole::tryFrom($name)?->label() ?? $name;
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with(['roles', 'venues.city', 'city'])->withoutGlobalScopes([SoftDeletingScope::class]);
    }

    /**
     * @return array<string, mixed>
     */
    public static function getPages(): array
    {
        return [
            'index' => ListUsers::route('/'),
            'create' => CreateUser::route('/create'),
            'edit' => EditUser::route('/{record}/edit'),
        ];
    }
}

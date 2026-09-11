<?php

declare(strict_types=1);

namespace App\Filament\Venue\Pages;

use App\Actions\InviteVenueMemberAction;
use App\Enums\VenueRole;
use App\Filament\Venue\Support\CurrentVenue;
use App\Models\User;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * §10.6 — i collaboratori, **visibili solo ai referenti**.
 *
 * La pagina non compare nemmeno nel menu a un collaboratore, e non si apre
 * scrivendone l'indirizzo: `canAccess()` interroga
 * `VenuePolicy::manageCollaborators()`, che è il permesso del referente e
 * dello staff, mai dell'editor. È la seconda metà della clausola di §3 —
 * «l'editor non può toccare dati sensibili del locale, proprietari, inviti» —
 * di cui la prima metà è che qui, e solo qui, compaiono gli indirizzi email
 * delle persone.
 *
 * **Si invitano collaboratori, non referenti.** Un referente in più cambia chi
 * risponde del locale, ed è una decisione della redazione: da qui si aggiunge
 * chi pubblica gli eventi, non chi possiede la scheda.
 */
class Collaborators extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUserGroup;

    protected static ?int $navigationSort = 3;

    protected static ?string $slug = 'collaboratori';

    protected string $view = 'filament.venue.pages.collaborators';

    public static function getNavigationLabel(): string
    {
        return __('manage.collaborators.title');
    }

    public function getTitle(): string
    {
        return __('manage.collaborators.title');
    }

    public function getSubheading(): ?string
    {
        return __('manage.collaborators.subheading');
    }

    public static function canAccess(): bool
    {
        return auth()->user()?->can('manageCollaborators', CurrentVenue::get()) ?? false;
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn (): Builder => $this->members())
            ->columns([
                TextColumn::make('name')
                    ->label(__('manage.fields.name'))
                    ->searchable()
                    ->weight('bold'),

                TextColumn::make('email')
                    ->label(__('manage.fields.email'))
                    ->searchable(),

                TextColumn::make('venue_role')
                    ->label(__('manage.fields.role'))
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => VenueRole::from($state)->label())
                    ->color(fn (string $state): string => $state === VenueRole::Owner->value ? 'primary' : 'gray'),

                TextColumn::make('venue_accepted_at')
                    ->label(__('manage.fields.accepted_at'))
                    ->dateTime('d/m/Y')
                    ->placeholder(__('manage.placeholders.pending_invite')),
            ])
            ->defaultSort('name')
            ->headerActions([
                $this->inviteAction(),
            ])
            ->recordActions([
                $this->removeAction(),
            ]);
    }

    /**
     * L'invito di §10.6. Il messaggio, l'account e il ruolo li mette
     * `InviteVenueMemberAction`: qui c'è solo la domanda da porre.
     */
    private function inviteAction(): Action
    {
        return Action::make('invite')
            ->label(__('manage.collaborators.invite'))
            ->icon(Heroicon::OutlinedEnvelope)
            ->modalDescription(__('manage.collaborators.invite_description'))
            ->modalSubmitActionLabel(__('manage.collaborators.invite_submit'))
            ->authorize(fn (): bool => static::canAccess())
            ->schema([
                TextInput::make('name')
                    ->label(__('manage.fields.name'))
                    ->required()
                    ->maxLength(255),

                TextInput::make('email')
                    ->label(__('manage.fields.email'))
                    ->email()
                    ->required()
                    ->maxLength(255),
            ])
            ->action(function (array $data): void {
                app(InviteVenueMemberAction::class)->execute(
                    CurrentVenue::get(),
                    (string) $data['email'],
                    (string) $data['name'],
                    VenueRole::Editor,
                );

                Notification::make()
                    ->title(__('manage.collaborators.invited'))
                    ->body(__('manage.collaborators.invited_body', ['email' => $data['email']]))
                    ->success()
                    ->send();
            });
    }

    private function removeAction(): Action
    {
        return Action::make('remove')
            ->label(__('manage.collaborators.remove'))
            ->icon(Heroicon::OutlinedTrash)
            ->color('danger')
            ->requiresConfirmation()
            ->modalDescription(__('manage.collaborators.remove_confirm'))
            ->authorize(fn (): bool => static::canAccess())
            // Un referente non si toglie da qui: chi risponde del locale lo
            // decide la redazione. E nessuno può togliere se stesso, o
            // resterebbe un locale senza nessuno che lo gestisce.
            ->visible(fn (User $record): bool => $record->getAttribute('venue_role') !== VenueRole::Owner->value)
            ->action(function (User $record): void {
                abort_unless(static::canAccess(), 403);
                abort_if($record->getAttribute('venue_role') === VenueRole::Owner->value, 403);
                CurrentVenue::get()->members()->detach($record->getKey());

                Notification::make()
                    ->title(__('manage.collaborators.removed'))
                    ->success()
                    ->send();
            });
    }

    /**
     * Le persone del locale corrente, con il ruolo che hanno **qui**: il
     * ruolo sta nella pivot, non sull'utente, perché la stessa persona può
     * essere referente di un locale e collaboratore di un altro.
     *
     * @return Builder<User>
     */
    private function members(): Builder
    {
        return User::query()
            ->join('venue_user', 'venue_user.user_id', '=', 'users.id')
            ->where('venue_user.venue_id', CurrentVenue::get()->getKey())
            ->select([
                'users.*',
                'venue_user.role as venue_role',
                'venue_user.invited_at as venue_invited_at',
                'venue_user.accepted_at as venue_accepted_at',
            ]);
    }
}

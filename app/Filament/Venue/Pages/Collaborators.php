<?php

declare(strict_types=1);

namespace App\Filament\Venue\Pages;

use App\Actions\InviteVenueMemberAction;
use App\Enums\VenueRole;
use App\Filament\Venue\Support\CurrentVenue;
use App\Models\User;
use App\Services\Management\CollaboratorOutcome;
use App\Services\Management\VenueCollaborators;
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
 *
 * **Un invito si accetta (D64).** Finché la persona non accetta, l'elenco
 * mostra solo l'email mascherata e «Invito in attesa»: il nome è quello del
 * suo account, non quello scritto nel modulo, e un referente non deve poterlo
 * leggere scrivendo un indirizzo a caso. Dalla stessa riga l'invito si
 * reinvia o si ritira, con lo stesso tetto degli inviti.
 *
 * Le regole (elenco, tetto, reinvio, ritiro, rimozione) stanno in
 * `VenueCollaborators`, che usa anche l'API dell'app: qui restano le domande
 * da porre e gli avvisi.
 */
class Collaborators extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUserGroup;

    protected static ?int $navigationSort = 4;

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
                    ->state(fn (User $record): string => self::accepted($record) ? (string) $record->name : __('manage.collaborators.pending_name'))
                    // Il nome si cerca solo fra chi ha accettato: cercarlo
                    // fra gli inviti direbbe di chi è l'account.
                    ->searchable(query: fn (Builder $query, string $search): Builder => $query
                        ->whereNotNull('venue_user.accepted_at')
                        ->where('users.name', 'like', '%'.$search.'%'))
                    ->weight('bold'),

                TextColumn::make('email')
                    ->label(__('manage.fields.email'))
                    ->state(fn (User $record): string => self::accepted($record) ? (string) $record->email : self::masked((string) $record->email))
                    ->searchable(query: fn (Builder $query, string $search): Builder => $query->where('users.email', 'like', '%'.$search.'%')),

                TextColumn::make('venue_role')
                    ->label(__('manage.fields.role'))
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => VenueRole::from($state)->label())
                    ->color(fn (string $state): string => $state === VenueRole::Owner->value ? 'primary' : 'gray'),

                TextColumn::make('venue_accepted_at')
                    ->label(__('manage.fields.accepted_at'))
                    ->dateTime('d/m/Y')
                    ->placeholder(__('manage.placeholders.pending_invite')),

                TextColumn::make('venue_invited_at')
                    ->label(__('manage.collaborators.invited_on'))
                    ->dateTime('d/m/Y')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            /* Prima chi è dentro, per nome; poi gli inviti, per email. */
            ->defaultSort(fn (Builder $query): Builder => $this->collaborators()->sorted($query))
            ->headerActions([
                $this->inviteAction(),
            ])
            ->recordActions([
                $this->resendAction(),
                $this->withdrawAction(),
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
                abort_unless(static::canAccess(), 403);
                $outcome = $this->collaborators()->invite(CurrentVenue::get(), $this->actor(), (string) $data['email'], (string) $data['name']);

                if ($outcome !== CollaboratorOutcome::Invited) {
                    $this->warn($outcome);

                    return;
                }

                Notification::make()
                    ->title(__('manage.collaborators.invited'))
                    ->body(__('manage.collaborators.invited_body', ['email' => $data['email']]))
                    ->success()
                    ->send();
            });
    }

    /**
     * «Reinvia»: un collegamento nuovo, che fa scadere il precedente. Conta
     * nello stesso tetto degli inviti, perché spedisce lo stesso messaggio.
     */
    private function resendAction(): Action
    {
        return Action::make('resend')
            ->label(__('manage.collaborators.resend'))
            ->icon(Heroicon::OutlinedArrowPath)
            ->requiresConfirmation()
            ->modalDescription(__('manage.collaborators.resend_confirm', ['days' => InviteVenueMemberAction::EXPIRES_DAYS]))
            ->authorize(fn (): bool => static::canAccess())
            ->visible(fn (User $record): bool => $this->isPendingEditorInvite($record))
            ->action(function (User $record): void {
                abort_unless(static::canAccess(), 403);
                $outcome = $this->collaborators()->resend(CurrentVenue::get(), $this->actor(), $record);
                abort_if($outcome === CollaboratorOutcome::NotAllowed, 403);

                if ($outcome !== CollaboratorOutcome::Resent) {
                    $this->warn($outcome);

                    return;
                }

                Notification::make()->title(__('manage.collaborators.resent'))->success()->send();
            });
    }

    private function withdrawAction(): Action
    {
        return Action::make('withdraw')
            ->label(__('manage.collaborators.withdraw'))
            ->icon(Heroicon::OutlinedXMark)
            ->color('danger')
            ->requiresConfirmation()
            ->modalDescription(__('manage.collaborators.withdraw_confirm'))
            ->authorize(fn (): bool => static::canAccess())
            ->visible(fn (User $record): bool => $this->isPendingEditorInvite($record))
            ->action(function (User $record): void {
                abort_unless(static::canAccess(), 403);
                abort_unless($this->collaborators()->withdraw(CurrentVenue::get(), $this->actor(), $record) === CollaboratorOutcome::Withdrawn, 403);

                Notification::make()->title(__('manage.collaborators.withdrawn'))->success()->send();
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
            // resterebbe un locale senza nessuno che lo gestisce. Un invito
            // in attesa non si «rimuove»: si ritira.
            ->visible(fn (User $record): bool => $record->getAttribute('venue_role') !== VenueRole::Owner->value && self::accepted($record))
            ->action(function (User $record): void {
                abort_unless(static::canAccess(), 403);
                abort_unless($this->collaborators()->remove(CurrentVenue::get(), $this->actor(), $record) === CollaboratorOutcome::Removed, 403);

                Notification::make()
                    ->title(__('manage.collaborators.removed'))
                    ->success()
                    ->send();
            });
    }

    /** Il tetto raggiunto o una persona già del locale: un avviso, niente di più. */
    private function warn(CollaboratorOutcome $outcome): void
    {
        $title = match ($outcome) {
            CollaboratorOutcome::AlreadyInvited => 'manage.collaborators.already_invited',
            CollaboratorOutcome::AlreadyMember => 'manage.collaborators.already_member',
            default => 'manage.collaborators.invite_limit',
        };

        Notification::make()->warning()->title(__($title))->send();
    }

    /** La riga è un invito da collaboratore ancora in attesa: «Reinvia» e «Ritira» si vedono solo lì. */
    private function isPendingEditorInvite(User $record): bool
    {
        return ! self::accepted($record) && $record->getAttribute('venue_role') === VenueRole::Editor->value;
    }

    private static function accepted(User $record): bool
    {
        return VenueCollaborators::accepted($record);
    }

    private static function masked(string $email): string
    {
        return VenueCollaborators::masked($email);
    }

    private function actor(): User
    {
        $user = auth()->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }

    private function collaborators(): VenueCollaborators
    {
        return app(VenueCollaborators::class);
    }

    /**
     * Le persone del locale corrente, inviti in attesa compresi: le colonne
     * li mostrano mascherati.
     *
     * @return Builder<User>
     */
    private function members(): Builder
    {
        return $this->collaborators()->members(CurrentVenue::get());
    }
}

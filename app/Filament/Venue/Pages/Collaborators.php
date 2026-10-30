<?php

declare(strict_types=1);

namespace App\Filament\Venue\Pages;

use App\Actions\InviteVenueMemberAction;
use App\Enums\VenueRole;
use App\Filament\Venue\Support\CurrentVenue;
use App\Models\User;
use App\Support\SecurityLog;
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
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

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
            /* Prima chi è dentro, per nome; poi gli inviti, per email: un
               ordinamento per nome mescolerebbe gli inviti secondo il nome
               dell'account, che non si vede. */
            ->defaultSort(fn (Builder $query): Builder => $query
                ->orderByRaw('venue_user.accepted_at is null')
                ->orderByRaw('case when venue_user.accepted_at is null then users.email else users.name end'))
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
                $venue = CurrentVenue::get();

                if ($this->inviteLimitReached()) {
                    return;
                }

                // Chi è già del locale non si reinvita: il ruolo non cambia da
                // qui, e un secondo messaggio non serve a nessuno. Chi ha un
                // invito in attesa si reinvita dalla sua riga.
                $existing = $venue->membersIncludingInvited()->where('users.email', mb_strtolower(trim((string) $data['email'])))->first();
                if ($existing instanceof User) {
                    $pending = $existing->getRelationValue('pivot')?->getAttribute('accepted_at') === null;
                    Notification::make()->warning()->title(__($pending ? 'manage.collaborators.already_invited' : 'manage.collaborators.already_member'))->send();

                    return;
                }

                $this->hitInviteLimit();

                app(InviteVenueMemberAction::class)->execute(
                    $venue,
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
                abort_unless(static::canAccess() && $this->isPendingEditorInvite($record), 403);

                if ($this->inviteLimitReached()) {
                    return;
                }

                $this->hitInviteLimit();
                app(InviteVenueMemberAction::class)->resend(CurrentVenue::get(), $record, $this->causer());

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
                abort_unless(static::canAccess() && $this->isPendingEditorInvite($record), 403);

                app(InviteVenueMemberAction::class)->withdraw(CurrentVenue::get(), $record, $this->causer());

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
                abort_if($record->getAttribute('venue_role') === VenueRole::Owner->value || ! self::accepted($record), 403);
                $record->venues()->detach(CurrentVenue::get()->getKey());
                SecurityLog::scrivi('accesso_locale_revocato', $record, [
                    'venue_id' => CurrentVenue::get()->getKey(),
                    'ruolo' => $record->getAttribute('venue_role'),
                ], causa: $this->causer());

                Notification::make()
                    ->title(__('manage.collaborators.removed'))
                    ->success()
                    ->send();
            });
    }

    /**
     * Ogni invito crea un account e spedisce un'email dal nostro indirizzo a
     * chi il referente sceglie: senza un tetto, la pagina diventa un modo di
     * scrivere a una lista intera. La chiave è per persona e per locale, come
     * per l'import da Facebook, e la condividono invito e «Reinvia».
     */
    private function inviteLimitReached(): bool
    {
        [$key, $dailyKey] = $this->inviteLimitKeys();

        if (RateLimiter::tooManyAttempts($key, 10) || RateLimiter::tooManyAttempts($dailyKey, 30)) {
            Notification::make()->warning()->title(__('manage.collaborators.invite_limit'))->send();

            return true;
        }

        return false;
    }

    private function hitInviteLimit(): void
    {
        [$key, $dailyKey] = $this->inviteLimitKeys();

        RateLimiter::hit($key, 3600);
        RateLimiter::hit($dailyKey, 86400);
    }

    /** @return array{0: string, 1: string} */
    private function inviteLimitKeys(): array
    {
        $key = 'venue-invite:'.auth()->id().':'.CurrentVenue::get()->getKey();

        return [$key, $key.':daily'];
    }

    /** Gli inviti dei referenti li gestisce la redazione, come i referenti. */
    private function isPendingEditorInvite(User $record): bool
    {
        return ! self::accepted($record) && $record->getAttribute('venue_role') === VenueRole::Editor->value;
    }

    private static function accepted(User $record): bool
    {
        return $record->getAttribute('venue_accepted_at') !== null;
    }

    /** `m****@esempio.it`: abbastanza per riconoscere l'indirizzo scritto, non per leggerne uno nuovo. */
    private static function masked(string $email): string
    {
        [$locale, $dominio] = array_pad(explode('@', $email, 2), 2, '');

        return Str::mask($locale, '*', 1).'@'.$dominio;
    }

    private function causer(): ?User
    {
        return auth()->user() instanceof User ? auth()->user() : null;
    }

    /**
     * Le persone del locale corrente, con il ruolo che hanno **qui**: il
     * ruolo sta nella pivot, non sull'utente, perché la stessa persona può
     * essere referente di un locale e collaboratore di un altro. Inviti in
     * attesa compresi: le colonne li mostrano mascherati.
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

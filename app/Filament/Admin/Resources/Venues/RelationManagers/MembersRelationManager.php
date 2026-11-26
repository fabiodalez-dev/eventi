<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\Venues\RelationManagers;

use App\Enums\VenueRole;
use App\Models\User;
use App\Models\Venue;
use App\Services\Editorial\EditorialVenueMembers;
use Filament\Actions\Action;
use Filament\Actions\AttachAction;
use Filament\Actions\DetachAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Referenti e collaboratori del locale (§9.2, «assegnazione owner ed editor»).
 *
 * Il ruolo sta nella pivot `venue_user`, non sull'utente: è l'appartenenza *a
 * questo locale* a distinguere un referente da un collaboratore, ed è la
 * stessa riga che le Policy interrogano per negare l'accesso ai contenuti di
 * un altro locale (§7 delle convenzioni).
 *
 * La sezione compare solo a chi la `VenuePolicy` autorizza con
 * `manageCollaborators`, che è il permesso del referente e dello staff — mai
 * del collaboratore.
 */
class MembersRelationManager extends RelationManager
{
    /* Anche gli inviti in sospeso (D64): la redazione li vede, li ritira e,
       se serve, li conferma dalla modifica della riga. */
    protected static string $relationship = 'membersIncludingInvited';

    /* Senza, Filament cercherebbe l'inverso in `venues`, che ignora gli
       inviti: chi ne ha uno comparirebbe fra gli account da aggiungere. */
    protected static ?string $inverseRelationship = 'venuesIncludingInvited';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('admin.sections.collaborators');
    }

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return auth()->user()?->can('manageCollaborators', $ownerRecord) ?? false;
    }

    public function form(Schema $schema): Schema
    {
        return $schema->columns(1)->components([
            Select::make('role')
                ->label(__('admin.fields.role'))
                ->options(VenueRole::options())
                ->required()
                ->default(VenueRole::Editor->value),

            /* In sola lettura: la data dell'invito sta nel link firmato
               spedito, e riscriverla (anche solo perdendo i secondi) lo
               invaliderebbe. */
            DateTimePicker::make('invited_at')
                ->label(__('admin.fields.invited_at'))
                ->disabled()
                ->dehydrated(false),

            DateTimePicker::make('accepted_at')
                ->label(__('admin.fields.accepted_at'))
                ->seconds(false),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('name')
            ->columns([
                TextColumn::make('name')
                    ->label(__('admin.fields.name'))
                    ->searchable(),

                TextColumn::make('email')
                    ->label(__('admin.fields.email'))
                    ->searchable(),

                TextColumn::make('pivot.role')
                    ->label(__('admin.fields.role'))
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => VenueRole::from($state)->label()),

                TextColumn::make('pivot.accepted_at')
                    ->label(__('admin.fields.accepted_at'))
                    ->panelMoment()
                    ->placeholder(__('admin.placeholders.pending_invite')),
            ])
            ->headerActions([
                // Accreditare il referente di un locale appena approvato
                // significa creare un account che ancora non esiste: è lo
                // stesso gesto con cui un referente invita un collaboratore
                // (§10.6), e passa dalla stessa azione perché non divergano.
                Action::make('invite_by_email')
                    ->label(__('admin.actions.invite_by_email'))
                    ->icon(Heroicon::OutlinedEnvelope)
                    ->authorize(fn (): bool => $this->canManage())
                    ->schema([
                        TextInput::make('name')
                            ->label(__('admin.fields.name'))
                            ->required()
                            ->maxLength(255),

                        TextInput::make('email')
                            ->label(__('admin.fields.email'))
                            ->email()
                            ->required()
                            ->maxLength(255),

                        Select::make('role')
                            ->label(__('admin.fields.role'))
                            ->options(VenueRole::options())
                            ->required()
                            ->default(VenueRole::Owner->value),
                    ])
                    ->action(function (array $data): void {
                        $venue = $this->getOwnerRecord();

                        if (! $venue instanceof Venue) {
                            return;
                        }

                        // L'invito non declassa e non rimanda il messaggio a
                        // chi ha già quel ruolo: dirlo, invece di «Invito
                        // inviato» quando non parte niente.
                        $members = app(EditorialVenueMembers::class);
                        $already = $members->alreadyThere($venue, (string) $data['email'], VenueRole::from((string) $data['role']));
                        if ($already !== null) {
                            Notification::make()
                                ->title($already)
                                ->warning()
                                ->send();

                            return;
                        }

                        /* Un account che esiste già riceve un invito da
                           accettare (D64); per chi ha chiesto lui l'accesso
                           c'è «Aggiungi» qui accanto, o l'approvazione della
                           sua richiesta. */

                        $members->invite(
                            $venue,
                            (string) $data['email'],
                            (string) $data['name'],
                            VenueRole::from((string) $data['role']),
                        );

                        Notification::make()
                            ->title(__('admin.notifications.invitation_sent'))
                            ->success()
                            ->send();
                    }),

                AttachAction::make()
                    ->label(__('admin.actions.invite_collaborator'))
                    ->preloadRecordSelect()
                    /* Solo account con l'email confermata: uno mai confermato
                       può averlo registrato chiunque con quell'indirizzo, e va
                       invitato per email (azione sopra), che azzera le
                       credenziali e manda il collegamento alla casella vera. */
                    ->recordSelectOptionsQuery(fn (Builder $query): Builder => $query->whereNotNull('email_verified_at'))
                    ->schema(fn (AttachAction $action): array => [
                        $action->getRecordSelect(),
                        Select::make('role')
                            ->label(__('admin.fields.role'))
                            ->options(VenueRole::options())
                            ->required()
                            ->default(VenueRole::Editor->value),
                    ])
                    /* Aggiungere qui un account confermato è la strada della
                       redazione per chi ha chiesto l'accesso: entra subito,
                       senza invito da accettare (D64). */
                    ->mutateDataUsing(fn (array $data): array => app(EditorialVenueMembers::class)->attachPivot($data))
                    ->after(fn (Model $record, array $data) => $this->logAccess('accesso_locale_concesso', $record, (string) ($data['role'] ?? '')))
                    ->authorize(fn (): bool => $this->canManage()),
            ])
            ->recordActions([
                EditAction::make()->authorize(fn (): bool => $this->canManage())
                    ->after(function (Model $record, array $data): void {
                        /* Un invito confermato a mano dalla redazione diventa
                           un'appartenenza vera: serve anche il ruolo globale,
                           che l'invito da accettare non assegna più. */
                        $venue = $this->getOwnerRecord();
                        if ($record instanceof User && $venue instanceof Venue) {
                            app(EditorialVenueMembers::class)->afterUpdate($venue, $record, isset($data['role']) ? (string) $data['role'] : null, $data['accepted_at'] ?? null, $this->actor());
                        }
                    }),
                DetachAction::make()->authorize(fn (): bool => $this->canManage())
                    ->after(fn (Model $record) => $this->logAccess('accesso_locale_revocato', $record, null)),
            ]);
    }

    /**
     * Chi entra in un locale, con quale ruolo, e chi lo ha deciso: la riga
     * del registro la scrive `EditorialVenueMembers`, come dall'app.
     */
    private function logAccess(string $evento, Model $record, ?string $ruolo): void
    {
        $venue = $this->getOwnerRecord();
        if (! $record instanceof User || ! $venue instanceof Venue) {
            return;
        }

        app(EditorialVenueMembers::class)->log($evento, $venue, $record, $ruolo, $this->actor());
    }

    private function actor(): ?User
    {
        return auth()->user() instanceof User ? auth()->user() : null;
    }

    private function canManage(): bool
    {
        $venue = $this->getOwnerRecord();

        return $venue instanceof Venue
            && (auth()->user()?->can('manageCollaborators', $venue) ?? false);
    }
}

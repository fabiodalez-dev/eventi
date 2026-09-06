<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\Venues\RelationManagers;

use App\Actions\InviteVenueMemberAction;
use App\Enums\VenueRole;
use App\Models\Venue;
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
    protected static string $relationship = 'members';

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

            DateTimePicker::make('invited_at')
                ->label(__('admin.fields.invited_at'))
                ->seconds(false),

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
                    ->dateTime('d/m/Y H:i')
                    ->placeholder(__('admin.placeholders.never')),
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

                        app(InviteVenueMemberAction::class)->execute(
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
                    ->schema(fn (AttachAction $action): array => [
                        $action->getRecordSelect(),
                        Select::make('role')
                            ->label(__('admin.fields.role'))
                            ->options(VenueRole::options())
                            ->required()
                            ->default(VenueRole::Editor->value),
                    ])
                    ->mutateDataUsing(function (array $data): array {
                        $data['invited_at'] = now();

                        return $data;
                    })
                    ->authorize(fn (): bool => $this->canManage()),
            ])
            ->recordActions([
                EditAction::make()->authorize(fn (): bool => $this->canManage()),
                DetachAction::make()->authorize(fn (): bool => $this->canManage()),
            ]);
    }

    private function canManage(): bool
    {
        $venue = $this->getOwnerRecord();

        return $venue instanceof Venue
            && (auth()->user()?->can('manageCollaborators', $venue) ?? false);
    }
}

<?php

declare(strict_types=1);

namespace App\Filament\Admin\Widgets;

use App\Filament\Admin\Resources\Users\RegistrationState;
use App\Filament\Admin\Resources\Users\UserResource;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;

/**
 * Le ultime persone che si sono iscritte, nel riepilogo della redazione.
 *
 * Nasce da un caso vero: un account registrato e non ancora confermato
 * restava in fondo all'elenco degli utenti, ordinato per nome, e nessuno lo
 * vedeva. Qui le iscrizioni stanno in ordine d'arrivo con il loro stato, e i
 * due collegamenti aprono l'elenco completo o solo chi è in attesa.
 *
 * Non dipende dalla città come gli altri riquadri: gli account sono della
 * piattaforma, non di una città. Lo vede chi può aprire l'elenco degli
 * utenti, con la stessa regola (`UserPolicy::viewAny`).
 */
class NewRegistrationsWidget extends TableWidget
{
    /** Subito prima di «Ritorno delle persone» (vedi `Dashboard::getWidgets()`). */
    protected static ?int $sort = 4;

    protected static bool $isLazy = false;

    protected int|string|array $columnSpan = 'full';

    /** Quante iscrizioni mostra il riquadro: il resto è nell'elenco. */
    public const LIMIT = 5;

    public static function canView(): bool
    {
        return auth()->user()?->can('viewAny', User::class) === true;
    }

    public function table(Table $table): Table
    {
        $recent = RegistrationState::scopeRecent(User::query())->count();
        $pending = RegistrationState::scopeIncomplete(User::query())->count();

        return $table
            ->heading(__('users.widget.heading'))
            ->description(trans_choice('users.widget.description', $recent, ['count' => $recent, 'days' => RegistrationState::RECENT_DAYS])
                .' · '.trans_choice('users.widget.pending', $pending, ['count' => $pending]))
            ->query(fn () => User::query()->latest('created_at')->latest('id')->limit(self::LIMIT))
            ->paginated(false)
            ->columns([
                TextColumn::make('name')
                    ->label(__('admin.fields.name'))
                    ->state(fn (User $record): string => filled($record->name) ? (string) $record->name : $record->email)
                    ->description(fn (User $record): string => filled($record->name) ? $record->email : __('users.no_name'))
                    ->wrap(),
                TextColumn::make('registration_state')
                    ->label(__('users.registration.state'))
                    ->state(fn (User $record): RegistrationState => RegistrationState::for($record))
                    ->badge()
                    ->tooltip(fn (User $record): string => RegistrationState::for($record)->getDescription()),
                TextColumn::make('created_at')
                    ->label(__('users.registration.registered_at'))
                    ->since()
                    ->dateTimeTooltip('d/m/Y H:i')
                    ->color('gray'),
            ])
            // Sul telefono ogni iscrizione è un blocco con le etichette.
            ->stackedOnMobile()
            ->recordUrl(fn (User $record): ?string => auth()->user()?->can('update', $record) === true
                ? UserResource::getUrl('edit', ['record' => $record])
                : null)
            ->headerActions([
                Action::make('pending')
                    ->label(__('users.widget.show_pending'))
                    ->icon(Heroicon::OutlinedEnvelope)
                    ->color('gray')
                    ->badge($pending > 0 ? (string) $pending : null)
                    ->badgeColor('warning')
                    ->url(UserResource::getUrl('index', ['tab' => 'pending'])),
                Action::make('all')
                    ->label(__('users.widget.all'))
                    ->color('gray')
                    ->url(UserResource::getUrl('index')),
            ])
            ->emptyStateHeading(__('users.widget.empty'))
            ->emptyStateDescription(__('users.widget.empty_description'))
            ->emptyStateIcon(Heroicon::OutlinedUserPlus);
    }
}

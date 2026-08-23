<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\ScheduledNotifications;

use App\Enums\NotificationChannel;
use App\Enums\NotificationSkipReason;
use App\Enums\NotificationStatus;
use App\Enums\NotificationType;
use App\Filament\Admin\Resources\ScheduledNotifications\Pages\ListScheduledNotifications;
use App\Models\EventOccurrence;
use App\Models\ScheduledNotification;
use BackedEnum;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Gli invii previsti, §15.5.
 *
 * Questa pagina è la ragione per cui il motore usa una tabella e non una coda:
 * «la tabella rende ogni invio previsto **visibile e verificabile in
 * anticipo** dal pannello admin: un job in coda ritardato non lo è».
 *
 * Da qui non si crea e non si modifica nulla. Una riga nasce da un gesto — un
 * salvataggio, un annullamento, una pianificazione — e l'unica azione ammessa è
 * fermarne una che non deve partire. Anche quella lascia traccia: lo stato
 * diventa `cancelled`, la riga resta.
 */
class ScheduledNotificationResource extends Resource
{
    protected static ?string $model = ScheduledNotification::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBellAlert;

    protected static ?int $navigationSort = 4;

    public static function getNavigationGroup(): ?string
    {
        return __('admin.navigation.system');
    }

    public static function getModelLabel(): string
    {
        return __('admin.resources.scheduled_notification.label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('admin.resources.scheduled_notification.plural');
    }

    public static function canCreate(): bool
    {
        return false;
    }

    /**
     * Quante righe sono già scadute e non sono ancora partite. Zero è la
     * risposta normale: un numero che cresce significa che il worker dei
     * cinque minuti non sta girando, ed è l'unico guasto di questo motore che
     * nessun log racconterebbe da solo.
     */
    public static function getNavigationBadge(): ?string
    {
        $due = ScheduledNotification::query()->due(CarbonImmutable::now())->count();

        return $due > 0 ? (string) $due : null;
    }

    public static function getNavigationBadgeColor(): string|array|null
    {
        return 'warning';
    }

    public static function table(Table $table): Table
    {
        return $table
            ->description(__('admin.notifications.lead'))
            ->columns([
                TextColumn::make('send_at')
                    ->label(__('admin.notifications.send_at'))
                    ->dateTime('d/m/Y H:i')
                    ->sortable(),

                TextColumn::make('type')
                    ->label(__('admin.fields.type'))
                    ->badge()
                    ->formatStateUsing(fn (ScheduledNotification $record): string => $record->type()?->label() ?? (string) $record->type),

                TextColumn::make('user.email')
                    ->label(__('admin.notifications.recipient'))
                    ->placeholder(__('admin.placeholders.none'))
                    ->searchable(),

                TextColumn::make('notifiable_type')
                    ->label(__('admin.notifications.subject'))
                    ->formatStateUsing(fn (ScheduledNotification $record): string => self::describeSubject($record))
                    ->placeholder(__('admin.placeholders.none'))
                    ->wrap(),

                TextColumn::make('status')
                    ->label(__('admin.fields.status'))
                    ->badge()
                    ->formatStateUsing(fn (NotificationStatus $state): string => $state->label())
                    ->color(fn (NotificationStatus $state): string => match ($state) {
                        NotificationStatus::Pending => 'warning',
                        NotificationStatus::Sent => 'success',
                        NotificationStatus::Skipped => 'gray',
                        NotificationStatus::Failed => 'danger',
                        NotificationStatus::Cancelled => 'gray',
                    }),

                TextColumn::make('channel')
                    ->label(__('admin.notifications.channel'))
                    ->formatStateUsing(fn (NotificationChannel $state): string => $state->label())
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('attempts')
                    ->label(__('admin.notifications.attempts'))
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('sent_at')
                    ->label(__('admin.notifications.sent_at'))
                    ->dateTime('d/m/Y H:i')
                    ->placeholder(__('admin.placeholders.never'))
                    ->toggleable(isToggledHiddenByDefault: true),

                /*
                 * Il motivo per cui una riga è stata saltata sta in
                 * `last_error`, che è l'unica colonna di testo libero della
                 * tabella: qui torna a essere una frase, non un codice.
                 */
                TextColumn::make('last_error')
                    ->label(__('admin.notifications.reason'))
                    ->formatStateUsing(fn (?string $state): string => $state === null
                        ? ''
                        : NotificationSkipReason::tryFrom($state)?->label() ?? $state)
                    ->placeholder(__('admin.placeholders.none'))
                    ->wrap(),

                TextColumn::make('dedupe_key')
                    ->label(__('admin.notifications.dedupe_key'))
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->copyable(),
            ])
            ->defaultSort('send_at', 'desc')
            ->filters([
                SelectFilter::make('status')
                    ->label(__('admin.fields.status'))
                    ->options(NotificationStatus::options()),

                SelectFilter::make('type')
                    ->label(__('admin.fields.type'))
                    ->options(NotificationType::options()),

                Filter::make('due')
                    ->label(__('admin.notifications.due'))
                    ->query(fn (Builder $query) => ScheduledNotification::dueScope($query, CarbonImmutable::now())),
            ])
            ->recordActions([
                Action::make('cancel')
                    ->label(__('admin.actions.cancel_notification'))
                    ->icon(Heroicon::OutlinedNoSymbol)
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalDescription(__('admin.confirmations.cancel_notification'))
                    ->visible(fn (ScheduledNotification $record): bool => auth()->user()?->can('cancel', $record) ?? false)
                    ->action(function (ScheduledNotification $record): void {
                        $record->markCancelled();
                    })
                    ->successNotificationTitle(__('admin.notices.notification_cancelled')),
            ]);
    }

    /**
     * @return array<string, mixed>
     */
    public static function getPages(): array
    {
        return [
            'index' => ListScheduledNotifications::route('/'),
        ];
    }

    /**
     * La colonna morph porta un alias breve (`event_occurrence`), non un nome
     * di classe: si traduce, invece di mostrare a chi legge una stringa da
     * programmatori.
     */
    private static function describeSubject(ScheduledNotification $notification): string
    {
        $type = $notification->notifiable_type;

        if (! is_string($type) || $type === '') {
            return '';
        }

        $key = 'admin.resources.'.$type.'.label';
        $label = __($key);
        $label = $label === $key ? $type : $label;

        $subject = $notification->notifiable;

        if (! $subject instanceof Model) {
            return $label;
        }

        $title = $subject instanceof EventOccurrence
            ? $subject->event->title
            : $subject->getAttribute('title') ?? $subject->getAttribute('name') ?? $subject->getKey();

        return $label.' '.__('common.separator').' '.(string) $title;
    }
}

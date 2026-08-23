<?php

declare(strict_types=1);

namespace App\Filament\Admin\Support;

use App\Actions\ModerateVenueAction;
use App\Enums\VenueStatus;
use App\Models\User;
use App\Models\Venue;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;

/**
 * Le azioni di moderazione del locale, scritte una volta e usate sia nella
 * riga della lista sia nell'intestazione della scheda.
 *
 * Ogni azione chiede il permesso alla `VenuePolicy` con `moderate`: il
 * pannello non decide nulla per conto proprio, e un moderatore che perdesse
 * quel permesso vedrebbe sparire i pulsanti senza che si tocchi questo file.
 */
final class VenueModeration
{
    /**
     * @return array<Action|ActionGroup>
     */
    public static function actions(): array
    {
        return [
            ActionGroup::make([
                self::approve(),
                self::reject(),
                self::suspend(),
                self::verify(),
            ])->dropdown(),
        ];
    }

    public static function statusColor(VenueStatus $status): string
    {
        return match ($status) {
            VenueStatus::Approved => 'success',
            VenueStatus::Pending => 'warning',
            VenueStatus::Rejected, VenueStatus::Suspended => 'danger',
            VenueStatus::Draft => 'gray',
        };
    }

    private static function approve(): Action
    {
        return Action::make('approve')
            ->label(__('admin.actions.approve'))
            ->icon(Heroicon::OutlinedCheckCircle)
            ->color('success')
            ->requiresConfirmation()
            ->authorize(fn (Venue $record): bool => self::canModerate($record))
            ->visible(fn (Venue $record): bool => $record->status !== VenueStatus::Approved)
            ->action(function (Venue $record): void {
                /** @var User $moderator */
                $moderator = auth()->user();

                app(ModerateVenueAction::class)->approve($record, $moderator);

                self::notify(__('admin.notifications.approved'));
            });
    }

    private static function reject(): Action
    {
        return Action::make('reject')
            ->label(__('admin.actions.reject'))
            ->icon(Heroicon::OutlinedXCircle)
            ->color('danger')
            ->authorize(fn (Venue $record): bool => self::canModerate($record))
            ->visible(fn (Venue $record): bool => $record->status !== VenueStatus::Rejected)
            ->schema([
                Textarea::make('reason')
                    ->label(__('admin.fields.rejection_reason'))
                    ->required()
                    ->rows(3),
            ])
            ->action(function (Venue $record, array $data): void {
                /** @var User $moderator */
                $moderator = auth()->user();

                app(ModerateVenueAction::class)->reject($record, $moderator, (string) $data['reason']);

                self::notify(__('admin.notifications.rejected'));
            });
    }

    private static function suspend(): Action
    {
        return Action::make('suspend')
            ->label(__('admin.actions.suspend'))
            ->icon(Heroicon::OutlinedNoSymbol)
            ->color('danger')
            ->requiresConfirmation()
            ->modalDescription(__('admin.confirmations.suspend'))
            ->authorize(fn (Venue $record): bool => self::canModerate($record))
            ->visible(fn (Venue $record): bool => $record->status === VenueStatus::Approved)
            ->schema([
                Textarea::make('reason')
                    ->label(__('admin.fields.rejection_reason'))
                    ->required()
                    ->rows(3),
            ])
            ->action(function (Venue $record, array $data): void {
                app(ModerateVenueAction::class)->suspend($record, (string) $data['reason']);

                self::notify(__('admin.notifications.suspended'));
            });
    }

    private static function verify(): Action
    {
        return Action::make('verify')
            ->label(fn (Venue $record): string => $record->is_verified
                ? __('admin.actions.unverify')
                : __('admin.actions.verify'))
            ->icon(Heroicon::OutlinedShieldCheck)
            ->requiresConfirmation()
            ->authorize(fn (Venue $record): bool => self::canModerate($record))
            ->action(function (Venue $record): void {
                app(ModerateVenueAction::class)->setVerified($record, ! $record->is_verified);

                self::notify(__('admin.notifications.verified'));
            });
    }

    private static function canModerate(Venue $venue): bool
    {
        return auth()->user()?->can('moderate', $venue) ?? false;
    }

    private static function notify(string $title): void
    {
        Notification::make()->title($title)->success()->send();
    }
}

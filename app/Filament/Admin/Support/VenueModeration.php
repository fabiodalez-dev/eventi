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
     * Le azioni **nella riga di una tabella**, raggruppate in un menu.
     *
     * Lì lo spazio è poco e le righe sono molte: quattro pulsanti per riga
     * renderebbero illeggibile l'elenco.
     *
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
            ])
                /* Un'etichetta, perché un menu di sole icone non dice cosa
                   contiene: chi cerca «sospendi» non ha motivo di aprire tre
                   puntini per scoprirlo. */
                ->label(__('admin.actions.moderate'))
                ->icon(Heroicon::OutlinedShieldCheck)
                ->button()
                /* Grigio, non lime.
                   Il lime dice «questa e' l'azione principale della
                   schermata»: ripetuto su cinquanta righe smette di dirlo, e
                   in un elenco di locali gia' approvati l'azione principale
                   non e' moderarli — e' trovarne uno. */
                ->color('gray')
                ->dropdown(),
        ];
    }

    /**
     * Le stesse azioni **nell'intestazione della scheda**, in chiaro.
     *
     * **Perché non lo stesso menu.** Erano raggruppate anche qui, e il
     * risultato era che nella scheda di un locale l'unica azione visibile
     * restava «Elimina» — quella irreversibile — mentre sospendere, rifiutare
     * e togliere la verifica stavano dietro un menu senza etichetta.
     *
     * È il verso sbagliato: sospendere un locale è ordinario e si annulla,
     * eliminarlo è definitivo e capita di rado. Un'interfaccia che mette in
     * mano la cosa pericolosa e nasconde quella di tutti i giorni fa
     * commettere l'errore che dovrebbe prevenire.
     *
     * Qui lo spazio c'è: le azioni si mostrano, e sono comunque poche perché
     * ognuna compare solo quando ha senso — «approva» sparisce su un locale
     * già approvato, «sospendi» esiste solo per chi è approvato.
     *
     * @return array<Action|ActionGroup>
     */
    public static function headerActions(): array
    {
        return [
            self::approve(),
            self::verify(),
            self::suspend(),
            self::reject(),
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
                    /* Il campo sul database e' `rejection_reason` e serve a
                       due azioni, ma chi sospende non sta rifiutando: leggere
                       «Motivo del rifiuto» dentro «Sospendi» fa dubitare di
                       aver premuto il pulsante giusto. */
                    ->label(__('admin.fields.suspension_reason'))
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

<?php

declare(strict_types=1);

namespace App\Filament\Admin\Support;

use App\Actions\DuplicateEventAction;
use App\Actions\ModerateEventAction;
use App\Actions\PublishEventAction;
use App\Enums\EventStatus;
use App\Filament\Admin\Resources\Events\EventResource;
use App\Models\Event;
use App\Models\User;
use Carbon\Carbon;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Route;

/**
 * Le decisioni della redazione su un evento (§9.2): pubblica, rifiuta con
 * motivo, annulla, metti in evidenza, duplica.
 *
 * Come per i locali, il pannello non decide: ogni azione interroga la
 * `EventPolicy` — `publish` per la messa online, `moderate` per il resto — e
 * il lavoro vero lo fanno le classi di `app/Actions`.
 */
final class EventModeration
{
    /**
     * @return array<Action|ActionGroup>
     */
    public static function actions(): array
    {
        return [
            self::viewOnSite(),
            self::publish(),
            ActionGroup::make([
                self::unpublish(),
                self::reject(),
                self::cancel(),
                self::feature(),
                self::duplicate(),
            ])->dropdown(),
        ];
    }

    /**
     * §9.2 — «preview della scheda pubblica».
     *
     * Prima di pubblicare, la redazione deve poter vedere la pagina come la
     * vedra chi la trova su un motore di ricerca. Si apre in una scheda nuova
     * per non perdere il lavoro in corso, e compare solo sugli eventi gia
     * pubblicati, perche la scheda pubblica risponde 404 su tutto il resto.
     */
    private static function viewOnSite(): Action
    {
        return Action::make('view_on_site')
            ->label(__('admin.actions.view_on_site'))
            ->icon(Heroicon::OutlinedArrowTopRightOnSquare)
            ->color('gray')
            ->url(fn (Event $record): ?string => Route::has('events.show')
                ? route('events.show', $record)
                : null)
            ->openUrlInNewTab()
            ->visible(fn (Event $record): bool => $record->status === EventStatus::Published
                && Route::has('events.show'));
    }

    private static function publish(): Action
    {
        return Action::make('publish')
            ->label(__('admin.actions.publish'))
            ->icon(Heroicon::OutlinedPaperAirplane)
            ->color('success')
            ->requiresConfirmation()
            ->modalDescription(__('admin.confirmations.publish'))
            ->authorize(fn (Event $record): bool => auth()->user()?->can('publish', $record) ?? false)
            ->visible(fn (Event $record): bool => $record->status !== EventStatus::Published)
            ->action(function (Event $record): void {
                $action = app(PublishEventAction::class);

                if (! $action->canPublish($record)) {
                    Notification::make()
                        ->title(__('admin.notifications.cannot_publish_without_date'))
                        ->danger()
                        ->send();

                    return;
                }

                $action->publish($record);

                self::notify(__('admin.notifications.published'));
            });
    }

    private static function unpublish(): Action
    {
        return Action::make('unpublish')
            ->label(__('admin.actions.unpublish'))
            ->icon(Heroicon::OutlinedArrowPath)
            ->requiresConfirmation()
            ->authorize(fn (Event $record): bool => auth()->user()?->can('publish', $record) ?? false)
            ->visible(fn (Event $record): bool => $record->status === EventStatus::Published)
            ->action(function (Event $record): void {
                app(PublishEventAction::class)->unpublish($record);

                self::notify(__('admin.notifications.unpublished'));
            });
    }

    private static function reject(): Action
    {
        return Action::make('reject')
            ->label(__('admin.actions.reject'))
            ->icon(Heroicon::OutlinedXCircle)
            ->color('danger')
            ->authorize(fn (Event $record): bool => auth()->user()?->can('moderate', $record) ?? false)
            ->visible(fn (Event $record): bool => $record->status !== EventStatus::Rejected)
            ->schema([
                Textarea::make('reason')
                    ->label(__('admin.fields.rejection_reason'))
                    ->required()
                    ->rows(3),
            ])
            ->action(function (Event $record, array $data): void {
                app(ModerateEventAction::class)->reject($record, (string) $data['reason']);

                self::notify(__('admin.notifications.rejected'));
            });
    }

    private static function cancel(): Action
    {
        return Action::make('cancel_event')
            ->label(__('admin.actions.cancel_event'))
            ->icon(Heroicon::OutlinedNoSymbol)
            ->color('danger')
            ->requiresConfirmation()
            ->modalDescription(__('admin.confirmations.cancel_event'))
            ->authorize(fn (Event $record): bool => auth()->user()?->can('moderate', $record) ?? false)
            ->visible(fn (Event $record): bool => $record->status !== EventStatus::Cancelled)
            ->schema([
                Textarea::make('note')
                    ->label(__('admin.fields.status_note'))
                    ->rows(2),
            ])
            ->action(function (Event $record, array $data): void {
                app(ModerateEventAction::class)->cancel($record, $data['note'] ?? null);

                self::notify(__('admin.notifications.cancelled'));
            });
    }

    private static function feature(): Action
    {
        return Action::make('feature')
            ->label(fn (Event $record): string => $record->is_featured
                ? __('admin.actions.unfeature')
                : __('admin.actions.feature'))
            ->icon(Heroicon::OutlinedStar)
            ->authorize(fn (Event $record): bool => auth()->user()?->can('moderate', $record) ?? false)
            ->schema(fn (Event $record): array => $record->is_featured ? [] : [
                DateTimePicker::make('featured_until')
                    ->label(__('admin.fields.featured_until'))
                    ->seconds(false),
            ])
            ->action(function (Event $record, array $data): void {
                $until = filled($data['featured_until'] ?? null)
                    ? Carbon::parse((string) $data['featured_until'])
                    : null;

                app(ModerateEventAction::class)->setFeatured($record, ! $record->is_featured, $until);

                self::notify($record->is_featured
                    ? __('admin.notifications.featured')
                    : __('admin.notifications.unfeatured'));
            });
    }

    private static function duplicate(): Action
    {
        return Action::make('duplicate')
            ->label(__('admin.actions.duplicate'))
            ->icon(Heroicon::OutlinedDocumentDuplicate)
            ->requiresConfirmation()
            ->authorize(fn (Event $record): bool => auth()->user()?->can('create', [Event::class, $record->venue]) ?? false)
            ->action(function (Event $record): mixed {
                /** @var User $author */
                $author = auth()->user();

                $copy = app(DuplicateEventAction::class)->execute($record, $author);

                self::notify(__('admin.notifications.duplicated'));

                return redirect(EventResource::getUrl('edit', ['record' => $copy]));
            });
    }

    private static function notify(string $title): void
    {
        Notification::make()->title($title)->success()->send();
    }
}

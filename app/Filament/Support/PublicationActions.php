<?php

declare(strict_types=1);

namespace App\Filament\Support;

use App\Actions\ScheduleEventPublication;
use App\Enums\EventStatus;
use App\Models\Event;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Forms\Components\DateTimePicker;
use Filament\Notifications\Notification;

final class PublicationActions
{
    public static function schedule(): Action
    {
        return Action::make('schedulePublication')->label('Programma pubblicazione')->icon('heroicon-o-clock')
            ->authorize(fn (Event $record): bool => auth()->user()?->can('update', $record) === true)
            ->visible(fn (Event $record): bool => in_array($record->status, [EventStatus::Draft, EventStatus::Pending], true))
            ->modalDescription('L’evento uscirà all’orario scelto solo se autorizzato alla pubblicazione. I locali senza pubblicazione autonoma devono attendere l’approvazione della redazione.')
            ->schema([DateTimePicker::make('publish_at')->label('Data e ora di pubblicazione (Europe/Rome)')->native(false)->locale('it')->displayFormat('d/m/Y H:i')->firstDayOfWeek(1)->seconds(false)->required()->after('now')])
            ->action(function (Event $record, array $data): void {
                $user = auth()->user();
                abort_unless($user !== null, 403);
                app(ScheduleEventPublication::class)->schedule($record, $user, CarbonImmutable::parse($data['publish_at'], 'Europe/Rome'));
                Notification::make()->success()->title('Programmazione salvata')->body($record->status === EventStatus::Pending ? 'In attesa di approvazione della redazione.' : 'Pubblicazione automatica all’orario scelto.')->send();
            });
    }

    public static function cancel(): Action
    {
        return Action::make('cancelPublicationSchedule')->label('Annulla programmazione')->color('gray')->icon('heroicon-o-x-mark')
            ->authorize(fn (Event $record): bool => auth()->user()?->can('update', $record) === true)
            ->visible(fn (Event $record): bool => $record->scheduled_publish_at !== null)
            ->action(function (Event $record): void {
                $user = auth()->user();
                abort_unless($user !== null, 403);
                app(ScheduleEventPublication::class)->cancel($record, $user);
                Notification::make()->success()->title('Programmazione annullata')->send();
            });
    }
}

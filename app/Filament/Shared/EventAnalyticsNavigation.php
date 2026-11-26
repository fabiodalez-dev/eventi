<?php

declare(strict_types=1);

namespace App\Filament\Shared;

use App\Filament\Venue\Pages\EventAnalyticsDetail;
use App\Filament\Venue\Resources\Events\EventResource;
use App\Services\Analytics\EventAnalyticsDashboard;
use Filament\Facades\Filament;
use Filament\Navigation\NavigationItem;

final class EventAnalyticsNavigation
{
    /** @return list<NavigationItem> */
    public static function items(int $eventId, bool $analytics, bool $editing = false): array
    {
        [$resource, $detail] = match (Filament::getCurrentPanel()?->getId()) {
            'venue' => [EventResource::class, EventAnalyticsDetail::class],
            'organizer' => [\App\Filament\Organizer\Resources\Events\EventResource::class, \App\Filament\Organizer\Pages\EventAnalyticsDetail::class],
            default => [null, null],
        };
        if ($resource === null) {
            return [];
        }

        /* L'evento si cerca fra quelli del pannello, non fra quelli con le
           statistiche: un evento solo co-organizzato si apre e si modifica,
           ma i suoi numeri restano di chi lo organizza (`ManagementAnalytics`). */
        $event = $resource::getEloquentQuery()->findOrFail($eventId);
        $items = [
            NavigationItem::make(__('analytics-dashboard.event_tab'))->url($resource::getUrl('view', ['record' => $event]))->isActiveWhen(fn () => ! $analytics && ! $editing),
        ];
        if ($resource::canEdit($event)) {
            $items[] = NavigationItem::make(__('manage.actions.edit'))->url($resource::getUrl('edit', ['record' => $event]))->isActiveWhen(fn () => $editing);
        }
        if (app(EventAnalyticsDashboard::class)->events()->whereKey($eventId)->exists()) {
            $items[] = NavigationItem::make(__('analytics-dashboard.statistics_tab'))->url($detail::getUrl(['subjectType' => 'event', 'subjectId' => $eventId]))->isActiveWhen(fn () => $analytics);
        }

        return $items;
    }
}

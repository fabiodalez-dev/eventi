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

        $event = app(EventAnalyticsDashboard::class)->events()->findOrFail($eventId);
        $items = [
            NavigationItem::make(__('analytics-dashboard.event_tab'))->url($resource::getUrl(Filament::getCurrentPanel()?->getId() === 'venue' ? 'view' : 'edit', ['record' => $event]))->isActiveWhen(fn () => ! $analytics && ! $editing),
            NavigationItem::make(__('analytics-dashboard.statistics_tab'))->url($detail::getUrl(['subjectType' => 'event', 'subjectId' => $eventId]))->isActiveWhen(fn () => $analytics),
        ];
        if (Filament::getCurrentPanel()?->getId() === 'venue' && $resource::canEdit($event)) {
            array_splice($items, 1, 0, [NavigationItem::make(__('manage.actions.edit'))->url($resource::getUrl('edit', ['record' => $event]))->isActiveWhen(fn () => $editing)]);
        }

        return $items;
    }
}

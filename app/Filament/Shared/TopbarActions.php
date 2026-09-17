<?php

declare(strict_types=1);

namespace App\Filament\Shared;

use App\Filament\Admin\Pages\EventsCalendar;
use App\Filament\Admin\Resources\EventComments\EventCommentResource;
use App\Filament\Venue\Pages\EventShareAnalytics;
use App\Filament\Venue\Pages\VenueProfile;
use App\Filament\Venue\Resources\Events\EventResource;
use App\Models\User;
use App\Models\Venue;
use Filament\Facades\Filament;

final class TopbarActions
{
    /** @return array<string, mixed>|null */
    public function context(): ?array
    {
        $panel = Filament::getCurrentPanel();
        $user = Filament::auth()->user();
        if (! $user instanceof User || $panel === null || ! $user->canAccessPanel($panel)) {
            return null;
        }
        $tenant = Filament::getTenant();
        $venuePanel = $panel->getId() === 'venue';
        if (! in_array($panel->getId(), ['admin', 'venue'], true)
            || ($venuePanel && (! $tenant instanceof Venue || ! $user->canAccessTenant($tenant)))) {
            return null;
        }

        $resource = $venuePanel
            ? EventResource::class
            : \App\Filament\Admin\Resources\Events\EventResource::class;
        $analytics = $venuePanel
            ? EventShareAnalytics::class
            : \App\Filament\Admin\Pages\EventShareAnalytics::class;
        $items = [];
        if ($resource::canCreate()) {
            $items[] = $this->item('create', 'plus', $resource::getUrl('create'), true);
        }
        if ($venuePanel && $resource::canViewAny()) {
            $items[] = $this->item('events', 'calendar-days', $resource::getUrl(), true);
        }
        if (! $venuePanel && EventCommentResource::canViewAny()) {
            $items[] = $this->item('comments', 'chat-bubble-left-right', EventCommentResource::getUrl(), true);
        }
        if ($analytics::canAccess()) {
            $items[] = $this->item('analytics', 'chart-bar', $analytics::getUrl(), true);
        }
        if (! $venuePanel && $resource::canViewAny()) {
            $items[] = $this->item('events', 'calendar-days', $resource::getUrl());
            if (EventsCalendar::canAccess()) {
                $items[] = $this->item('calendar', 'calendar', EventsCalendar::getUrl());
            }
        }
        if ($user->hasAnyRole(['admin', 'super_admin'])
            || ($venuePanel && $user->ownedVenues()->whereKey($tenant->id)->exists())) {
            $items[] = $this->item('tickets', 'ticket', route('ticketing.manage.index'));
        }
        if ($venuePanel && VenueProfile::canAccess()) {
            $items[] = $this->item('profile', 'building-storefront', VenueProfile::getUrl());
        }
        $items[] = $this->item('public', 'arrow-top-right-on-square', $venuePanel
            ? route('venues.show', ['slug' => $tenant->slug])
            : url('/'));

        return [
            'panel' => $panel->getId(),
            'name' => $venuePanel ? $tenant->name : config('app.name'),
            'label' => __('topbar.'.$panel->getId()),
            'home' => $panel->getUrl($venuePanel ? $tenant : null),
            'items' => $items,
        ];
    }

    /** @return array{key: string, icon: string, url: string, inline: bool} */
    private function item(string $key, string $icon, string $url, bool $inline = false): array
    {
        return ['key' => $key, 'icon' => 'heroicon-o-'.$icon, 'url' => $url, 'inline' => $inline];
    }
}

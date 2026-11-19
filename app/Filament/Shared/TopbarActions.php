<?php

declare(strict_types=1);

namespace App\Filament\Shared;

use App\Enums\VenueStatus;
use App\Filament\Admin\Pages\EventsCalendar;
use App\Filament\Admin\Resources\EventComments\EventCommentResource;
use App\Filament\Organizer\Pages\Profile;
use App\Filament\Organizer\Pages\Sponsorships as OrganizerSponsorships;
use App\Filament\Venue\Pages\EventShareAnalytics;
use App\Filament\Venue\Pages\Social;
use App\Filament\Venue\Pages\Sponsorships;
use App\Filament\Venue\Pages\VenueProfile;
use App\Filament\Venue\Resources\Events\EventResource;
use App\Models\Organizer;
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
        if ($panel->getId() === 'organizer') {
            return $tenant instanceof Organizer && $user->canAccessTenant($tenant)
                ? $this->organizerContext($panel->getId(), $tenant)
                : null;
        }
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
        if ($venuePanel && Social::canAccess()) {
            $items[] = $this->item('social', 'photo', Social::getUrl(), label: Social::getNavigationLabel());
        }
        if ($venuePanel && Sponsorships::canAccess()) {
            $items[] = $this->item('sponsorships', 'megaphone', Sponsorships::getUrl(), label: Sponsorships::getNavigationLabel());
        }
        if ($venuePanel && VenueProfile::canAccess()) {
            $items[] = $this->item('profile', 'building-storefront', VenueProfile::getUrl());
        }
        $items[] = $this->item('public', 'arrow-top-right-on-square', $venuePanel && in_array($tenant->status, [VenueStatus::Approved, VenueStatus::Suspended], true)
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

    /**
     * Il pannello degli organizzatori ha le stesse scorciatoie di quello dei
     * locali: nuovo evento, elenco, statistiche, e il profilo pubblico quando
     * e' attivo.
     *
     * @return array<string, mixed>
     */
    private function organizerContext(string $panelId, Organizer $organizer): array
    {
        $resource = \App\Filament\Organizer\Resources\Events\EventResource::class;
        $analytics = \App\Filament\Organizer\Pages\EventShareAnalytics::class;
        $items = [];
        if ($resource::canCreate()) {
            $items[] = $this->item('create', 'plus', $resource::getUrl('create'), true);
        }
        if ($resource::canViewAny()) {
            $items[] = $this->item('events', 'calendar-days', $resource::getUrl(), true);
        }
        if ($analytics::canAccess()) {
            $items[] = $this->item('analytics', 'chart-bar', $analytics::getUrl(), true);
        }
        if (OrganizerSponsorships::canAccess()) {
            $items[] = $this->item('sponsorships', 'megaphone', OrganizerSponsorships::getUrl(), label: OrganizerSponsorships::getNavigationLabel());
        }
        $items[] = $this->item('organizer_profile', 'user-group', Profile::getUrl());
        $items[] = $this->item('public', 'arrow-top-right-on-square', $organizer->is_active
            ? route('organizers.show', ['slug' => $organizer->slug])
            : url('/'));

        return [
            'panel' => $panelId,
            'name' => $organizer->name,
            'label' => __('topbar.organizer'),
            'home' => Filament::getCurrentPanel()?->getUrl($organizer) ?? url('/'),
            'items' => $items,
        ];
    }

    /**
     * Una scorciatoia. L'etichetta arriva da `lang/it/topbar.php` con la
     * chiave, oppure, per le pagine del pannello, dal nome che hanno nel menu
     * laterale: la stessa parola nei due posti.
     *
     * @return array{key: string, icon: string, url: string, inline: bool, label: string}
     */
    private function item(string $key, string $icon, string $url, bool $inline = false, ?string $label = null): array
    {
        return ['key' => $key, 'icon' => 'heroicon-o-'.$icon, 'url' => $url, 'inline' => $inline, 'label' => $label ?? __('topbar.'.$key)];
    }
}

<?php

declare(strict_types=1);

namespace App\Filament\Shared;

use App\Enums\VenueStatus;
use App\Filament\Admin\Pages\EventsCalendar;
use App\Filament\Admin\Pages\EventShareAnalytics as AdminStatistics;
use App\Filament\Admin\Resources\EventComments\EventCommentResource;
use App\Filament\Admin\Resources\Events\EventResource as AdminEvents;
use App\Filament\Organizer\Pages\Profile as OrganizerProfile;
use App\Filament\Organizer\Pages\Sponsorships as OrganizerSponsorships;
use App\Filament\Organizer\Pages\Statistics as OrganizerStatistics;
use App\Filament\Organizer\Resources\Events\EventResource as OrganizerEvents;
use App\Filament\Venue\Pages\Social;
use App\Filament\Venue\Pages\Sponsorships as VenueSponsorships;
use App\Filament\Venue\Pages\Statistics as VenueStatistics;
use App\Filament\Venue\Pages\VenueProfile;
use App\Filament\Venue\Resources\Events\EventResource as VenueEvents;
use App\Models\Organizer;
use App\Models\User;
use App\Models\Venue;
use Filament\Facades\Filament;

/**
 * La barra in alto dei tre pannelli: dove sono, tre scorciatoie, «Altro».
 *
 * Le tre scorciatoie sono le stesse, nello stesso ordine, in redazione, nel
 * locale e nell'organizzazione: «Nuovo evento», l'elenco degli eventi,
 * «Statistiche». Nel locale e nell'organizzazione «Statistiche» apre la
 * pagina che nel menu laterale si chiama così (non quella in dettaglio): una
 * parola, una destinazione.
 *
 * **Il nome di una voce è quello della pagina** (`getNavigationLabel()`):
 * menu laterale e barra dicono la stessa parola. Solo le voci che non sono
 * pagine del pannello («Nuovo evento», «Biglietti e partecipanti», il sito)
 * prendono il nome da `lang/it/topbar.php`.
 *
 * Ogni voce segue le autorizzazioni di oggi: compare solo se chi guarda può
 * aprire la pagina.
 */
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

        return match ($panel->getId()) {
            'admin' => $this->build('admin', $user, null),
            'venue' => $tenant instanceof Venue && $user->canAccessTenant($tenant) ? $this->build('venue', $user, $tenant) : null,
            'organizer' => $tenant instanceof Organizer && $user->canAccessTenant($tenant) ? $this->build('organizer', $user, $tenant) : null,
            default => null,
        };
    }

    /** @return array<string, mixed> */
    private function build(string $panel, User $user, Venue|Organizer|null $tenant): array
    {
        [$events, $statistics] = match ($panel) {
            'venue' => [VenueEvents::class, VenueStatistics::class],
            'organizer' => [OrganizerEvents::class, OrganizerStatistics::class],
            default => [AdminEvents::class, AdminStatistics::class],
        };

        $items = [];

        if ($events::canCreate()) {
            $items[] = $this->item('create', 'plus', $events::getUrl('create'), true, __('topbar.create'));
        }

        if ($events::canViewAny()) {
            $items[] = $this->item('events', 'calendar-days', $events::getUrl(), true, $events::getNavigationLabel());
        }

        if ($statistics::canAccess()) {
            $items[] = $this->item('analytics', 'chart-bar', $statistics::getUrl(), true, $statistics::getNavigationLabel());
        }

        $items = [...$items, ...match ($panel) {
            'venue' => $this->venueMenu($user, $tenant),
            'organizer' => $this->organizerMenu($tenant),
            default => $this->adminMenu($user),
        }];

        return [
            'panel' => $panel,
            'name' => $tenant !== null ? $tenant->name : config('app.name'),
            'label' => __('topbar.'.$panel),
            'home' => $panel === 'admin' ? Filament::getCurrentPanel()?->getUrl() : Filament::getCurrentPanel()?->getUrl($tenant),
            'items' => $items,
        ];
    }

    /** @return list<array<string, mixed>> */
    private function adminMenu(User $user): array
    {
        $items = [];

        if (EventCommentResource::canViewAny()) {
            $items[] = $this->item('comments', 'chat-bubble-left-right', EventCommentResource::getUrl(), label: EventCommentResource::getNavigationLabel());
        }

        if (EventsCalendar::canAccess()) {
            $items[] = $this->item('calendar', 'calendar', EventsCalendar::getUrl(), label: EventsCalendar::getNavigationLabel());
        }

        if ($user->hasAnyRole(['admin', 'super_admin'])) {
            $items[] = $this->tickets();
        }

        $items[] = $this->item('public', 'arrow-top-right-on-square', url('/'), label: __('topbar.public'));

        return $items;
    }

    /** @return list<array<string, mixed>> */
    private function venueMenu(User $user, Venue|Organizer|null $venue): array
    {
        $items = [];

        if ($venue instanceof Venue && $user->ownedVenues()->whereKey($venue->getKey())->exists()) {
            $items[] = $this->tickets();
        }

        if (Social::canAccess()) {
            $items[] = $this->item('social', 'photo', Social::getUrl(), label: Social::getNavigationLabel());
        }

        if (VenueSponsorships::canAccess()) {
            $items[] = $this->item('sponsorships', 'megaphone', VenueSponsorships::getUrl(), label: VenueSponsorships::getNavigationLabel());
        }

        if (VenueProfile::canAccess()) {
            $items[] = $this->item('profile', 'building-storefront', VenueProfile::getUrl(), label: VenueProfile::getNavigationLabel());
        }

        $online = $venue instanceof Venue && in_array($venue->status, [VenueStatus::Approved, VenueStatus::Suspended], true);
        $items[] = $online
            ? $this->item('public', 'arrow-top-right-on-square', route('venues.show', ['slug' => $venue->slug]), label: __('topbar.public_venue'))
            : $this->item('public', 'arrow-top-right-on-square', url('/'), label: __('topbar.public'));

        return $items;
    }

    /** @return list<array<string, mixed>> */
    private function organizerMenu(Venue|Organizer|null $organizer): array
    {
        $items = [];

        if (OrganizerSponsorships::canAccess()) {
            $items[] = $this->item('sponsorships', 'megaphone', OrganizerSponsorships::getUrl(), label: OrganizerSponsorships::getNavigationLabel());
        }

        if (OrganizerProfile::canAccess()) {
            $items[] = $this->item('organizer_profile', 'user-group', OrganizerProfile::getUrl(), label: OrganizerProfile::getNavigationLabel());
        }

        $online = $organizer instanceof Organizer && $organizer->is_active;
        $items[] = $online
            ? $this->item('public', 'arrow-top-right-on-square', route('organizers.show', ['slug' => $organizer->slug]), label: __('topbar.public_organizer'))
            : $this->item('public', 'arrow-top-right-on-square', url('/'), label: __('topbar.public'));

        return $items;
    }

    /** @return array<string, mixed> */
    private function tickets(): array
    {
        return $this->item('tickets', 'ticket', route('ticketing.manage.index'), label: __('pannelli-impianto.tickets'));
    }

    /**
     * Una voce della barra: in vista (`inline`) o dentro «Altro».
     *
     * @return array{key: string, icon: string, url: string, inline: bool, label: string}
     */
    private function item(string $key, string $icon, string $url, bool $inline = false, string $label = ''): array
    {
        return ['key' => $key, 'icon' => 'heroicon-o-'.$icon, 'url' => $url, 'inline' => $inline, 'label' => $label];
    }
}

<?php

declare(strict_types=1);

namespace App\Filament\Venue\Widgets\Support;

use App\Enums\OccurrenceStatus;
use App\Filament\Organizer\Pages\EventShareAnalytics as OrganizerEventShareAnalytics;
use App\Filament\Organizer\Pages\Profile as OrganizerProfile;
use App\Filament\Organizer\Pages\SponsorshipAnalytics as OrganizerSponsorshipAnalytics;
use App\Filament\Organizer\Pages\Sponsorships as OrganizerSponsorships;
use App\Filament\Organizer\Resources\CatalogReviews\CatalogReviewResource as OrganizerCatalogReviewResource;
use App\Filament\Organizer\Resources\EventComments\EventCommentResource as OrganizerEventCommentResource;
use App\Filament\Organizer\Resources\Events\EventResource as OrganizerEventResource;
use App\Filament\Support\EventStatusPresentation;
use App\Filament\Support\PosterColumn;
use App\Filament\Venue\Pages\CalendarImport;
use App\Filament\Venue\Pages\Collaborators;
use App\Filament\Venue\Pages\EventShareAnalytics;
use App\Filament\Venue\Pages\Social;
use App\Filament\Venue\Pages\SponsorshipAnalytics;
use App\Filament\Venue\Pages\Sponsorships;
use App\Filament\Venue\Pages\VenueAccess;
use App\Filament\Venue\Pages\VenueProfile;
use App\Filament\Venue\Resources\CatalogReviews\CatalogReviewResource;
use App\Filament\Venue\Resources\EventComments\EventCommentResource;
use App\Filament\Venue\Resources\Events\EventResource;
use App\Models\City;
use App\Models\Event;
use App\Models\EventOccurrence;
use App\Models\Organizer;
use App\Models\User;
use App\Models\Venue;
use App\Queries\EventOccurrenceQuery;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Filament\Pages\Page;
use Filament\Resources\Resource;
use Illuminate\Database\Eloquent\Builder;

/**
 * Chi sta guardando il riepilogo: un locale (`/gestione`) o un'organizzazione
 * (`/organizza`). I riquadri del riepilogo sono gli stessi nei due pannelli e
 * chiedono a questa classe le tre cose che cambiano: di quali eventi si
 * parla, in che città (per «oggi»), e quali pagine si possono aprire.
 *
 * Non è un widget: la scoperta automatica dei widget la salta.
 */
final class ManagementContext
{
    public static function isOrganizer(): bool
    {
        return Filament::getCurrentPanel()?->getId() === 'organizer';
    }

    public static function tenant(): Venue|Organizer|null
    {
        $tenant = Filament::getTenant();

        return $tenant instanceof Venue || $tenant instanceof Organizer ? $tenant : null;
    }

    /**
     * La città che decide che cos'è «oggi». L'organizzazione non ha una città
     * propria: vale quella della sede principale dei suoi eventi, altrimenti
     * la prima del catalogo.
     */
    public static function city(): ?City
    {
        $tenant = self::tenant();

        if ($tenant instanceof Venue) {
            return $tenant->city ?? City::query()->find($tenant->city_id);
        }

        if ($tenant instanceof Organizer) {
            $cityId = self::events()->reorder()->orderByDesc('id')->value('city_id');

            return City::query()->find($cityId) ?? City::query()->orderBy('id')->first();
        }

        return null;
    }

    /**
     * Le date pubblicate del locale o dell'organizzazione, con lo stesso
     * motore temporale del sito: «oggi» e «prossimi» hanno una definizione sola.
     */
    public static function occurrences(): ?EventOccurrenceQuery
    {
        $tenant = self::tenant();
        $city = self::city();

        if ($tenant === null || $city === null) {
            return null;
        }

        $query = EventOccurrenceQuery::for($city);

        return $tenant instanceof Venue ? $query->atVenue($tenant) : $query->byOrganizer($tenant);
    }

    /**
     * Gli eventi del pannello, bozze comprese.
     *
     * @return Builder<Event>
     */
    public static function events(): Builder
    {
        $tenant = self::tenant();
        $query = Event::query();

        return match (true) {
            $tenant instanceof Venue => $query->where('venue_id', $tenant->getKey()),
            $tenant instanceof Organizer => $query->where('organizer_id', $tenant->getKey()),
            default => $query->whereRaw('1 = 0'),
        };
    }

    /** @return class-string<resource> */
    public static function eventResource(): string
    {
        return self::isOrganizer() ? OrganizerEventResource::class : EventResource::class;
    }

    public static function statisticsUrl(): ?string
    {
        $page = self::isOrganizer() ? OrganizerEventShareAnalytics::class : EventShareAnalytics::class;

        return $page::canAccess() ? $page::getUrl() : null;
    }

    /**
     * I dati della card di un evento (vista `filament.venue.widgets.event-card`)
     * per i riquadri del riepilogo: locandina, titolo che apre la scheda,
     * data nel fuso della città, luogo, stato della data se non è «in
     * programma», e i collegamenti scelti fra quelli dell'evento.
     *
     * @param  list<string>  $linkKeys
     * @param  list<array{0: string, 1: string}>  $extraLines
     * @return array<string, mixed>
     */
    public static function card(Event $event, ?EventOccurrence $date, array $linkKeys, array $extraLines = []): array
    {
        $resource = self::eventResource();
        $timezone = self::city()->timezone ?? (string) config('app.timezone');
        $links = collect(EventResource::eventLinks($event, withView: true))
            ->filter(fn (array $link): bool => in_array($link['key'], $linkKeys, true))
            ->sortBy(fn (array $link): int => (int) array_search($link['key'], $linkKeys, true))
            ->values()
            ->all();
        $date?->setRelation('event', $event);

        return [
            'poster' => PosterColumn::url($event),
            'title' => $event->title,
            'href' => $resource::getUrl('view', ['record' => $event]),
            'lines' => array_values(array_filter([
                $date === null ? null : ['heroicon-o-clock', CarbonImmutable::instance($date->starts_at)->setTimezone($timezone)->translatedFormat('D d/m, H:i')],
                $date === null ? null : ['heroicon-o-map-pin', $date->locationLabel()],
                ...$extraLines,
            ], fn (?array $line): bool => $line !== null && filled($line[1]))),
            'badges' => $date !== null && $date->status !== OccurrenceStatus::Scheduled
                ? [[$date->status->label(), EventStatusPresentation::occurrenceColor($date->status)]]
                : [],
            'links' => $links,
        ];
    }

    /**
     * Tutte le pagine del pannello, in tre gruppi: gli eventi e ciò che serve
     * a farli girare, i numeri e il pubblico, il locale (o l'organizzazione).
     * Ogni voce compare solo se chi guarda può aprirla.
     *
     * @return array<string, list<array{key: string, label: string, icon: string, url: string, primary: bool}>>
     */
    public static function shortcuts(): array
    {
        $resource = self::eventResource();
        $organizer = self::isOrganizer();
        $groups = [
            'group_events' => [],
            'group_audience' => [],
            $organizer ? 'group_organizer' : 'group_venue' => [],
        ];

        if ($resource::canCreate()) {
            $groups['group_events'][] = self::item('create', __('manage.actions.new_event'), 'heroicon-o-plus', $resource::getUrl('create'), true);
        }
        if ($resource::canViewAny()) {
            $groups['group_events'][] = self::item('events', $resource::getNavigationLabel(), 'heroicon-o-calendar-days', $resource::getUrl());
        }

        $pages = $organizer
            ? [
                'group_events' => [OrganizerSponsorships::class],
                'group_audience' => [OrganizerEventShareAnalytics::class, OrganizerSponsorshipAnalytics::class, OrganizerEventCommentResource::class, OrganizerCatalogReviewResource::class],
                'group_organizer' => [OrganizerProfile::class],
            ]
            : [
                'group_events' => [Social::class, Sponsorships::class],
                'group_audience' => [EventShareAnalytics::class, SponsorshipAnalytics::class, EventCommentResource::class, CatalogReviewResource::class],
                'group_venue' => [VenueProfile::class, Collaborators::class, CalendarImport::class, VenueAccess::class],
            ];

        foreach ($pages as $group => $classes) {
            foreach ($classes as $class) {
                $entry = self::entry($class);
                if ($entry !== null) {
                    $groups[$group][] = $entry;
                }
            }
        }

        /* La biglietteria vive fuori dal pannello, e la apre solo chi è
           referente del locale (come la voce della barra laterale). */
        $user = auth()->user();
        if (! $organizer && $user instanceof User && $user->ownedVenueIds() !== []) {
            array_splice($groups['group_events'], 2, 0, [self::item('tickets', __('manage.navigation.tickets'), 'heroicon-o-ticket', route('ticketing.manage.index'))]);
        }

        return array_filter($groups);
    }

    /**
     * @param  class-string  $class
     * @return array{key: string, label: string, icon: string, url: string, primary: bool}|null
     */
    private static function entry(string $class): ?array
    {
        // Solo le pagine che il pannello registra davvero: una classe senza rotta non diventa un pulsante rotto.
        $panel = Filament::getCurrentPanel();
        if ($panel === null || ! in_array($class, [...$panel->getPages(), ...$panel->getResources()], true)) {
            return null;
        }

        if (is_subclass_of($class, Resource::class)) {
            if (! $class::canViewAny()) {
                return null;
            }

            return self::item(class_basename($class), $class::getNavigationLabel(), self::icon($class::getNavigationIcon()), $class::getUrl());
        }

        if (is_subclass_of($class, Page::class)) {
            if (! $class::canAccess()) {
                return null;
            }

            // «Il tuo accesso» non sta nel menu laterale (è nel menu dell'utente): il nome lo dà la pagina.
            $label = $class === VenueAccess::class ? __('manage.access.title') : $class::getNavigationLabel();

            return self::item(class_basename($class), $label, self::icon($class::getNavigationIcon()), $class::getUrl());
        }

        return null;
    }

    private static function icon(mixed $icon): string
    {
        if ($icon instanceof \BackedEnum) {
            return 'heroicon-'.$icon->value;
        }

        return is_string($icon) && $icon !== '' ? $icon : 'heroicon-o-arrow-right';
    }

    /** @return array{key: string, label: string, icon: string, url: string, primary: bool} */
    private static function item(string $key, string $label, string $icon, string $url, bool $primary = false): array
    {
        return ['key' => $key, 'label' => $label, 'icon' => $icon, 'url' => $url, 'primary' => $primary];
    }
}

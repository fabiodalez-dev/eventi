<?php

declare(strict_types=1);

namespace App\Filament\Organizer\Resources\Events\Pages;

use App\Filament\Organizer\Pages\EventAnalyticsDetail;
use App\Filament\Organizer\Resources\Events\EventResource;
use App\Models\Event;
use Filament\Navigation\NavigationItem;
use Filament\Pages\Enums\SubNavigationPosition;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Illuminate\Contracts\Support\Htmlable;

/**
 * La scheda di un evento in sola lettura per chi organizza: la stessa vista
 * del pannello dei locali (card impilate a tutta larghezza), così chi lavora
 * in tutti e due i pannelli ritrova le cose nello stesso posto.
 *
 * @extends ViewRecord<Event>
 */
class ViewEvent extends ViewRecord
{
    protected static string $resource = EventResource::class;

    public function getTitle(): string|Htmlable
    {
        return $this->eventRecord()->title;
    }

    public function infolist(Schema $schema): Schema
    {
        $event = $this->eventRecord();

        return $schema->columns(1)->components([
            View::make('filament.events.venue-event-overview')
                ->viewData([
                    'editUrl' => EventResource::canEdit($event) ? EventResource::getUrl('edit', ['record' => $event]) : null,
                    'statisticsUrl' => EventAnalyticsDetail::getUrl(['subjectType' => 'event', 'subjectId' => $event->getKey()]),
                ])
                ->columnSpanFull(),
        ]);
    }

    public function getRelationManagers(): array
    {
        return [];
    }

    protected function getHeaderActions(): array
    {
        return [];
    }

    public static function getSubNavigationPosition(): SubNavigationPosition
    {
        return SubNavigationPosition::Top;
    }

    /**
     * Le stesse schede del pannello dei locali: Evento, Modifica, Statistiche.
     *
     * @return list<NavigationItem>
     */
    public function getSubNavigation(): array
    {
        $event = $this->eventRecord();
        $items = [
            NavigationItem::make(__('analytics-dashboard.event_tab'))->url(EventResource::getUrl('view', ['record' => $event]))->isActiveWhen(fn (): bool => true),
        ];
        if (EventResource::canEdit($event)) {
            $items[] = NavigationItem::make(__('manage.actions.edit'))->url(EventResource::getUrl('edit', ['record' => $event]))->isActiveWhen(fn (): bool => false);
        }
        $items[] = NavigationItem::make(__('analytics-dashboard.statistics_tab'))
            ->url(EventAnalyticsDetail::getUrl(['subjectType' => 'event', 'subjectId' => $event->getKey()]))
            ->isActiveWhen(fn (): bool => false);

        return $items;
    }

    private function eventRecord(): Event
    {
        return $this->getRecord();
    }
}

<?php

declare(strict_types=1);

namespace App\Filament\Venue\Resources\Events\Pages;

use App\Filament\Shared\EventAnalyticsNavigation;
use App\Filament\Venue\Pages\EventAnalyticsDetail;
use App\Filament\Venue\Resources\Events\EventResource;
use App\Filament\Venue\Support\EventActions;
use App\Models\Event;
use Filament\Pages\Enums\SubNavigationPosition;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Illuminate\Contracts\Support\Htmlable;

/**
 * La scheda di un evento in sola lettura: una colonna sola, a tutta
 * larghezza, fatta di card impilate (testata con locandina e pulsanti,
 * informazioni, descrizione, serate). Il titolo della pagina è il titolo
 * dell'evento; per cambiare qualcosa c'è «Modifica».
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
        // The overview renders every date read-only; editing remains on EditEvent.
        return [];
    }

    /**
     * I collegamenti alle altre pagine stanno nella testata della scheda,
     * accanto alla locandina. Qui sopra restano le azioni che lavorano
     * sull'evento senza cambiare pagina: pubblicarlo, duplicarlo, ripeterlo.
     */
    protected function getHeaderActions(): array
    {
        return [
            EventActions::publish(),
            EventActions::duplicate(),
            EventActions::repeat(),
        ];
    }

    public static function getSubNavigationPosition(): SubNavigationPosition
    {
        return SubNavigationPosition::Top;
    }

    public function getSubNavigation(): array
    {
        return EventAnalyticsNavigation::items((int) $this->getRecord()->getKey(), false);
    }

    private function eventRecord(): Event
    {
        return $this->getRecord();
    }
}

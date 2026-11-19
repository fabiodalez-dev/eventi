<?php

namespace App\Filament\Organizer\Resources\Events\Pages;

use App\Filament\Organizer\Resources\Events\EventResource;
use App\Filament\Venue\Resources\Events\Pages\ListEvents as VenueListEvents;
use App\Models\Organizer;
use Filament\Actions\CreateAction;
use Filament\Facades\Filament;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;

class ListEvents extends ListRecords
{
    protected static string $resource = EventResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->label(__('manage.actions.new_event'))->icon('heroicon-o-plus')];
    }

    /**
     * Le stesse schede di `/gestione`: tutti, prossimi, passati, bozze.
     *
     * @return array<string, Tab>
     */
    public function getTabs(): array
    {
        $tenant = Filament::getTenant();

        return $tenant instanceof Organizer ? VenueListEvents::timingTabs($tenant->city) : [];
    }
}

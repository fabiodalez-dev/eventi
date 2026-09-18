<?php

declare(strict_types=1);

namespace App\Filament\Venue\Resources\Events\Pages;

use App\Filament\Shared\EventAnalyticsNavigation;
use App\Filament\Venue\Resources\Events\EventResource;
use App\Models\Event;
use Filament\Actions\EditAction;
use Filament\Pages\Enums\SubNavigationPosition;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;

/** @extends ViewRecord<Event> */
class ViewEvent extends ViewRecord
{
    protected static string $resource = EventResource::class;

    public function infolist(Schema $schema): Schema
    {
        return $schema->components([View::make('filament.events.venue-event-overview')]);
    }

    public function getRelationManagers(): array
    {
        // The overview renders every date read-only; editing remains on EditEvent.
        return [];
    }

    protected function getHeaderActions(): array
    {
        return [EditAction::make()->label(__('manage.actions.edit'))];
    }

    public static function getSubNavigationPosition(): SubNavigationPosition
    {
        return SubNavigationPosition::Top;
    }

    public function getSubNavigation(): array
    {
        return EventAnalyticsNavigation::items((int) $this->getRecord()->getKey(), false);
    }
}

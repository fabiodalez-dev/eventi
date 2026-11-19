<?php

declare(strict_types=1);

namespace App\Filament\Venue\Resources\Events\Pages;

use App\Filament\Venue\Resources\Events\EventResource;
use App\Filament\Venue\Support\CurrentVenue;
use App\Models\City;
use App\Queries\VenueDashboardQuery;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;

class ListEvents extends ListRecords
{
    protected static string $resource = EventResource::class;

    public function getTitle(): string
    {
        return __('manage.resources.event.plural');
    }

    /**
     * @return array<mixed>
     */
    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->label(__('manage.actions.new_event'))
                ->icon('heroicon-o-plus'),
        ];
    }

    /**
     * @return array<string, Tab>
     */
    public function getTabs(): array
    {
        return self::timingTabs(CurrentVenue::city());
    }

    /**
     * Le quattro schede dell'elenco eventi, uguali in `/gestione` e in
     * `/organizza`: tutti, i prossimi, i passati e le bozze. Sono schede e
     * non un filtro nascosto dietro un'icona perché sono la prima scelta che
     * fa chi apre l'elenco.
     *
     * @return array<string, Tab>
     */
    public static function timingTabs(City $city): array
    {
        $tabs = ['all' => Tab::make(__('manage.event_list.all'))];

        foreach (['upcoming' => 'upcoming', 'past' => 'past_plural', 'drafts' => 'drafts'] as $timing => $label) {
            $tabs[$timing] = Tab::make(__('manage.event_list.'.$label))
                ->modifyQueryUsing(fn (Builder $query) => VenueDashboardQuery::applyTiming($query, $city, $timing));
        }

        return $tabs;
    }
}

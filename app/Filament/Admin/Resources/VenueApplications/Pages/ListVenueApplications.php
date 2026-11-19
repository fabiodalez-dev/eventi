<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\VenueApplications\Pages;

use App\Enums\ApplicationStatus;
use App\Filament\Admin\Resources\VenueApplications\VenueApplicationResource;
use App\Models\VenueApplication;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;

/**
 * La coda: si apre su quelle da valutare, con il conto di ogni stato nelle
 * schede. Le richieste che aspettano ancora la conferma dell'email non ci
 * sono, né nell'elenco né nei conti (scope globale del modello).
 */
class ListVenueApplications extends ListRecords
{
    protected static string $resource = VenueApplicationResource::class;

    /**
     * @return array<string, Tab>
     */
    public function getTabs(): array
    {
        $counts = VenueApplication::query()->toBase()
            ->selectRaw('status, COUNT(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        $tabs = [];
        foreach ([ApplicationStatus::Pending, ApplicationStatus::Approved, ApplicationStatus::Rejected] as $status) {
            $tabs[$status->value] = Tab::make(__('venue_applications.admin.tabs.'.$status->value))
                ->badge((int) ($counts[$status->value] ?? 0))
                ->badgeColor(VenueApplicationResource::statusColor($status))
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->where('venue_applications.status', $status));
        }

        $tabs['all'] = Tab::make(__('venue_applications.admin.tabs.all'));

        return $tabs;
    }

    public function getDefaultActiveTab(): string|int|null
    {
        return ApplicationStatus::Pending->value;
    }

    /**
     * @return array<mixed>
     */
    protected function getHeaderActions(): array
    {
        return [];
    }
}

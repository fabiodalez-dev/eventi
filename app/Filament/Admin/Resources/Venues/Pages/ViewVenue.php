<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\Venues\Pages;

use App\Filament\Admin\Resources\Venues\VenueResource;
use App\Filament\Admin\Support\VenueModeration;
use App\Models\Venue;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Illuminate\Contracts\Support\Htmlable;

/**
 * La scheda di un locale in redazione, in sola lettura.
 *
 * Il moderatore può approvare, sospendere, verificare un locale ma non
 * modificarne la scheda: prima l'unica pagina era il modulo di modifica, e
 * per lui era un 403. Qui legge copertina, stato, indirizzo, contatti e i
 * prossimi eventi, e decide dall'intestazione. Una colonna sola, card
 * impilate, nessun campo compilabile.
 *
 * @extends ViewRecord<Venue>
 */
class ViewVenue extends ViewRecord
{
    protected static string $resource = VenueResource::class;

    public function getTitle(): string|Htmlable
    {
        return $this->venueRecord()->name;
    }

    public function infolist(Schema $schema): Schema
    {
        $venue = $this->venueRecord();

        return $schema->columns(1)->components([
            View::make('filament.admin.venues.overview')
                ->viewData([
                    'venue' => $venue,
                    'editUrl' => VenueResource::canEdit($venue) ? VenueResource::getUrl('edit', ['record' => $venue]) : null,
                ])
                ->columnSpanFull(),
        ]);
    }

    /** Collaboratori ed eventi si leggono nella scheda; si gestiscono nella modifica. */
    public function getRelationManagers(): array
    {
        return [];
    }

    /**
     * @return array<mixed>
     */
    protected function getHeaderActions(): array
    {
        return VenueModeration::headerActions();
    }

    private function venueRecord(): Venue
    {
        /** @var Venue $venue */
        $venue = $this->getRecord();

        return $venue;
    }
}

<?php

declare(strict_types=1);

namespace App\Filament\Venue\Resources\Events\Pages;

use App\Filament\Support\PublicationActions;
use App\Filament\Venue\Pages\Social;
use App\Filament\Venue\Resources\Events\EventResource;
use App\Filament\Venue\Support\EventActions;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Support\Arr;

/**
 * La scheda di un evento già inserito.
 *
 * Le date non stanno qui ma nella sezione dedicata sotto al modulo: è la
 * stessa scelta del pannello di redazione (D24, punto 5), e per la stessa
 * ragione — due moduli che scrivono le stesse righe nella stessa pagina si
 * sovrascrivono a vicenda.
 */
class EditEvent extends EditRecord
{
    protected static string $resource = EventResource::class;

    protected function mutateFormDataBeforeSave(array $data): array
    {
        // Solo colonne offerte dal modulo: i campi amministrativi e il tenant
        // non diventano scrivibili aggiungendoli a una richiesta Livewire.
        return Arr::only($data, [
            'title', 'description', 'category_id', 'price_type', 'price_min', 'price_max',
            'ticket_url', 'booking_url', 'external_links', 'facts', 'content_details',
            'seo', 'organizer_name', 'organizer_url',
        ]);
    }

    /**
     * @return array<mixed>
     */
    protected function getHeaderActions(): array
    {
        return [
            PublicationActions::schedule(),
            PublicationActions::cancel(),
            Action::make('pagePreview')->label(__('promotions.preview'))->url(fn () => route('events.preview', $this->getRecord()))->openUrlInNewTab()->color('gray'),
            Action::make('social')->label(__('social.preview'))->url(fn () => Social::getUrl(['event' => $this->getRecord()->getKey()])),
            EventActions::viewOnSite(),
            EventActions::publish(),
            EventActions::repeat(),
            EventActions::duplicate(),
            DeleteAction::make(),
        ];
    }
}

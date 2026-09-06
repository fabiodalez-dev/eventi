<?php

declare(strict_types=1);

namespace App\Filament\Venue\Resources\Events\Pages;

use App\Filament\Venue\Pages\Social;
use App\Filament\Venue\Resources\Events\EventResource;
use App\Filament\Venue\Support\EventActions;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

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

    /**
     * @return array<mixed>
     */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('social')->label(__('social.preview'))->url(fn () => Social::getUrl(['event' => $this->getRecord()->getKey()])),
            EventActions::viewOnSite(),
            EventActions::publish(),
            EventActions::repeat(),
            EventActions::duplicate(),
            DeleteAction::make(),
        ];
    }
}

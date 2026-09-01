<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\Sponsorships\Pages;

use App\Filament\Admin\Resources\Sponsorships\SponsorshipResource;
use App\Models\Event;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditSponsorship extends EditRecord
{
    protected static string $resource = SponsorshipResource::class;

    /**
     * @return array<mixed>
     */
    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }

    /**
     * Se l'evento cambia, la città lo segue: restare quella di prima
     * significherebbe una campagna che non compare in nessuna delle due.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        if (isset($data['event_id'])) {
            $data['city_id'] = Event::query()->whereKey($data['event_id'])->value('city_id');
        }

        return $data;
    }
}

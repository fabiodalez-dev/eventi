<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\Sponsorships\Pages;

use App\Filament\Admin\Resources\Sponsorships\SponsorshipResource;
use App\Models\Event;
use Filament\Resources\Pages\CreateRecord;

class CreateSponsorship extends CreateRecord
{
    protected static string $resource = SponsorshipResource::class;

    /**
     * La città e l'autore non si chiedono: la prima viene dall'evento — una
     * campagna in una città diversa da quella del proprio evento è un dato
     * incoerente, non un caso da coprire — e il secondo è chi sta compilando.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['city_id'] ??= Event::query()->whereKey($data['event_id'] ?? null)->value('city_id');
        $data['created_by'] ??= auth()->id();

        return $data;
    }
}

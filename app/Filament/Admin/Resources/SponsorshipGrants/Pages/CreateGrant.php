<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\SponsorshipGrants\Pages;

use App\Filament\Admin\Resources\SponsorshipGrants\SponsorshipGrantResource;
use App\Models\SponsorshipGrant;
use App\Services\Sponsorship\GrantCampaigns;
use Filament\Resources\Pages\CreateRecord;

class CreateGrant extends CreateRecord
{
    protected static string $resource = SponsorshipGrantResource::class;

    public function getTitle(): string
    {
        return __('promotions.create_title');
    }

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['created_by'] = auth()->id();

        return $data;
    }

    protected function afterCreate(): void
    {
        $record = $this->getRecord();
        if ($record instanceof SponsorshipGrant) {
            app(GrantCampaigns::class)->sync($record);
        }
    }
}

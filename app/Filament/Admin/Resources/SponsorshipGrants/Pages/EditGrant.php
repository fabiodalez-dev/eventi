<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\SponsorshipGrants\Pages;

use App\Filament\Admin\Resources\SponsorshipGrants\SponsorshipGrantResource;
use App\Models\SponsorshipGrant;
use App\Services\Sponsorship\GrantCampaigns;
use Filament\Resources\Pages\EditRecord;

class EditGrant extends EditRecord
{
    protected static string $resource = SponsorshipGrantResource::class;

    protected function afterSave(): void
    {
        $record = $this->getRecord();
        if ($record instanceof SponsorshipGrant) {
            app(GrantCampaigns::class)->sync($record);
        }
    }
}

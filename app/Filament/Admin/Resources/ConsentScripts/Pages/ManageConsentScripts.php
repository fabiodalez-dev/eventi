<?php

namespace App\Filament\Admin\Resources\ConsentScripts\Pages;

use App\Filament\Admin\Resources\ConsentScripts\ConsentScriptResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageConsentScripts extends ManageRecords
{
    protected static string $resource = ConsentScriptResource::class;

    public function getSubheading(): ?string
    {
        return __('consent_scripts.help');
    }

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}

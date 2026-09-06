<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\SponsorshipGrants\Pages;

use App\Filament\Admin\Resources\SponsorshipGrants\SponsorshipGrantResource;
use App\Filament\Admin\Resources\Sponsorships\SponsorshipResource;
use App\Filament\Admin\Widgets\PromotionSummary;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListGrants extends ListRecords
{
    protected static string $resource = SponsorshipGrantResource::class;

    protected function getHeaderActions(): array
    {
        return [Action::make('campaigns')->label(__('promotions.campaigns'))->url(SponsorshipResource::getUrl())->color('gray'), CreateAction::make()];
    }

    protected function getHeaderWidgets(): array
    {
        return [PromotionSummary::class];
    }
}

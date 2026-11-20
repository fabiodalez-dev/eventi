<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\SponsorshipGrants\Pages;

use App\Filament\Admin\Resources\SponsorshipGrants\SponsorshipGrantResource;
use App\Filament\Admin\Resources\Sponsorships\SponsorshipResource;
use App\Filament\Admin\Widgets\PromotionSummary;
use App\Filament\Concerns\HasPlainBreadcrumbs;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListGrants extends ListRecords
{
    /* Le briciole dicono solo il titolo, senza «Lista» in fondo (D69). */
    use HasPlainBreadcrumbs;

    protected static string $resource = SponsorshipGrantResource::class;

    protected function getHeaderActions(): array
    {
        return [Action::make('campaigns')->label(__('promotions.campaigns'))->url(SponsorshipResource::getUrl())->color('gray'), CreateAction::make()->label(__('promotions.create_title'))];
    }

    protected function getHeaderWidgets(): array
    {
        return [PromotionSummary::class];
    }

    /** Il titolo com'e' scritto nei testi, non con le iniziali maiuscole che Filament aggiunge da solo. */
    public function getTitle(): string
    {
        return __('promotions.title');
    }

    /** @return array<string, string> */
    public function getBreadcrumbs(): array
    {
        return [];
    }
}

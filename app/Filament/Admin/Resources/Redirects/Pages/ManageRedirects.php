<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\Redirects\Pages;

use App\Filament\Admin\Resources\Redirects\RedirectResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

/**
 * `ManageRecords` e non `ListRecords`: una riga sono due indirizzi e una
 * scelta: aprire una scheda intera per scriverli sarebbe un giro inutile.
 */
class ManageRedirects extends ManageRecords
{
    protected static string $resource = RedirectResource::class;

    public function getTitle(): string
    {
        return __('redirects.title');
    }

    public function getSubheading(): ?string
    {
        return __('redirects.lead');
    }

    /** @return array<CreateAction> */
    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label(__('redirects.actions.add')),
        ];
    }
}

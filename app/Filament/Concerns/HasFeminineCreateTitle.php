<?php

declare(strict_types=1);

namespace App\Filament\Concerns;

/**
 * Il titolo della pagina «Nuovo …» di Filament è al maschile per tutti:
 * «Nuovo Sponsorizzazione», «Nuovo Categoria». Le risorse con un nome
 * femminile usano questo, che dice «Nuova sponsorizzazione».
 */
trait HasFeminineCreateTitle
{
    public function getTitle(): string
    {
        return __('admin.create_feminine.title', ['label' => mb_strtolower(static::getResource()::getModelLabel())]);
    }

    public function getBreadcrumb(): string
    {
        return __('admin.create_feminine.breadcrumb');
    }
}

<?php

declare(strict_types=1);

namespace App\Filament\Concerns;

use Illuminate\Contracts\Support\Htmlable;

/**
 * Le briciole di una pagina elenco senza «Lista» in fondo.
 *
 * Filament scrive «Eventi › Lista» sopra il titolo «Eventi»: la seconda voce
 * non dice niente a chi legge. Una pagina elenco che usa questo trait mostra
 * solo il suo titolo.
 */
trait HasPlainBreadcrumbs
{
    /** @return array<int|string, string> */
    public function getBreadcrumbs(): array
    {
        $title = $this->getTitle();

        return [$title instanceof Htmlable ? $title->toHtml() : $title];
    }
}

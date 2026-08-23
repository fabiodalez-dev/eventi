<?php

declare(strict_types=1);

namespace App\Filament\Admin\Widgets;

use App\Models\City;
use App\Queries\EditorialDashboardQuery;
use App\Support\CurrentCity;
use Filament\Widgets\StatsOverviewWidget;

/**
 * Base dei riquadri della dashboard di redazione.
 *
 * Due cose che non vanno ripetute in ogni riquadro stanno qui.
 *
 * **Quale città.** Il sito pubblico guarda la prima città *accesa*
 * (`CurrentCity`), ma la redazione lavora anche su una città che non è ancora
 * in linea — anzi, comincia proprio da lì: `cities.is_active` nasce falso di
 * proposito. Quando nessuna città è accesa il pannello ripiega sulla più
 * antica, e la risoluzione sta in questo unico punto perché non diventi una
 * seconda definizione sparsa per i riquadri.
 *
 * **Niente città, niente riquadri.** Finché la prima città non esiste ogni
 * numero varrebbe zero e la dashboard sarebbe una fila di contenitori vuoti:
 * i riquadri semplicemente non vengono disegnati (§8.6).
 */
abstract class EditorialWidget extends StatsOverviewWidget
{
    public static function canView(): bool
    {
        return static::resolveCity() instanceof City;
    }

    protected static function resolveCity(): ?City
    {
        return app(CurrentCity::class)->get()
            ?? City::query()->orderBy('id')->first();
    }

    protected function dashboard(): EditorialDashboardQuery
    {
        $city = static::resolveCity();

        abort_if($city === null, 404);

        return EditorialDashboardQuery::for($city);
    }
}

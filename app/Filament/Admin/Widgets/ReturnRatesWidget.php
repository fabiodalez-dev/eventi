<?php

declare(strict_types=1);

namespace App\Filament\Admin\Widgets;

use App\Filament\Admin\Support\DeskLinks;
use App\Services\Editorial\DeskSummary;
use Filament\Support\Icons\Heroicon;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * I numeri di ritorno, accanto a quelli di pubblicazione.
 *
 * Stanno nella dashboard della redazione e non in una pagina a sé perché sono
 * la domanda che viene prima di tutte le altre: le persone tornano? Se la
 * risposta peggiora mentre il catalogo cresce, il problema non è il catalogo.
 *
 * Ogni numero porta con sé la propria definizione: un tasso senza definizione
 * viene letto come una promessa.
 */
class ReturnRatesWidget extends EditorialWidget
{
    protected static ?int $sort = 4;

    protected function getHeading(): ?string
    {
        return __('admin.dashboard.returns_heading');
    }

    protected function getDescription(): ?string
    {
        return __('admin.dashboard.returns_description');
    }

    /**
     * @return array<Stat>
     */
    protected function getStats(): array
    {
        // Gli altri riquadri di questa dashboard leggono la città risolta per il
        // pannello: questi numeri devono avere lo stesso confine, o si finisce a
        // confrontare una città con tutte le altre nella stessa schermata.
        return DeskLinks::stats(app(DeskSummary::class)->returns($this->city()), [
            'returning' => Heroicon::OutlinedArrowPath,
            'booked_people' => Heroicon::OutlinedTicket,
            'attendance' => Heroicon::OutlinedCheckBadge,
        ]);
    }
}

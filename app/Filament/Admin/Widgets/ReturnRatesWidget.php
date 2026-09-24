<?php

declare(strict_types=1);

namespace App\Filament\Admin\Widgets;

use App\Services\Analytics\ReturnMetrics;
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
        $metrics = app(ReturnMetrics::class)->summary();

        return [
            Stat::make(__('admin.dashboard.returning'), $metrics['returning']['rate'].'%')
                ->description(__('admin.dashboard.descriptions.returning', [
                    'tornati' => $metrics['returning']['returned'],
                    'base' => $metrics['returning']['base'],
                    'giorni' => $metrics['window_days'],
                ]))
                ->icon(Heroicon::OutlinedArrowPath),

            Stat::make(__('admin.dashboard.booked_people'), $metrics['booked'])
                ->description(__('admin.dashboard.descriptions.booked_people', ['prenotazioni' => $metrics['bookings']]))
                ->icon(Heroicon::OutlinedTicket),

            Stat::make(__('admin.dashboard.attendance'), $metrics['attendance'] === null ? '—' : $metrics['attendance'].'%')
                ->description(__('admin.dashboard.descriptions.attendance', ['ingressi' => $metrics['check_ins']]))
                ->icon(Heroicon::OutlinedCheckBadge),
        ];
    }
}

<?php

declare(strict_types=1);

namespace App\Filament\Admin\Support;

use App\DTOs\Editorial\DeskStat;
use App\DTOs\Editorial\DeskTarget;
use App\Filament\Admin\Resources\Events\EventResource;
use App\Filament\Admin\Resources\ImportSources\ImportSourceResource;
use App\Filament\Admin\Resources\Reports\ReportResource;
use App\Filament\Admin\Resources\Users\UserResource;
use App\Filament\Admin\Resources\VenueApplications\VenueApplicationResource;
use App\Filament\Admin\Resources\Venues\VenueResource;
use Filament\Resources\Resource as FilamentResource;
use Filament\Support\Icons\Heroicon;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * I riquadri del riepilogo (`DeskSummary`) disegnati come `Stat` del
 * pannello: la destinazione diventa l'indirizzo della risorsa con il filtro
 * acceso, l'icona la sceglie il widget.
 */
final class DeskLinks
{
    /** @var array<string, class-string<FilamentResource>> */
    private const RESOURCES = [
        'events' => EventResource::class,
        'venues' => VenueResource::class,
        'applications' => VenueApplicationResource::class,
        'reports' => ReportResource::class,
        'import_sources' => ImportSourceResource::class,
        'users' => UserResource::class,
    ];

    /** L'elenco della risorsa con il filtro o la scheda della destinazione. */
    public static function url(DeskTarget $target): string
    {
        return self::RESOURCES[$target->area]::getUrl('index', $target->panelParameters());
    }

    /**
     * @param  list<DeskStat>  $stats
     * @param  array<string, Heroicon>  $icons  l'icona di ogni riquadro, per chiave
     * @return array<Stat>
     */
    public static function stats(array $stats, array $icons = []): array
    {
        return array_map(static function (DeskStat $row) use ($icons): Stat {
            $stat = Stat::make($row->label, $row->value)->description($row->description);
            if (isset($icons[$row->key])) {
                $stat->icon($icons[$row->key]);
            }
            if ($row->tone !== null) {
                $stat->color($row->tone);
            }
            if ($row->target instanceof DeskTarget) {
                $stat->url(self::url($row->target));
            }

            return $stat;
        }, $stats);
    }
}

<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\ArchiveExpiredEvents;
use Illuminate\Console\Command;

/**
 * L'automatismo che mancava a §14.5: gli eventi scaduti passano da soli in
 * `archived`.
 *
 * Gira ogni notte da `routes/console.php`. Con `--dry-run` conta e non tocca
 * nulla: è il modo di guardare quanti eventi sparirebbero dalle liste **prima**
 * di farli sparire, che su un archivio arretrato di mesi è una differenza che
 * si vede.
 *
 * La soglia predefinita sta in `config/eventi.php` e si sovrascrive con
 * `--days`. Zero è ammesso e significa «tutto ciò che è già passato».
 */
final class ArchiveExpiredEventsCommand extends Command
{
    public function __construct()
    {
        $this->signature = 'events:archive'
            .' {--days= : '.__('console.events_archive.option_days').'}'
            .' {--dry-run : '.__('console.events_archive.option_dry_run').'}';

        $this->description = __('console.events_archive.description');

        parent::__construct();
    }

    public function handle(ArchiveExpiredEvents $action): int
    {
        $days = $this->days();
        $dryRun = (bool) $this->option('dry-run');

        $archived = $action($days, $dryRun);
        $total = array_sum($archived);

        if ($total === 0) {
            $this->info(__('console.events_archive.empty', ['days' => $days]));

            return self::SUCCESS;
        }

        foreach ($archived as $city => $count) {
            $this->line(__('console.events_archive.city', ['city' => $city, 'count' => $count]));
        }

        $this->info(__(
            $dryRun ? 'console.events_archive.would_archive' : 'console.events_archive.done',
            ['count' => $total, 'days' => $days],
        ));

        return self::SUCCESS;
    }

    /**
     * `--days` vince sulla configurazione; un valore negativo non esiste —
     * archiviare «fra tre giorni» significherebbe togliere dalle liste eventi
     * che devono ancora avvenire.
     */
    private function days(): int
    {
        $option = $this->option('days');

        return is_numeric($option)
            ? max((int) $option, 0)
            : config()->integer('eventi.archive_after_days');
    }
}

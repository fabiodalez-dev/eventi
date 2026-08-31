<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Exceptions\ImportException;
use App\Jobs\Import\ImportSourceJob;
use App\Models\ImportSource;
use App\Services\Import\ImportDriverFactory;
use App\Services\Import\ImportRunner;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;

/**
 * L'esecuzione oraria di §14.2. Gira da `routes/console.php` e si invoca a mano
 * dopo aver dichiarato una sorgente nuova.
 *
 * **Accoda, non esegue.** Ogni sorgente diventa un `ImportSourceJob` proprio,
 * perché è così che una irraggiungibile non ferma le altre. Con `--sync` il
 * comando esegue invece nel proprio processo e stampa il resoconto: è il modo
 * di provare una sorgente appena configurata e vedere subito che cosa entra,
 * senza dover guardare i log di un worker.
 */
final class RunImportsCommand extends Command
{
    /**
     * Sorgenti lette a blocchi: l'elenco cresce con le città.
     */
    private const CHUNK = 50;

    public function __construct()
    {
        $this->signature = 'import:run'
            .' {--source= : '.__('console.import_run.option_source').'}'
            .' {--sync : '.__('console.import_run.option_sync').'}';

        $this->description = __('console.import_run.description');

        parent::__construct();
    }

    public function handle(ImportRunner $runner): int
    {
        $query = ImportSource::query()
            ->active()
            ->whereIn('type', ImportDriverFactory::supportedTypes())
            ->orderBy('id');

        $source = $this->option('source');

        if (is_numeric($source)) {
            $query->whereKey((int) $source);
        }

        $sync = (bool) $this->option('sync');
        $sources = 0;
        $totals = ['created' => 0, 'updated' => 0, 'unchanged' => 0, 'excluded' => 0, 'cancelled' => 0, 'errors' => 0];

        $query->chunkById(self::CHUNK, function (Collection $chunk) use ($runner, $sync, &$sources, &$totals): void {
            /** @var ImportSource $item */
            foreach ($chunk as $item) {
                $sources++;

                if (! $sync) {
                    ImportSourceJob::dispatch((int) $item->getKey());

                    continue;
                }

                $this->line(__('console.import_run.source', ['id' => $item->getKey(), 'url' => (string) $item->url]));

                try {
                    $report = $runner->run($item)->toArray();
                } catch (ImportException $exception) {
                    $this->warn(__('console.import_run.failed', [
                        'id' => $item->getKey(),
                        'reason' => $exception->getMessage(),
                    ]));

                    $totals['errors']++;

                    continue;
                }

                foreach (array_keys($totals) as $key) {
                    $totals[$key] += $report[$key];
                }
            }
        });

        if ($sources === 0) {
            $this->info(__('console.import_run.empty'));

            return self::SUCCESS;
        }

        $this->info($sync
            ? __('console.import_run.done', ['sources' => $sources, ...$totals])
            : __('console.import_run.queued', ['sources' => $sources]));

        return self::SUCCESS;
    }
}

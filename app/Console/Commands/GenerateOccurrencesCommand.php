<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\GenerateOccurrencesAction;
use App\Enums\EventStatus;
use App\Models\EventRecurrence;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * Estende l'orizzonte delle ricorrenze. Gira ogni mese (`routes/console.php`)
 * e può essere invocato a mano dopo aver modificato una regola.
 */
final class GenerateOccurrencesCommand extends Command
{
    /**
     * Ricorrenze lette a blocchi: la tabella cresce con il catalogo.
     */
    private const CHUNK = 100;

    public function __construct()
    {
        $this->signature = 'occurrences:generate'
            .' {--recurrence= : '.__('console.occurrences_generate.option_recurrence').'}'
            .' {--months= : '.__('console.occurrences_generate.option_months').'}';

        $this->description = __('console.occurrences_generate.description');

        parent::__construct();
    }

    public function handle(GenerateOccurrencesAction $action): int
    {
        $months = $this->option('months');
        $horizon = is_numeric($months)
            ? CarbonImmutable::now()->addMonths((int) $months)->endOfDay()
            : null;

        $query = EventRecurrence::query()
            ->whereHas('event', fn (Builder $event): Builder => $event->whereNotIn('status', [
                EventStatus::Archived->value,
                EventStatus::Rejected->value,
            ]));

        $recurrence = $this->option('recurrence');

        if (is_numeric($recurrence)) {
            $query->whereKey((int) $recurrence);
        }

        $processed = 0;
        $created = 0;

        $query->orderBy('id')->chunkById(self::CHUNK, function (Collection $chunk) use ($action, $horizon, &$processed, &$created): void {
            /** @var EventRecurrence $item */
            foreach ($chunk as $item) {
                $created += $action($item, $horizon);
                $processed++;
            }
        });

        if ($processed === 0) {
            $this->info(__('console.occurrences_generate.empty'));

            return self::SUCCESS;
        }

        $this->info(__('console.occurrences_generate.done', [
            'recurrences' => $processed,
            'created' => $created,
        ]));

        return self::SUCCESS;
    }
}

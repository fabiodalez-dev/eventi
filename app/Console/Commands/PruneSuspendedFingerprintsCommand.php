<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Community\PruneSuspendedFingerprints;
use Illuminate\Console\Command;

/**
 * Elimina le impronte dei numeri trattenute per gli account sospesi e
 * cancellati, passato il periodo di conservazione dichiarato nell'informativa.
 * Gira ogni notte da `routes/console.php`; con `--dry-run` dice soltanto
 * quante ne toglierebbe.
 */
final class PruneSuspendedFingerprintsCommand extends Command
{
    public function __construct()
    {
        $this->signature = 'community:prune-fingerprints'
            .' {--dry-run : '.__('console.community_prune_fingerprints.option_dry_run').'}';

        $this->description = __('console.community_prune_fingerprints.description');

        parent::__construct();
    }

    public function handle(PruneSuspendedFingerprints $action): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $count = $action($dryRun);
        $months = config()->integer('community.suspended_fingerprint_retention_months');

        $this->info(__(match (true) {
            $count === 0 => 'console.community_prune_fingerprints.empty',
            $dryRun => 'console.community_prune_fingerprints.would_remove',
            default => 'console.community_prune_fingerprints.done',
        }, ['count' => $count, 'months' => $months]));

        return self::SUCCESS;
    }
}

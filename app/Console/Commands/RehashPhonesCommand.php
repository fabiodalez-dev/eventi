<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Community\RehashPhoneFingerprints;
use App\DTOs\PhoneRehashReport;
use Illuminate\Console\Command;
use Illuminate\Contracts\Cache\LockTimeoutException;

/**
 * La rotazione di `WHATSAPP_PHONE_HASH_KEY` (issue #103; procedura in
 * `docs/RUNBOOK.md`).
 *
 * La chiave nuova è quella che l'applicazione usa già, dalla configurazione.
 * La vecchia arriva **solo** dall'ambiente del processo
 * (`read -rs OLDKEY`, poi `WHATSAPP_PHONE_HASH_PREVIOUS_KEY="$OLDKEY" php artisan ...`,
 * così nella cronologia resta solo il nome della variabile): non un'opzione,
 * che finirebbe nella cronologia della shell e in `ps`, e non `.env`, dove
 * `config:cache` la congelerebbe e resterebbe dimenticata dopo la rotazione.
 *
 * Non stampa mai numeri, impronte o chiavi: solo conteggi e id di riga.
 */
final class RehashPhonesCommand extends Command
{
    private const PREVIOUS_KEY = 'WHATSAPP_PHONE_HASH_PREVIOUS_KEY';

    public function __construct()
    {
        $this->signature = 'community:rehash-phones'
            .' {--dry-run : '.__('console.community_rehash.option_dry_run').'}'
            .' {--release-orphans : '.__('console.community_rehash.option_release_orphans').'}';

        $this->description = __('console.community_rehash.description');

        parent::__construct();
    }

    public function handle(RehashPhoneFingerprints $action): int
    {
        $newKey = (string) config('community.phone_hash_key');
        $oldKey = $this->previousKey();
        if ($newKey === '') {
            $this->error(__('console.community_rehash.missing_new_key'));

            return self::FAILURE;
        }
        if ($oldKey === '') {
            $this->error(__('console.community_rehash.missing_old_key', ['variable' => self::PREVIOUS_KEY]));

            return self::FAILURE;
        }
        if (hash_equals($newKey, $oldKey)) {
            $this->error(__('console.community_rehash.same_keys'));

            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');
        try {
            $report = $action($newKey, $oldKey, $dryRun, (bool) $this->option('release-orphans'));
        } catch (LockTimeoutException) {
            $this->error(__('console.community_rehash.busy'));

            return self::FAILURE;
        }

        $this->table(
            [__('console.community_rehash.class'), __('console.community_rehash.users'), __('console.community_rehash.challenges')],
            array_map(fn (string $class): array => [
                __('console.community_rehash.classes.'.$class),
                $report->users[$class] ?? 0,
                $class === 'orphan' ? '—' : ($report->challenges[$class] ?? 0),
            ], ['already', 'rehash', 'mismatch', 'orphan']),
        );

        return $report->aborted() ? $this->abort($report) : $this->conclude($report, $dryRun);
    }

    private function abort(PhoneRehashReport $report): int
    {
        if ($report->mismatchedUsers !== [] || $report->mismatchedChallenges !== []) {
            $this->error(__('console.community_rehash.mismatch'));
            $this->ids('users_ids', $report->mismatchedUsers);
            $this->ids('challenges_ids', $report->mismatchedChallenges);
        }
        if ($report->collidingUsers !== []) {
            $this->error(__('console.community_rehash.collision'));
            $this->ids('users_ids', $report->collidingUsers);
        }
        if ($report->orphansBlocking) {
            $this->error(__('console.community_rehash.orphans', ['count' => $report->users['orphan'] ?? 0]));
        }
        $this->error(__('console.community_rehash.nothing_written'));

        return self::FAILURE;
    }

    private function conclude(PhoneRehashReport $report, bool $dryRun): int
    {
        $orphans = $report->users['orphan'] ?? 0;
        if ($orphans > 0) {
            $this->warn(__($dryRun ? 'console.community_rehash.would_release' : 'console.community_rehash.released', ['count' => $orphans]));
        }
        $this->info(__(match (true) {
            $dryRun => 'console.community_rehash.dry_run',
            $report->written => 'console.community_rehash.done',
            default => 'console.community_rehash.nothing_to_do',
        }, ['users' => $report->users['rehash'] ?? 0, 'challenges' => $report->challenges['rehash'] ?? 0]));

        return self::SUCCESS;
    }

    /** @param  list<int|string>  $ids */
    private function ids(string $label, array $ids): void
    {
        if ($ids !== []) {
            $this->line(__('console.community_rehash.'.$label, ['ids' => implode(', ', array_unique($ids))]));
        }
    }

    /** Dall'ambiente del processo e da nient'altro: vedi il commento della classe. */
    private function previousKey(): string
    {
        $value = $_SERVER[self::PREVIOUS_KEY] ?? getenv(self::PREVIOUS_KEY);

        return is_string($value) ? $value : '';
    }
}

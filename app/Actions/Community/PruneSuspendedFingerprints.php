<?php

declare(strict_types=1);

namespace App\Actions\Community;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * La scadenza delle impronte trattenute per gli account sospesi e cancellati
 * (issue #104).
 *
 * `DeleteAccount` conserva l'impronta del numero e la data della sospensione di
 * chi è stato sospeso, perché non possa reiscriversi con lo stesso numero. Il
 * bando però non vale per sempre: passati `suspended_fingerprint_retention_months`
 * dalla sospensione se ne vanno entrambe, e dell'account resta la riga già
 * anonimizzata. Si conta dalla sospensione perché ciò che si conserva è il
 * bando, ed è la sua età a giustificarlo; l'informativa dice esattamente questo.
 *
 * Le sospensioni sono tre (globale, social, passaggi) e ciascuna trattiene
 * l'impronta: si conta dalla più recente, e scadono insieme.
 *
 * Tocca solo account cancellati: un account attivo e sospeso tiene la propria
 * sospensione finché lo staff non la toglie.
 */
final class PruneSuspendedFingerprints
{
    public function __invoke(bool $dryRun = false): int
    {
        $cutoff = now()->subMonthsNoOverflow(config()->integer('community.suspended_fingerprint_retention_months'));

        $columns = ['community_suspended_at', 'social_suspended_at', 'carpool_suspended_at'];
        $query = User::onlyTrashed()->whereNotNull('whatsapp_phone_hash');
        foreach ($columns as $column) {
            $query->where(fn (Builder $query) => $query->whereNull($column)->orWhere($column, '<', $cutoff));
        }
        $ids = $query
            ->where(fn (Builder $query) => $query->whereNotNull($columns[0])->orWhereNotNull($columns[1])->orWhereNotNull($columns[2])
                // Difensivo: DeleteAccount toglie già l'impronta a chi non era sospeso.
                ->orWhere('deleted_at', '<', $cutoff))
            ->pluck('id')
            ->all();

        $count = count($ids);
        if ($dryRun || $count === 0) {
            return $count;
        }

        DB::transaction(function () use ($ids, $count): void {
            foreach (array_chunk($ids, 500) as $chunk) {
                DB::table('users')->whereIn('id', $chunk)->update(['whatsapp_phone_hash' => null, 'community_suspended_at' => null, 'social_suspended_at' => null, 'carpool_suspended_at' => null]);
            }
            // Solo il conteggio: gli identificativi riporterebbero le persone al bando appena scaduto.
            activity('community')->withProperties(['count' => $count])->event('fingerprints_pruned')->log('fingerprints_pruned');
        });

        return $count;
    }
}

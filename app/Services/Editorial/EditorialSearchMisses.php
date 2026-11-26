<?php

declare(strict_types=1);

namespace App\Services\Editorial;

use App\Models\SearchMiss;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Gate;

/**
 * Le ricerche senza risultati per l'app della redazione (R-ST-06), come la
 * tabella di `SearchMissResource`: ricerca sulla parola cercata, filtro
 * «Città», ordine per «Quante volte» (predefinito, dal più cercato), «Ultima
 * volta» o «Prima volta», eliminazione di una riga o di un gruppo.
 *
 * Chi può lo dice `SearchMissPolicy` (`events.moderate`: moderatore, admin,
 * super admin). Le date si scrivono come le colonne del pannello, nel fuso
 * di Roma che la tabella usa per tutte le città.
 */
final class EditorialSearchMisses
{
    /** Le colonne che si ordinano, come quelle `sortable()` della tabella. */
    public const SORTS = ['count', 'last_searched_at', 'first_searched_at'];

    /** Quante righe al massimo in una sola eliminazione di gruppo. */
    public const BULK_MAX = 200;

    /** Il fuso delle colonne «Ultima volta» e «Prima volta» del pannello. */
    private const TIMEZONE = 'Europe/Rome';

    /**
     * @param  array<string, string>  $filters  `city` (id)
     * @return LengthAwarePaginator<int, SearchMiss>
     */
    public function paginate(string $search, array $filters, string $sort, int $perPage, int $page): LengthAwarePaginator
    {
        [$column, $direction] = self::sort($sort);

        return SearchMiss::query()
            ->with('city:id,name')
            ->when($search !== '', fn (Builder $query) => $query->where('term', 'like', '%'.addcslashes($search, '%_\\').'%'))
            ->when(isset($filters['city']) && ctype_digit($filters['city']), fn (Builder $query) => $query->where('city_id', (int) $filters['city']))
            ->orderBy($column, $direction)
            ->orderBy('id', $direction)
            ->paginate($perPage, ['*'], 'page', $page);
    }

    /**
     * La colonna e il verso: `count`, `-count`, `last_searched_at`, …; il
     * predefinito della tabella è «Quante volte» dal più alto.
     *
     * @return array{0: string, 1: 'asc'|'desc'}
     */
    public static function sort(string $sort): array
    {
        $column = ltrim($sort, '-');

        return in_array($column, self::SORTS, true) ? [$column, str_starts_with($sort, '-') ? 'desc' : 'asc'] : ['count', 'desc'];
    }

    /** @return array<string, mixed> */
    public function row(SearchMiss $miss, User $user): array
    {
        return [
            'id' => (int) $miss->id,
            'term' => (string) $miss->term,
            'count' => (int) $miss->count,
            'last_searched_at' => $miss->last_searched_at->toIso8601String(),
            'last_searched_label' => $miss->last_searched_at->timezone(self::TIMEZONE)->format('d/m/Y H:i'),
            'first_searched_at' => $miss->first_searched_at->toIso8601String(),
            'first_searched_label' => $miss->first_searched_at->timezone(self::TIMEZONE)->format('d/m/Y'),
            'city' => $miss->city === null ? null : ['id' => (int) $miss->city->id, 'name' => (string) $miss->city->name],
            'abilities' => ['remove' => Gate::forUser($user)->allows('delete', $miss)],
        ];
    }

    /** Toglie una riga, con `SearchMissPolicy::delete` come l'azione «Elimina» della riga. */
    public function remove(SearchMiss $miss, User $user): void
    {
        Gate::forUser($user)->authorize('delete', $miss);
        $miss->delete();
    }

    /**
     * Toglie un gruppo di righe, come «Elimina selezionati»: `deleteAny` per
     * il gruppo, `delete` per ciascuna. Gli id che non ci sono più si
     * ignorano (qualcun altro le ha già tolte). Restituisce quante ne ha
     * tolte.
     *
     * @param  list<int>  $ids
     */
    public function removeMany(array $ids, User $user): int
    {
        Gate::forUser($user)->authorize('deleteAny', SearchMiss::class);
        $removed = 0;
        foreach (SearchMiss::query()->whereKey($ids)->get() as $miss) {
            Gate::forUser($user)->authorize('delete', $miss);
            $miss->delete();
            $removed++;
        }

        return $removed;
    }
}

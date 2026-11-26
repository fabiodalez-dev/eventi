<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Editorial\Concerns;

use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * I parametri degli elenchi della redazione per locali, richieste e
 * organizzatori, secondo la convenzione delle fondamenta: `page`,
 * `per_page` (al massimo 50), `q`, `tab`, `filter[chiave]`, `sort`.
 */
trait ListsPlaces
{
    private function listPage(Request $request): int
    {
        return max(1, $request->integer('page', 1));
    }

    private function listPerPage(Request $request): int
    {
        $default = config()->integer('api.limits.default', 24);
        $max = config()->integer('api.limits.max', 50);

        return min($max, max(1, $request->integer('per_page', $default)));
    }

    private function listSearch(Request $request): string
    {
        $q = $request->query('q');

        return is_string($q) ? mb_substr(trim($q), 0, 120) : '';
    }

    /** @param  list<string>  $allowed */
    private function listTab(Request $request, array $allowed, string $default): string
    {
        $tab = $request->query('tab');

        return is_string($tab) && in_array($tab, $allowed, true) ? $tab : $default;
    }

    /**
     * @param  list<string>  $allowed
     * @return array<string, string>
     */
    private function listFilters(Request $request, array $allowed): array
    {
        $filters = $request->query('filter');
        if (! is_array($filters)) {
            return [];
        }

        $clean = [];
        foreach ($allowed as $key) {
            $value = $filters[$key] ?? null;
            if (is_scalar($value) && (string) $value !== '') {
                $clean[$key] = (string) $value;
            }
        }

        return $clean;
    }

    private function listSort(Request $request): string
    {
        $sort = $request->query('sort');

        return is_string($sort) ? $sort : '';
    }

    /**
     * La risposta di un elenco: le righe e la `meta` del paginatore, con i
     * conteggi delle schede quando ci sono.
     *
     * @param  LengthAwarePaginator<int, mixed>  $page
     * @param  list<array<string, mixed>>  $rows
     * @param  array<string, int>|null  $tabs
     * @return array<string, mixed>
     */
    private function listPayload(LengthAwarePaginator $page, array $rows, ?array $tabs = null): array
    {
        $meta = [
            'current_page' => $page->currentPage(),
            'last_page' => $page->lastPage(),
            'per_page' => $page->perPage(),
            'total' => $page->total(),
        ];
        if ($tabs !== null) {
            $meta['tabs'] = $tabs;
        }

        return ['data' => $rows, 'meta' => $meta];
    }
}

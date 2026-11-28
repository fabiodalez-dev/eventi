<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Editorial;

use App\Enums\ApiErrorCode;
use App\Exceptions\ApiException;
use App\Http\Controllers\Api\V1\Editorial\Concerns\ListsPlaces;
use App\Http\Controllers\Controller;
use App\Models\City;
use App\Models\SearchMiss;
use App\Models\User;
use App\Services\Editorial\EditorialSearchMisses;
use App\Support\Editorial\EditorialDesk;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Le ricerche senza risultati (R-ST-06), come `SearchMissResource`: elenco
 * con ricerca, filtro «Città» e ordinamento, «Elimina» su una riga e su un
 * gruppo. Moderatore, admin e super admin (`SearchMissPolicy`).
 *
 * Togliere una riga non è un'azione pericolosa (decisione 4): sparisce dal
 * registro finché qualcuno non cerca di nuovo la stessa cosa. Per questo le
 * rotte si chiamano `remove` e `bulk-remove`, non `destroy`, e non chiedono
 * la password recente. Il pannello non scrive nel registro e nemmeno l'app.
 */
final class SearchMissController extends Controller
{
    use ListsPlaces;

    public function __construct(private readonly EditorialSearchMisses $misses) {}

    public function index(Request $request): JsonResponse
    {
        $user = $this->user($request);
        Gate::forUser($user)->authorize('viewAny', SearchMiss::class);
        $page = $this->misses->paginate($this->listSearch($request), $this->listFilters($request, ['city']), $this->listSort($request), $this->listPerPage($request), $this->listPage($request));

        return response()->json([
            ...$this->listPayload($page, array_values(array_map(fn (SearchMiss $miss): array => $this->misses->row($miss, $user), $page->items()))),
            // Le città del filtro, come le opzioni della relazione nel pannello.
            'choices' => ['cities' => City::query()->orderBy('name')->get(['id', 'name'])
                ->map(fn (City $city): array => ['value' => (string) $city->id, 'label' => (string) $city->name])->values()->all()],
        ]);
    }

    public function remove(Request $request, int $miss): JsonResponse
    {
        $user = $this->user($request);
        $record = SearchMiss::query()->find($miss);
        if (! $record instanceof SearchMiss) {
            throw new ApiException(ApiErrorCode::NotFound);
        }
        $this->misses->remove($record, $user);

        return response()->json(['data' => ['id' => $miss, 'removed' => true], 'meta' => ['message' => __('filament-actions::delete.single.notifications.deleted.title')]]);
    }

    /** `ids`: da 1 a 200 id; quelli già tolti si ignorano. */
    public function bulkRemove(Request $request): JsonResponse
    {
        $user = $this->user($request);
        $data = $request->validate([
            'ids' => ['required', 'array', 'min:1', 'max:'.EditorialSearchMisses::BULK_MAX],
            'ids.*' => ['integer', 'min:1', 'distinct'],
        ]);
        $removed = $this->misses->removeMany(array_values(array_map('intval', $data['ids'])), $user);

        return response()->json(['data' => ['removed' => $removed], 'meta' => ['message' => __('filament-actions::delete.multiple.notifications.deleted.title')]]);
    }

    private function user(Request $request): User
    {
        EditorialDesk::require();
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        return $user;
    }
}

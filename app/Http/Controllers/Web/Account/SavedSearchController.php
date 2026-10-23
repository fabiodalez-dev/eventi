<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web\Account;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Web\Concerns\InteractsWithCity;
use App\Http\Requests\Web\Account\StoreSavedSearchRequest;
use App\Models\SavedSearch;
use App\Models\User;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Le ricerche salvate di una persona: si creano dalla pagina degli eventi o
 * dalla ricerca, si rimuovono dalla pagina degli interessi. Lo stesso
 * controller risponde al sito e all'API (`/v1/me/searches`).
 */
final class SavedSearchController extends Controller
{
    use InteractsWithCity;

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        return ApiResponse::collection($user->savedSearches()->where('city_id', $this->city()->getKey())->orderByDesc('id')->get()
            ->map(fn (SavedSearch $search): array => self::toArray($search))->all());
    }

    public function store(StoreSavedSearchRequest $request): RedirectResponse|JsonResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);
        $filters = $request->filters();
        $city = $this->city();

        if ($filters === []) {
            throw ValidationException::withMessages(['filters' => __('search.saved.empty_filters')]);
        }
        $existing = $user->savedSearches()->where('city_id', $city->getKey())->get()
            ->first(fn (SavedSearch $search): bool => $search->queryString() === $filters);
        if ($existing instanceof SavedSearch) {
            return $request->is('api/*')
                ? ApiResponse::item(self::toArray($existing))
                : back()->with('status', __('search.saved.exists'));
        }
        if ($user->savedSearches()->count() >= SavedSearch::MAX_PER_USER) {
            throw ValidationException::withMessages(['filters' => __('search.saved.limit', ['max' => SavedSearch::MAX_PER_USER])]);
        }

        $search = $user->savedSearches()->create([
            'city_id' => $city->getKey(),
            'name' => $request->name(),
            'filters' => $filters,
            /* Da adesso in poi: ciò che è già pubblicato lo sta guardando. */
            'last_checked_at' => now(),
        ]);

        return $request->is('api/*')
            ? ApiResponse::item(self::toArray($search), status: 201)
            : back()->with('status', __('search.saved.stored'));
    }

    public function destroy(Request $request, SavedSearch $search): RedirectResponse|JsonResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User && (int) $search->user_id === (int) $user->getKey(), 403);
        $search->delete();

        return $request->is('api/*')
            ? response()->json(null, 204)
            : back()->with('status', __('search.saved.removed'));
    }

    /** @return array<string, mixed> */
    public static function toArray(SavedSearch $search): array
    {
        return [
            'id' => $search->getKey(),
            'name' => $search->name,
            'filters' => $search->queryString(),
            'url' => route('events.index', $search->queryString()),
            'last_checked_at' => $search->last_checked_at?->toIso8601String(),
            'created_at' => $search->created_at?->toIso8601String(),
        ];
    }
}

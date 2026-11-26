<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Editorial;

use App\Http\Controllers\Api\V1\Editorial\Concerns\ListsPlaces;
use App\Http\Controllers\Controller;
use App\Models\ConsentScript;
use App\Services\Editorial\EditorialSettings;
use App\Support\Editorial\EditorialDesk;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Gli script di consenso (R-SY-11), in sola lettura: `ConsentScriptPolicy`
 * (admin e super admin). Uno script è JavaScript eseguito nel browser di
 * ogni visitatore: crearlo, modificarlo, accenderlo ed eliminarlo si fa solo
 * dal sito (decisione 5 del piano della redazione).
 */
final class ConsentScriptController extends Controller
{
    use ListsPlaces;

    public function index(Request $request, EditorialSettings $settings): JsonResponse
    {
        Gate::forUser(EditorialDesk::require()->user)->authorize('viewAny', ConsentScript::class);
        $q = $this->listSearch($request);

        $page = ConsentScript::query()
            ->when($q !== '', fn (Builder $query) => $query->where('name', 'like', '%'.addcslashes($q, '%_\\').'%'))
            ->orderBy('sort_order')->orderBy('id')
            ->paginate($this->listPerPage($request), page: $this->listPage($request));

        $payload = $this->listPayload($page, $page->getCollection()->map(fn (ConsentScript $script): array => $settings->scriptRow($script))->values()->all());
        $payload['meta']['categories'] = $settings->categories();

        return response()->json($payload);
    }

    /** Uno script con il suo codice, da leggere. */
    public function show(int $script, EditorialSettings $settings): JsonResponse
    {
        $found = ConsentScript::query()->findOrFail($script);
        Gate::forUser(EditorialDesk::require()->user)->authorize('view', $found);

        return response()->json(['data' => $settings->scriptRow($found, withCode: true)]);
    }
}

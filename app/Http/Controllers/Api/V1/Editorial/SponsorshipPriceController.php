<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Editorial;

use App\Enums\ApiErrorCode;
use App\Exceptions\ApiException;
use App\Http\Controllers\Api\V1\Editorial\Concerns\ListsPlaces;
use App\Http\Controllers\Controller;
use App\Models\SponsorshipPrice;
use App\Models\User;
use App\Services\Editorial\Promotion\EditorialSponsorshipPrices;
use App\Support\Editorial\EditorialDesk;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Il listino (R-SP-13): elenco con il filtro «attiva», nuova voce, modifica,
 * eliminazione (con la password recente). Solo admin e super admin
 * (`SponsorshipPricePolicy`).
 */
final class SponsorshipPriceController extends Controller
{
    use ListsPlaces;

    public function __construct(private readonly EditorialSponsorshipPrices $prices) {}

    /** `filter[is_active]` (`1`/`0`), `sort` (`placement`, `months`, `amount_cents`). */
    public function index(Request $request): JsonResponse
    {
        $user = $this->user($request);
        Gate::forUser($user)->authorize('viewAny', SponsorshipPrice::class);
        $page = $this->prices->paginate($this->listFilters($request, ['is_active']), $this->listSort($request), $this->listPerPage($request), $this->listPage($request));

        return response()->json([
            ...$this->listPayload($page, array_values(array_map(fn (SponsorshipPrice $price): array => $this->prices->row($price, $user), $page->items()))),
            'choices' => EditorialSponsorshipPrices::choices(),
            'can_create' => Gate::forUser($user)->allows('create', SponsorshipPrice::class),
        ]);
    }

    /** `placement`, `months`, `amount` (euro), `is_active`. */
    public function store(Request $request): JsonResponse
    {
        $user = $this->user($request);
        Gate::forUser($user)->authorize('create', SponsorshipPrice::class);

        return response()->json(['data' => $this->prices->row($this->prices->create($request->all()), $user)], 201);
    }

    public function update(Request $request, int $price): JsonResponse
    {
        $user = $this->user($request);

        return response()->json(['data' => $this->prices->row($this->prices->update($this->price($user, $price, 'update'), $request->all()), $user)]);
    }

    /** Elimina la voce del listino: azione pericolosa, password recente. */
    public function destroy(Request $request, int $price): JsonResponse
    {
        $this->price($this->user($request), $price, 'delete')->delete();

        return response()->json(['data' => ['deleted' => $price]]);
    }

    private function user(Request $request): User
    {
        EditorialDesk::require();
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        return $user;
    }

    private function price(User $user, int $id, string $ability): SponsorshipPrice
    {
        $price = SponsorshipPrice::query()->find($id);
        if (! $price instanceof SponsorshipPrice) {
            throw new ApiException(ApiErrorCode::NotFound);
        }
        Gate::forUser($user)->authorize($ability, $price);

        return $price;
    }
}

<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Editorial;

use App\Enums\ApiErrorCode;
use App\Exceptions\ApiException;
use App\Http\Controllers\Api\V1\Editorial\Concerns\ListsPlaces;
use App\Http\Controllers\Controller;
use App\Models\SponsorshipGrant;
use App\Models\User;
use App\Services\Editorial\Promotion\EditorialSponsorshipGrants;
use App\Support\Editorial\EditorialDesk;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Le abilitazioni («Abilitazioni e pagamenti», R-SP-11…12): elenco con
 * filtri per locale e organizzatore, scheda con l'avanzamento, nuova e
 * modifica. Nessuna eliminazione, come nel pannello. Solo admin e super
 * admin (`SponsorshipGrantPolicy`).
 */
final class SponsorshipGrantController extends Controller
{
    use ListsPlaces;

    public function __construct(private readonly EditorialSponsorshipGrants $grants) {}

    /** `q` (nome del committente), `filter[venue]`, `filter[organizer]` (id). */
    public function index(Request $request): JsonResponse
    {
        $user = $this->user($request);
        Gate::forUser($user)->authorize('viewAny', SponsorshipGrant::class);
        $page = $this->grants->paginate($this->listSearch($request), $this->listFilters($request, EditorialSponsorshipGrants::FILTERS),
            $this->listPerPage($request), $this->listPage($request));

        return response()->json([
            ...$this->listPayload($page, array_values(array_map(fn (SponsorshipGrant $grant): array => $this->grants->row($grant, $user), $page->items()))),
            'choices' => EditorialSponsorshipGrants::choices(),
            'can_create' => Gate::forUser($user)->allows('create', SponsorshipGrant::class),
        ]);
    }

    public function show(Request $request, int $grant): JsonResponse
    {
        $user = $this->user($request);

        return response()->json(['data' => $this->grants->detail($this->grant($user, $grant, 'view'), $user)]);
    }

    /** Una nuova abilitazione: committente (locale o organizzatore), modalità, collocazione, consegna, periodo, pagamento. */
    public function store(Request $request): JsonResponse
    {
        $user = $this->user($request);
        Gate::forUser($user)->authorize('create', SponsorshipGrant::class);
        $grant = $this->grants->create($request->all(), $user);

        return response()->json(['data' => $this->grants->detail($grant, $user)], 201);
    }

    /** La modifica: committente, modalità e collocazione restano quelli di prima. */
    public function update(Request $request, int $grant): JsonResponse
    {
        $user = $this->user($request);
        $saved = $this->grants->update($this->grant($user, $grant, 'update'), $request->all());

        return response()->json(['data' => $this->grants->detail($saved, $user)]);
    }

    private function user(Request $request): User
    {
        EditorialDesk::require();
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        return $user;
    }

    private function grant(User $user, int $id, string $ability): SponsorshipGrant
    {
        $grant = SponsorshipGrant::query()->find($id);
        if (! $grant instanceof SponsorshipGrant) {
            throw new ApiException(ApiErrorCode::NotFound);
        }
        Gate::forUser($user)->authorize($ability, $grant);

        return $grant;
    }
}

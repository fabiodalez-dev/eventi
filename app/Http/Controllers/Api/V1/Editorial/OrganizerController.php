<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Editorial;

use App\Enums\ApiErrorCode;
use App\Enums\ContactMode;
use App\Exceptions\ApiException;
use App\Http\Controllers\Api\V1\Editorial\Concerns\ListsPlaces;
use App\Http\Controllers\Controller;
use App\Models\Organizer;
use App\Models\User;
use App\Services\Editorial\EditorialOrganizers;
use App\Support\Editorial\EditorialDesk;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Gli organizzatori (R-OR-01…02): elenco, nuovo e modifica, solo admin e
 * super admin (`OrganizerPolicy`). Nessuna eliminazione, come nel pannello.
 */
final class OrganizerController extends Controller
{
    use ListsPlaces;

    public function __construct(private readonly EditorialOrganizers $organizers) {}

    /** L'elenco per nome, con ricerca. */
    public function index(Request $request): JsonResponse
    {
        $user = $this->user($request);
        Gate::forUser($user)->authorize('viewAny', Organizer::class);
        $page = $this->organizers->paginate($this->listSearch($request), $this->listPerPage($request), $this->listPage($request));

        return response()->json([
            ...$this->listPayload($page, array_values(array_map(fn (Organizer $organizer): array => $this->organizers->row($organizer, $user), $page->items()))),
            'choices' => self::choices(),
        ]);
    }

    /** Il modulo di un organizzatore, con le scelte per compilarlo. */
    public function show(Request $request, int $organizer): JsonResponse
    {
        $user = $this->user($request);
        $record = $this->organizer($user, $organizer, 'view');

        return response()->json(['data' => [...$this->organizers->detail($record, $user), 'choices' => self::choices()]]);
    }

    /** Le persone fra cui scegliere responsabile e collaboratori (`q` su email o nome, al massimo 50). */
    public function people(Request $request): JsonResponse
    {
        $user = $this->user($request);
        Gate::forUser($user)->authorize('viewAny', Organizer::class);
        $q = $request->query('q');

        return response()->json(['data' => $this->organizers->people(is_string($q) ? mb_substr($q, 0, 120) : '')
            ->map(fn (User $person): array => ['id' => (int) $person->getKey(), 'name' => (string) $person->name, 'email' => (string) $person->email])->values()->all()]);
    }

    /** Un organizzatore nuovo: `name`, `city_id`, `owner_id` obbligatori; il resto facoltativo. */
    public function store(Request $request): JsonResponse
    {
        $user = $this->user($request);
        Gate::forUser($user)->authorize('create', Organizer::class);
        $organizer = $this->organizers->create($request->all());

        return response()->json(['data' => [...$this->organizers->detail($organizer, $user), 'choices' => self::choices()]], 201);
    }

    /** Salva le chiavi inviate. */
    public function update(Request $request, int $organizer): JsonResponse
    {
        $user = $this->user($request);
        $record = $this->organizer($user, $organizer, 'update');
        $saved = $this->organizers->update($record, $request->all());

        return response()->json(['data' => [...$this->organizers->detail($saved, $user), 'choices' => self::choices()]]);
    }

    /** @return array<string, list<array{value: string, label: string}>> */
    private static function choices(): array
    {
        return [
            'cities' => EditorialOrganizers::cityChoices(),
            'contact_modes' => array_map(static fn (string $value, string $label): array => ['value' => $value, 'label' => $label], array_keys(ContactMode::options()), ContactMode::options()),
        ];
    }

    private function user(Request $request): User
    {
        EditorialDesk::require();
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        return $user;
    }

    private function organizer(User $user, int $id, string $ability): Organizer
    {
        $organizer = Organizer::query()->find($id);
        if (! $organizer instanceof Organizer) {
            throw new ApiException(ApiErrorCode::NotFound);
        }
        Gate::forUser($user)->authorize($ability, $organizer);

        return $organizer;
    }
}

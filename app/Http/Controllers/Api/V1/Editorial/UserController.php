<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Editorial;

use App\Actions\Users\SyncUserRoles;
use App\Actions\Users\UpdateUserByStaff;
use App\Enums\RegistrationState;
use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Editorial\Users\StoreUserRequest;
use App\Http\Requests\Api\V1\Editorial\Users\UpdateUserRequest;
use App\Http\Requests\Api\V1\Editorial\Users\UserCredentialsRequest;
use App\Http\Requests\Api\V1\Editorial\Users\UserFormRules;
use App\Http\Requests\Api\V1\Editorial\Users\UserRolesRequest;
use App\Http\Requests\Api\V1\Editorial\Users\UsersQuery;
use App\Models\City;
use App\Models\User;
use App\Models\Venue;
use App\Services\Users\StaffUserPayload;
use App\Services\Users\UserDirectory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Hash;

/**
 * Gli account nella redazione dell'app (R5: R-US-01, 02, 04…06, 12), come la
 * risorsa «Utenti» del pannello: elenco con schede, filtri e ricerca,
 * scheda, creazione, modifica, ruoli, eliminazione e ripristino. Le regole
 * sono quelle del pannello (`UserPolicy`, `SyncUserRoles`,
 * `UpdateUserByStaff`), con i limiti della decisione 4: dall'app i ruoli
 * arrivano fino a moderatore, email e password solo le proprie,
 * l'eliminazione definitiva e l'esportazione restano sul sito.
 */
final class UserController extends Controller
{
    public function index(UsersQuery $request, UserDirectory $directory, StaffUserPayload $payload): JsonResponse
    {
        $staff = $this->staff($request);
        $tab = $request->validated('tab');
        $q = $request->validated('q');
        $sort = $request->validated('sort');
        $page = $directory->page(
            is_string($tab) ? $tab : null,
            $request->filters(),
            is_string($q) ? trim($q) : null,
            is_string($sort) ? $sort : null,
            $request->perPage(),
            (int) ($request->validated('page') ?? 1),
        );

        return response()->json([
            'data' => $page->getCollection()->map(fn (User $user): array => $payload->row($staff, $user))->values(),
            'meta' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
                'tabs' => $directory->tabCounts(),
            ],
        ]);
    }

    /**
     * Le scelte dei filtri e del modulo: stati dell'iscrizione, ruoli, città,
     * locali, fusi, lingue, e i ruoli che chi chiede può dare dall'app.
     */
    public function options(Request $request): JsonResponse
    {
        $staff = $this->staff($request);
        Gate::forUser($staff)->authorize('viewAny', User::class);

        $choices = static fn (iterable $rows): array => collect($rows)->map(static fn (mixed $label, mixed $value): array => ['value' => (string) $value, 'label' => (string) $label])->values()->all();

        return response()->json(['data' => [
            'registration_states' => $choices(RegistrationState::options()),
            'roles' => $choices(UserRole::options()),
            'assignable_roles' => SyncUserRoles::assignable($staff, inApp: true),
            'cities' => $choices(City::query()->orderBy('name')->pluck('name', 'id')),
            'venues' => $choices(Venue::query()->orderBy('name')->pluck('name', 'id')),
            'timezones' => timezone_identifiers_list(),
            'locales' => UserFormRules::LOCALES,
            'default_timezone' => 'Europe/Rome',
            'default_locale' => 'it',
        ]]);
    }

    public function show(Request $request, User $user, StaffUserPayload $payload): JsonResponse
    {
        $staff = $this->staff($request);
        Gate::forUser($staff)->authorize('view', $user);

        return $this->detail($staff, $user, $payload);
    }

    /** «Nuovo utente»: i campi del modulo e i ruoli, come `CreateUser` (i ruoli passano da `SyncUserRoles`). */
    public function store(StoreUserRequest $request, SyncUserRoles $roles, StaffUserPayload $payload): JsonResponse
    {
        $staff = $this->staff($request);
        /** @var array<string, mixed> $data */
        $data = $request->safe()->except('roles');
        $user = DB::transaction(function () use ($data, $staff, $request, $roles): User {
            $user = new User([...$data, 'password' => Hash::make((string) $data['password'])]);
            $user->save();
            $requested = $request->validated('roles');
            $roles->syncNames($staff, $user, is_array($requested) ? array_values(array_map('strval', $requested)) : [], inApp: true);

            return $user;
        });

        return $this->detail($staff, $user, $payload, __('users.app.created'), 201);
    }

    /** «Modifica utente»: città, nome, fuso e lingua. */
    public function update(UpdateUserRequest $request, User $user, UpdateUserByStaff $action, StaffUserPayload $payload): JsonResponse
    {
        $staff = $this->staff($request);
        /** @var array<string, mixed> $data */
        $data = $request->safe()->only(['city_id', 'name', 'timezone', 'locale']);
        $action->update($staff, $user, $data);

        return $this->detail($staff, $user, $payload, __('users.app.updated'));
    }

    /**
     * Le proprie email e password: cadono tutti i token dell'app, compreso
     * quello di questa richiesta, e i dispositivi; si rientra con le
     * credenziali nuove (`meta.signed_out`).
     */
    public function credentials(UserCredentialsRequest $request, User $user, UpdateUserByStaff $action): JsonResponse
    {
        $staff = $this->staff($request);
        $password = $request->validated('password');
        $changes = $action->update($staff, $user, array_filter([
            'email' => (string) $request->validated('email'),
            'password' => is_string($password) && $password !== '' ? Hash::make($password) : null,
        ], static fn (mixed $value): bool => $value !== null));

        return response()->json([
            'data' => ['changes' => $changes],
            'meta' => ['signed_out' => $changes !== [], 'message' => $changes !== [] ? __('users.app.credentials_updated') : __('users.app.updated')],
        ]);
    }

    /** I ruoli di piattaforma: quelli permessi dall'app, gli altri restano (`SyncUserRoles`). */
    public function roles(UserRolesRequest $request, User $user, SyncUserRoles $roles, StaffUserPayload $payload): JsonResponse
    {
        $staff = $this->staff($request);
        $roles->syncNames($staff, $user, $request->roles(), inApp: true);

        return $this->detail($staff, $user, $payload, __('users.app.roles_updated'));
    }

    /** Elimina (nel cestino, si ripristina): `UserPolicy::delete`, mai se stessi né un super admin se non lo si è. */
    public function destroy(Request $request, User $user, StaffUserPayload $payload): JsonResponse
    {
        $staff = $this->staff($request);
        Gate::forUser($staff)->authorize('delete', $user);
        abort_if($user->trashed(), 404);
        $user->delete();

        return $this->detail($staff, $user, $payload, __('users.app.deleted'));
    }

    public function restore(Request $request, User $user, StaffUserPayload $payload): JsonResponse
    {
        $staff = $this->staff($request);
        Gate::forUser($staff)->authorize('restore', $user);
        abort_unless($user->trashed(), 404);
        $user->restore();

        return $this->detail($staff, $user, $payload, __('users.app.restored'));
    }

    private function detail(User $staff, User $user, StaffUserPayload $payload, ?string $message = null, int $status = 200): JsonResponse
    {
        $fresh = User::withTrashed()->with(UserDirectory::ROW_RELATIONS)->findOrFail($user->id);
        $body = ['data' => $payload->detail($staff, $fresh)];
        if ($message !== null) {
            $body['meta'] = ['message' => $message];
        }

        return response()->json($body, $status);
    }

    private function staff(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        return $user;
    }
}

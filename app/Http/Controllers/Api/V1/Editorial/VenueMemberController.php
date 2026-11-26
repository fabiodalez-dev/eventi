<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Editorial;

use App\Enums\ApiErrorCode;
use App\Enums\VenueRole;
use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\Venue;
use App\Services\Editorial\EditorialVenueMembers;
use App\Support\Api\ApiDate;
use App\Support\Editorial\EditorialDesk;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Le persone di un locale dalla redazione (R-VN-12): referenti,
 * collaboratori e inviti in sospeso; invita per email, aggiungi un account
 * con l'email confermata, cambia ruolo o accettazione, scollega. Le regole
 * sono quelle della sezione «Persone» del pannello (`EditorialVenueMembers`),
 * e chiedono `VenuePolicy::manageCollaborators` (admin e super admin).
 */
final class VenueMemberController extends Controller
{
    public function __construct(private readonly EditorialVenueMembers $members) {}

    /** Le persone del locale, con ruolo, data dell'invito e dell'accettazione. */
    public function index(Request $request, int $venue): JsonResponse
    {
        $record = $this->venue($request, $venue);
        $timezone = $this->timezone($record);

        return response()->json(['data' => [
            'members' => $this->members->list($record)->map(fn (User $user): array => $this->row($user, $timezone))->values()->all(),
            'roles' => array_map(static fn (VenueRole $role): array => ['value' => $role->value, 'label' => $role->label()], VenueRole::cases()),
        ]]);
    }

    /** Gli account che si possono aggiungere: email confermata, non ancora del locale (`q` cerca nome o email). */
    public function candidates(Request $request, int $venue): JsonResponse
    {
        $record = $this->venue($request, $venue);
        $q = $request->query('q');

        return response()->json(['data' => $this->members->candidates($record, is_string($q) ? mb_substr($q, 0, 120) : '')
            ->map(fn (User $user): array => ['id' => (int) $user->getKey(), 'name' => (string) $user->name, 'email' => (string) $user->email])->values()->all()]);
    }

    /**
     * «Invita per email»: `name`, `email`, `role` (predefinito referente, come
     * nel pannello). Chi ha già quel ruolo non riceve un secondo messaggio:
     * 409 con la spiegazione.
     */
    public function invite(Request $request, int $venue): JsonResponse
    {
        $record = $this->venue($request, $venue);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255'],
            'role' => ['sometimes', 'required', Rule::enum(VenueRole::class)],
        ], [], ['name' => __('admin.fields.name'), 'email' => __('admin.fields.email'), 'role' => __('admin.fields.role')]);
        $role = VenueRole::from((string) ($data['role'] ?? VenueRole::Owner->value));

        $already = $this->members->alreadyThere($record, (string) $data['email'], $role);
        if ($already !== null) {
            throw new ApiException(ApiErrorCode::Conflict, $already);
        }
        $this->members->invite($record, (string) $data['email'], (string) $data['name'], $role);

        return $this->listResponse($record, (string) __('admin.notifications.invitation_sent'), 201);
    }

    /**
     * «Aggiungi un account»: `user_id` (email confermata, non ancora del
     * locale) e `role` (predefinito collaboratore). Entra subito.
     */
    public function attach(Request $request, int $venue): JsonResponse
    {
        $record = $this->venue($request, $venue);
        $data = $request->validate([
            'user_id' => ['required', 'integer'],
            'role' => ['sometimes', 'required', Rule::enum(VenueRole::class)],
        ]);
        $user = User::query()->find((int) $data['user_id']);
        if (! $user instanceof User || ! $this->members->canAttach($record, $user)) {
            throw ValidationException::withMessages(['user_id' => __('validation.exists', ['attribute' => __('admin.fields.name')])]);
        }
        $this->members->attach($record, $user, VenueRole::from((string) ($data['role'] ?? VenueRole::Editor->value)), $this->actor($request));

        return $this->listResponse($record, null, 201);
    }

    /** «Modifica»: `role` e `accepted_at` (ISO 8601, vuoto = invito ancora da accettare). */
    public function update(Request $request, int $venue, int $user): JsonResponse
    {
        $record = $this->venue($request, $venue);
        $member = $this->member($record, $user);
        $data = $request->validate([
            'role' => ['required', Rule::enum(VenueRole::class)],
            'accepted_at' => ['present', 'nullable', 'date'],
        ]);
        $accepted = filled($data['accepted_at']) ? CarbonImmutable::parse((string) $data['accepted_at'])->utc()->startOfMinute() : null;
        $this->members->update($record, $member, VenueRole::from((string) $data['role']), $accepted, $this->actor($request));

        return $this->listResponse($record, null);
    }

    /** «Scollega»: toglie la persona dal locale (anche un invito in sospeso). */
    public function remove(Request $request, int $venue, int $user): JsonResponse
    {
        $record = $this->venue($request, $venue);
        $this->members->detach($record, $this->member($record, $user), $this->actor($request));

        return $this->listResponse($record, null);
    }

    private function listResponse(Venue $venue, ?string $message, int $status = 200): JsonResponse
    {
        $timezone = $this->timezone($venue);

        return response()->json(['data' => [
            'message' => $message,
            'members' => $this->members->list($venue)->map(fn (User $user): array => $this->row($user, $timezone))->values()->all(),
        ]], $status);
    }

    /** @return array<string, mixed> */
    private function row(User $user, string $timezone): array
    {
        $pivot = $user->getRelationValue('pivot');
        $role = VenueRole::tryFrom((string) $pivot?->getAttribute('role'));
        $invited = $pivot?->getAttribute('invited_at');
        $accepted = $pivot?->getAttribute('accepted_at');

        return [
            'id' => (int) $user->getKey(),
            'name' => (string) $user->name,
            'email' => (string) $user->email,
            'role' => $role === null ? null : ['value' => $role->value, 'label' => $role->label()],
            'invited_at' => $invited === null ? null : ApiDate::instant(CarbonImmutable::parse((string) $invited, 'UTC'), $timezone),
            'accepted_at' => $accepted === null ? null : ApiDate::instant(CarbonImmutable::parse((string) $accepted, 'UTC'), $timezone),
            'pending' => $accepted === null,
        ];
    }

    private function venue(Request $request, int $id): Venue
    {
        EditorialDesk::require();
        $user = $request->user();
        abort_unless($user instanceof User, 401);
        $venue = Venue::query()->with('city')->find($id);
        if (! $venue instanceof Venue) {
            throw new ApiException(ApiErrorCode::NotFound);
        }
        Gate::forUser($user)->authorize('manageCollaborators', $venue);

        return $venue;
    }

    private function member(Venue $venue, int $id): User
    {
        $member = $this->members->find($venue, $id);
        if (! $member instanceof User) {
            throw new ApiException(ApiErrorCode::NotFound);
        }

        return $member;
    }

    private function actor(Request $request): ?User
    {
        $user = $request->user();

        return $user instanceof User ? $user : null;
    }

    private function timezone(Venue $venue): string
    {
        return $venue->city->timezone ?? config()->string('app.timezone');
    }
}

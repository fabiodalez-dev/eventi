<?php

declare(strict_types=1);

namespace App\Services\Users;

use App\Actions\Users\SyncUserRoles;
use App\Enums\Permission;
use App\Enums\RegistrationState;
use App\Enums\UserRole;
use App\Enums\VenueRole;
use App\Enums\WhatsappChallengeStatus;
use App\Models\User;
use App\Models\Venue;
use App\Models\WhatsappChallenge;
use App\Services\Carpool\CommunitySafety;
use App\Services\Community\CommunityModeration;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;

/**
 * Le righe e la scheda degli account per l'app della redazione (R-US-01,
 * R-US-05…12), con le colonne dell'elenco del pannello e, per ogni riga,
 * le `abilities` calcolate come la visibilità delle azioni del pannello.
 *
 * Il chiamante carica `UserDirectory::ROW_RELATIONS` prima: qui non parte
 * nessuna query per riga.
 */
final class StaffUserPayload
{
    public function __construct(private readonly CommunitySafety $safety) {}

    /**
     * Le abilità di `$staff` su `$user`, una per azione del pannello:
     *
     * - `update`, `roles`: «Modifica» (`UserPolicy::update`; il campo dei
     *   ruoli sta nel modulo);
     * - `credentials`: email e password, dall'app solo le proprie (decisione 4);
     * - `destroy`, `restore`: `UserPolicy::delete` e `restore`;
     * - `suspend`, `unsuspend`: «Sospendi»/«Riattiva» della community
     *   (sospendere solo chi sta sotto, `CommunityModeration::outranks`);
     * - `handle`: «Cambia nome utente» (`community.manage` e rango più alto);
     * - `whatsapp_revoke`: admin e super admin, se c'è una verifica;
     * - `whatsapp_history`: tutta la redazione.
     *
     * @return array<string, bool>
     */
    public function abilities(User $staff, User $user): array
    {
        $gate = Gate::forUser($staff);
        $trashed = $user->trashed();
        $community = $this->safety->staff($staff, Permission::ManageCommunity);
        $outranks = CommunityModeration::outranks($staff, $user);
        $suspended = $user->community_suspended_at !== null;
        $update = $gate->allows('update', $user);

        return [
            'update' => $update,
            'roles' => $update && $staff->can(Permission::ManageUsers->value),
            'credentials' => $update && $staff->is($user),
            'destroy' => ! $trashed && $gate->allows('delete', $user),
            'restore' => $trashed && $gate->allows('restore', $user),
            'suspend' => ! $trashed && ! $suspended && $community && $outranks,
            'unsuspend' => ! $trashed && $suspended && $community,
            'handle' => ! $trashed && $user->communityProfile !== null && $community && $outranks,
            'whatsapp_revoke' => $user->whatsapp_verified_at !== null && $staff->hasAnyRole([UserRole::Admin->value, UserRole::SuperAdmin->value]),
            'whatsapp_history' => $staff->isEditorialStaff(),
        ];
    }

    /** @return array<string, mixed> */
    public function row(User $staff, User $user): array
    {
        $state = RegistrationState::for($user);
        $named = filled($user->name);

        return [
            'id' => $user->id,
            // Chi si registra dall'app può non dare un nome: la riga mostra l'indirizzo.
            'name' => $named ? (string) $user->name : (string) $user->email,
            'has_name' => $named,
            'email' => (string) $user->email,
            'registration' => ['value' => $state->value, 'label' => $state->getLabel(), 'description' => $state->getDescription()],
            'roles' => $user->roles->map(fn (Model $role): array => self::role((string) $role->getAttribute('name')))->values()->all(),
            'memberships' => $user->venues->map(fn (Venue $venue): array => [
                'venue_id' => $venue->id,
                'venue_name' => (string) $venue->name,
                'city_name' => $venue->city?->name,
                'role' => (string) $venue->getRelation('pivot')->getAttribute('role'),
                'role_label' => VenueRole::tryFrom((string) $venue->getRelation('pivot')->getAttribute('role'))?->label() ?? '—',
            ])->values()->all(),
            'city' => $user->city === null ? null : ['id' => $user->city->id, 'name' => (string) $user->city->name],
            'venue_cities' => $user->venues->pluck('city.name')->filter()->unique()->values()->all(),
            'created_at' => self::date($user->getAttribute('created_at')),
            'email_verified_at' => self::date($user->email_verified_at),
            'whatsapp_verified_at' => self::date($user->whatsapp_verified_at),
            'community_suspended_at' => self::date($user->community_suspended_at),
            'community_handle' => $user->communityProfile?->handle,
            'last_active_at' => self::date($user->last_active_at),
            'deleted_at' => self::date($user->deleted_at),
            'abilities' => $this->abilities($staff, $user),
        ];
    }

    /**
     * La scheda: la riga più i campi del modulo del pannello (sezioni
     * «Account», «Community e moderazione», «Ruoli»).
     *
     * @return array<string, mixed>
     */
    public function detail(User $staff, User $user): array
    {
        return [
            ...$this->row($staff, $user),
            'form' => [
                'city_id' => $user->city_id,
                'name' => (string) $user->name,
                'email' => (string) $user->email,
                'timezone' => (string) $user->timezone,
                'locale' => (string) $user->locale,
                'roles' => $user->roles->pluck('name')->map(fn (mixed $name): string => (string) $name)->sort()->values()->all(),
            ],
            // Il numero non esce mai intero: le ultime quattro cifre, come il pannello.
            'whatsapp_masked' => $user->whatsapp_phone ? '••••'.substr((string) $user->whatsapp_phone, -4) : null,
            /* I ruoli che chi guarda può dare dall'app: gli altri restano come sono. */
            'assignable_roles' => SyncUserRoles::assignable($staff, inApp: true),
        ];
    }

    /**
     * Gli ultimi 30 tentativi di verifica WhatsApp (R-US-11), come la
     * finestra del pannello: data, ultime quattro cifre, esito, tentativi,
     * scadenza.
     *
     * @return list<array<string, mixed>>
     */
    public function whatsappHistory(User $user): array
    {
        return WhatsappHistory::for($user)->map(fn (WhatsappChallenge $challenge): array => [
            'id' => $challenge->id,
            'created_at' => self::date($challenge->created_at),
            'phone_masked' => '••••'.substr((string) $challenge->phone, -4),
            // Anche le righe fallite sono consumate: l'esito dell'invio conta più dell'uso.
            'status' => $challenge->status !== WhatsappChallengeStatus::Failed && $challenge->consumed_at ? 'used' : $challenge->status->value,
            'status_label' => $challenge->status !== WhatsappChallengeStatus::Failed && $challenge->consumed_at
                ? (string) __('community.used') : (string) __('community.challenge_status.'.$challenge->status->value),
            'attempts' => (int) $challenge->attempts,
            'expires_at' => self::date($challenge->expires_at),
        ])->values()->all();
    }

    /** @return array{value: string, label: string} */
    private static function role(string $name): array
    {
        return ['value' => $name, 'label' => UserRole::tryFrom($name)?->label() ?? $name];
    }

    private static function date(mixed $value): ?string
    {
        return $value instanceof DateTimeInterface ? CarbonImmutable::instance($value)->toIso8601String() : null;
    }
}

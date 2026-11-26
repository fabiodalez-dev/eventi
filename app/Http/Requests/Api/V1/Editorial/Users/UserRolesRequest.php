<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Editorial\Users;

use App\Actions\Users\SyncUserRoles;
use App\Enums\Permission;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * I ruoli di piattaforma di un account (R-US-06). Chi modifica la scheda
 * (`UserPolicy::update` e `users.manage`) sceglie fra i ruoli che dall'app si
 * possono dare: fino a moderatore (decisione 4), e `super_admin` comunque
 * solo da un super admin. Amministratore e super amministratore che la
 * persona ha già restano: si danno e si tolgono dal sito.
 */
final class UserRolesRequest extends FormRequest
{
    public function authorize(): bool
    {
        $target = $this->route('user');
        $actor = $this->user();

        return $target instanceof User && $actor instanceof User
            && $actor->can('update', $target) && $actor->can(Permission::ManageUsers->value);
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        $actor = $this->user();

        return [
            'roles' => ['present', 'array'],
            'roles.*' => ['string', 'distinct', Rule::in(SyncUserRoles::assignable($actor instanceof User ? $actor : null, inApp: true))],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return UserFormRules::messages();
    }

    /** @return list<string> */
    public function roles(): array
    {
        $roles = $this->validated('roles');

        return is_array($roles) ? array_values(array_map(static fn (mixed $role): string => (string) $role, $roles)) : [];
    }
}

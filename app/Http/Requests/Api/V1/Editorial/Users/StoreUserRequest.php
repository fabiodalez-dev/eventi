<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Editorial\Users;

use App\Actions\Users\SyncUserRoles;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

/**
 * «Nuovo utente» (R-US-04): i campi del modulo del pannello, con le stesse
 * regole (`UserResource::form()`), e i ruoli che dall'app si possono dare
 * (fino a moderatore, decisione 4). `UserPolicy::create`: admin e super admin.
 */
final class StoreUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', User::class) === true;
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'city_id' => ['nullable', 'integer', Rule::exists('cities', 'id')],
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', Rule::unique('users', 'email')],
            // La stessa politica della registrazione, non una regola a parte.
            'password' => ['required', 'string', Password::defaults()],
            'timezone' => ['required', 'string', Rule::in(timezone_identifiers_list())],
            'locale' => ['required', 'string', Rule::in(UserFormRules::LOCALES)],
            'roles' => ['present', 'array'],
            'roles.*' => ['string', 'distinct', Rule::in(SyncUserRoles::assignable($this->actor(), inApp: true))],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return UserFormRules::messages();
    }

    private function actor(): ?User
    {
        $user = $this->user();

        return $user instanceof User ? $user : null;
    }
}

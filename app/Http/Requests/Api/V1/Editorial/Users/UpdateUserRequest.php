<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Editorial\Users;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * «Modifica utente» (R-US-05) dall'app: città, nome, fuso e lingua, con le
 * regole del modulo del pannello. Email e password di un'altra persona si
 * cambiano solo dal sito (decisione 4): qui sono rifiutate con un messaggio,
 * le proprie passano da `PUT …/credentials`. La data di conferma dell'email
 * non è fra i campi: nel pannello il campo c'è ma il salvataggio la ignora
 * (non è in `$fillable`), e l'app non finge di salvarla.
 * `UserPolicy::update`: admin e super admin, mai su un super admin se non lo si è.
 */
final class UpdateUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        $target = $this->route('user');

        return $target instanceof User && $this->user()?->can('update', $target) === true;
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'city_id' => ['nullable', 'integer', Rule::exists('cities', 'id')],
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'timezone' => ['sometimes', 'required', 'string', Rule::in(timezone_identifiers_list())],
            'locale' => ['sometimes', 'required', 'string', Rule::in(UserFormRules::LOCALES)],
            'email' => ['prohibited'],
            'password' => ['prohibited'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return UserFormRules::messages();
    }
}

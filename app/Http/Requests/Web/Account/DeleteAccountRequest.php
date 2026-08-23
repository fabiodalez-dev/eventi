<?php

declare(strict_types=1);

namespace App\Http\Requests\Web\Account;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * La conferma scritta prima di cancellare l'account (§15.2).
 *
 * Non è burocrazia: la cancellazione è immediata e definitiva, e un pulsante
 * premuto per sbaglio su un telefono cancellerebbe salvataggi e promemoria
 * senza appello. La parola da scrivere è una traduzione come tutte le altre.
 */
class DeleteAccountRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'conferma' => ['required', 'string', Rule::in([__('account.profile.delete_keyword')])],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['conferma' => __('account.profile.delete_confirm')];
    }
}

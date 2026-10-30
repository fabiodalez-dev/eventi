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
 *
 * Serve anche la password attuale, come nell'API: la parola chiave ferma lo
 * sbaglio, non chi trova aperta la sessione di un altro su un telefono o un
 * computer condiviso.
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
            'password' => ['required', 'string', 'current_password:web'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['conferma' => __('account.profile.delete_confirm'), 'password' => __('account.profile.delete_password')];
    }
}

<?php

declare(strict_types=1);

namespace App\Http\Requests\Installer;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

/**
 * Il passo 5 del wizard: l'account di amministrazione.
 *
 * Nessuna regola `unique`: la tabella `users` non esiste ancora quando questo
 * modulo viene compilato, e una regola che interroga un database inesistente
 * fallirebbe con un errore di connessione al posto di un messaggio di
 * validazione. L'unicità la garantisce comunque il vincolo della colonna, e
 * l'operazione che crea l'utente riconosce un'email già presente.
 */
class AdminStepRequest extends FormRequest
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
            'name' => ['required', 'string', 'max:100'],
            'email' => ['required', 'email:filter', 'max:255'],
            'password' => ['required', 'string', 'confirmed', Password::defaults()],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        /** @var array<string, string> $fields */
        $fields = trans('installer.admin.fields');

        return $fields;
    }

    /**
     * @return array<string, string>
     */
    public function values(): array
    {
        return [
            'name' => $this->string('name')->trim()->value(),
            'email' => $this->string('email')->trim()->lower()->value(),
            'password' => (string) $this->input('password', ''),
        ];
    }
}

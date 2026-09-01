<?php

declare(strict_types=1);

namespace App\Http\Requests\Installer;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Il passo 2 del wizard: dove sta il database e con quali credenziali.
 *
 * Il nome del database è limitato a lettere, cifre e trattini bassi perché
 * l'installer prova a crearlo, e `CREATE DATABASE` non accetta segnaposto: un
 * nome libero interpolato in quella riga sarebbe un'iniezione SQL scritta a
 * mano. La restrizione non toglie niente — MySQL non ammette altro senza
 * virgolette rovesce.
 */
class DatabaseStepRequest extends FormRequest
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
            'db_host' => ['required', 'string', 'max:255'],
            'db_port' => ['required', 'integer', 'between:1,65535'],
            'db_database' => ['required', 'string', 'max:64', 'regex:/^[A-Za-z0-9_]+$/'],
            'db_username' => ['required', 'string', 'max:255'],
            'db_password' => ['nullable', 'string', 'max:255'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        /** @var array<string, string> $fields */
        $fields = trans('installer.database.fields');

        return $fields;
    }

    /**
     * @return array<string, string>
     */
    public function values(): array
    {
        return [
            'db_host' => $this->string('db_host')->trim()->value(),
            'db_port' => (string) $this->integer('db_port'),
            'db_database' => $this->string('db_database')->trim()->value(),
            'db_username' => $this->string('db_username')->trim()->value(),
            'db_password' => (string) $this->input('db_password', ''),
        ];
    }
}

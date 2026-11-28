<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Editorial\Settings;

use App\Rules\InternalRedirectTarget;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Un indirizzo cambiato, con i campi e le regole del modulo del pannello:
 * due percorsi fino a 191 caratteri, la destinazione interna
 * (`InternalRedirectTarget`), la città facoltativa, 301 o 302, il jolly. Un
 * indirizzo già scritto per la stessa città lo rifiuta il database: qui
 * diventa un 422.
 */
final class StoreRedirectRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        $city = $this->input('city_id');
        $from = ['required', 'string', 'max:191'];
        /* Il vincolo unico è su (città, percorso): senza città MySQL non lo applica, e nemmeno qui. */
        if (is_numeric($city)) {
            $from[] = Rule::unique('redirects', 'from_path')->where('city_id', (int) $city);
        }

        return [
            'from_path' => $from,
            'to_path' => ['required', 'string', 'max:191', new InternalRedirectTarget],
            'city_id' => ['nullable', 'integer', Rule::exists('cities', 'id')],
            'status' => ['required', 'integer', Rule::in([301, 302])],
            'is_wildcard' => ['sometimes', 'boolean'],
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return [
            'from_path' => __('redirects.fields.from'),
            'to_path' => __('redirects.fields.to'),
            'city_id' => __('redirects.fields.city'),
            'status' => __('redirects.fields.status'),
            'is_wildcard' => __('redirects.fields.wildcard'),
        ];
    }
}

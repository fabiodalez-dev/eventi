<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Me;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * `PATCH /v1/me` (§15.8). Il profilo è minimo per scelta (§15.2): nome
 * facoltativo, fuso, lingua, consenso marketing. Nient'altro è modificabile
 * perché nient'altro viene raccolto.
 *
 * L'email non si cambia da qui: cambiarla significherebbe rifare la verifica,
 * ed è un flusso proprio con un proprio messaggio, non un campo in un modulo.
 */
class UpdateProfileRequest extends FormRequest
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
            'name' => ['sometimes', 'nullable', 'string', 'max:120'],
            'timezone' => ['sometimes', 'string', 'timezone'],
            'locale' => ['sometimes', 'string', Rule::in(config()->array('account.locales'))],
            'marketing_opt_in' => ['sometimes', 'boolean'],
            'daily_digest_time' => ['sometimes', 'nullable', 'date_format:H:i'],
            /*
             * Tre valori accettati, tre significati diversi (D36):
             * `{"from":"23:00","to":"08:00"}` sono le proprie ore, `{}` è
             * «nessun silenzio» scelto di proposito, `null` è «non decido» e
             * riporta alla finestra predefinita. `required_with` non scatta su
             * un oggetto vuoto — un valore vuoto non conta come presente — ed
             * è ciò che rende dichiarabile il secondo caso.
             */
            'quiet_hours' => ['sometimes', 'nullable', 'array:from,to'],
            'quiet_hours.from' => ['required_with:quiet_hours', 'date_format:H:i'],
            'quiet_hours.to' => ['required_with:quiet_hours', 'date_format:H:i'],
        ];
    }
}

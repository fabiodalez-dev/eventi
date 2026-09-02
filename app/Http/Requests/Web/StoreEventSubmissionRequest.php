<?php

declare(strict_types=1);

namespace App\Http\Requests\Web;

use App\Support\Honeypot;
use App\Support\Turnstile;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Proposta di un evento dal pubblico (§11.1).
 *
 * Si chiede il minimo indispensabile: che cosa succede, quando all'incirca,
 * dove e come ricontattare chi propone. Tutto il resto lo completa la
 * redazione, e un modulo lungo è un modulo che nessuno compila.
 */
class StoreEventSubmissionRequest extends FormRequest
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
            'title' => ['required', 'string', 'max:255'],
            'starts_at_hint' => ['nullable', 'date'],
            'venue_id' => ['nullable', 'integer', Rule::exists('venues', 'id')->whereNull('deleted_at')],
            'venue_hint' => ['nullable', 'string', 'max:255'],
            'raw_text' => ['nullable', 'string', 'max:5000'],
            'contact_name' => ['nullable', 'string', 'max:120'],
            'contact_email' => ['required', 'email:filter', 'max:255'],
            ...Honeypot::rules(),
            ...Turnstile::rules('proposta-evento'),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        /** @var array<string, string> $fields */
        $fields = trans('forms.submission.fields');

        return $fields;
    }
}

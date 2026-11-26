<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Editorial\Settings;

use App\Enums\ConsentCategory;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Un cookie nuovo, con i campi e i tetti del modulo del pannello. Il nome è
 * unico nella finalità: il database lo impone, e qui diventa un 422 invece
 * di un errore.
 */
final class StoreCookieRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'category' => ['required', 'string', Rule::in(ConsentCategory::values())],
            'name' => ['required', 'string', 'max:255', Rule::unique('cookie_declarations', 'name')->where('category', (string) $this->input('category'))],
            'provider' => ['nullable', 'string', 'max:255'],
            'purpose' => ['required', 'string', 'max:255'],
            'duration' => ['nullable', 'string', 'max:64'],
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return [
            'category' => __('cookies.fields.category'),
            'name' => __('cookies.fields.name'),
            'provider' => __('cookies.fields.provider'),
            'purpose' => __('cookies.fields.purpose'),
            'duration' => __('cookies.fields.duration'),
        ];
    }
}

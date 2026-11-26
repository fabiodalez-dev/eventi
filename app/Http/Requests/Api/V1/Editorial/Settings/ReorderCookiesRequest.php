<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Editorial\Settings;

use App\Enums\ConsentCategory;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Il nuovo ordine di una scheda del registro dei cookie: gli id di quella finalità, dal primo all'ultimo. */
final class ReorderCookiesRequest extends FormRequest
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
            'ids' => ['required', 'array', 'min:1', 'max:500'],
            'ids.*' => ['required', 'integer', 'distinct', Rule::exists('cookie_declarations', 'id')->where('category', (string) $this->input('category'))],
        ];
    }
}

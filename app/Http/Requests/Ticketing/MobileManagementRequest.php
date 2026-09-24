<?php

declare(strict_types=1);

namespace App\Http\Requests\Ticketing;

use Illuminate\Foundation\Http\FormRequest;

class MobileManagementRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return ['period' => ['sometimes', 'in:upcoming,past'], 'q' => ['nullable', 'string', 'max:120'], 'page' => ['sometimes', 'integer', 'min:1']];
    }
}

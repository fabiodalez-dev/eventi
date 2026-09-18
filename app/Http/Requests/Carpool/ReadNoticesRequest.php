<?php

declare(strict_types=1);

namespace App\Http\Requests\Carpool;

use Illuminate\Foundation\Http\FormRequest;

final class ReadNoticesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['through' => ['nullable', 'integer', 'min:0']];
    }
}

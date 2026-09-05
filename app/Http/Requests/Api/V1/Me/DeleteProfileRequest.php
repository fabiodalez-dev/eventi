<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Me;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class DeleteProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'confirmation' => ['required', 'string', Rule::in([__('account.profile.delete_keyword')])],
            'password' => ['required', 'string', 'current_password:sanctum'],
        ];
    }
}

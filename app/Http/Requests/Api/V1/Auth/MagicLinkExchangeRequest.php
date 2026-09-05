<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Auth;

use Illuminate\Foundation\Http\FormRequest;

final class MagicLinkExchangeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'token' => ['required', 'string', 'size:64'],
            'device_name' => ['nullable', 'string', 'max:120'],
        ];
    }
}

<?php

declare(strict_types=1);

namespace App\Http\Requests\Community;

use Illuminate\Foundation\Http\FormRequest;

final class WhatsappRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['phone' => ['required', 'string', 'max:30', 'phone:INTERNATIONAL']];
    }
}

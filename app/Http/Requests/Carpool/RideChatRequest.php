<?php

declare(strict_types=1);

namespace App\Http\Requests\Carpool;

use Illuminate\Foundation\Http\FormRequest;

class RideChatRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return $this->route('action') === 'send'
            ? ['body' => ['required', 'string', 'max:2000'], 'request_key' => ['required', 'uuid']]
            : ['read_through_id' => ['sometimes', 'integer', 'min:0'], 'muted' => ['sometimes', 'boolean'], 'archived' => ['sometimes', 'boolean']];
    }
}

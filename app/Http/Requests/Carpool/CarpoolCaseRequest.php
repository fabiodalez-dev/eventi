<?php

declare(strict_types=1);

namespace App\Http\Requests\Carpool;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CarpoolCaseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $base = ['body' => ['required', 'string', 'min:5', 'max:2000'], 'request_key' => ['required', 'uuid']];

        return $this->route('case') ? $base : $base + ['reason' => ['required', Rule::in(array_keys(__('carpool.reasons')))],
            'review_id' => ['nullable', 'integer', 'min:1'], 'offer_id' => ['nullable', 'integer', 'min:1'], 'request_id' => ['nullable', 'integer', 'min:1'], 'message_id' => ['nullable', 'integer', 'min:1']];
    }
}

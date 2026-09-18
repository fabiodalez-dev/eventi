<?php

declare(strict_types=1);

namespace App\Http\Requests\Carpool;

use Illuminate\Foundation\Http\FormRequest;

class RideReviewRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['request_key' => ['required', 'uuid']] + match ($this->route('action')) {
            'confirm' => ['confirm' => ['accepted']],
            'save' => ['rating' => ['required', 'integer', 'between:1,5'], 'body' => ['nullable', 'string', 'max:1000'], 'revision' => ['required', 'integer', 'min:0']],
            'remove' => ['revision' => ['required', 'integer', 'min:1']],
            default => ['action' => ['required', 'in:confirm,save,remove']],
        };
    }
}

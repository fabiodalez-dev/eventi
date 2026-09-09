<?php

namespace App\Http\Requests\Web;

use Illuminate\Foundation\Http\FormRequest;

final class GoogleCalendarCallbackRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return ['state' => ['required', 'string', 'max:128'], 'code' => ['nullable', 'string', 'max:4096'], 'error' => ['nullable', 'string', 'max:100']];
    }
}

<?php

declare(strict_types=1);

namespace App\Http\Requests\Reviews;

use Illuminate\Foundation\Http\FormRequest;

class StoreVenueReviewRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('body'))) {
            $this->merge(['body' => trim($this->input('body'))]);
        }
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return ['rating' => ['required', 'integer', 'between:1,5'], 'body' => ['required', 'string', 'min:10', 'max:3000']];
    }

    protected function getRedirectUrl(): string
    {
        return route('venues.show', ['slug' => $this->route('slug')]).'#recensioni';
    }
}

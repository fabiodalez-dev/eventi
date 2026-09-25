<?php

declare(strict_types=1);

namespace App\Http\Requests\Reviews;

use Illuminate\Foundation\Http\FormRequest;

class StoreCatalogReviewRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->canParticipateInCommunity() ?? false;
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
        return ['rating' => ['nullable', 'required_without:body', 'integer', 'between:1,5'], 'body' => ['nullable', 'required_without:rating', 'string', 'min:10', 'max:3000'], 'revision' => ['nullable', 'integer', 'min:0']];
    }

    protected function getRedirectUrl(): string
    {
        return route($this->route('type') === 'organizer' ? 'organizers.show' : 'venues.show', ['slug' => $this->route('slug')]).'#recensioni';
    }
}

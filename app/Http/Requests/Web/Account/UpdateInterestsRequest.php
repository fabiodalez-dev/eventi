<?php

declare(strict_types=1);

namespace App\Http\Requests\Web\Account;

use App\Enums\VenueStatus;
use App\Support\CurrentCity;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class UpdateInterestsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'categories' => ['present', 'array', 'max:100'],
            'categories.*' => ['integer', 'distinct', Rule::exists('categories', 'id')->where('is_active', true)],
            'venues' => ['present', 'array', 'max:500'],
            'venues.*' => ['integer', 'distinct', Rule::exists('venues', 'id')->where('city_id', app(CurrentCity::class)->get()?->id)->where('status', VenueStatus::Approved->value)->whereNull('deleted_at')],
        ];
    }

    protected function prepareForValidation(): void
    {
        if (! $this->isJson()) {
            $this->merge(['categories' => $this->input('categories', []), 'venues' => $this->input('venues', [])]);
        }
    }
}

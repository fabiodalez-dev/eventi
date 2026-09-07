<?php

declare(strict_types=1);

namespace App\Http\Requests\Web;

use App\Enums\VenueStatus;
use App\Support\CurrentCity;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class CalendarWizardRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'step' => ['sometimes', 'integer', 'between:1,3'],
            'categories' => ['sometimes', 'array', 'max:50'],
            'categories.*' => ['string', 'distinct', Rule::exists('categories', 'slug')->where('is_active', true)],
            'venue' => ['nullable', 'string', Rule::exists('venues', 'slug')->where('city_id', app(CurrentCity::class)->get()?->getKey())->where('status', VenueStatus::Approved->value)->whereNull('deleted_at')],
            'days' => ['sometimes', 'integer', Rule::in([7, 30, 90])],
            'free' => ['sometimes', 'boolean'],
        ];
    }
}

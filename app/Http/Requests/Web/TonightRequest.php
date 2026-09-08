<?php

namespace App\Http\Requests\Web;

use App\Support\CurrentCity;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class TonightRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        $city = app(CurrentCity::class)->get();

        return [
            'step' => ['sometimes', 'integer', 'between:1,3'],
            'question' => ['sometimes', Rule::in(['municipality', 'district', 'when', 'budget', 'categories', 'results'])],
            'municipality' => ['nullable', Rule::in(config('discovery-geography.'.$city?->slug.'.municipalities', [$city?->name]))],
            'when' => ['sometimes', Rule::in(['tonight', 'starting_soon'])],
            'zone' => ['nullable', 'string', 'max:120', ...($this->input('municipality') === 'Padova' ? [Rule::in(config('discovery-geography.padova.districts'))] : [])],
            'budget' => ['nullable', Rule::in(['0', '10', '20', '30', '50'])],
            'categories' => ['sometimes', 'array', 'max:30'],
            'categories.*' => ['integer', 'distinct', Rule::exists('categories', 'id')->where('is_active', true)],
        ];
    }
}

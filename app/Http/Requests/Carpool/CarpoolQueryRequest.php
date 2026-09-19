<?php

declare(strict_types=1);

namespace App\Http\Requests\Carpool;

use App\Enums\RideAccessibility;
use App\Enums\RideLeg;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CarpoolQueryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['requests_page' => ['nullable', 'integer', 'between:1,10000'], 'searches_page' => ['nullable', 'integer', 'between:1,10000'], 'wanted_page' => ['nullable', 'integer', 'between:1,10000'], 'archived' => ['nullable', 'boolean'], 'leg' => ['nullable', Rule::enum(RideLeg::class)], 'zone' => ['nullable', 'string', 'max:120'],
            'seats' => ['nullable', 'integer', 'min:1', 'max:8'], 'accessibility' => ['nullable', Rule::enum(RideAccessibility::class)],
            'sort' => ['nullable', Rule::in(['departure', 'recent'])], 'page' => ['nullable', 'integer', 'min:1', 'max:10000'],
            'after' => ['nullable', 'integer', 'min:0'], 'before' => ['nullable', 'integer', 'min:1'],
            'tab' => ['nullable', Rule::in(['offered', 'requested', 'history', 'searches', 'messages'])]];
    }
}

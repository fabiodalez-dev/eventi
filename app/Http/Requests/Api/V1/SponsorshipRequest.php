<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use App\Enums\SponsorshipPlacement;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class SponsorshipRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'city' => ['nullable', 'string', 'max:120'],
            'placement' => ['required', Rule::enum(SponsorshipPlacement::class)],
        ];
    }

    public function placement(): SponsorshipPlacement
    {
        return SponsorshipPlacement::from((string) $this->validated('placement'));
    }
}

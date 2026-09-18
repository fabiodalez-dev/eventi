<?php

declare(strict_types=1);

namespace App\Http\Requests\Community;

use App\Enums\PostIntent;
use App\Enums\SavedVisibility;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class PublicationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['visibility' => ['required', Rule::enum(SavedVisibility::class)],
            'intent' => ['nullable', Rule::enum(PostIntent::class)], 'body' => ['nullable', 'string', 'max:500']];
    }
}

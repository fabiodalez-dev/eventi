<?php

declare(strict_types=1);

namespace App\Http\Requests\Community;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class CommunityQueryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['attendance_page' => ['nullable', 'integer', 'min:1', 'max:10000'], 'following_page' => ['nullable', 'integer', 'min:1', 'max:10000'], 'tab' => ['nullable', Rule::in(['following', 'followers', 'blocks'])], 'q' => ['nullable', 'string', 'max:80'], 'sort' => ['nullable', Rule::in(['recent', 'event'])],
            'scope' => ['nullable', Rule::in(['following', 'discover'])], 'past' => ['nullable', 'boolean'], 'featured' => ['nullable', 'boolean'],
            'page' => ['nullable', 'integer', 'min:1', 'max:10000']];
    }
}

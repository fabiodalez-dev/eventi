<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Me;

use App\Enums\FollowableType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * `POST /v1/me/follows` (§15.8): seguire un locale, un tag, una categoria o
 * un evento ricorrente.
 */
class StoreFollowRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'type' => ['required', Rule::enum(FollowableType::class)],
            'id' => ['required', 'integer', 'min:1'],
            'notify' => ['sometimes', 'boolean'],
        ];
    }

    public function followableType(): FollowableType
    {
        return FollowableType::from((string) $this->validated('type'));
    }
}

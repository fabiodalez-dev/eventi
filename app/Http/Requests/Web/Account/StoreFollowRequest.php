<?php

declare(strict_types=1);

namespace App\Http\Requests\Web\Account;

use App\Enums\FollowableType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

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
        ];
    }

    public function followableType(): FollowableType
    {
        return FollowableType::from((string) $this->validated('type'));
    }
}

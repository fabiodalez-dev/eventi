<?php

declare(strict_types=1);

namespace App\Http\Requests\Comments;

use App\Enums\EventCommentReactionType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ReactEventCommentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return ['type' => ['required', 'string', Rule::enum(EventCommentReactionType::class)]];
    }
}

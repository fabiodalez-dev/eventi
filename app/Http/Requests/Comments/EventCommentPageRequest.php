<?php

declare(strict_types=1);

namespace App\Http\Requests\Comments;

use Illuminate\Foundation\Http\FormRequest;

class EventCommentPageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'commenti' => ['sometimes', 'integer', 'min:1'],
            'commento' => ['sometimes', 'integer', 'min:1'],
            'risposte' => ['sometimes', 'integer', 'min:1'],
        ];
    }
}

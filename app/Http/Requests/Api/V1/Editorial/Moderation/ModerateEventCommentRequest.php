<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Editorial\Moderation;

use Illuminate\Foundation\Http\FormRequest;

/**
 * «Nascondi» (`…/hide`, con una nota facoltativa fino a 1000 caratteri) o
 * «Ripristina» (`…/restore`) un commento, con la revisione letta
 * all'apertura, come i riquadri del pannello. La policy la chiede il
 * controller e di nuovo `ModerateComment`.
 */
final class ModerateEventCommentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'revision' => ['required', 'integer', 'min:0'],
            'note' => ['nullable', 'string', 'max:1000'],
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return ['note' => __('comments.moderation.note')];
    }
}

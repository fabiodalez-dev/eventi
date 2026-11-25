<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Management;

use App\Models\EventComment;
use App\Services\Management\ManagedContent;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Nascondere (`POST …/comments/{comment}/hide`) o rimettere in vista
 * (`POST …/comments/{comment}/restore`) un commento.
 *
 * Il commento si cerca solo fra quelli degli eventi del soggetto (404
 * altrimenti); poi decide `EventCommentPolicy::hide` o `restore`, come i
 * pulsanti del pannello. `ModerateComment` la richiede di nuovo prima di
 * scrivere.
 */
final class ModerateCommentRequest extends FormRequest
{
    private ?EventComment $comment = null;

    public function authorize(): bool
    {
        return $this->user()?->can($this->hiding() ? 'hide' : 'restore', $this->comment()) === true;
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'revision' => ['required', 'integer', 'min:0'],
            ...($this->hiding() ? ['note' => ['nullable', 'string', 'max:1000']] : []),
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return ['note' => __('comments.moderation.note')];
    }

    public function hiding(): bool
    {
        return str_ends_with((string) $this->route()?->getName(), '.hide');
    }

    public function comment(): EventComment
    {
        return $this->comment ??= ManagedContent::current()->comments()->whereKey((int) $this->route('comment'))->firstOrFail();
    }
}

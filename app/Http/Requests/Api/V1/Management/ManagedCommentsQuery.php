<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Management;

use App\Enums\EventCommentStatus;
use App\Models\EventComment;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * I filtri dell'elenco dei commenti (`GET …/comments`): quelli della tabella
 * del pannello (stato e ricerca) più l'evento e la pagina.
 */
final class ManagedCommentsQuery extends FormRequest
{
    /** Vedere i commenti, anche nascosti: `EventCommentPolicy::viewAny`, come il menu del pannello. */
    public function authorize(): bool
    {
        return $this->user()?->can('viewAny', EventComment::class) === true;
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'status' => ['nullable', Rule::enum(EventCommentStatus::class)],
            'q' => ['nullable', 'string', 'max:100'],
            'event' => ['nullable', 'integer', 'min:1'],
            'page' => ['nullable', 'integer', 'min:1', 'max:10000'],
        ];
    }
}

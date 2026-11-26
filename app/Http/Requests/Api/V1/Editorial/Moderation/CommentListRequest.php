<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Editorial\Moderation;

use App\Enums\EventCommentStatus;
use App\Services\Editorial\ModerationDesk;
use Illuminate\Validation\Rule;

/** `GET /api/v1/editorial/comments`: filtro `status`, ricerca su evento, autore e testo, ordinamento per «Mi piace». */
final class CommentListRequest extends ModerationListRequest
{
    protected function sorts(): array
    {
        return ModerationDesk::COMMENT_SORTS;
    }

    protected function filterRules(): array
    {
        return [
            'filter.status' => ['nullable', Rule::enum(EventCommentStatus::class)],
        ];
    }
}

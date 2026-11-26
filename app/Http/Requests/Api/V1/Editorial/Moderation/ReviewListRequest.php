<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Editorial\Moderation;

use App\Services\Editorial\ModerationDesk;
use Illuminate\Validation\Rule;

/** `GET /api/v1/editorial/reviews`: filtri `type` (venue, organizer) e `status`, ricerca sul nome dell'autore. */
final class ReviewListRequest extends ModerationListRequest
{
    protected function sorts(): array
    {
        return ModerationDesk::REVIEW_SORTS;
    }

    protected function filterRules(): array
    {
        return [
            'filter.type' => ['nullable', Rule::in(ModerationDesk::REVIEW_TYPES)],
            'filter.status' => ['nullable', Rule::in(ModerationDesk::reviewStatuses())],
        ];
    }
}

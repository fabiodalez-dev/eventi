<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Editorial\System;

use App\Enums\NotificationStatus;
use App\Enums\NotificationType;
use App\Http\Requests\Api\V1\Editorial\Moderation\ModerationListRequest;
use App\Services\Editorial\EditorialScheduledNotifications;
use Illuminate\Validation\Rule;

/**
 * `GET /api/v1/editorial/scheduled-notifications`: i filtri del pannello,
 * `filter[status]`, `filter[type]`, `filter[due]` (1, «in scadenza»); `q`
 * sull'email del destinatario; ordinamento per orario di invio.
 */
final class ScheduledNotificationListRequest extends ModerationListRequest
{
    protected function sorts(): array
    {
        return EditorialScheduledNotifications::SORTS;
    }

    protected function filterRules(): array
    {
        return [
            'filter.status' => ['nullable', Rule::enum(NotificationStatus::class)],
            'filter.type' => ['nullable', Rule::in(NotificationType::values())],
            'filter.due' => ['nullable', 'boolean'],
        ];
    }
}

<?php

declare(strict_types=1);

namespace App\Services\Social;

use App\Models\EventOccurrence;

final class SocialSource
{
    public static function fingerprint(EventOccurrence $date): string
    {
        return hash('sha256', json_encode([
            $date->only(['starts_at', 'is_all_day', 'price_override', 'status']),
            $date->event->only(['title', 'city_id', 'category_id', 'organizer_name', 'price_type', 'price_min', 'price_max', 'currency', 'poster', 'custom_location']),
            $date->event->venue?->only(['name', 'address', 'municipality']),
            $date->event->category->only(['name']),
            $date->event->getFirstMedia('poster')?->only(['id', 'file_name', 'size', 'updated_at']),
        ], JSON_THROW_ON_ERROR));
    }
}

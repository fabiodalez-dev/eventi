<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\EventOccurrence;

final class EventUrl
{
    public static function occurrence(EventOccurrence $occurrence): string
    {
        return route('events.occurrence', [
            'slug' => $occurrence->event->slug,
            'occurrence' => $occurrence->url_number,
        ]);
    }
}

<?php

declare(strict_types=1);

namespace App\Services\Calendar;

use App\Models\City;
use App\Models\User;
use App\Queries\EventOccurrenceQuery;

final class SavedCalendar
{
    public function __construct(private readonly OccurrenceCalendar $calendar) {}

    public function export(City $city, User $user): string
    {
        $items = EventOccurrenceQuery::archiveFor($city)->savedBy($user)->get();
        $items->load(['event.city', 'event.venue', 'event.category']);

        return $this->calendar->feed($items, __('subscriptions.saved_title'), __('subscriptions.saved_export_help'));
    }
}

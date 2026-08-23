<?php

declare(strict_types=1);

namespace App\Filament\Support;

use App\Enums\EventStatus;
use App\Enums\OccurrenceStatus;

/**
 * Il colore con cui il pannello mostra uno stato. Sta qui perché lo stesso
 * stato compare in cinque tabelle diverse, e cinque mappe scritte a mano
 * finirebbero per non coincidere: il rosso di "annullato" deve essere lo
 * stesso ovunque, altrimenti chi legge smette di fidarsi del colore.
 */
final class EventStatusPresentation
{
    public static function color(EventStatus $status): string
    {
        return match ($status) {
            EventStatus::Published => 'success',
            EventStatus::Pending => 'warning',
            EventStatus::Rejected, EventStatus::Cancelled => 'danger',
            EventStatus::Draft, EventStatus::Archived => 'gray',
        };
    }

    public static function occurrenceColor(OccurrenceStatus $status): string
    {
        return match ($status) {
            OccurrenceStatus::Scheduled => 'success',
            OccurrenceStatus::SoldOut => 'info',
            OccurrenceStatus::Postponed, OccurrenceStatus::Moved => 'warning',
            OccurrenceStatus::Cancelled => 'danger',
        };
    }
}

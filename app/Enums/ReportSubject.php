<?php

declare(strict_types=1);

namespace App\Enums;

use App\Models\Event;
use App\Models\Venue;

/**
 * Su che cosa verte una segnalazione arrivata dall'API (§13.1, `POST /v1/reports`).
 *
 * I valori sono gli **alias della morph map** — `event`, `venue` — e non i nomi
 * delle classi: il contratto pubblico non deve dipendere dal namespace PHP, e
 * la colonna `reports.reportable_type` contiene già quegli stessi alias
 * (deviazione 12 di `docs/SCHEMA.md`).
 */
enum ReportSubject: string
{
    case Event = 'event';
    case Venue = 'venue';

    /**
     * @return class-string<Event|Venue>
     */
    public function model(): string
    {
        return match ($this) {
            self::Event => Event::class,
            self::Venue => Venue::class,
        };
    }

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}

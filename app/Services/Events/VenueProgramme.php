<?php

declare(strict_types=1);

namespace App\Services\Events;

use App\Models\City;
use App\Models\EventOccurrence;
use App\Models\Venue;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use SimpleSoftwareIO\QrCode\Facades\QrCode;

/**
 * Il programma settimanale di un locale, in A4: una riga per data, il QR
 * della scheda del locale in fondo. Lo stesso linguaggio di DomPDF della
 * locandina: tabelle e stili in linea, niente componenti del sito.
 */
final class VenueProgramme
{
    /** @param Collection<int, EventOccurrence> $occurrences */
    public function pdf(Venue $venue, City $city, CarbonImmutable $monday, Collection $occurrences): string
    {
        $url = route('venues.show', $venue);
        $days = $occurrences->groupBy(fn ($occurrence) => $occurrence->starts_at->setTimezone($city->timezone)->format('Y-m-d'));

        return Pdf::loadView('pdf.venue-programme', [
            'venue' => $venue,
            'city' => $city,
            'monday' => $monday,
            'sunday' => $monday->addDays(6),
            'days' => $days,
            'qr' => 'data:image/svg+xml;base64,'.base64_encode((string) QrCode::format('svg')->size(220)->margin(1)->errorCorrection('M')->generate($url)),
            'url' => $url,
        ])->setPaper('a4')->output();
    }

    public function filename(Venue $venue, CarbonImmutable $monday): string
    {
        return str($venue->name.'-programma-'.$monday->format('Y-m-d'))->slug()->append('.pdf')->value();
    }
}

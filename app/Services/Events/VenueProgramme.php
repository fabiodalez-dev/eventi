<?php

declare(strict_types=1);

namespace App\Services\Events;

use App\Models\City;
use App\Models\EventOccurrence;
use App\Models\Venue;
use App\Queries\EventOccurrenceQuery;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use SimpleSoftwareIO\QrCode\Facades\QrCode;

/**
 * Il programma settimanale di un locale, in A4: una riga per data, il QR
 * della scheda del locale in fondo. Lo stesso linguaggio di DomPDF della
 * locandina: tabelle e stili in linea, niente componenti del sito.
 *
 * Lo stampano la pagina pubblica (`/locali/{slug}/programma.pdf`) e l'app
 * di gestione (`GET V/dashboard/programme`), con la stessa settimana e le
 * stesse date.
 */
final class VenueProgramme
{
    /**
     * Il lunedì della settimana da stampare: quella di `$requested`
     * (`AAAA-MM-GG`, un giorno qualsiasi), altrimenti quella corrente, nel
     * fuso della città. Un valore che non è una data vale «questa settimana».
     */
    public function monday(City $city, ?string $requested): CarbonImmutable
    {
        $start = is_string($requested) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $requested) === 1
            ? CarbonImmutable::parse($requested, $city->timezone)
            : CarbonImmutable::now($city->timezone);

        return $start->startOfWeek(CarbonImmutable::MONDAY)->startOfDay();
    }

    /**
     * Le date pubblicate del locale nella settimana che comincia `$monday`,
     * in ordine di inizio, senza quelle dimostrative.
     *
     * @return Collection<int, EventOccurrence>
     */
    public function occurrences(Venue $venue, City $city, CarbonImmutable $monday): Collection
    {
        $occurrences = EventOccurrenceQuery::for($city)->excludingDemo()->atVenue($venue)
            ->between($monday, $monday->addDays(6)->endOfDay())->get()->sortBy('starts_at')->values();
        $occurrences->load(['event.category', 'event.media']);

        return $occurrences;
    }

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

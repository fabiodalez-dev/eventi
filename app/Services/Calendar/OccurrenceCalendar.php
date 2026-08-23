<?php

declare(strict_types=1);

namespace App\Services\Calendar;

use App\Enums\OccurrenceStatus;
use App\Models\EventOccurrence;
use Illuminate\Support\Str;
use Spatie\IcalendarGenerator\Components\Calendar;
use Spatie\IcalendarGenerator\Components\Event as CalendarEvent;
use Spatie\IcalendarGenerator\Enums\EventStatus as CalendarStatus;

/**
 * "Aggiungi al calendario" della scheda evento (§11.5), nelle due forme che
 * servono davvero: un file `.ics` — che funziona su iOS, macOS, Outlook e
 * Thunderbird senza account — e un collegamento a Google Calendar, che è ciò
 * che si aspetta chi usa Android.
 *
 * Gli istanti si scrivono in UTC con il suffisso `Z`: è la sola forma che non
 * richiede di spedire la definizione del fuso dentro il file e che nessun
 * client interpreta male.
 */
final class OccurrenceCalendar
{
    /**
     * Ogni quanto un client dovrebbe rileggere un calendario sottoscritto.
     * Sei ore: gli eventi non cambiano al minuto, e un aggiornamento più
     * frequente moltiplicherebbe le richieste senza cambiare nulla per chi
     * legge.
     */
    private const REFRESH_MINUTES = 360;

    public function ics(EventOccurrence $occurrence): string
    {
        return Calendar::create($occurrence->event->title)
            ->productIdentifier($this->productIdentifier())
            ->event($this->entry($occurrence))
            ->get();
    }

    /**
     * Il calendario sottoscrivibile di `/eventi.ics` (§11.10): non un
     * appuntamento da salvare ma un indirizzo da incollare nel proprio
     * telefono, che poi lo rilegge da solo.
     *
     * `refreshInterval` è ciò che lo rende un abbonamento e non uno scarico
     * unico: senza, molti client leggono il file una volta e non tornano più.
     *
     * @param  iterable<int, EventOccurrence>  $occurrences
     */
    public function feed(iterable $occurrences, string $name, ?string $description = null): string
    {
        $calendar = Calendar::create($name)
            ->productIdentifier($this->productIdentifier())
            ->refreshInterval(self::REFRESH_MINUTES);

        if ($description !== null && $description !== '') {
            $calendar->description($description);
        }

        foreach ($occurrences as $occurrence) {
            $calendar->event($this->entry($occurrence));
        }

        return $calendar->get();
    }

    /**
     * Una data, come voce di calendario. È la stessa forma sia per il file di
     * un singolo evento sia per il calendario della città: un client che li
     * riceve entrambi riconosce la stessa data dallo stesso identificativo e
     * non la duplica.
     */
    private function entry(EventOccurrence $occurrence): CalendarEvent
    {
        $event = $occurrence->event;

        $entry = CalendarEvent::create()
            ->name($event->title)
            ->uniqueIdentifier($this->identifier($occurrence))
            ->startsAt($occurrence->starts_at->utc())
            ->endsAt($occurrence->effective_ends_at->utc())
            ->url(route('events.show', $event))
            ->status($this->status($occurrence->status));

        $description = $this->description($occurrence);

        if ($description !== '') {
            $entry->description($description);
        }

        $venue = $event->venue;

        if ($venue !== null) {
            $entry->address($this->address($occurrence), $venue->name);
            $entry->coordinates((float) $venue->lat, (float) $venue->lng);
        }

        if ($occurrence->is_all_day) {
            $entry->fullDay();
        }

        return $entry;
    }

    private function productIdentifier(): string
    {
        return '-//'.config()->string('app.name').'//IT';
    }

    /**
     * Nome del file scaricato: leggibile, senza accenti e senza spazi.
     */
    public function filename(EventOccurrence $occurrence): string
    {
        return Str::slug($occurrence->event->title.'-'.$occurrence->starts_at->format('Y-m-d')).'.ics';
    }

    /**
     * Google Calendar vuole gli istanti in UTC compatto (`20260905T193000Z`).
     */
    public function googleUrl(EventOccurrence $occurrence): string
    {
        $event = $occurrence->event;

        return 'https://calendar.google.com/calendar/render?'.http_build_query(array_filter([
            'action' => 'TEMPLATE',
            'text' => $event->title,
            'dates' => $occurrence->starts_at->utc()->format('Ymd\THis\Z').'/'.$occurrence->effective_ends_at->utc()->format('Ymd\THis\Z'),
            'details' => $this->description($occurrence),
            'location' => $this->address($occurrence),
            'ctz' => $event->city->timezone,
        ], static fn (string $value): bool => $value !== ''));
    }

    private function identifier(EventOccurrence $occurrence): string
    {
        return sprintf('occorrenza-%d@%s', $occurrence->getKey(), (string) parse_url(url('/'), PHP_URL_HOST));
    }

    private function status(OccurrenceStatus $status): CalendarStatus
    {
        return match ($status) {
            OccurrenceStatus::Cancelled => CalendarStatus::Cancelled,
            OccurrenceStatus::Postponed, OccurrenceStatus::Moved => CalendarStatus::Tentative,
            OccurrenceStatus::Scheduled, OccurrenceStatus::SoldOut => CalendarStatus::Confirmed,
        };
    }

    private function description(EventOccurrence $occurrence): string
    {
        $event = $occurrence->event;

        $parts = array_filter([
            $event->subtitle,
            $event->short_description,
            route('events.show', $event),
        ], static fn (?string $value): bool => filled($value));

        return implode("\n\n", $parts);
    }

    private function address(EventOccurrence $occurrence): string
    {
        $venue = $occurrence->event->venue;

        if ($venue === null) {
            $custom = is_array($occurrence->event->custom_location) ? $occurrence->event->custom_location : [];

            $name = is_string($custom['name'] ?? null) ? $custom['name'] : null;
            $address = is_string($custom['address'] ?? null) ? $custom['address'] : null;

            return implode(', ', array_filter([$name, $address], static fn (?string $value): bool => filled($value)));
        }

        return implode(', ', array_filter([
            $venue->name,
            $venue->address,
            $venue->municipality,
        ], static fn (?string $value): bool => filled($value)));
    }
}

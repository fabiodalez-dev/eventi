<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\SubmissionStatus;
use App\Models\City;
use App\Models\EventSubmission;
use App\Models\Venue;
use Carbon\CarbonImmutable;

/**
 * Registra una proposta arrivata dal pubblico (§11.1, `/proponi-evento`).
 *
 * La proposta **non** diventa un evento: nasce `pending` e resta in coda di
 * moderazione (§14.6). Il collegamento a un locale reale lo fa un moderatore,
 * quindi qui il locale si accetta anche come semplice indicazione in chiaro
 * (`venue_hint`), che è quello che sa chi propone.
 */
final class CreateEventSubmission
{
    /**
     * @param  array{title: string, raw_text?: string|null, venue_hint?: string|null, venue_id?: int|null, starts_at_hint?: string|null, contact_name?: string|null, contact_email: string}  $data
     */
    public function handle(City $city, array $data, ?string $ipAddress = null): EventSubmission
    {
        $venueId = $data['venue_id'] ?? null;

        if ($venueId !== null && ! Venue::query()->whereKey($venueId)->inCity($city)->exists()) {
            $venueId = null;
        }

        return EventSubmission::create([
            'city_id' => $city->getKey(),
            'venue_id' => $venueId,
            'event_id' => null,
            'title' => $data['title'],
            'raw_text' => $data['raw_text'] ?? null,
            'venue_hint' => $data['venue_hint'] ?? null,
            'starts_at_hint' => $this->hintedInstant($city, $data['starts_at_hint'] ?? null),
            'contact_name' => $data['contact_name'] ?? null,
            'contact_email' => $data['contact_email'],
            'status' => SubmissionStatus::Pending,
            'ip_address' => $ipAddress,
        ]);
    }

    /**
     * Chi propone scrive l'ora del proprio orologio, cioè quella della città:
     * va riportata a UTC prima di finire in una colonna `DATETIME`, altrimenti
     * un concerto delle 21:00 diventa delle 23:00 nella coda di moderazione.
     */
    private function hintedInstant(City $city, ?string $value): ?CarbonImmutable
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        return CarbonImmutable::parse($value, $city->timezone)->utc();
    }
}

<?php

declare(strict_types=1);

namespace App\Services\Events;

use App\Models\City;
use App\Models\Venue;
use App\Support\VenueClosures;
use Carbon\CarbonImmutable;

/**
 * I due avvisi accanto all'orario di una data, nei moduli dei pannelli e
 * nell'app: «Stessa sera in zona» (altri eventi vicini alla stessa ora) e la
 * chiusura straordinaria del locale quel giorno. Non bloccano: la sera la
 * sceglie chi gestisce.
 *
 * Le regole stavano nei campi Filament (`SameNightNearbyField`,
 * `VenueClosureWarning`), che adesso chiedono qui.
 */
final class EventDateChecks
{
    public function __construct(private readonly SameNightNearby $nearby) {}

    /**
     * Il contesto della ricerca: i luoghi candidati in ordine di preferenza
     * (un locale, oppure un indirizzo a mano con `lat`/`lng`) e vince il
     * primo con le coordinate. `null` se manca la città, l'orario o un luogo.
     *
     * @param  list<Venue|array<string, mixed>|null>  $places
     * @return array{city: City, lat: float, lng: float, start: CarbonImmutable, end: CarbonImmutable|null, venue_id: int|null, category_id: int|null, event_id: int|null}|null
     */
    public static function context(?City $city, array $places, mixed $start, mixed $end, string $timezone, ?int $venueId = null, mixed $categoryId = null, ?int $eventId = null): ?array
    {
        $begins = self::time($start, $timezone);
        if ($city === null || $begins === null) {
            return null;
        }
        foreach ($places as $place) {
            [$lat, $lng] = $place instanceof Venue ? [$place->lat, $place->lng] : [$place['lat'] ?? null, $place['lng'] ?? null];
            if (is_numeric($lat) && is_numeric($lng)) {
                return ['city' => $city, 'lat' => (float) $lat, 'lng' => (float) $lng, 'start' => $begins, 'end' => self::time($end, $timezone),
                    'venue_id' => $venueId, 'category_id' => is_numeric($categoryId) ? (int) $categoryId : null, 'event_id' => $eventId];
            }
        }

        return null;
    }

    /**
     * Legge un orario del modulo: le stringhe vuote o malformate non sono un
     * errore da mostrare, solo l'assenza di una sera da confrontare.
     */
    public static function time(mixed $value, string $timezone): ?CarbonImmutable
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }
        try {
            return CarbonImmutable::parse($value, $timezone);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Gli eventi della stessa sera in zona, per un contesto di `context()`.
     *
     * @param  array{city: City, lat: float, lng: float, start: CarbonImmutable, end: CarbonImmutable|null, venue_id?: int|null, category_id?: int|null, event_id?: int|null}|null  $context
     * @return list<array{title: string, place: string, time: string, category: string, same_category: bool, distance_m: int, url: string}>
     */
    public function sameNight(?array $context): array
    {
        if ($context === null) {
            return [];
        }

        return $this->nearby->around($context['city'], $context['lat'], $context['lng'], $context['start'],
            $context['end'], $context['venue_id'] ?? null, $context['category_id'] ?? null, $context['event_id'] ?? null);
    }

    /**
     * La chiusura straordinaria del locale che copre il giorno dell'inizio,
     * nel fuso della città del locale.
     *
     * @return array{from: string, to: string, note: string|null}|null
     */
    public static function closure(?Venue $venue, ?CarbonImmutable $start): ?array
    {
        if (! $venue instanceof Venue || $start === null || ! is_array($venue->closures)) {
            return null;
        }
        $timezone = $venue->city->timezone ?? config()->string('app.timezone');

        return VenueClosures::covering($venue->closures, $timezone, day: $start->setTimezone($timezone)->format('Y-m-d'));
    }
}

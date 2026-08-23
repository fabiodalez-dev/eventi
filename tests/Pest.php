<?php

declare(strict_types=1);

use App\Enums\EventStatus;
use App\Models\Category;
use App\Models\City;
use App\Models\Event;
use App\Models\EventOccurrence;
use App\Models\Venue;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class)->in('Feature');

/**
 * La città pilota: fuso Europe/Rome, cutoff notturno alle 06:00, "inizia tra
 * poco" a 180 minuti. Sono i valori su cui §8 fonda tutti i suoi esempi.
 *
 * @param  array<string, mixed>  $attributes
 */
function testCity(array $attributes = []): City
{
    return City::factory()->padova()->create($attributes);
}

/**
 * Categoria con durata predefinita, usata quando l'occorrenza non porta
 * un'ora di fine (§8.3).
 *
 * @param  array<string, mixed>  $attributes
 */
function testCategory(array $attributes = []): Category
{
    return Category::factory()->create([
        'name' => 'Musica dal vivo',
        'default_duration_minutes' => 180,
        'supports_ongoing' => true,
        'is_nightlife' => false,
        ...$attributes,
    ]);
}

/**
 * Un'occorrenza pubblicata, ancorata a istanti espressi **in UTC**.
 *
 * Gli istanti si passano in UTC di proposito: il cast `datetime` di Eloquent
 * scrive l'ora dell'oggetto così com'è, senza convertirla, quindi passare un
 * orario locale salverebbe un istante sbagliato di uno o due fusi.
 *
 * @param  array<string, mixed>  $occurrence  attributi dell'occorrenza
 * @param  array<string, mixed>  $event  attributi dell'evento
 */
function occurrenceAt(
    City $city,
    Category $category,
    string $startsAtUtc,
    ?string $endsAtUtc = null,
    array $occurrence = [],
    array $event = [],
    ?Venue $venue = null,
): EventOccurrence {
    $venue ??= Venue::factory()->approved()->create(['city_id' => $city->getKey()]);

    $eventModel = Event::factory()->create([
        'city_id' => $city->getKey(),
        'category_id' => $category->getKey(),
        'venue_id' => $venue->getKey(),
        'status' => EventStatus::Published,
        'published_at' => CarbonImmutable::now('UTC'),
        ...$event,
    ]);

    return EventOccurrence::factory()->create([
        'event_id' => $eventModel->getKey(),
        'starts_at' => CarbonImmutable::parse($startsAtUtc, 'UTC'),
        'ends_at' => $endsAtUtc === null ? null : CarbonImmutable::parse($endsAtUtc, 'UTC'),
        'doors_at' => null,
        ...$occurrence,
    ]);
}

/**
 * La stessa occorrenza, ma con gli orari scritti come li scrive un gestore:
 * nell'ora locale della città.
 *
 * @param  array<string, mixed>  $occurrence  attributi dell'occorrenza
 * @param  array<string, mixed>  $event  attributi dell'evento
 */
function occurrenceAtLocal(
    City $city,
    Category $category,
    string $localStartsAt,
    ?string $localEndsAt = null,
    array $occurrence = [],
    array $event = [],
    ?Venue $venue = null,
): EventOccurrence {
    return occurrenceAt(
        $city,
        $category,
        localInstant($city, $localStartsAt)->utc()->format('Y-m-d H:i:s'),
        $localEndsAt === null ? null : localInstant($city, $localEndsAt)->utc()->format('Y-m-d H:i:s'),
        $occurrence,
        $event,
        $venue,
    );
}

/**
 * Stesso istante espresso nell'ora locale della città: è così che sono scritti
 * gli scenari di §18 ("evento 18:00–20:00", "sono le 19:00").
 */
function localInstant(City $city, string $localDateTime): CarbonImmutable
{
    return CarbonImmutable::parse($localDateTime, $city->timezone);
}

/**
 * Fissa "adesso" a un orario locale della città. §8.1: l'adesso del motore è
 * sempre quello della città, mai quello del server.
 */
function freezeLocal(City $city, string $localDateTime): CarbonImmutable
{
    $instant = localInstant($city, $localDateTime);

    Carbon::setTestNow($instant);

    return $instant;
}

/**
 * @param  iterable<int, EventOccurrence>  $occurrences
 * @return array<int, int>
 */
function idsOf(iterable $occurrences): array
{
    $ids = [];

    foreach ($occurrences as $occurrence) {
        $ids[] = (int) $occurrence->getKey();
    }

    return $ids;
}

<?php

declare(strict_types=1);

use App\Enums\EventStatus;
use App\Enums\ImportSourceType;
use App\Enums\VenueStatus;
use App\Models\Category;
use App\Models\City;
use App\Models\Event;
use App\Models\ImportSource;
use App\Models\Venue;
use App\Services\Import\ImportRunner;
use Carbon\Carbon;
use Illuminate\Support\Str;
use Tests\Support\IcsFixtures;

/**
 * Un calendario importato pubblica direttamente (D32) solo se il locale è
 * stato accettato: è la stessa condizione che EventPolicy::publish chiede a
 * chi pubblica a mano. Prima il proprietario di un locale in bozza o in
 * attesa metteva online eventi mai visti dalla redazione collegando un feed.
 */
beforeEach(function (): void {
    Carbon::setTestNow(Carbon::parse('2026-08-20 12:00:00', 'UTC'));
    $this->city = City::factory()->padova()->create();
    Category::factory()->create(['default_duration_minutes' => 120]);
});

function importaDaLocale(City $city, ?Venue $venue, string $title): ?Event
{
    IcsFixtures::fakeEvents(['UID:'.Str::slug($title)."@test\nDTSTART:20260905T193000Z\nDTEND:20260905T213000Z\nSUMMARY:{$title}"]);
    $source = ImportSource::query()->create([
        'city_id' => $city->id,
        'venue_id' => $venue?->id,
        'type' => ImportSourceType::Ics,
        'url' => IcsFixtures::URL,
        'is_active' => true,
    ]);
    app(ImportRunner::class)->run($source);

    return Event::query()->where('title', $title)->first();
}

it('crea in attesa gli eventi importati da un locale non ancora approvato', function (VenueStatus $status): void {
    $venue = Venue::factory()->create(['city_id' => $this->city->id, 'status' => $status]);

    $event = importaDaLocale($this->city, $venue, 'Serata locale '.$status->value);

    expect($event)->not->toBeNull()
        ->and($event->status)->toBe(EventStatus::Pending)
        ->and($event->published_at)->toBeNull();
    $this->get(route('events.show', $event))->assertNotFound();
})->with([VenueStatus::Draft, VenueStatus::Pending]);

it('pubblica gli eventi importati da un locale approvato o senza locale', function (): void {
    $venue = Venue::factory()->approved()->create(['city_id' => $this->city->id]);

    expect(importaDaLocale($this->city, $venue, 'Serata approvata')?->status)->toBe(EventStatus::Published)
        ->and(importaDaLocale($this->city, null, 'Serata della città')?->status)->toBe(EventStatus::Published);
});

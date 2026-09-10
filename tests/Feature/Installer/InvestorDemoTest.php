<?php

use App\Console\Commands\InvestorDemoCommand;
use App\Enums\VenueType;
use App\Models\Event;
use App\Models\Venue;
use Database\Seeders\CategorySeeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Queue::fake();
    Storage::fake('public');
    $this->city = testCity();
    $this->seed(CategorySeeder::class);
    foreach (VenueType::cases() as $type) {
        Venue::factory()->create(['city_id' => $this->city->id, 'type' => $type, 'status' => 'approved']);
    }
});

it('validates without writing', function () {
    $this->artisan('events:investor-demo', ['--dry-run' => true])->assertSuccessful();
    expect(Event::count())->toBe(0);
});

it('requires explicit permission in production', function () {
    app()->detectEnvironment(fn () => 'production');
    $this->artisan('events:investor-demo')->assertFailed();
    expect(Event::count())->toBe(0);
});

it('rejects missing local venues before writing any event', function () {
    Venue::query()->delete();
    expect(fn () => Artisan::call('events:investor-demo'))->toThrow(RuntimeException::class);
    expect(Event::count())->toBe(0);
});

it('ships real image files with complete source attribution for all categories', function () {
    $credits = json_decode(file_get_contents(database_path('seeders/investor-media/credits.json')), true);
    expect($credits)->toHaveCount(14);
    foreach ($credits as $credit) {
        expect(getimagesize(database_path('seeders/investor-media/'.$credit['file'])))->not->toBeFalse()
            ->and($credit['source'])->toStartWith('https://commons.wikimedia.org/')
            ->and($credit['author'])->not->toBeEmpty()
            ->and($credit['license'])->not->toBeEmpty();
    }
});

it('imports exactly 300 illustrated demo events over 60 days and is idempotent', function () {
    $this->travelTo(now()->setDate(2026, 9, 10)->setTime(12, 0));
    $existing = Event::factory()->create(['city_id' => $this->city->id]);
    $this->artisan('events:investor-demo')->assertSuccessful();
    $events = Event::where('source_ref', 'like', InvestorDemoCommand::PREFIX.'%')->with('occurrences', 'media')->get();
    expect($events)->toHaveCount(300)
        ->and($events->every(fn ($event) => $event->is_demo && $event->hasMedia('poster') && $event->occurrences->count() === 1))->toBeTrue()
        ->and($events->flatMap->occurrences->map(fn ($date) => $date->starts_at->format('Y-m-d'))->unique())->toHaveCount(60)
        ->and($events->flatMap->occurrences->min('starts_at')->isFuture())->toBeTrue();
    $first = $events->first();
    $first->update(['title' => 'Titolo corretto dalla redazione']);
    $this->travel(2)->days();
    $this->artisan('events:investor-demo')->assertSuccessful();
    expect(Event::count())->toBe(301)
        ->and($first->fresh()->title)->toBe('Titolo corretto dalla redazione')
        ->and($existing->fresh()->source_ref)->toBe($existing->source_ref);
});

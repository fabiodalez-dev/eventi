<?php

declare(strict_types=1);

use App\Actions\Comments\PostComment;
use App\Enums\UserRole;
use App\Filament\Admin\Resources\EventComments\EventCommentResource;
use App\Filament\Admin\Resources\Events\EventResource;
use App\Filament\Admin\Resources\Venues\Pages\EditVenue;
use App\Filament\Admin\Resources\Venues\RelationManagers\EventsRelationManager;
use App\Filament\Admin\Resources\Venues\VenueResource;
use App\Filament\Support\PosterColumn;
use App\Models\Category;
use App\Models\City;
use App\Models\Event;
use App\Models\EventOccurrence;
use App\Models\User;
use App\Models\Venue;
use Carbon\Carbon;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

/**
 * Gli elenchi della redazione con la locandina: eventi, eventi del locale,
 * commenti; la copertina nei locali. Una riga senza immagine mostra il
 * segnaposto, mai un buco, e il numero di query non cresce con le righe.
 */
beforeEach(function (): void {
    (new RolesAndPermissionsSeeder)->run();
    Storage::fake('public');

    $this->city = City::factory()->padova()->create();
    freezeLocal($this->city, '2026-11-10 12:00');
    $this->category = Category::factory()->create(['default_duration_minutes' => 120]);
    $this->venue = Venue::factory()->approved()->create(['city_id' => $this->city->id, 'name' => 'Teatro della prova']);

    $this->admin = User::factory()->create();
    $this->admin->assignRole(UserRole::Admin->value);
    $this->actingAs($this->admin);
});

afterEach(function (): void {
    Carbon::setTestNow();
});

function adminListEvent(array $attributes = [], ?string $startsAt = '2026-11-20 16:30:00'): Event
{
    $event = Event::factory()->create([
        'city_id' => test()->city->id,
        'category_id' => test()->category->id,
        'venue_id' => test()->venue->id,
        'status' => 'published',
        ...$attributes,
    ]);

    if ($startsAt !== null) {
        EventOccurrence::factory()->create(['event_id' => $event->id, 'starts_at' => $startsAt, 'ends_at' => null]);
    }

    return $event;
}

it('shows the poster thumbnail or the placeholder on every event row, with the next date in the city timezone', function (): void {
    $withPoster = adminListEvent(['title' => 'Concerto con locandina']);
    $withPoster->addMedia(UploadedFile::fake()->image('locandina.jpg', 600, 800))->toMediaCollection('poster');
    adminListEvent(['title' => 'Serata senza locandina']);
    adminListEvent(['title' => 'Bozza senza date', 'status' => 'draft'], null);

    $response = $this->get(EventResource::getUrl('index'))->assertOk();

    $response->assertSee(PosterColumn::url($withPoster->fresh()), false)
        ->assertSee(asset(PosterColumn::PLACEHOLDER), false)
        ->assertSee('Concerto con locandina')
        ->assertSee('Serata senza locandina')
        // 16:30 UTC del 20 novembre sono le 17:30 a Padova.
        ->assertSee('Prossima data: ven 20/11/2026, 17:30')
        ->assertSee('Nessuna data')
        ->assertSee('Pubblicato')
        ->assertDontSee('>published<', false);

    expect(PosterColumn::url($withPoster->fresh()))->not->toBe(asset(PosterColumn::PLACEHOLDER));
});

it('keeps the number of queries constant when the event list grows', function (): void {
    adminListEvent(['title' => 'Prima']);
    $this->get(EventResource::getUrl('index'))->assertOk();

    $count = function (): int {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->get(EventResource::getUrl('index'))->assertOk();
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $queries;
    };

    $few = $count();
    foreach (range(1, 5) as $index) {
        adminListEvent(['title' => 'Altra '.$index]);
    }
    $many = $count();

    expect($many)->toBe($few);
});

it('shows the cover or the placeholder in the venue list and posters in the venue events tab', function (): void {
    $this->venue->addMedia(UploadedFile::fake()->image('facciata.jpg', 900, 600))->toMediaCollection('cover');
    $plain = Venue::factory()->approved()->create(['city_id' => $this->city->id, 'name' => 'Circolo senza foto']);
    $event = adminListEvent(['title' => 'Prosa del giovedì']);

    $this->get(VenueResource::getUrl('index'))->assertOk()
        ->assertSee(VenueResource::coverUrl($this->venue->fresh()), false)
        ->assertSee(asset(PosterColumn::PLACEHOLDER), false)
        ->assertSee('Circolo senza foto');

    expect(VenueResource::coverUrl($plain))->toBe(asset(PosterColumn::PLACEHOLDER));

    $this->get(VenueResource::getUrl('edit', ['record' => $this->venue]))->assertOk();
    Livewire::test(EventsRelationManager::class, ['ownerRecord' => $this->venue, 'pageClass' => EditVenue::class])
        ->assertCanSeeTableRecords([$event])
        ->assertSee($event->title)
        ->assertSee(asset(PosterColumn::PLACEHOLDER), false);
});

it('shows the event poster next to each comment', function (): void {
    $event = adminListEvent(['title' => 'Serata commentata']);
    app(PostComment::class)->handle($event, User::factory()->create(['name' => 'Chi commenta']), 'Ci sarò di sicuro');

    $this->get(EventCommentResource::getUrl('index'))->assertOk()
        ->assertSee('Commenti agli eventi')
        ->assertSee('Serata commentata')
        ->assertSee('Ci sarò di sicuro')
        ->assertSee(asset(PosterColumn::PLACEHOLDER), false);
});

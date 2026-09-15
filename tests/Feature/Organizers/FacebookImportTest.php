<?php

declare(strict_types=1);

use App\Enums\EventStatus;
use App\Filament\Organizer\Resources\Events\Pages\CreateEvent;
use App\Models\Event;
use App\Models\Organizer;
use App\Models\User;
use App\Services\Import\HostResolver;
use Filament\Facades\Filament;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Livewire\Livewire;
use Tests\Support\VenueIsolationScenario;

beforeEach(function (): void {
    $this->scenario = VenueIsolationScenario::make();
    $this->organizer = Organizer::create(['name' => 'Portici', 'city_id' => $this->scenario->city->id, 'owner_id' => $this->scenario->ownerA->id, 'is_active' => true]);
    $this->actingAs($this->scenario->ownerA);
    Filament::setCurrentPanel('organizer');
    Filament::setTenant($this->organizer);
    $this->payload = json_decode(file_get_contents(base_path('tests/Fixtures/facebook/event.json')), true);
    $this->mock(HostResolver::class)->shouldReceive('resolve')->andReturn(['93.184.216.34']);
    Http::preventStrayRequests();
    Http::fake(['https://www.facebook.com/events/*' => Http::response('<html><script type="application/json">{}</script></html>')]);
});

it('imports a poster and editable fields and saves an organizer draft with the reviewed date', function (): void {
    Process::fake(['*' => Process::result(output: json_encode($this->payload))]);
    $image = UploadedFile::fake()->image('source.jpg', 1200, 628);
    Http::fake(['https://scontent.xx.fbcdn.net/*' => Http::response(file_get_contents($image->getPathname()), 200, ['Content-Type' => 'image/jpeg'])]);
    $count = Event::count();
    $page = Livewire::test(CreateEvent::class)->assertSee(__('facebook_import.action'))
        ->call('importFacebook', $this->payload['url'])->assertNotified(__('facebook_import.loaded'))
        ->assertFormSet(['title' => 'Concerto importato', 'starts_at' => '2026-10-16 21:00', 'custom_location.address' => 'Via Ticino 5, Padova']);
    expect(Event::count())->toBe($count);
    expect($page->get('data.poster'))->toHaveCount(1);
    $page->fillForm(['title' => 'Serata corretta', 'starts_at' => '2026-10-16 20:00', 'category_id' => $this->scenario->category->id, 'venue_id' => $this->scenario->venueB->id])
        ->call('create')->assertHasNoFormErrors();
    $event = Event::where('title', 'Serata corretta')->sole();
    expect($event->organizer_id)->toBe($this->organizer->id);
    expect($event->venue_id)->toBe($this->scenario->venueB->id);
    expect($event->status)->toBe(EventStatus::Draft);
    expect($event->source_ref)->toBe('facebook:'.$this->payload['id']);
    expect($event->occurrences()->sole()->starts_at->format('Y-m-d H:i'))->toBe('2026-10-16 18:00');
    expect($event->getFirstMedia('poster'))->not->toBeNull();
});

it('preserves form input when organizer extraction fails and refuses a non-member', function (): void {
    Process::fake(['*' => Process::result(exitCode: 1)]);
    $page = Livewire::test(CreateEvent::class)->fillForm(['title' => 'Testo conservato'])
        ->call('importFacebook', $this->payload['url'])->assertNotified(__('facebook_import.failed'))
        ->assertFormSet(['title' => 'Testo conservato']);
    $this->actingAs(User::factory()->create());
    $page->call('importFacebook', $this->payload['url'])->assertForbidden();
});

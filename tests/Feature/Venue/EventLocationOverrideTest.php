<?php

declare(strict_types=1);

use App\Filament\Venue\Resources\Events\Pages\CreateEvent;
use App\Filament\Venue\Resources\Events\Pages\EditEvent;
use App\Models\Event;
use Filament\Facades\Filament;
use Livewire\Livewire;
use Tests\Support\VenueIsolationScenario;

beforeEach(function (): void {
    $this->scenario = VenueIsolationScenario::make();
    $this->actingAs($this->scenario->ownerA);
    Filament::setCurrentPanel('venue');
    Filament::setTenant($this->scenario->venueA);
});

it('saves a manually entered offsite address and coordinates without changing the owning venue', function (): void {
    $originalAddress = $this->scenario->venueA->address;
    Livewire::test(CreateEvent::class)->fillForm([
        'title' => 'Evento fuori sede', 'category_id' => $this->scenario->category->id,
        'starts_at' => now()->addDay()->format('Y-m-d H:i'),
        'custom_location' => ['name' => 'Giardino', 'address' => 'Via Altrove 10', 'lat' => 45.41148, 'lng' => 11.87822],
    ])->call('create')->assertHasNoFormErrors();
    $event = Event::where('title', 'Evento fuori sede')->sole();
    expect($event->custom_location['lat'])->toBe(45.41148)
        ->and($event->venue_id)->toBe($this->scenario->venueA->id)
        ->and($event->locationVenue())->toBeNull()
        ->and($event->occurrences()->sole()->locationLabel())->toBe('Giardino, Via Altrove 10')
        ->and($this->scenario->venueA->fresh()->address)->toBe($originalAddress);

    Livewire::test(EditEvent::class, ['record' => $event->getRouteKey()])
        ->fillForm(['custom_location.address' => ''])
        ->call('save')->assertHasNoFormErrors();
    $event->refresh();
    expect($event->custom_location)->toBeNull()
        ->and($event->locationVenue()->id)->toBe($this->scenario->venueA->id);
});

it('rejects invalid coordinates and oversized custom addresses', function (): void {
    Livewire::test(CreateEvent::class)->fillForm([
        'title' => 'Luogo non valido', 'category_id' => $this->scenario->category->id,
        'custom_location' => ['address' => str_repeat('a', 256), 'lat' => 91, 'lng' => -181],
    ])->call('create')->assertHasFormErrors(['custom_location.address', 'custom_location.lat', 'custom_location.lng']);
    expect(Event::where('title', 'Luogo non valido')->exists())->toBeFalse();
});

it('uses the offsite address and map coordinates on the public page and reverts when cleared', function (): void {
    $event = $this->scenario->publishedEventA;
    $event->update(['custom_location' => ['name' => 'Giardino esterno', 'address' => 'Via Altrove 10', 'lat' => 45.41148, 'lng' => 11.87822]]);
    $this->get(route('events.show', $event))->assertOk()->assertSee('Via Altrove 10')->assertSee('45.41148')->assertSee('11.87822');
    $event->update(['custom_location' => ['name' => 'Nome residuo', 'address' => '', 'lat' => 45.41148, 'lng' => 11.87822]]);
    expect($event->locationVenue()->id)->toBe($this->scenario->venueA->id);
    $this->get(route('events.show', $event))->assertOk()->assertSee($this->scenario->venueA->address)->assertDontSee('Via Altrove 10');
});

it('returns offsite coordinates to the native app without changing ownership', function (): void {
    $event = $this->scenario->publishedEventA;
    $event->update(['custom_location' => ['name' => 'Giardino esterno', 'address' => 'Via Altrove 10', 'lat' => 45.41148, 'lng' => 11.87822]]);
    $response = $this->getJson('/api/v1/events/'.$event->slug)->assertOk();
    $response->assertJsonPath('data.venue', null)
        ->assertJsonPath('data.custom_location.lat', 45.41148)
        ->assertJsonPath('data.custom_location.lng', 11.87822);
    expect($event->fresh()->venue_id)->toBe($this->scenario->venueA->id);
});

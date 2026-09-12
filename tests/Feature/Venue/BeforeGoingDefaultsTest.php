<?php

declare(strict_types=1);

use App\Filament\Admin\Resources\Events\Pages\CreateEvent as AdminCreateEvent;
use App\Filament\Venue\Resources\Events\Pages\EditEvent;
use App\Models\Event;
use App\Models\EventFeature;
use App\Services\Seo\EditorialContent;
use Filament\Facades\Filament;
use Livewire\Livewire;
use Tests\Support\VenueIsolationScenario;

it('prefills existing event forms and preserves only their specific additions', function (): void {
    $scenario = VenueIsolationScenario::make();
    $this->actingAs($scenario->ownerA);
    Filament::setCurrentPanel('venue');
    Filament::setTenant($scenario->venueA);
    $feature = EventFeature::create(['name' => 'Guardaroba', 'icon' => 'check-circle']);
    $extra = EventFeature::create(['name' => 'Interprete', 'icon' => 'users']);
    $scenario->venueA->update(['content_details' => ['accessibility' => 'no', 'feature_ids' => [$feature->id]]]);
    $event = Event::factory()->create(['venue_id' => $scenario->venueA->id, 'city_id' => $scenario->venueA->city_id]);
    Livewire::test(EditEvent::class, ['record' => $event->getRouteKey()])
        ->assertFormSet(['content_details.accessibility' => 'no', 'content_details.feature_ids' => [$feature->id]])
        ->fillForm(['content_details.accessibility' => 'yes', 'content_details.feature_ids' => [(string) $feature->id, (string) $extra->id]])
        ->call('save')->assertHasNoFormErrors();
    expect($event->fresh()->content_details['accessibility'])->toBe('yes')
        ->and($event->fresh()->content_details['feature_ids'])->toBe([$extra->id]);
    $scenario->venueA->update(['content_details' => []]);
    $items = collect(app(EditorialContent::class)->details($event->fresh())['practical_items'])->pluck('label')->all();
    expect($items)->toContain('Interprete')->not->toContain('Guardaroba');
});

it('refreshes defaults when the admin changes venue without losing event additions', function (): void {
    $scenario = VenueIsolationScenario::make();
    $this->actingAs($scenario->admin);
    Filament::setCurrentPanel('admin');
    $feature = EventFeature::create(['name' => 'Guardaroba', 'icon' => 'check-circle']);
    $extra = EventFeature::create(['name' => 'Interprete', 'icon' => 'users']);
    $scenario->venueA->update(['content_details' => ['accessibility' => 'no', 'feature_ids' => [$feature->id]]]);
    $scenario->venueB->update(['content_details' => ['accessibility' => 'yes']]);
    Livewire::test(AdminCreateEvent::class)
        ->set('data.venue_id', $scenario->venueA->id)
        ->assertFormSet(['content_details.accessibility' => 'no', 'content_details.feature_ids' => [$feature->id]])
        ->set('data.content_details.feature_ids', [$feature->id, $extra->id])
        ->set('data.venue_id', $scenario->venueB->id)
        ->assertFormSet(['content_details.accessibility' => 'yes', 'content_details.feature_ids' => [$extra->id]]);
});

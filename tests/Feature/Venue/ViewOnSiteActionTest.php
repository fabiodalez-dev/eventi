<?php

declare(strict_types=1);

use App\Enums\EventStatus;
use App\Filament\Venue\Resources\Events\Pages\EditEvent;
use Filament\Facades\Filament;
use Livewire\Livewire;
use Tests\Support\VenueIsolationScenario;

/**
 * Dalla scheda di un evento si arriva alla pagina pubblica.
 *
 * Chi inserisce un evento vuole vedere come appare a chi lo cerca. Il
 * collegamento pero non deve comparire sulle bozze: la scheda pubblica
 * risponde 404 su tutto cio che non e pubblicato, e un pulsante che porta a
 * una pagina inesistente e peggio di un pulsante assente.
 */
beforeEach(function (): void {
    $this->scenario = VenueIsolationScenario::make();
    $this->venue = $this->scenario->venueA;

    $this->actingAs($this->scenario->ownerA);

    Filament::setCurrentPanel('venue');
    Filament::setTenant($this->venue);
});

it('offre il collegamento alla scheda pubblica su un evento pubblicato', function (): void {
    $event = $this->scenario->publishedEventA;

    Livewire::test(EditEvent::class, ['record' => $event->getRouteKey()])
        ->assertActionExists('viewOnSite')
        ->assertActionVisible('viewOnSite')
        ->assertActionHasUrl('viewOnSite', route('events.show', $event));
});

it('apre la scheda pubblica in una finestra nuova', function (): void {
    $event = $this->scenario->publishedEventA;

    Livewire::test(EditEvent::class, ['record' => $event->getRouteKey()])
        ->assertActionShouldOpenUrlInNewTab('viewOnSite');
});

it('nasconde il collegamento sulle bozze', function (): void {
    $event = $this->scenario->publishedEventA;
    $event->update(['status' => EventStatus::Draft]);

    Livewire::test(EditEvent::class, ['record' => $event->fresh()->getRouteKey()])
        ->assertActionHidden('viewOnSite');
});

it('il collegamento porta a una pagina che risponde davvero', function (): void {
    $event = $this->scenario->publishedEventA;

    $this->get(route('events.show', $event))->assertOk();
})->group('integration');

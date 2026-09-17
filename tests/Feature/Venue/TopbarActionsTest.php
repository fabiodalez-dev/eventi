<?php

declare(strict_types=1);

use App\Filament\Shared\TopbarActions;
use Filament\Facades\Filament;
use Tests\Support\VenueIsolationScenario;

it('offers authorized shortcuts for each management role', function (string $role, string $panel, array $included, array $excluded): void {
    $scenario = VenueIsolationScenario::make();
    $this->actingAs($scenario->{$role});
    Filament::setCurrentPanel($panel);
    Filament::setTenant($panel === 'venue' ? $scenario->venueA : null);
    $context = app(TopbarActions::class)->context();
    $items = collect($context['items'])->keyBy('key');
    foreach ($included as $key) {
        expect($items->has($key))->toBeTrue();
    }
    foreach ($excluded as $key) {
        expect($items->has($key))->toBeFalse();
    }
    if ($panel === 'venue') {
        expect($context['name'])->toBe('Circolo Aurora');
        expect($items['events']['url'])->toContain('/gestione/'.$scenario->venueA->slug.'/');
        expect($items['analytics']['url'])->toContain('/gestione/'.$scenario->venueA->slug.'/');
        expect($context['home'])->toContain('/gestione/'.$scenario->venueA->slug);
    }
})->with([
    ['admin', 'admin', ['create', 'comments', 'analytics', 'calendar', 'tickets', 'public'], ['profile']],
    ['moderator', 'admin', ['comments', 'calendar', 'public'], ['analytics', 'tickets', 'profile']],
    ['ownerA', 'venue', ['create', 'events', 'analytics', 'tickets', 'profile', 'public'], ['comments', 'calendar']],
    ['editorA', 'venue', ['create', 'events', 'analytics', 'public'], ['comments', 'tickets']],
]);

it('does not expose another venues shortcuts or shortcuts to unauthenticated users', function (): void {
    $scenario = VenueIsolationScenario::make();
    $this->actingAs($scenario->ownerB);
    Filament::setCurrentPanel('venue');
    Filament::setTenant($scenario->venueA);
    expect(app(TopbarActions::class)->context())->toBeNull();
    auth()->logout();
    expect(app(TopbarActions::class)->context())->toBeNull();
});

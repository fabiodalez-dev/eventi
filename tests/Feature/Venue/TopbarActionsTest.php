<?php

declare(strict_types=1);

use App\Enums\VenueStatus;
use App\Filament\Admin\Pages\EventsCalendar;
use App\Filament\Admin\Pages\EventShareAnalytics as AdminStatistics;
use App\Filament\Admin\Resources\EventComments\EventCommentResource;
use App\Filament\Admin\Resources\Events\EventResource as AdminEvents;
use App\Filament\Organizer\Pages\Profile as OrganizerProfile;
use App\Filament\Organizer\Pages\Statistics as OrganizerStatistics;
use App\Filament\Organizer\Resources\Events\EventResource as OrganizerEvents;
use App\Filament\Shared\TopbarActions;
use App\Filament\Venue\Pages\Social;
use App\Filament\Venue\Pages\Statistics as VenueStatistics;
use App\Filament\Venue\Pages\VenueProfile;
use App\Filament\Venue\Resources\Events\EventResource as VenueEvents;
use App\Models\Organizer;
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
        expect($items['social']['url'])->toContain('/gestione/'.$scenario->venueA->slug.'/');
        expect($items['sponsorships']['label'])->not->toBe('topbar.sponsorships');
        expect($context['home'])->toContain('/gestione/'.$scenario->venueA->slug);
    }
})->with([
    ['admin', 'admin', ['create', 'comments', 'analytics', 'calendar', 'tickets', 'public'], ['profile']],
    ['moderator', 'admin', ['comments', 'calendar', 'public'], ['analytics', 'tickets', 'profile']],
    ['ownerA', 'venue', ['create', 'events', 'analytics', 'tickets', 'social', 'sponsorships', 'profile', 'public'], ['comments', 'calendar']],
    ['editorA', 'venue', ['create', 'events', 'analytics', 'social', 'sponsorships', 'public'], ['comments', 'tickets']],
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

it('opens the public site instead of a missing profile for a venue awaiting approval', function (): void {
    $scenario = VenueIsolationScenario::make();
    $scenario->venueA->update(['status' => VenueStatus::Pending]);
    $this->actingAs($scenario->ownerA);
    Filament::setCurrentPanel('venue');
    Filament::setTenant($scenario->venueA);
    $items = collect(app(TopbarActions::class)->context()['items'])->keyBy('key');
    expect($items['public']['url'])->toBe(url('/'));
});

it('ha le stesse tre scorciatoie in vista nei tre pannelli, con il nome della pagina', function (string $panel): void {
    $scenario = VenueIsolationScenario::make();
    $organizer = Organizer::create(['name' => 'Collettivo Portici', 'owner_id' => $scenario->ownerA->id, 'city_id' => $scenario->city->id, 'is_active' => true]);
    [$user, $url, $events, $statistics] = match ($panel) {
        'admin' => [$scenario->admin, '/admin', AdminEvents::class, AdminStatistics::class],
        'venue' => [$scenario->ownerA, '/gestione/'.$scenario->venueA->slug, VenueEvents::class, VenueStatistics::class],
        'organizer' => [$scenario->ownerA, '/organizza/'.$organizer->slug, OrganizerEvents::class, OrganizerStatistics::class],
    };
    $this->actingAs($user)->get($url)->assertOk();

    $items = collect(app(TopbarActions::class)->context()['items'])->keyBy('key');
    $inline = $items->filter(fn (array $item): bool => $item['inline']);

    expect($inline->keys()->all())->toBe(['create', 'events', 'analytics'])
        ->and($items['create']['label'])->toBe(__('topbar.create'))
        ->and($items['events']['label'])->toBe($events::getNavigationLabel())
        ->and($items['events']['url'])->toBe($events::getUrl())
        ->and($items['analytics']['label'])->toBe($statistics::getNavigationLabel())
        ->and($items['analytics']['url'])->toBe($statistics::getUrl());

    $pages = match ($panel) {
        'admin' => ['comments' => EventCommentResource::class, 'calendar' => EventsCalendar::class],
        'venue' => ['social' => Social::class, 'profile' => VenueProfile::class],
        'organizer' => ['organizer_profile' => OrganizerProfile::class],
    };
    foreach ($pages as $key => $class) {
        expect($items[$key]['label'])->toBe($class::getNavigationLabel())->and($items[$key]['inline'])->toBeFalse();
    }
})->with(['admin', 'venue', 'organizer']);

it('apre dalla scorciatoia «Statistiche» del locale la pagina che nel menu si chiama così', function (): void {
    $scenario = VenueIsolationScenario::make();
    $this->actingAs($scenario->ownerA)->get('/gestione/'.$scenario->venueA->slug)->assertOk();

    $items = collect(app(TopbarActions::class)->context()['items'])->keyBy('key');

    expect($items['analytics']['url'])->toBe(VenueStatistics::getUrl())
        ->and($items['analytics']['url'])->not->toContain('statistiche-condivisioni');
});

it('chiama il collegamento al sito con il suo nome, con la scheda online e no', function (): void {
    $scenario = VenueIsolationScenario::make();
    $this->actingAs($scenario->ownerA)->get('/gestione/'.$scenario->venueA->slug)->assertOk();
    $online = collect(app(TopbarActions::class)->context()['items'])->keyBy('key')['public'];
    expect($online['label'])->toBe('Vedi il locale sul sito')->and($online['url'])->toBe(route('venues.show', ['slug' => $scenario->venueA->slug]));

    $scenario->venueA->update(['status' => VenueStatus::Pending]);
    Filament::setTenant($scenario->venueA->fresh());
    $offline = collect(app(TopbarActions::class)->context()['items'])->keyBy('key')['public'];
    expect($offline['label'])->toBe('Vai al sito')->and($offline['url'])->toBe(url('/'));

});

/* Ogni prova apre un pannello solo: la richiesta HTTP fissa pannello e locale per tutto il processo di prova. */
it('chiama l’area dell’organizzatore «La tua organizzazione» e la sua pagina con il suo nome', function (): void {
    $scenario = VenueIsolationScenario::make();
    $organizer = Organizer::create(['name' => 'Collettivo Portici', 'owner_id' => $scenario->ownerA->id, 'city_id' => $scenario->city->id, 'is_active' => true]);
    $this->actingAs($scenario->ownerA)->get('/organizza/'.$organizer->slug)->assertOk();

    $context = app(TopbarActions::class)->context();
    expect($context['label'])->toBe('La tua organizzazione')
        ->and(collect($context['items'])->keyBy('key')['public']['label'])->toBe('Vedi la pagina sul sito');
});

it('chiama la redazione «Redazione»', function (): void {
    $scenario = VenueIsolationScenario::make();
    $this->actingAs($scenario->admin)->get('/admin')->assertOk();

    $context = app(TopbarActions::class)->context();
    expect($context['label'])->toBe('Redazione')
        ->and(collect($context['items'])->keyBy('key')['public']['label'])->toBe('Vai al sito');
});

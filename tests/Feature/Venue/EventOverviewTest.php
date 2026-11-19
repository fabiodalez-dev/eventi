<?php

declare(strict_types=1);

use App\Enums\EventStatus;
use App\Filament\Organizer\Resources\Events\EventResource as OrganizerEventResource;
use App\Filament\Organizer\Resources\Events\Pages\ViewEvent as OrganizerViewEvent;
use App\Filament\Venue\Resources\Events\EventResource;
use App\Filament\Venue\Resources\Events\Pages\ViewEvent;
use App\Models\EventOccurrence;
use App\Models\Organizer;
use Carbon\Carbon;
use Filament\Facades\Filament;
use Filament\Schemas\Components\View;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\Support\ImageFixtures;
use Tests\Support\VenueIsolationScenario;

/**
 * La scheda di un evento nel pannello: una colonna sola a tutta larghezza,
 * fatta di card (testata con locandina e pulsanti, informazioni, descrizione
 * con i suoi a capo, serate in programma e già passate).
 */
beforeEach(function (): void {
    $this->scenario = VenueIsolationScenario::make();
    freezeLocal($this->scenario->city, '2026-09-10 12:00');
    $this->event = $this->scenario->publishedEventA;
    $this->event->update(['description' => "<p>Prima riga\nseconda riga</p>\n<p>Secondo paragrafo</p>"]);
    EventOccurrence::factory()->create(['event_id' => $this->event->id, 'starts_at' => '2026-08-01 19:00:00', 'ends_at' => '2026-08-01 21:00:00', 'highlight' => 'Anteprima d’estate']);
    $this->actingAs($this->scenario->ownerA);
    Filament::setCurrentPanel('venue');
    Filament::setTenant($this->scenario->venueA);
});

afterEach(function (): void {
    Carbon::setTestNow();
});

it('shows one full-width column of cards with the event title, a placeholder poster and the line breaks', function (): void {
    $url = EventResource::getUrl('view', ['record' => $this->event]);

    $this->get($url)->assertOk()
        ->assertSee('data-event-read-view', false)
        ->assertSee('data-poster-placeholder', false)
        ->assertSee('<h1', false)
        ->assertSee($this->event->title)
        ->assertDontSee('Visualizza '.$this->event->title)
        ->assertSee('Prima riga<br>seconda riga', false)
        ->assertSee('Secondo paragrafo')
        ->assertSee(EventResource::getUrl('edit', ['record' => $this->event]), false)
        ->assertSee(route('events.show', $this->event), false)
        ->assertSee('Prossima data')
        ->assertSee('11/09/2026')
        ->assertSee('In programma')
        ->assertSee('Già passate')
        ->assertSee('01/08/2026')
        ->assertSee('Passata')
        ->assertSee('Anteprima d’estate')
        ->assertDontSee('<table', false);

    $page = Livewire::test(ViewEvent::class, ['record' => $this->event->getRouteKey()]);
    $schema = $page->instance()->getSchema('infolist');

    expect($page->instance()->getTitle())->toBe($this->event->title)
        ->and($schema->getColumns('lg'))->toBe(1)
        ->and(collect($schema->getComponents())->first())->toBeInstanceOf(View::class)
        ->and(collect($schema->getComponents())->first()->getColumnSpan('default'))->toBe('full')
        ->and($page->instance()->getRelationManagers())->toBeEmpty();
});

it('shows the real poster when the event has one', function (): void {
    Storage::fake('public');
    $this->event->addMedia(ImageFixtures::upload('poster.png', ImageFixtures::png(600, 800)))->toMediaCollection('poster');

    $this->get(EventResource::getUrl('view', ['record' => $this->event]))->assertOk()
        ->assertSee('data-event-poster', false)
        ->assertDontSee('data-poster-placeholder', false);
});

it('says when an event only has past dates and hides the site link for drafts', function (): void {
    $draft = $this->scenario->draftEventA;
    EventOccurrence::factory()->create(['event_id' => $draft->id, 'starts_at' => '2026-07-01 19:00:00', 'ends_at' => null]);

    $this->get(EventResource::getUrl('view', ['record' => $draft]))->assertOk()
        ->assertSee('Ultima data')
        ->assertSee('Passato')
        ->assertSee(EventStatus::Draft->label())
        ->assertDontSee(route('events.show', $draft), false);
});

it('keeps other venues out of the read view', function (): void {
    $this->get(EventResource::getUrl('view', ['record' => $this->scenario->publishedEventB]))->assertNotFound();
});

it('gives organizers the same read view with their own tabs', function (): void {
    $organizer = Organizer::create(['city_id' => $this->scenario->city->id, 'owner_id' => $this->scenario->ownerA->id, 'name' => 'Collettivo Lettura', 'is_active' => true]);
    $this->event->update(['organizer_id' => $organizer->id]);
    Filament::setCurrentPanel('organizer');
    Filament::setTenant($organizer);

    $view = OrganizerEventResource::getUrl('view', ['record' => $this->event]);
    $this->get($view)->assertOk()
        ->assertSee('data-event-read-view', false)
        ->assertSee('Prima riga<br>seconda riga', false)
        ->assertSee(OrganizerEventResource::getUrl('edit', ['record' => $this->event]), false);

    $page = Livewire::test(OrganizerViewEvent::class, ['record' => $this->event->getRouteKey()]);
    $tabs = collect($page->instance()->getSubNavigation());

    expect($page->instance()->getTitle())->toBe($this->event->title)
        ->and($tabs->map->getLabel()->all())->toBe(['Evento', 'Modifica', 'Statistiche'])
        ->and($tabs->first()->getUrl())->toBe($view);
});

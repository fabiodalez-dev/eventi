<?php

declare(strict_types=1);

use App\Filament\Support\PosterColumn;
use App\Filament\Venue\Resources\Events\EventResource;
use App\Filament\Venue\Resources\Events\Pages\ListEvents;
use App\Models\Event;
use App\Models\EventOccurrence;
use Carbon\Carbon;
use Filament\Facades\Filament;
use Livewire\Livewire;
use Tests\Support\VenueIsolationScenario;

/**
 * L'elenco eventi di `/gestione`: una card per evento con la locandina (o il
 * segnaposto), lo stato in italiano, la prossima data o — per gli eventi già
 * andati — l'ultima, con il distintivo «Passato». Le schede dividono i
 * prossimi, i passati e le bozze.
 */
beforeEach(function (): void {
    $this->scenario = VenueIsolationScenario::make();
    freezeLocal($this->scenario->city, '2026-09-10 12:00');
    $this->past = Event::factory()->create([
        'city_id' => $this->scenario->city->id,
        'category_id' => $this->scenario->category->id,
        'venue_id' => $this->scenario->venueA->id,
        'created_by' => $this->scenario->ownerA->id,
        'status' => 'published',
        'title' => 'Serata già andata',
    ]);
    EventOccurrence::factory()->create(['event_id' => $this->past->id, 'starts_at' => '2026-08-20 19:00:00', 'ends_at' => null]);
    $this->actingAs($this->scenario->ownerA);
    Filament::setCurrentPanel('venue');
    Filament::setTenant($this->scenario->venueA);
});

afterEach(function (): void {
    Carbon::setTestNow();
});

it('shows a poster or the placeholder on every card, the status in Italian and the right date', function (): void {
    $this->get(EventResource::getUrl('index'))->assertOk()
        ->assertSee(asset(PosterColumn::PLACEHOLDER), false)
        ->assertSee('Pubblicato')
        ->assertSee('Bozza')
        ->assertDontSee('>published<', false)
        ->assertSee('ven 11 set, 21:00')
        ->assertDontSee('11/09/2026')
        ->assertSee('Si è svolto gio 20 ago, 21:00')
        ->assertSee('Passato')
        ->assertSee('Nessuna data')
        ->assertSee('Prossimi')
        ->assertSee('Passati')
        ->assertSee('Bozze')
        ->assertDontSee($this->scenario->publishedEventB->title);
});

it('sorts upcoming events first and filters with the timing tabs', function (): void {
    $list = Livewire::test(ListEvents::class)
        ->assertCanSeeTableRecords([$this->scenario->publishedEventA, $this->past, $this->scenario->draftEventA], inOrder: true);

    $list->set('activeTab', 'upcoming')
        ->assertCanSeeTableRecords([$this->scenario->publishedEventA])
        ->assertCanNotSeeTableRecords([$this->past, $this->scenario->draftEventA]);

    $list->set('activeTab', 'past')
        ->assertCanSeeTableRecords([$this->past])
        ->assertCanNotSeeTableRecords([$this->scenario->publishedEventA, $this->scenario->draftEventA]);

    $list->set('activeTab', 'drafts')
        ->assertCanSeeTableRecords([$this->scenario->draftEventA])
        ->assertCanNotSeeTableRecords([$this->scenario->publishedEventA, $this->past]);
});

it('keeps edit as the visible button and the other actions in the row menu', function (): void {
    Livewire::test(ListEvents::class)
        ->assertTableActionVisible('edit', $this->scenario->publishedEventA)
        ->assertTableActionVisible('duplicate', $this->scenario->publishedEventA)
        ->assertTableActionVisible('viewOnSite', $this->scenario->publishedEventA)
        ->assertTableActionHidden('viewOnSite', $this->scenario->draftEventA)
        ->assertSee(EventResource::getUrl('view', ['record' => $this->scenario->publishedEventA]), false);
});

<?php

declare(strict_types=1);

use App\Filament\Organizer\Resources\Events\EventResource;
use App\Filament\Organizer\Resources\Events\Pages\ListEvents;
use App\Filament\Support\PosterColumn;
use App\Models\Event;
use App\Models\Organizer;
use App\Models\User;
use Carbon\Carbon;
use Filament\Facades\Filament;
use Livewire\Livewire;

/**
 * L'elenco eventi di `/organizza`: le stesse card di `/gestione` (locandina o
 * segnaposto, stato in italiano con il suo colore, prossima o ultima data) più
 * il locale principale; la card apre la scheda in lettura.
 */
beforeEach(function (): void {
    $this->city = testCity();
    freezeLocal($this->city, '2026-09-10 12:00');
    $this->category = testCategory();
    $this->owner = User::factory()->create();
    $this->organizer = Organizer::create(['city_id' => $this->city->id, 'owner_id' => $this->owner->id, 'name' => 'Collettivo Itinerante', 'is_active' => true]);
    /* Titoli fissi: quelli della factory vengono da un elenco piccolo e ogni
       tanto due eventi si chiamavano uguale, e `assertDontSee` falliva. */
    $this->upcoming = occurrenceAtLocal($this->city, $this->category, '2026-09-11 21:00', event: ['title' => 'Serata del collettivo'])->event;
    $this->past = occurrenceAtLocal($this->city, $this->category, '2026-08-20 21:00', event: ['title' => 'Assemblea di agosto'])->event;
    $this->other = occurrenceAtLocal($this->city, $this->category, '2026-09-12 21:00', event: ['title' => 'Concerto di un altro organizzatore'])->event;
    foreach ([$this->upcoming, $this->past] as $event) {
        $event->update(['organizer_id' => $this->organizer->id, 'organizer_name' => null]);
    }
    $this->actingAs($this->owner);
    Filament::setCurrentPanel('organizer');
    Filament::setTenant($this->organizer);
});

afterEach(function (): void {
    Carbon::setTestNow();
});

it('shows posters, Italian statuses, the main venue and no fake missing dates', function (): void {
    $this->get(EventResource::getUrl('index'))->assertOk()
        ->assertSee('<h1', false)
        ->assertSee('I tuoi eventi')
        ->assertDontSee('I Tuoi Eventi')
        ->assertSee(asset(PosterColumn::PLACEHOLDER), false)
        ->assertSee('Pubblicato')
        ->assertDontSee('>published<', false)
        ->assertSee('Prossima data: 11/09/2026 21:00')
        ->assertSee('Ultima data: 20/08/2026 21:00')
        ->assertSee('Passato')
        ->assertDontSee('Nessuna data')
        ->assertSee($this->upcoming->venue->name)
        ->assertSee(EventResource::getUrl('view', ['record' => $this->upcoming]), false)
        ->assertDontSee($this->other->title);
});

it('splits upcoming and past events with the same tabs as the venue panel', function (): void {
    Livewire::test(ListEvents::class)
        ->assertCanSeeTableRecords([$this->upcoming, $this->past], inOrder: true)
        ->set('activeTab', 'past')
        ->assertCanSeeTableRecords([$this->past])
        ->assertCanNotSeeTableRecords([$this->upcoming])
        ->set('activeTab', 'upcoming')
        ->assertCanSeeTableRecords([$this->upcoming])
        ->assertCanNotSeeTableRecords([$this->past]);
});

it('opens the read view of an organized event and keeps others out', function (): void {
    $this->get(EventResource::getUrl('view', ['record' => $this->upcoming]))->assertOk()->assertSee('data-event-read-view', false);
    $this->get(EventResource::getUrl('view', ['record' => $this->other]))->assertNotFound();
    expect(Event::query()->count())->toBe(3);
});

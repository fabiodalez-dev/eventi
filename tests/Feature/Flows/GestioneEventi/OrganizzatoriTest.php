<?php

declare(strict_types=1);

require_once __DIR__.'/SupportoGestioneEventi.php';

use App\Enums\EventStatus;
use App\Filament\Organizer\Resources\Events\Pages\CreateEvent as OrganizerCreateEvent;
use App\Filament\Organizer\Resources\Events\Pages\EditEvent as OrganizerEditEvent;
use App\Models\Event;
use App\Models\Organizer;
use App\Models\User;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Livewire\Livewire;
use Tests\Support\VenueIsolationScenario;

/*
 * Protegge l'incontro fra un'organizzazione e il locale che la ospita: l'evento
 * nasce in `/organizza`, passa sempre dalla redazione, compare nel cartellone
 * del locale scelto e sulla sua pagina pubblica, e ogni ritocco a un evento
 * già online torna in revisione. Un'altra organizzazione non lo vede.
 */
beforeEach(function (): void {
    $this->scenario = VenueIsolationScenario::make();
    $this->venue = $this->scenario->venueA;
    freezeLocal($this->scenario->city, '2026-09-10 12:00');

    $this->referente = User::factory()->create(['name' => 'Paola Collettivo']);
    $this->organizzazione = Organizer::create([
        'city_id' => $this->scenario->city->id,
        'owner_id' => $this->referente->id,
        'name' => 'Collettivo Ponte Molino',
        'is_active' => true,
    ]);
});

afterEach(function (): void {
    Filament::setTenant(null);
    Filament::setCurrentPanel(null);
    Carbon::setTestNow();
    CarbonImmutable::setTestNow();
});

function geEntraOrganizza(User $user, Organizer $organizer, string $path = ''): void
{
    Filament::setTenant(null);
    test()->actingAs($user)->get('/organizza/'.$organizer->slug.$path)->assertOk();
    Filament::setCurrentPanel('organizer');
    Filament::setTenant($organizer);
}

function geEventoOrganizzato(object $test, string $titolo): Event
{
    geEntraOrganizza($test->referente, $test->organizzazione, '/eventi/create');
    Livewire::test(OrganizerCreateEvent::class)
        ->fillForm([
            'title' => $titolo,
            'description' => '<p>Una serata di musica e parole.</p>',
            'city_id' => $test->scenario->city->id,
            'venue_id' => $test->venue->id,
            'category_id' => $test->scenario->category->id,
            'price_type' => 'free',
            'starts_at' => '2026-09-19 21:00:00',
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    return Event::query()->where('title', $titolo)->sole();
}

it('porta l’evento di un’organizzazione dal suo pannello alla redazione, al cartellone del locale e al sito', function (): void {
    $evento = geEventoOrganizzato($this, 'Reading sul ponte');
    expect($evento->status)->toBe(EventStatus::Draft)
        ->and($evento->organizer_id)->toBe($this->organizzazione->id)
        ->and($evento->venue_id)->toBe($this->venue->id)
        ->and(geOraLocale($evento->occurrences()->sole()))->toBe('2026-09-19 21:00');

    Livewire::test(OrganizerEditEvent::class, ['record' => $evento->getRouteKey()])->callAction('submit');
    expect($evento->fresh()->status)->toBe(EventStatus::Pending);

    geSitoPubblico();
    $this->get('/eventi/'.$evento->slug)->assertNotFound();

    geRedazione($this->scenario->admin, $evento)->callAction('publish');
    expect($evento->fresh()->status)->toBe(EventStatus::Published);

    // Il locale che ospita lo ritrova nel proprio cartellone.
    geEntra($this->scenario->ownerA, $this->venue, '/eventi');
    $this->get('/gestione/'.$this->venue->slug.'/eventi')->assertSee('Reading sul ponte');

    geSitoPubblico();
    $this->get('/eventi/'.$evento->slug)->assertOk()->assertSee('Reading sul ponte')->assertSee('Circolo Aurora');
    $this->get('/locali/'.$this->venue->slug)->assertOk()->assertSee('Reading sul ponte');
});

it('rimanda in redazione l’evento online che l’organizzazione ritocca', function (): void {
    $evento = geEventoOrganizzato($this, 'Concerto sul fiume');
    $evento->update(['status' => EventStatus::Pending]);
    geRedazione($this->scenario->admin, $evento)->callAction('publish');

    geEntraOrganizza($this->referente, $this->organizzazione, '/eventi/'.$evento->getRouteKey().'/edit');
    Livewire::test(OrganizerEditEvent::class, ['record' => $evento->getRouteKey()])
        ->fillForm(['title' => 'Concerto sul fiume, seconda edizione'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($evento->fresh()->status)->toBe(EventStatus::Pending);
    geSitoPubblico();
    $this->get('/eventi/'.$evento->slug)->assertNotFound();
});

it('non mostra né apre l’evento a un’altra organizzazione, nemmeno scrivendo l’indirizzo', function (): void {
    $evento = geEventoOrganizzato($this, 'Laboratorio di serigrafia');

    $altro = User::factory()->create();
    $altra = Organizer::create(['city_id' => $this->scenario->city->id, 'owner_id' => $altro->id, 'name' => 'Associazione Altinate', 'is_active' => true]);

    Filament::setTenant(null);
    $this->actingAs($altro)->get('/organizza/'.$altra->slug.'/eventi')->assertOk()->assertDontSee('Laboratorio di serigrafia');
    $this->actingAs($altro)->get('/organizza/'.$altra->slug.'/eventi/'.$evento->getRouteKey().'/edit')->assertNotFound();
    $this->actingAs($altro)->get('/organizza/'.$this->organizzazione->slug.'/eventi')->assertNotFound();

    expect($altro->can('update', $evento))->toBeFalse();
});

it('non lascia inviare alla redazione un evento senza date', function (): void {
    geEntraOrganizza($this->referente, $this->organizzazione, '/eventi/create');
    Livewire::test(OrganizerCreateEvent::class)
        ->fillForm([
            'title' => 'Idea senza data',
            'description' => '<p>Da definire.</p>',
            'city_id' => $this->scenario->city->id,
            'venue_id' => $this->venue->id,
            'category_id' => $this->scenario->category->id,
            'price_type' => 'free',
        ])
        ->call('create')
        ->assertHasNoFormErrors();
    $evento = Event::query()->where('title', 'Idea senza data')->sole();

    Livewire::test(OrganizerEditEvent::class, ['record' => $evento->getRouteKey()])
        ->callAction('submit')
        ->assertNotified('Aggiungi almeno una data prima di inviare');

    expect($evento->fresh()->status)->toBe(EventStatus::Draft);
});

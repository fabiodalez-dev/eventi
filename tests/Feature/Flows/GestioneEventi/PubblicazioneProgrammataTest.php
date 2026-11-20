<?php

declare(strict_types=1);

require_once __DIR__.'/SupportoGestioneEventi.php';

use App\Enums\EventStatus;
use App\Enums\NotificationType;
use App\Filament\Venue\Resources\Events\Pages\EditEvent;
use App\Models\Event;
use App\Models\ScheduledNotification;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Livewire\Livewire;
use Tests\Support\VenueIsolationScenario;

/*
 * Protegge la pubblicazione programmata vista dal pannello del locale: il
 * gestore sceglie giorno e ora, l'evento resta invisibile fino a quel
 * momento, il comando lo mette online una volta sola, e chi non pubblica da
 * sé continua a passare dalla redazione anche con un orario scelto. Il
 * comando `events:publish-due` è quello che gira davvero ogni minuto.
 */
beforeEach(function (): void {
    $this->scenario = VenueIsolationScenario::make();
    $this->venue = $this->scenario->venueA;
    $this->owner = $this->scenario->ownerA;
    $this->bozza = $this->scenario->draftEventA;
    geData($this->bozza, '2026-09-26 21:00');
    freezeLocal($this->scenario->city, '2026-09-10 12:00');
});

afterEach(function (): void {
    Filament::setTenant(null);
    Filament::setCurrentPanel(null);
    Carbon::setTestNow();
    CarbonImmutable::setTestNow();
});

function geProgramma(Event $event, string $quandoLocale): void
{
    Livewire::test(EditEvent::class, ['record' => $event->getRouteKey()])
        ->callAction('schedulePublication', data: ['publish_at' => $quandoLocale])
        ->assertHasNoActionErrors();
}

it('programma dal pannello l’uscita di una bozza e la mette online all’ora scelta, una volta sola', function (): void {
    $this->venue->update(['auto_publish' => true]);
    geEntra($this->owner, $this->venue, '/eventi/'.$this->bozza->getRouteKey().'/modifica');
    geProgramma($this->bozza, '2026-09-12 09:00:00');

    $this->bozza->refresh();
    expect($this->bozza->status)->toBe(EventStatus::Draft)
        ->and($this->bozza->scheduled_publish_at->utc()->format('Y-m-d H:i'))->toBe('2026-09-12 07:00')
        ->and($this->bozza->publication_scheduled_by)->toBe($this->owner->id);

    // La scheda nel pannello dice quando uscirà, nell'ora della città.
    $this->get('/gestione/'.$this->venue->slug.'/eventi/'.$this->bozza->getRouteKey().'/modifica')->assertOk()->assertSee('Pubblicazione prevista');

    geSitoPubblico();
    $this->get('/eventi/'.$this->bozza->slug)->assertNotFound();

    // Un minuto prima: niente.
    freezeLocal($this->scenario->city, '2026-09-12 08:59');
    $this->artisan('events:publish-due')->assertSuccessful();
    expect($this->bozza->fresh()->status)->toBe(EventStatus::Draft);

    freezeLocal($this->scenario->city, '2026-09-12 09:01');
    $this->artisan('events:publish-due')->assertSuccessful();
    $this->bozza->refresh();
    expect($this->bozza->status)->toBe(EventStatus::Published)
        ->and($this->bozza->scheduled_publish_at)->toBeNull()
        ->and(ScheduledNotification::query()->ofType(NotificationType::EventPublished->value)->where('notifiable_id', $this->bozza->id)->count())->toBe(2);

    // Rieseguito non ripubblica né riavvisa.
    $this->artisan('events:publish-due')->assertSuccessful();
    expect(ScheduledNotification::query()->ofType(NotificationType::EventPublished->value)->where('notifiable_id', $this->bozza->id)->count())->toBe(2);

    $this->get('/eventi/'.$this->bozza->slug)->assertOk()->assertSee($this->bozza->title);
});

it('per un locale senza pubblicazione autonoma l’orario non sostituisce l’approvazione', function (): void {
    geEntra($this->owner, $this->venue, '/eventi/'.$this->bozza->getRouteKey().'/modifica');
    geProgramma($this->bozza, '2026-09-12 09:00:00');

    expect($this->bozza->fresh()->status)->toBe(EventStatus::Pending);
    $this->get('/gestione/'.$this->venue->slug.'/eventi/'.$this->bozza->getRouteKey().'/modifica')
        ->assertOk()->assertSee('In attesa di approvazione della redazione');

    // Passata l'ora, senza approvazione resta in coda.
    freezeLocal($this->scenario->city, '2026-09-12 10:00');
    $this->artisan('events:publish-due')->assertSuccessful();
    expect($this->bozza->fresh()->status)->toBe(EventStatus::Pending);
    geSitoPubblico();
    $this->get('/eventi/'.$this->bozza->slug)->assertNotFound();
});

it('lascia alla redazione approvare in anticipo: l’evento esce comunque all’ora scelta dal locale', function (): void {
    geEntra($this->owner, $this->venue, '/eventi/'.$this->bozza->getRouteKey().'/modifica');
    geProgramma($this->bozza, '2026-09-14 18:00:00');

    geRedazione($this->scenario->admin, $this->bozza)->callAction('publish');
    $this->bozza->refresh();
    expect($this->bozza->status)->toBe(EventStatus::Draft)
        ->and($this->bozza->scheduled_publish_at)->not->toBeNull()
        ->and($this->bozza->publication_scheduled_by)->toBe($this->scenario->admin->id);

    geSitoPubblico();
    $this->get('/eventi/'.$this->bozza->slug)->assertNotFound();

    freezeLocal($this->scenario->city, '2026-09-14 18:05');
    $this->artisan('events:publish-due')->assertSuccessful();
    expect($this->bozza->fresh()->status)->toBe(EventStatus::Published);
    $this->get('/eventi/'.$this->bozza->slug)->assertOk();
});

it('annulla la programmazione dal pannello e l’evento resta bozza anche dopo l’ora', function (): void {
    $this->venue->update(['auto_publish' => true]);
    geEntra($this->owner, $this->venue, '/eventi/'.$this->bozza->getRouteKey().'/modifica');
    geProgramma($this->bozza, '2026-09-12 09:00:00');

    Livewire::test(EditEvent::class, ['record' => $this->bozza->getRouteKey()])
        ->assertActionVisible('cancelPublicationSchedule')
        ->callAction('cancelPublicationSchedule')
        ->assertNotified('Programmazione annullata');

    expect($this->bozza->fresh()->scheduled_publish_at)->toBeNull()
        ->and($this->bozza->fresh()->publication_scheduled_by)->toBeNull();

    freezeLocal($this->scenario->city, '2026-09-12 10:00');
    $this->artisan('events:publish-due')->assertSuccessful();
    expect($this->bozza->fresh()->status)->toBe(EventStatus::Draft);
});

it('non programma nel passato né un evento senza date', function (): void {
    $this->venue->update(['auto_publish' => true]);
    geEntra($this->owner, $this->venue, '/eventi/'.$this->bozza->getRouteKey().'/modifica');
    Livewire::test(EditEvent::class, ['record' => $this->bozza->getRouteKey()])
        ->callAction('schedulePublication', data: ['publish_at' => '2026-09-09 09:00:00'])
        ->assertHasActionErrors(['publish_at']);
    expect($this->bozza->fresh()->scheduled_publish_at)->toBeNull();

    $senzaDate = Event::factory()->create([
        'city_id' => $this->scenario->city->id, 'category_id' => $this->scenario->category->id,
        'venue_id' => $this->venue->id, 'created_by' => $this->owner->id,
        'title' => 'Bozza ancora senza date', 'status' => EventStatus::Draft,
    ]);
    geEntra($this->owner, $this->venue, '/eventi/'.$senzaDate->getRouteKey().'/modifica');
    Livewire::test(EditEvent::class, ['record' => $senzaDate->getRouteKey()])
        ->callAction('schedulePublication', data: ['publish_at' => '2026-09-12 09:00:00'])
        ->assertNotNotified('Programmazione salvata');
    expect($senzaDate->fresh()->scheduled_publish_at)->toBeNull()
        ->and($senzaDate->fresh()->status)->toBe(EventStatus::Draft);
});

it('rimanda in redazione un evento programmato e approvato che il locale riscrive', function (): void {
    geEntra($this->owner, $this->venue, '/eventi/'.$this->bozza->getRouteKey().'/modifica');
    geProgramma($this->bozza, '2026-09-14 18:00:00');
    geRedazione($this->scenario->admin, $this->bozza)->callAction('publish');
    expect($this->bozza->fresh()->status)->toBe(EventStatus::Draft);

    // Il gestore cambia il testo dopo l'approvazione: la redazione deve rileggerlo.
    geEntra($this->owner, $this->venue, '/eventi/'.$this->bozza->getRouteKey().'/modifica');
    Livewire::test(EditEvent::class, ['record' => $this->bozza->getRouteKey()])
        ->fillForm(['title' => 'Titolo cambiato dopo il via libera'])
        ->call('save')
        ->assertHasNoFormErrors();
    expect($this->bozza->fresh()->status)->toBe(EventStatus::Pending);

    freezeLocal($this->scenario->city, '2026-09-14 18:05');
    $this->artisan('events:publish-due')->assertSuccessful();
    expect($this->bozza->fresh()->status)->toBe(EventStatus::Pending);
    geSitoPubblico();
    $this->get('/eventi/'.$this->bozza->slug)->assertNotFound();
});

it('non pubblica all’ora programmata se nel frattempo il gestore ha perso l’accesso al locale', function (): void {
    $this->venue->update(['auto_publish' => true]);
    geEntra($this->owner, $this->venue, '/eventi/'.$this->bozza->getRouteKey().'/modifica');
    geProgramma($this->bozza, '2026-09-12 09:00:00');

    $this->owner->venues()->detach($this->venue->id);

    freezeLocal($this->scenario->city, '2026-09-12 09:30');
    $this->artisan('events:publish-due')->assertSuccessful();
    expect($this->bozza->fresh()->status)->toBe(EventStatus::Draft);
    geSitoPubblico();
    $this->get('/eventi/'.$this->bozza->slug)->assertNotFound();
});

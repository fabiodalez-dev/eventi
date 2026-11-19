<?php

declare(strict_types=1);

use App\Enums\EventStatus;
use App\Filament\Venue\Resources\Events\Pages\EditEvent;
use Filament\Facades\Filament;
use Livewire\Livewire;
use Tests\Support\VenueIsolationScenario;

/*
 * Una descrizione in testo semplice (import, eventi creati da script) si apre
 * nell'editor dei pannelli a paragrafi: salvando senza toccarla non si fonde
 * in un paragrafo solo.
 */
beforeEach(function (): void {
    $this->scenario = VenueIsolationScenario::make();
    $this->venue = $this->scenario->venueA;
    $this->event = $this->scenario->publishedEventA;
    $this->event->forceFill(['description' => "Quaranta posti in tutto.\n\nEvento dimostrativo.\nSeconda riga."])->saveQuietly();
    $this->actingAs($this->scenario->ownerA);
    $this->get(EditEvent::getUrl(['record' => $this->event], panel: 'venue', tenant: $this->venue))->assertOk();
    Filament::setCurrentPanel('venue');
    Filament::setTenant($this->venue);
});

afterEach(function (): void {
    Filament::setCurrentPanel(null);
});

it('apre il testo semplice a paragrafi e lo salva senza fondere i paragrafi', function (): void {
    $pagina = Livewire::test(EditEvent::class, ['record' => $this->event->getRouteKey()]);

    $stato = json_encode($pagina->get('data.description'));
    expect($stato)->toContain('Quaranta posti in tutto.')
        ->and($stato)->not->toContain('tutto.Evento');

    $pagina->call('save')->assertHasNoFormErrors();

    $salvata = (string) $this->event->fresh()->description;
    expect(substr_count($salvata, '<p>'))->toBe(2)
        ->and($salvata)->toContain('<p>Quaranta posti in tutto.</p>')
        ->and($salvata)->toMatch('~Evento dimostrativo\.<br\s*/?>Seconda riga\.~')
        ->and($salvata)->not->toContain('tutto.Evento');
});

it('tiene l’HTML com’era, con gli a capo scritti dentro il paragrafo come br', function (): void {
    $this->event->forceFill(['description' => "<p>Uno</p><p>Due\nTre &lt;10 anni</p>"])->saveQuietly();

    Livewire::test(EditEvent::class, ['record' => $this->event->getRouteKey()])->call('save')->assertHasNoFormErrors();

    $salvata = (string) $this->event->fresh()->description;
    expect(substr_count($salvata, '<p>'))->toBe(2)
        ->and($salvata)->toMatch('~Due<br\s*/?>Tre &lt;10 anni~')
        ->and($this->event->fresh()->status)->toBe(EventStatus::Published);
});

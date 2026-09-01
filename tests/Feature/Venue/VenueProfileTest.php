<?php

declare(strict_types=1);

use App\Enums\AccessibilityFeature;
use App\Enums\TransitMode;
use App\Filament\Venue\Pages\VenueProfile;
use Filament\Facades\Filament;
use Livewire\Livewire;
use Tests\Support\VenueIsolationScenario;

/**
 * «Il tuo locale» (§10): la schermata che fino a oggi non esisteva.
 *
 * Undici campi vivevano nello schema — indirizzo, orari, contatti,
 * accessibilità, come arrivare — senza che nessun modulo li mostrasse a chi
 * avrebbe dovuto scriverli. La pagina esiste per questo, e solo il referente
 * la apre: un collaboratore pubblica gli eventi, non cambia l'indirizzo.
 */
beforeEach(function (): void {
    $this->scenario = VenueIsolationScenario::make();
    $this->venue = $this->scenario->venueA;

    Filament::setCurrentPanel('venue');

    /*
     * **L'ordine conta**: `setTenant()` emette `TenantSet`, che pretende un
     * utente. Chiamato prima dell'autenticazione riceve `null` e solleva un
     * TypeError prima ancora che il test cominci — l'errore non ha niente a
     * che vedere con cio' che si sta verificando, e lo nasconde.
     *
     * Si autentica qui il referente, che e' il caso normale di questa
     * schermata; i test che guardano un altro utente rifanno `actingAs` e il
     * locale resta il suo — che e' esattamente cio' che devono verificare.
     */
    $this->actingAs($this->scenario->ownerA);

    Filament::setTenant($this->venue);
});

it('salva indirizzo, quartiere, orari, contatti, come arrivare, accessibilità e scheda informativa', function (): void {
    $this->actingAs($this->scenario->ownerA);

    Livewire::test(VenueProfile::class)
        ->fillForm([
            'address' => 'Via dei Livello, 32',
            'address_extra' => 'Ingresso dal cortile',
            'postal_code' => '35139',
            'municipality' => 'Padova',
            'zone' => 'Portello',
            'phone' => '+39 049 123456',
            'email' => 'info@circolo.test',
            'website' => 'https://circolo.test',
            'capacity' => 180,
            'requires_membership' => true,
            'membership_notes' => 'Tessera annuale in sede.',
            'opening_hours' => [
                ['day' => 'fri', 'open' => '18:00', 'close' => '02:00'],
            ],
            'transit' => [
                ['mode' => TransitMode::Tram->value, 'text' => 'Tram SIR1, fermata Ospedali.'],
            ],
            'info' => [
                ['label' => 'Guardaroba', 'value' => 'Gratuito'],
            ],
            'accessibility' => [
                AccessibilityFeature::StepFreeEntrance->value => 1,
                AccessibilityFeature::TactilePath->value => 0,
            ],
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    $venue = $this->venue->fresh();

    expect($venue?->address)->toBe('Via dei Livello, 32')
        ->and($venue?->zone)->toBe('Portello')
        ->and($venue?->phone)->toBe('+39 049 123456')
        ->and($venue?->capacity)->toBe(180)
        ->and($venue?->requires_membership)->toBeTrue()
        // Gli orari tornano nella forma per giorno di D19, non nelle righe piatte del modulo.
        ->and($venue?->opening_hours)->toBe(['fri' => [['open' => '18:00', 'close' => '02:00']]])
        ->and($venue?->transit->toArray())->toBe([['mode' => TransitMode::Tram->value, 'text' => 'Tram SIR1, fermata Ospedali.']])
        ->and($venue?->info->toArray())->toBe([['label' => 'Guardaroba', 'value' => 'Gratuito']])
        ->and($venue?->accessibility->has(AccessibilityFeature::StepFreeEntrance))->toBeTrue()
        // Dichiarata assente: resta, e non diventa «non dichiarata».
        ->and($venue?->accessibility->declares(AccessibilityFeature::TactilePath))->toBeTrue()
        ->and($venue?->accessibility->has(AccessibilityFeature::TactilePath))->toBeFalse()
        // Mai toccata: resta non dichiarata, che non è «no».
        ->and($venue?->accessibility->declares(AccessibilityFeature::GuideDogAllowed))->toBeFalse();
});

it('non cambia il nome del locale, che è identità e la tiene la redazione', function (): void {
    $this->actingAs($this->scenario->ownerA);

    $nome = $this->venue->name;

    Livewire::test(VenueProfile::class)
        ->fillForm(['venue_name' => 'Nome scritto a mano', 'address' => 'Via nuova, 1', 'municipality' => 'Padova'])
        ->call('save');

    expect($this->venue->fresh()?->name)->toBe($nome);
});

it('resta chiusa a un collaboratore', function (): void {
    $this->actingAs($this->scenario->editorA);

    expect(VenueProfile::canAccess())->toBeFalse();

    $this->get('/gestione/'.$this->venue->slug.'/locale')->assertForbidden();
});

it('non apre il locale di qualcun altro', function (): void {
    $this->actingAs($this->scenario->ownerB);

    $this->get('/gestione/'.$this->scenario->venueA->slug.'/locale')->assertNotFound();
});

it('rifiuta le righe di come arrivare senza mezzo o senza testo', function (): void {
    $this->actingAs($this->scenario->ownerA);

    Livewire::test(VenueProfile::class)
        ->fillForm([
            'address' => 'Via dei Livello, 32',
            'municipality' => 'Padova',
            'transit' => [['mode' => TransitMode::Bus->value, 'text' => '']],
        ])
        ->call('save')
        ->assertHasFormErrors(['transit']);
});

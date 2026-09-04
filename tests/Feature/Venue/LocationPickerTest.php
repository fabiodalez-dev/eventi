<?php

declare(strict_types=1);

use App\Filament\Venue\Pages\VenueProfile;
use App\Filament\Venue\Widgets\MissingLocationWidget;
use App\Models\City;
use App\Models\User;
use App\Models\Venue;
use Filament\Facades\Filament;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Support\VenueIsolationScenario;

/**
 * Il punto sulla mappa: chi lo mette, e come si sa che non l'ha messo nessuno.
 *
 * Un locale nato da una richiesta di iscrizione riceve le coordinate del
 * centro citta' — il modulo pubblico chiede un indirizzo scritto a mano, non
 * un punto. Il risultato inganna: nessun campo vuoto, nessun errore, un
 * segnaposto sulla mappa. Solo che e' in piazza insieme a tutti gli altri, e
 * nessuno va a controllare una cosa che sembra a posto.
 */
beforeEach(function (): void {
    $this->citta = City::factory()->create(['center_lat' => 45.4064, 'center_lng' => 11.8768]);
    $this->referente = User::factory()->create();
});

function localeDi(City $citta, User $referente, float $lat, float $lng): Venue
{
    $venue = Venue::factory()->approved()->create([
        'city_id' => $citta->getKey(),
        'lat' => $lat,
        'lng' => $lng,
    ]);
    $venue->members()->attach($referente, ['role' => 'owner']);

    return $venue;
}

it('avvisa il locale che sta ancora sul centro della citta', function (): void {
    $venue = localeDi($this->citta, $this->referente, 45.4064, 11.8768);

    $this->actingAs($this->referente);
    Filament::setCurrentPanel('venue');
    Filament::setTenant($venue);

    expect(MissingLocationWidget::canView())->toBeTrue();
});

it('tace con chi il segnaposto lo ha gia spostato', function (): void {
    /* Anche di poco: chi ha mosso il punto ha fatto una scelta, e continuare
       a dirgli che non e' sulla mappa insegna a ignorare gli avvisi. */
    $venue = localeDi($this->citta, $this->referente, 45.4071, 11.8802);

    $this->actingAs($this->referente);
    Filament::setCurrentPanel('venue');
    Filament::setTenant($venue);

    expect(MissingLocationWidget::canView())->toBeFalse();
});

it('lascia al locale il proprio punto, che prima non poteva toccare', function (): void {
    /*
     * Dallo scenario del progetto e non da un contesto costruito a mano: la
     * pagina di Filament vuole pannello, tenant e utente allineati, e
     * rifarlo qui significherebbe tenerne due versioni allineate.
     */
    $scenario = VenueIsolationScenario::make();

    Filament::setCurrentPanel('venue');
    $this->actingAs($scenario->ownerA);
    Filament::setTenant($scenario->venueA);

    /* Le coordinate non erano fra i campi che un locale poteva modificare:
       le scriveva la redazione, e per i locali nati da una richiesta di
       iscrizione non le scriveva nessuno. */
    Livewire\Livewire::test(VenueProfile::class)
        ->fillForm(['lat' => 45.4111, 'lng' => 11.8765])
        ->call('save')
        ->assertHasNoFormErrors();

    expect((float) $scenario->venueA->fresh()->lat)->toBe(45.4111)
        ->and((float) $scenario->venueA->fresh()->lng)->toBe(11.8765);
});

it('lascia al locale caricare il proprio logo', function (): void {
    /*
     * Il logo esisteva come collezione e la scheda pubblica lo mostrava da
     * sempre — ma poteva caricarlo solo la redazione. Un locale appena
     * iscritto restava senza faccia, e il file ce l'ha lui.
     */
    $scenario = VenueIsolationScenario::make();

    Filament::setCurrentPanel('venue');
    $this->actingAs($scenario->ownerA);
    Filament::setTenant($scenario->venueA);

    Storage::fake('media');

    Livewire\Livewire::test(VenueProfile::class)
        ->fillForm([
            'logo' => [UploadedFile::fake()->image('logo.png', 512, 512)],
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($scenario->venueA->fresh()->getMedia('logo'))->toHaveCount(1);
});

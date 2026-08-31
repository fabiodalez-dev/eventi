<?php

declare(strict_types=1);

use App\Enums\ImportSourceType;
use App\Filament\Venue\Pages\CalendarImport;
use App\Models\ImportSource;
use Filament\Facades\Filament;
use Livewire\Livewire;
use Tests\Support\IcsFixtures;
use Tests\Support\VenueIsolationScenario;

/**
 * §14.2 — la pagina con cui un locale collega il proprio calendario.
 *
 * Due comportamenti sono qui la ragione stessa della pagina, e i test che li
 * presidiano non sono formalità:
 *
 * 1. **L'anteprima viene prima.** Gli eventi importati sono pubblicati
 *    direttamente (D32): l'anteprima è l'unico momento in cui una persona
 *    guarda cosa sta per finire sul sito. Se si potesse accendere una sorgente
 *    senza averla vista, il contrappeso non esisterebbe.
 * 2. **Un locale non tocca il calendario di un altro.** È lo scenario F di §18
 *    applicato a una superficie nuova: una policy corretta non protegge un
 *    endpoint che non la invoca.
 */
beforeEach(function (): void {
    $this->scenario = VenueIsolationScenario::make();
    $this->venue = $this->scenario->venueA;

    $this->actingAs($this->scenario->ownerA);

    Filament::setCurrentPanel('venue');
    Filament::setTenant($this->venue);
});

it('apre la pagina al referente del locale', function (): void {
    Livewire::test(CalendarImport::class)->assertOk();
});

it('rifiuta di collegare finche non si e guardata l anteprima', function (): void {
    IcsFixtures::fake('google-calendar');

    Livewire::test(CalendarImport::class)
        ->fillForm(['url' => IcsFixtures::URL])
        ->call('connect');

    // Nessuna sorgente creata: il canale verso il sito pubblico non si apre
    // senza che qualcuno abbia visto cosa ci passera.
    expect(ImportSource::query()->count())->toBe(0);
});

it('collega la sorgente dopo l anteprima', function (): void {
    IcsFixtures::fake('google-calendar');

    Livewire::test(CalendarImport::class)
        ->fillForm(['url' => IcsFixtures::URL])
        ->call('preview')
        ->assertSet('previewed', true)
        ->call('connect');

    $source = ImportSource::query()->where('venue_id', $this->venue->getKey())->first();

    expect($source)->not->toBeNull()
        ->and($source->type)->toBe(ImportSourceType::Ics)
        ->and($source->is_active)->toBeTrue();
});

it('mostra nell anteprima le date che entreranno e non quelle escluse', function (): void {
    IcsFixtures::fake('internal-entries');

    $component = Livewire::test(CalendarImport::class)
        ->fillForm(['url' => IcsFixtures::URL])
        ->call('preview');

    $titles = array_column($component->get('previewRows'), 'title');

    expect($titles)->toContain("Concerto del quartetto d'archi");

    foreach ($titles as $title) {
        expect(mb_strtolower($title))
            ->not->toContain('riunione')
            ->not->toContain('chiuso');
    }
});

it('dimentica l anteprima quando cambia l indirizzo', function (): void {
    IcsFixtures::fake('google-calendar');

    Livewire::test(CalendarImport::class)
        ->fillForm(['url' => IcsFixtures::URL])
        ->call('preview')
        ->assertSet('previewed', true)
        // Un indirizzo diverso e un calendario diverso: cio che si e visto
        // prima non descrive piu cio che si sta per accendere.
        ->fillForm(['url' => IcsFixtures::URL.'?altro'])
        ->assertSet('previewed', false);
});

it('rifiuta un indirizzo che punta alla rete interna', function (): void {
    Livewire::test(CalendarImport::class)
        ->fillForm(['url' => 'http://127.0.0.1:8000/calendario.ics'])
        ->call('preview');

    expect(ImportSource::query()->count())->toBe(0);
})->group('security');

it('non mostra al locale A la sorgente del locale B', function (): void {
    $altrui = ImportSource::factory()->create([
        'city_id' => $this->scenario->venueB->city_id,
        'venue_id' => $this->scenario->venueB->getKey(),
        'type' => ImportSourceType::Ics->value,
        'url' => IcsFixtures::URL,
    ]);

    $component = Livewire::test(CalendarImport::class);

    // La pagina guarda solo il locale su cui e aperta: quella dell'altro non
    // compare nemmeno come oggetto in memoria.
    expect($component->instance()->source()?->getKey())->not->toBe($altrui->getKey());

    // E la policy nega comunque, se qualcuno ci arrivasse per un'altra strada.
    expect($this->scenario->ownerA->can('view', $altrui))->toBeFalse()
        ->and($this->scenario->ownerA->can('update', $altrui))->toBeFalse()
        ->and($this->scenario->ownerA->can('run', $altrui))->toBeFalse()
        ->and($this->scenario->ownerA->can('delete', $altrui))->toBeFalse();
})->group('security');

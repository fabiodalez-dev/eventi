<?php

declare(strict_types=1);

use App\Enums\EventSource;
use App\Enums\EventStatus;
use App\Enums\VerificationStatus;
use App\Filament\Admin\Resources\Events\Pages\ListEvents;
use Filament\Facades\Filament;
use Livewire\Livewire;
use Tests\Support\VenueIsolationScenario;

/**
 * Un evento importato ha la **stessa dignità** degli altri nel sito pubblico,
 * ed è **riconoscibile** nei pannelli.
 *
 * Sono due regole opposte che convivono, ed è deliberato:
 *
 * - Fuori, chi cerca cosa fare stasera non deve chiedersi da quale tubo sia
 *   passato un concerto. Un marchio di provenienza sarebbe rumore che non
 *   aiuta a decidere, e declasserebbe eventi veri per un dettaglio interno.
 * - Dentro, chi modera deve distinguere ciò che una persona ha scritto da ciò
 *   che una macchina ha letto da un calendario altrui: gli importati nessuno
 *   li ha verificati, e alla prossima lettura possono cambiare da soli.
 *
 * È il tipo di regola che si perde alla prima rifattorizzazione, perché
 * "mostriamo la provenienza ovunque" sembra più coerente. Questi test esistono
 * per impedirlo.
 */
beforeEach(function (): void {
    $this->scenario = VenueIsolationScenario::make();

    $this->imported = $this->scenario->publishedEventA;
    $this->imported->update([
        'source' => EventSource::ImportIcs,
        'verification_status' => VerificationStatus::Unverified,
        'status' => EventStatus::Published,
    ]);
});

it('non rivela nella scheda pubblica che l evento arriva da un calendario', function (): void {
    $html = $this->get(route('events.show', $this->imported))->assertOk()->getContent();

    expect($html)
        ->not->toContain(EventSource::ImportIcs->label())
        ->not->toContain('import_ics')
        ->not->toContain(VerificationStatus::Unverified->label())
        ->not->toContain('unverified');
});

it('non rivela la provenienza nemmeno nelle liste pubbliche', function (): void {
    $html = $this->get(route('events.index'))->assertOk()->getContent();

    expect($html)
        ->not->toContain('import_ics')
        ->not->toContain('unverified');
});

it('non espone la provenienza in API', function (): void {
    $payload = $this->getJson('/api/v1/events?limit=50')->assertOk()->json();

    expect(json_encode($payload))
        ->not->toContain('import_ics')
        ->not->toContain('verification_status');
});

it('mostra la provenienza nell elenco della redazione', function (): void {
    $admin = $this->scenario->admin;

    $this->actingAs($admin);
    Filament::setCurrentPanel('admin');

    Livewire::test(ListEvents::class)
        ->assertCanSeeTableRecords([$this->imported])
        ->assertSee(EventSource::ImportIcs->label());
});

it('non marca gli eventi scritti a mano', function (): void {
    $manuale = $this->scenario->publishedEventB;
    $manuale->update(['source' => EventSource::Manual]);

    expect($manuale->source->isImported())->toBeFalse()
        ->and($this->imported->source->isImported())->toBeTrue();
});

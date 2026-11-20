<?php

declare(strict_types=1);

require_once __DIR__.'/SupportoGestioneEventi.php';

use App\Actions\ModerateVenueAction;
use App\Enums\EventStatus;
use App\Filament\Venue\Pages\VenueProfile;
use App\Filament\Venue\Resources\Events\Pages\CreateEvent;
use App\Filament\Venue\Resources\Events\Pages\EditEvent;
use App\Filament\Venue\Resources\Events\Pages\ListEvents;
use App\Filament\Venue\Resources\Events\Pages\ViewEvent;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Illuminate\Testing\TestResponse;
use Livewire\Livewire;
use Tests\Support\VenueIsolationScenario;

/*
 * Protegge la cache delle pagine (§12.3) dal lato di chi gestisce: ogni gesto
 * del pannello che cambia ciò che il pubblico deve vedere — pubblicare,
 * correggere, annullare, eliminare, chiudere per ferie, l'approvazione della
 * redazione — rende vecchie le copie salvate di elenchi e pagine del locale.
 * Il segno è l'intestazione `X-Page-Cache`: dopo il gesto la pagina si
 * ricompone («miss») e porta la novità, invece di servire la copia di prima.
 */
beforeEach(function (): void {
    $this->scenario = VenueIsolationScenario::make();
    $this->venue = $this->scenario->venueA;
    $this->owner = $this->scenario->ownerA;
    $this->venue->update(['auto_publish' => true]);
    $this->evento = $this->scenario->publishedEventA;
    freezeLocal($this->scenario->city, '2026-09-10 12:00');
    config()->set('page_cache.enabled', true);
});

afterEach(function (): void {
    Filament::setTenant(null);
    Filament::setCurrentPanel(null);
    Carbon::setTestNow();
    CarbonImmutable::setTestNow();
});

/** Una pagina pubblica, letta da anonimo due volte: la seconda deve arrivare dalla cache. */
function geInCache(string $url): void
{
    geSitoPubblico();
    test()->get($url)->assertOk()->assertHeader('X-Page-Cache', 'miss');
    test()->get($url)->assertOk()->assertHeader('X-Page-Cache', 'hit');
}

/** Dopo un gesto del pannello, la stessa pagina si ricompone. */
function geRicomposta(string $url): TestResponse
{
    geSitoPubblico();

    return test()->get($url)->assertOk()->assertHeader('X-Page-Cache', 'miss');
}

it('fa comparire nell’elenco già in cache l’evento appena pubblicato dal pannello', function (): void {
    geInCache('/eventi');
    $this->get('/eventi')->assertDontSee('Notte di letture ad alta voce');

    geEntra($this->owner, $this->venue, '/eventi/nuovo');
    Livewire::test(CreateEvent::class)
        ->fillForm(geDatiEvento($this->scenario->category, ['title' => 'Notte di letture ad alta voce', 'starts_at' => '2026-09-13 21:00:00']))
        ->call('create')
        ->assertHasNoFormErrors();

    geRicomposta('/eventi')->assertSee('Notte di letture ad alta voce');
    $this->get('/eventi')->assertHeader('X-Page-Cache', 'hit')->assertSee('Notte di letture ad alta voce');
});

it('porta il titolo corretto nella giornata e nella pagina del locale in cache', function (): void {
    geInCache('/eventi/2026-09-11');
    geInCache('/locali/'.$this->venue->slug);

    geEntra($this->owner, $this->venue, '/eventi/'.$this->evento->getRouteKey().'/modifica');
    Livewire::test(EditEvent::class, ['record' => $this->evento->getRouteKey()])
        ->fillForm(['title' => 'Concerto di canti popolari'])
        ->call('save')
        ->assertHasNoFormErrors();

    geRicomposta('/eventi/2026-09-11')->assertSee('Concerto di canti popolari');
    geRicomposta('/locali/'.$this->venue->slug)->assertSee('Concerto di canti popolari');
});

it('rinfresca la giornata in cache quando una data si annulla o va esaurita', function (): void {
    geInCache('/eventi/2026-09-11');

    geEntra($this->owner, $this->venue, '/eventi');
    Livewire::test(ListEvents::class)->callTableAction('soldOutNextDate', $this->evento);
    geRicomposta('/eventi/2026-09-11')->assertSee(__('events.badge.sold_out'));

    $this->get('/eventi/2026-09-11')->assertHeader('X-Page-Cache', 'hit');
    geEntra($this->owner, $this->venue, '/eventi/'.$this->evento->getRouteKey().'/modifica');
    geDate($this->evento)->callTableAction('cancel_occurrence', $this->scenario->occurrenceA);
    geRicomposta('/eventi/2026-09-11')->assertDontSee(__('events.badge.sold_out'));
});

it('toglie dalle pagine in cache l’evento eliminato dal pannello', function (): void {
    geInCache('/eventi/2026-09-11');
    geInCache('/locali/'.$this->venue->slug);
    $this->get('/locali/'.$this->venue->slug)->assertSee('Concerto di musica popolare');

    geEntra($this->owner, $this->venue, '/eventi/'.$this->evento->getRouteKey().'/modifica');
    Livewire::test(EditEvent::class, ['record' => $this->evento->getRouteKey()])->callAction('delete');

    geRicomposta('/eventi/2026-09-11')->assertDontSee('Concerto di musica popolare');
    geRicomposta('/locali/'.$this->venue->slug)->assertDontSee('Concerto di musica popolare');
});

it('fa entrare nelle pagine in cache l’evento approvato dalla redazione', function (): void {
    $this->venue->update(['auto_publish' => false]);
    $bozza = $this->scenario->draftEventA;
    geData($bozza, '2026-09-16 21:00');
    geEntra($this->owner, $this->venue, '/eventi/'.$bozza->getRouteKey());
    Livewire::test(ViewEvent::class, ['record' => $bozza->getRouteKey()])->callAction('publish');
    expect($bozza->fresh()->status)->toBe(EventStatus::Pending);

    geInCache('/eventi/2026-09-16');
    $this->get('/eventi/2026-09-16')->assertDontSee('Bozza del circolo Aurora');

    geRedazione($this->scenario->admin, $bozza)->callAction('publish');
    geRicomposta('/eventi/2026-09-16')->assertSee('Bozza del circolo Aurora');
});

it('aggiorna la pagina del locale in cache quando il gestore segna le ferie', function (): void {
    geInCache('/locali/'.$this->venue->slug);

    geEntra($this->owner, $this->venue, '/locale');
    Livewire::test(VenueProfile::class)
        ->set('data.closures', [['from' => '2026-09-20', 'to' => '2026-09-25', 'note' => 'Chiusi per lavori al palco']])
        ->call('save')
        ->assertHasNoErrors();

    geRicomposta('/locali/'.$this->venue->slug)->assertSee('Chiusi per lavori al palco');
});

it('svuota dalle pagine in cache gli eventi di un locale sospeso', function (): void {
    geInCache('/eventi/2026-09-11');
    geInCache('/eventi');

    app(ModerateVenueAction::class)->suspend($this->venue, 'In verifica');

    geRicomposta('/eventi/2026-09-11')->assertDontSee('Concerto di musica popolare');
    geRicomposta('/eventi')->assertDontSee('Concerto di musica popolare');
});

it('non mette mai in cache la scheda dell’evento, che dopo una modifica è subito giusta', function (): void {
    geSitoPubblico();
    $this->get('/eventi/'.$this->evento->slug)->assertOk()->assertHeaderMissing('X-Page-Cache');

    geEntra($this->owner, $this->venue, '/eventi/'.$this->evento->getRouteKey().'/modifica');
    Livewire::test(EditEvent::class, ['record' => $this->evento->getRouteKey()])
        ->fillForm(['description' => '<p>Porte aperte alle 20.</p>'])
        ->call('save');

    geSitoPubblico();
    $this->get('/eventi/'.$this->evento->slug)->assertOk()->assertHeaderMissing('X-Page-Cache')->assertSee('Porte aperte alle 20.');
    // Chi è collegato non riceve né scrive copie.
    geEntra($this->owner, $this->venue);
    geFuoriDalPannello();
    $this->get('/eventi')->assertOk()->assertHeaderMissing('X-Page-Cache');
});

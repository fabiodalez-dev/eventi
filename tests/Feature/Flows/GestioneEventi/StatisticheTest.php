<?php

declare(strict_types=1);

require_once __DIR__.'/SupportoGestioneEventi.php';

use App\DTOs\ConsentState;
use App\Filament\Venue\Pages\Dashboard;
use App\Filament\Venue\Pages\EventAnalyticsDetail;
use App\Filament\Venue\Pages\EventShareAnalytics;
use App\Filament\Venue\Resources\Events\EventResource;
use App\Filament\Venue\Resources\Events\Pages\CreateEvent;
use App\Filament\Venue\Resources\Events\Pages\EditEvent;
use App\Filament\Venue\Resources\Events\RelationManagers\OccurrencesRelationManager;
use App\Filament\Venue\Widgets\UpcomingEventsWidget;
use App\Models\Event;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\URL;
use Livewire\Livewire;
use Tests\Support\VenueIsolationScenario;

/*
 * Protegge le statistiche viste dal pannello del locale lungo la vita di un
 * evento: le visite misurate sulla scheda pubblica arrivano all'evento e alle
 * sue date, il selettore degli eventi (D69) propone solo il proprio
 * cartellone diviso in prossimi e passati, senza mai un numero d'ordine, e
 * le statistiche di un evento eliminato non si riaprono.
 */
beforeEach(function (): void {
    $this->scenario = VenueIsolationScenario::make();
    $this->venue = $this->scenario->venueA;
    $this->owner = $this->scenario->ownerA;
    $this->venue->update(['auto_publish' => true]);
    freezeLocal($this->scenario->city, '2026-09-10 18:00');
});

afterEach(function (): void {
    Filament::setTenant(null);
    Filament::setCurrentPanel(null);
    Carbon::setTestNow();
    CarbonImmutable::setTestNow();
});

/** Un visitatore che ha accettato le statistiche apre la scheda `$volte` volte. */
function geVisite(array $parametri, int $volte, string $metrica = 'views'): void
{
    $stato = new ConsentState('flussi-gestione-eventi', config('consent.version'), ['necessary' => true, 'statistics' => true]);
    test()->withCredentials()->withCookie(config('consent.cookie'), $stato->encode());
    $url = URL::signedRoute('content.metrics', $parametri, absolute: false);
    for ($i = 0; $i < $volte; $i++) {
        test()->postJson($url, ['metric' => $metrica])->assertNoContent();
    }
}

it('porta le visite della scheda pubblica nelle statistiche dell’evento e della sua data', function (): void {
    $evento = $this->scenario->publishedEventA;
    $data = $this->scenario->occurrenceA;
    geVisite(['type' => 'event', 'id' => $evento->id, 'occurrence' => $data->id], 4);
    geVisite(['type' => 'event', 'id' => $this->scenario->publishedEventB->id], 9);

    geEntra($this->owner, $this->venue, '/statistiche-condivisioni/event/'.$evento->id);
    $dettaglio = Livewire::test(EventAnalyticsDetail::class, ['subjectType' => 'event', 'subjectId' => $evento->id])
        ->assertSee($evento->title);
    expect($dettaglio->instance()->dashboard()['totals']['views'])->toBe(4);

    // Le date dell'evento portano le loro visite.
    geEntra($this->owner, $this->venue, '/eventi/'.$evento->getRouteKey().'/modifica');
    Livewire::test(OccurrencesRelationManager::class, ['ownerRecord' => $evento, 'pageClass' => EditEvent::class])
        ->assertTableColumnStateSet('analytics_views', 4, $data);
});

it('passa da un evento all’altro del locale con il selettore, che non mostra mai numeri d’ordine', function (): void {
    $passato = Event::factory()->create([
        'city_id' => $this->scenario->city->id, 'category_id' => $this->scenario->category->id,
        'venue_id' => $this->venue->id, 'title' => 'Festa di primavera', 'status' => 'published', 'published_at' => now(),
    ]);
    geData($passato, '2026-05-02 20:00');

    geEntra($this->owner, $this->venue, '/statistiche-condivisioni/event/'.$this->scenario->publishedEventA->id);
    $pagina = Livewire::test(EventAnalyticsDetail::class, ['subjectType' => 'event', 'subjectId' => $this->scenario->publishedEventA->id]);
    $gruppi = $pagina->instance()->otherEventForm->getComponent('otherEvent')->getOptions();

    expect(array_keys($gruppi))->toBe([__('pannelli.select.upcoming'), __('pannelli.select.past')])
        ->and(json_encode($gruppi[__('pannelli.select.upcoming')]))->toContain('Bozza del circolo Aurora')
        ->and(json_encode($gruppi[__('pannelli.select.past')]))->toContain('Festa di primavera')
        // L'evento che si sta guardando non si propone a sé stesso.
        ->and(json_encode($gruppi))->not->toContain('Concerto di musica popolare')
        ->and(json_encode($gruppi))->not->toContain('#'.$passato->id);

    // Si porta dietro solo il periodo scelto, mai il filtro di un altro evento.
    $pagina->call('goToEvent', $passato->id)
        ->assertRedirectContains('/statistiche-condivisioni/event/'.$passato->id.'?');
    $this->get(EventAnalyticsDetail::getUrl(['subjectType' => 'event', 'subjectId' => $passato->id]))->assertOk()->assertSee('Festa di primavera');
});

it('filtra le statistiche del locale per evento e rifiuta un evento altrui forzato nel filtro', function (): void {
    geVisite(['type' => 'event', 'id' => $this->scenario->publishedEventA->id], 2);
    $altro = occurrenceAt($this->scenario->city, $this->scenario->category, '2026-09-20 19:00:00', event: ['title' => 'Cineforum del giovedì'], venue: $this->venue)->event;
    geVisite(['type' => 'event', 'id' => $altro->id], 1);
    geVisite(['type' => 'event', 'id' => $this->scenario->publishedEventB->id], 7);

    geEntra($this->owner, $this->venue, '/statistiche-condivisioni');
    $pagina = Livewire::test(EventShareAnalytics::class);
    expect($pagina->instance()->dashboard()['totals']['views'])->toBe(3);

    $pagina->set('filters.event', $this->scenario->publishedEventA->id);
    expect($pagina->instance()->dashboard()['totals']['views'])->toBe(2);

    $pagina->set('filters.event', $this->scenario->publishedEventB->id)->assertForbidden();
});

it('chiude le statistiche di un evento eliminato e le toglie dal selettore', function (): void {
    $evento = $this->scenario->publishedEventA;
    geEntra($this->owner, $this->venue, '/eventi/'.$evento->getRouteKey().'/modifica');
    Livewire::test(EditEvent::class, ['record' => $evento->getRouteKey()])->callAction('delete');

    $this->get(EventAnalyticsDetail::getUrl(['subjectType' => 'event', 'subjectId' => $evento->id]))->assertNotFound();

    geEntra($this->owner, $this->venue, '/statistiche-condivisioni/event/'.$this->scenario->draftEventA->id);
    $opzioni = json_encode(Livewire::test(EventAnalyticsDetail::class, ['subjectType' => 'event', 'subjectId' => $this->scenario->draftEventA->id])
        ->instance()->otherEventForm->getComponent('otherEvent')->getOptions());
    expect($opzioni)->not->toContain('Concerto di musica popolare');
});

it('mette nel riepilogo del pannello l’evento appena creato, con il collegamento alla sua scheda', function (): void {
    geEntra($this->owner, $this->venue, '/eventi/nuovo');
    Livewire::test(CreateEvent::class)
        ->fillForm(geDatiEvento($this->scenario->category, ['title' => 'Aperitivo con dj set', 'starts_at' => '2026-09-12 19:00:00']))
        ->call('create')
        ->assertHasNoFormErrors();
    $evento = Event::query()->where('title', 'Aperitivo con dj set')->sole();

    $this->get(Dashboard::getUrl())->assertOk();
    Livewire::test(UpcomingEventsWidget::class)
        ->assertSee('Aperitivo con dj set')
        ->assertSee(EventResource::getUrl('view', ['record' => $evento]), false)
        ->assertDontSee($this->scenario->publishedEventB->title);
});

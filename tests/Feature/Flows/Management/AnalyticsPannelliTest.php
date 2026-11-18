<?php

declare(strict_types=1);

use App\DTOs\ConsentState;
use App\Filament\Admin\Pages\EventShareAnalytics as AdminEventShareAnalytics;
use App\Filament\Venue\Pages\Statistics;
use App\Models\EventViewDaily;
use Carbon\Carbon;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;
use Livewire\Livewire;
use Tests\Support\VenueIsolationScenario;

/**
 * Flusso: le visite e i clic misurati sulle schede pubbliche arrivano nelle
 * statistiche dei pannelli. Il gestore del locale vede i propri numeri e solo
 * quelli; l'amministrazione vede l'insieme e lo può filtrare per locale.
 */
beforeEach(function (): void {
    $this->scenario = VenueIsolationScenario::make();
    freezeLocal($this->scenario->city, '2026-09-10 18:00');
});

afterEach(function (): void {
    Carbon::setTestNow();
});

/**
 * Un visitatore che ha accettato le statistiche manda `$times` misure.
 *
 * @param  array<string, int|string>  $parameters
 */
function gstMisura(array $parameters, string $metric, int $times = 1): void
{
    $url = URL::signedRoute('content.metrics', $parameters, absolute: false);
    for ($i = 0; $i < $times; $i++) {
        test()->postJson($url, ['metric' => $metric])->assertNoContent();
    }
}

function gstConsensoStatistiche(): void
{
    $state = new ConsentState('flussi-gestione', config('consent.version'), ['necessary' => true, 'statistics' => true]);
    test()->withCredentials()->withCookie(config('consent.cookie'), $state->encode());
}

it('porta le visite misurate sulla scheda pubblica nelle statistiche del locale, solo a chi lo gestisce', function (): void {
    $s = $this->scenario;
    $eventA = ['type' => 'event', 'id' => $s->publishedEventA->id, 'occurrence' => $s->occurrenceA->id];

    // Senza consenso alle statistiche la visita non lascia traccia.
    gstMisura($eventA, 'views');
    expect(EventViewDaily::query()->count())->toBe(0);

    // Con il consenso: tre visite e un clic sui biglietti all'evento A,
    // due visite al profilo del locale A, cinque visite all'evento di B.
    gstConsensoStatistiche();
    gstMisura($eventA, 'views', 3);
    gstMisura($eventA, 'ticket_clicks');
    gstMisura(['type' => 'venue', 'id' => $s->venueA->id], 'views', 2);
    gstMisura(['type' => 'event', 'id' => $s->publishedEventB->id], 'views', 5);

    // Una misura con firma manomessa viene respinta.
    $this->postJson('/misure/event/'.$s->publishedEventA->id, ['metric' => 'views'])->assertForbidden();

    $rowA = EventViewDaily::query()->where('event_id', $s->publishedEventA->id)->sole();
    expect((int) $rowA->views)->toBe(3)
        ->and((int) $rowA->ticket_clicks)->toBe(1)
        ->and($rowA->date->toDateString())->toBe('2026-09-10');
    $this->assertDatabaseHas('occurrence_views_daily', ['occurrence_id' => $s->occurrenceA->id, 'views' => 3]);
    $this->assertDatabaseHas('profile_views_daily', ['profile_type' => 'venue', 'profile_id' => $s->venueA->id, 'views' => 2]);

    // Il gestore di A apre le statistiche del suo locale.
    $this->actingAs($s->ownerA)->get('/gestione/'.$s->venueA->slug.'/statistiche')->assertOk()
        ->assertSee('Concerto di musica popolare')
        ->assertDontSee('Reading su poesia contemporanea');

    Filament::setCurrentPanel('venue');
    Filament::setTenant($s->venueA);
    $report = Livewire::test(Statistics::class)
        ->assertSee('Concerto di musica popolare')
        ->instance()->report();
    expect($report['totals']['views'])->toBe(3)
        ->and($report['totals']['ticket_clicks'])->toBe(1)
        ->and($report['profile']['views'])->toBe(2);

    // Le statistiche dell'altro locale non gli si aprono.
    $this->get('/gestione/'.$s->venueB->slug.'/statistiche')->assertNotFound();

    // Il gestore di B vede i suoi cinque, non i tre di A.
    $this->actingAs($s->ownerB);
    Filament::setTenant($s->venueB);
    $reportB = Livewire::test(Statistics::class)->instance()->report();
    expect($reportB['totals']['views'])->toBe(5)
        ->and($reportB['profile']['views'])->toBe(0);
});

it('mostra all\'amministrazione le misure di tutti i locali, filtrabili, e le nega a moderatori e gestori di altri locali', function (): void {
    $s = $this->scenario;

    gstConsensoStatistiche();
    gstMisura(['type' => 'event', 'id' => $s->publishedEventA->id], 'views', 4);
    gstMisura(['type' => 'event', 'id' => $s->publishedEventA->id], 'shares');
    gstMisura(['type' => 'event', 'id' => $s->publishedEventB->id], 'views', 2);

    // L'amministratrice apre il cruscotto e la scheda dell'evento A.
    $this->actingAs($s->admin)->get('/admin/statistiche-condivisioni')->assertOk();
    $this->get('/admin/statistiche-condivisioni/event/'.$s->publishedEventA->id)->assertOk()
        ->assertSee('Concerto di musica popolare');

    Filament::setCurrentPanel('admin');
    Filament::setTenant(null);
    $page = Livewire::test(AdminEventShareAnalytics::class);
    $tutti = $page->instance()->dashboard();
    expect($tutti['totals']['views'])->toBe(6)
        ->and($tutti['events']->pluck('event')->all())->toContain('Concerto di musica popolare', 'Reading su poesia contemporanea');

    $soloA = $page->set('filters.venue', $s->venueA->id)->instance()->dashboard();
    expect($soloA['totals']['views'])->toBe(4)
        // Gli eventi del locale A (anche la bozza, che non ha misure), nessuno di B.
        ->and($soloA['events']->pluck('venue')->unique()->values()->all())->toBe(['Circolo Aurora'])
        ->and($soloA['events']->pluck('event')->all())->toContain('Concerto di musica popolare')
        ->and($soloA['events']->pluck('event')->all())->not->toContain('Reading su poesia contemporanea');

    // Il moderatore non vede i numeri commerciali.
    $this->actingAs($s->moderator)->get('/admin/statistiche-condivisioni')->assertForbidden();

    // Il gestore di B non apre la scheda di un evento di A dal proprio pannello.
    $this->actingAs($s->ownerB)
        ->get('/gestione/'.$s->venueB->slug.'/statistiche-condivisioni/event/'.$s->publishedEventA->id)
        ->assertNotFound();
    expect(DB::table('event_views_daily')->sum('views'))->toEqual(6);
});

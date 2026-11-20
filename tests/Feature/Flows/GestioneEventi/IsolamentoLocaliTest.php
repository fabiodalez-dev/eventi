<?php

declare(strict_types=1);

require_once __DIR__.'/SupportoGestioneEventi.php';

use App\Enums\EventStatus;
use App\Enums\OccurrenceStatus;
use App\Enums\UserRole;
use App\Enums\VenueRole;
use App\Filament\Venue\Pages\EventAnalyticsDetail;
use App\Filament\Venue\Pages\Social;
use App\Filament\Venue\Pages\Sponsorships;
use App\Filament\Venue\Pages\Statistics;
use App\Filament\Venue\Resources\Events\Pages\CreateEvent;
use App\Filament\Venue\Resources\Events\Pages\EditEvent;
use App\Filament\Venue\Resources\Events\Pages\ListEvents;
use App\Filament\Venue\Resources\Events\Pages\ViewEvent;
use App\Filament\Venue\Resources\Events\RelationManagers\OccurrencesRelationManager;
use App\Models\Event;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Laravel\Sanctum\Sanctum;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use Tests\Support\VenueIsolationScenario;

/*
 * Protegge il muro fra due locali della stessa città (§18 scenario F): chi
 * gestisce il Circolo Aurora non vede, non apre, non modifica e non misura
 * gli eventi del Teatro Belzoni — né dall'elenco, né scrivendo l'indirizzo a
 * mano, né forzando gli identificatori dentro le chiamate Livewire, né
 * dall'app. Ogni prova controlla anche che l'evento altrui sia rimasto
 * com'era: un 403 che però ha già scritto qualcosa non è un muro.
 */
beforeEach(function (): void {
    $this->scenario = VenueIsolationScenario::make();
    $this->a = $this->scenario->venueA;
    $this->b = $this->scenario->venueB;
    $this->owner = $this->scenario->ownerA;
    $this->eventoB = $this->scenario->publishedEventB;
    $this->dataB = $this->scenario->occurrenceB;
    freezeLocal($this->scenario->city, '2026-09-10 12:00');
});

afterEach(function (): void {
    Filament::setTenant(null);
    Filament::setCurrentPanel(null);
    Carbon::setTestNow();
    CarbonImmutable::setTestNow();
});

it('non apre a mano nessuna pagina di un evento altrui, nemmeno dal proprio locale', function (): void {
    $base = '/gestione/'.$this->a->slug.'/eventi/';
    foreach ([$this->eventoB, $this->scenario->draftEventB] as $altrui) {
        $this->actingAs($this->owner)->get($base.$altrui->getRouteKey())->assertNotFound();
        $this->actingAs($this->owner)->get($base.$altrui->getRouteKey().'/modifica')->assertNotFound();
    }
    // E il locale altrui, intero, non esiste per lui.
    foreach (['', '/eventi', '/eventi/nuovo', '/collaboratori', '/calendario', '/statistiche'] as $pagina) {
        $this->actingAs($this->owner)->get('/gestione/'.$this->b->slug.$pagina)->assertNotFound();
    }
    // Nemmeno le pagine collegate all'evento: statistiche, grafiche, sponsorizzazione, biglietteria.
    geEntra($this->owner, $this->a);
    $this->get(EventAnalyticsDetail::getUrl(['subjectType' => 'event', 'subjectId' => $this->eventoB->id]))->assertNotFound();
    expect($this->get(Social::getUrl(['event' => $this->eventoB->id]))->status())->toBeIn([403, 404]);
    expect($this->get(route('ticketing.manage.show', $this->dataB))->status())->toBeIn([403, 404]);
});

it('mostra nell’elenco solo il proprio cartellone e non agisce sulle righe altrui', function (): void {
    geEntra($this->owner, $this->a, '/eventi');
    $elenco = Livewire::test(ListEvents::class)
        ->assertCanSeeTableRecords([$this->scenario->publishedEventA, $this->scenario->draftEventA])
        ->assertCanNotSeeTableRecords([$this->eventoB, $this->scenario->draftEventB]);

    // Una riga altrui chiamata per id non si trova: l'azione non parte.
    foreach (['duplicate', 'soldOutNextDate'] as $azione) {
        try {
            $elenco->callTableAction($azione, $this->eventoB);
            $this->fail('L’azione '.$azione.' ha accettato una riga di un altro locale.');
        } catch (Exception $fermata) {
            expect($fermata->getMessage())->toContain('no longer exists');
        }
    }

    geFuoriDalPannello();
    expect(Event::query()->where('venue_id', $this->a->id)->count())->toBe(2)
        ->and(Event::query()->where('venue_id', $this->b->id)->count())->toBe(2)
        ->and($this->dataB->fresh()->status)->toBe(OccurrenceStatus::Scheduled);
});

it('non lascia modificare l’evento altrui caricando la scheda con il suo id', function (): void {
    geEntra($this->owner, $this->a, '/eventi');

    Livewire::test(EditEvent::class, ['record' => $this->eventoB->getRouteKey()])->assertNotFound();
    Livewire::test(ViewEvent::class, ['record' => $this->scenario->draftEventB->getRouteKey()])->assertNotFound();

    expect($this->eventoB->fresh()->title)->toBe('Reading su poesia contemporanea');
});

it('non tocca le date altrui nemmeno forzando l’evento nella sezione «Date»', function (): void {
    geEntra($this->owner, $this->a, '/eventi/'.$this->scenario->publishedEventA->getRouteKey().'/modifica');

    $tentativo = function (callable $gesto): void {
        try {
            $gesto();
        } catch (Throwable) {
            // Fermato con un'eccezione: va bene quanto un rifiuto, conta che non abbia scritto.
        }
    };

    $tentativo(fn () => Livewire::test(OccurrencesRelationManager::class, ['ownerRecord' => $this->eventoB, 'pageClass' => EditEvent::class])
        ->callTableAction('cancel_occurrence', $this->dataB));
    $tentativo(fn () => Livewire::test(OccurrencesRelationManager::class, ['ownerRecord' => $this->eventoB, 'pageClass' => EditEvent::class])
        ->callTableAction('edit', $this->dataB, data: ['starts_at' => '2026-12-01 21:00:00']));
    $tentativo(fn () => Livewire::test(OccurrencesRelationManager::class, ['ownerRecord' => $this->eventoB, 'pageClass' => EditEvent::class])
        ->callTableAction('create', data: ['starts_at' => '2026-12-02 21:00:00']));
    // E dalla propria sezione, puntando la riga altrui.
    $tentativo(fn () => geDate($this->scenario->publishedEventA)->callTableAction('delete', $this->dataB));

    $this->dataB->refresh();
    expect($this->dataB->status)->toBe(OccurrenceStatus::Scheduled)
        ->and($this->dataB->starts_at->utc()->format('Y-m-d H:i'))->toBe('2026-09-12 19:00')
        ->and($this->eventoB->occurrences()->count())->toBe(1);
});

it('non si fa riscrivere un evento altrui dal proprio modulo cambiando la bozza in corso', function (): void {
    geEntra($this->owner, $this->a, '/eventi/nuovo');

    expect(fn () => Livewire::test(CreateEvent::class)->set('draftId', $this->scenario->draftEventB->id))
        ->toThrow(CannotUpdateLockedPropertyException::class);

    // E anche se il numero arrivasse, la bozza si rilegge solo fra quelle del locale.
    $pagina = Livewire::test(CreateEvent::class);
    $pagina->instance()->draftId = $this->scenario->draftEventB->id;
    $pagina->instance()->data = [...$pagina->instance()->data, 'title' => 'Titolo dirottato'];
    $pagina->instance()->saveDraft();

    expect($this->scenario->draftEventB->fresh()->title)->toBe('Bozza del teatro Belzoni')
        ->and(Event::query()->where('title', 'Titolo dirottato')->sole()->venue_id)->toBe($this->a->id);
});

it('non mostra numeri né eventi altrui nelle statistiche, nemmeno chiedendoli per id', function (): void {
    geEntra($this->owner, $this->a, '/statistiche');

    Livewire::test(EventAnalyticsDetail::class, ['subjectType' => 'event', 'subjectId' => $this->scenario->publishedEventA->id])
        ->call('goToEvent', $this->eventoB->id)
        ->assertNotFound()
        ->assertNoRedirect();

    Livewire::test(EventAnalyticsDetail::class, ['subjectType' => 'venue', 'subjectId' => $this->b->id])->assertNotFound();
    Livewire::test(EventAnalyticsDetail::class, ['subjectType' => 'event', 'subjectId' => $this->eventoB->id])->assertNotFound();

    // Il selettore degli eventi propone i soli eventi del locale.
    $opzioni = json_encode(Livewire::test(EventAnalyticsDetail::class, ['subjectType' => 'event', 'subjectId' => $this->scenario->draftEventA->id])
        ->instance()->otherEventForm->getComponent('otherEvent')->getOptions());
    expect($opzioni)->toContain('Concerto di musica popolare')
        ->and($opzioni)->not->toContain('Reading su poesia contemporanea')
        ->and($opzioni)->not->toContain('Bozza del teatro Belzoni');

    $this->get(Statistics::getUrl())->assertOk()->assertDontSee('Reading su poesia contemporanea');
});

it('non prepara una sponsorizzazione per l’evento di un altro locale passato nell’indirizzo', function (): void {
    geEntra($this->owner, $this->a, '/eventi');
    $this->get(Sponsorships::getUrl(['event' => $this->eventoB->id]))->assertOk();

    request()->query->set('event', (string) $this->eventoB->id);
    $pagina = Livewire::withQueryParams(['event' => $this->eventoB->id])->test(Sponsorships::class);
    expect($pagina->get('eventId'))->not->toBe($this->eventoB->id);
});

it('chi gestisce due locali vede in ciascuno solo gli eventi di quello', function (): void {
    $this->owner->assignRole(UserRole::VenueOwner->value);
    $this->owner->venues()->attach($this->b, ['role' => VenueRole::Editor->value, 'invited_at' => now(), 'accepted_at' => now()]);
    $this->owner->refresh();

    $this->actingAs($this->owner)->get('/gestione/'.$this->a->slug.'/eventi')->assertOk()
        ->assertSee('Concerto di musica popolare')->assertDontSee('Reading su poesia contemporanea');
    $this->actingAs($this->owner)->get('/gestione/'.$this->b->slug.'/eventi')->assertOk()
        ->assertSee('Reading su poesia contemporanea')->assertDontSee('Concerto di musica popolare');

    // Un evento di A non si apre dall'indirizzo di B, anche se entrambi sono suoi.
    $this->actingAs($this->owner)->get('/gestione/'.$this->b->slug.'/eventi/'.$this->scenario->publishedEventA->getRouteKey().'/modifica')->assertNotFound();

    // Nel locale B è collaboratore: niente collaboratori, ma gli eventi sì.
    $this->actingAs($this->owner)->get('/gestione/'.$this->b->slug.'/collaboratori')->assertForbidden();
});

it('non apre dall’app le date di un altro locale né ne scrive i dettagli', function (): void {
    app('auth')->forgetGuards();
    Sanctum::actingAs($this->owner);

    $elenco = $this->getJson('/api/v1/management/dates')->assertOk()->json('data');
    expect(collect($elenco)->pluck('id')->all())->toContain($this->scenario->occurrenceA->id)
        ->and(collect($elenco)->pluck('id')->all())->not->toContain($this->dataB->id);

    expect($this->getJson('/api/v1/management/dates/'.$this->dataB->id)->status())->toBeIn([403, 404]);
    expect($this->getJson('/api/v1/management/dates/'.$this->dataB->id.'/bookings')->status())->toBeIn([403, 404]);
    expect($this->patchJson('/api/v1/management/dates/'.$this->dataB->id.'/details', [
        'practical_details' => ['entrance_notes' => 'Entrata dal retro'],
        'cost_breakdown' => ['admission' => 99],
    ])->status())->toBeIn([403, 404]);

    expect($this->dataB->fresh()->practical_details['entrance_notes'] ?? null)->toBeNull();
});

it('non pubblica l’evento altrui nemmeno chiamando l’azione della propria scheda', function (): void {
    $this->a->update(['auto_publish' => true]);
    geData($this->scenario->draftEventB, '2026-09-20 21:00');
    geEntra($this->owner, $this->a, '/eventi/'.$this->scenario->draftEventA->getRouteKey());

    try {
        Livewire::test(ViewEvent::class, ['record' => $this->scenario->draftEventA->getRouteKey()])
            ->set('record', $this->scenario->draftEventB->getRouteKey())
            ->callAction('publish');
    } catch (Throwable) {
        // Il blocco del record è il primo muro.
    }

    expect($this->scenario->draftEventB->fresh()->status)->toBe(EventStatus::Draft);
});

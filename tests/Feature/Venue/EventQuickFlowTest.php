<?php

declare(strict_types=1);

use App\Enums\EventStatus;
use App\Enums\PriceType;
use App\Filament\Organizer\Pages\Dashboard as OrganizerDashboard;
use App\Filament\Venue\Pages\Dashboard;
use App\Filament\Venue\Pages\EventAnalyticsDetail;
use App\Filament\Venue\Pages\Social;
use App\Filament\Venue\Pages\Sponsorships;
use App\Filament\Venue\Resources\Events\EventResource;
use App\Filament\Venue\Resources\Events\Pages\CreateEvent;
use App\Filament\Venue\Resources\Events\Pages\ListEvents;
use App\Filament\Venue\Widgets\ShortcutsWidget;
use App\Filament\Venue\Widgets\Support\ManagementContext;
use App\Filament\Venue\Widgets\UpcomingEventsWidget;
use App\Models\Event;
use App\Models\Organizer;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Support\VenueIsolationScenario;

/**
 * Il pannello del locale semplice e collegato: un evento si crea compilando
 * solo il blocco «L'essenziale», e da ogni evento — scheda, riga dell'elenco,
 * riepilogo — si arriva alle pagine che lo riguardano, già filtrate su di
 * lui, solo se chi guarda le può aprire.
 */
beforeEach(function (): void {
    $this->scenario = VenueIsolationScenario::make();
    $this->venue = $this->scenario->venueA;
    freezeLocal($this->scenario->city, '2026-09-10 12:00');
    $this->actingAs($this->scenario->ownerA);
    Filament::setCurrentPanel('venue');
    Filament::setTenant($this->venue);
});

afterEach(function (): void {
    Carbon::setTestNow();
});

it('crea un evento compilando solo il blocco essenziale e apre la sua scheda', function (): void {
    $this->venue->update(['auto_publish' => true]);
    // Prima una GET vera: lo scope del tenant si comporta come nel pannello (§13.5).
    $this->get(EventResource::getUrl('create'))->assertOk();

    $page = Livewire::test(CreateEvent::class)
        ->fillForm([
            'title' => 'Serata rapida',
            'starts_at' => CarbonImmutable::now('Europe/Rome')->addDays(2)->setTime(21, 0)->format('Y-m-d H:i:s'),
            'category_id' => $this->scenario->category->getKey(),
            'price_type' => PriceType::Free->value,
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $event = Event::query()->where('title', 'Serata rapida')->sole();

    expect($event->status)->toBe(EventStatus::Published)
        ->and($event->occurrences()->count())->toBe(1);
    $page->assertRedirect(EventResource::getUrl('view', ['record' => $event]));
});

it('mostra una pagina sola: l’essenziale aperto, il resto in sezioni facoltative, l’anteprima e la bozza', function (): void {
    $this->get(EventResource::getUrl('create'))
        ->assertOk()
        ->assertSee(__('manage.quick.essentials'))
        ->assertSee(__('manage.quick.preview'))
        ->assertSee('data-event-preview', false)
        ->assertSee(__('manage.quick.more'))
        ->assertSee(__('manage.quick.facebook'))
        ->assertSee(__('manage.recurrence.toggle'))
        ->assertSee(__('manage.quick.save_draft'))
        ->assertDontSee('fi-sc-wizard', false);
});

it('salva una bozza dal pulsante, ma solo con un titolo', function (): void {
    Livewire::test(CreateEvent::class)
        ->call('saveDraftNow')
        ->assertNotified(__('manage.quick.draft_needs_title'));

    expect(Event::query()->where('venue_id', $this->venue->id)->where('status', EventStatus::Draft)->count())->toBe(1);

    Livewire::test(CreateEvent::class)
        ->fillForm(['title' => 'Da finire domani'])
        ->call('saveDraftNow')
        ->assertNotified(__('manage.quick.draft_saved'));

    expect(Event::query()->where('title', 'Da finire domani')->sole()->status)->toBe(EventStatus::Draft);
});

it('collega la scheda dell’evento a modifica, statistiche, social, sponsorizzazione, biglietteria e sito', function (): void {
    $event = $this->scenario->publishedEventA;

    $this->get(EventResource::getUrl('view', ['record' => $event]))
        ->assertOk()
        ->assertSee(EventResource::getUrl('edit', ['record' => $event]), false)
        ->assertSee(EventAnalyticsDetail::getUrl(['subjectType' => 'event', 'subjectId' => $event->id]), false)
        ->assertSee(Social::getUrl(['event' => $event->id]), false)
        ->assertSee(Sponsorships::getUrl(['event' => $event->id]), false)
        ->assertSee(route('ticketing.manage.show', $this->scenario->occurrenceA), false)
        ->assertSee(route('events.show', $event), false);

    expect(collect(EventResource::eventLinks($event))->pluck('key')->all())
        ->toBe(['edit', 'statistics', 'social', 'sponsor', 'tickets', 'site']);
});

it('non offre sponsorizzazione e sito per una bozza', function (): void {
    $keys = collect(EventResource::eventLinks($this->scenario->draftEventA))->pluck('key');

    expect($keys)->not->toContain('sponsor')
        ->and($keys)->not->toContain('site');
});

it('non mostra la biglietteria a un collaboratore che non gestisce le prenotazioni', function (): void {
    $this->actingAs($this->scenario->editorA);
    $event = $this->scenario->publishedEventA;

    $this->get(EventResource::getUrl('view', ['record' => $event]))
        ->assertOk()
        ->assertDontSee(route('ticketing.manage.show', $this->scenario->occurrenceA), false);

    expect(collect(EventResource::eventLinks($event))->pluck('key'))->not->toContain('tickets');
});

it('mette le stesse pagine nel menu della riga dell’elenco', function (): void {
    $this->get(EventResource::getUrl('index'))->assertOk();

    Livewire::test(ListEvents::class)
        ->assertTableActionVisible('statistics', $this->scenario->publishedEventA)
        ->assertTableActionVisible('social', $this->scenario->publishedEventA)
        ->assertTableActionVisible('sponsor', $this->scenario->publishedEventA)
        ->assertTableActionVisible('tickets', $this->scenario->publishedEventA)
        ->assertTableActionHidden('sponsor', $this->scenario->draftEventA);
});

it('verifica il permesso della biglietteria una volta per locale, non una per riga dell’elenco', function (): void {
    $queries = function (): int {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->get(EventResource::getUrl('index'))->assertOk();
        $count = collect(DB::getQueryLog())->filter(fn (array $query): bool => str_contains($query['query'], 'venue_user'))->count();
        DB::disableQueryLog();

        return $count;
    };

    $queries(); // la prima richiesta riempie le cache di ruoli e permessi
    $prima = $queries();
    $categoria = testCategory();
    foreach (range(1, 5) as $n) {
        occurrenceAt($this->scenario->city, $categoria, now()->addDays($n)->format('Y-m-d H:i:s'), venue: $this->venue);
    }

    expect($queries())->toBe($prima);
});

it('dal riepilogo porta ai prossimi eventi con la locandina e a tutte le pagine', function (): void {
    $this->get(Dashboard::getUrl())->assertOk()
        // È ancora la pagina delle statistiche del locale (§13.4).
        ->assertSee(__('analytics-dashboard.profile_report'));

    Livewire::test(UpcomingEventsWidget::class)
        ->assertSee($this->scenario->publishedEventA->title)
        ->assertSee('data-panel-event-poster', false)
        ->assertSee(EventResource::getUrl('view', ['record' => $this->scenario->publishedEventA]), false)
        ->assertDontSee($this->scenario->publishedEventB->title);

    Livewire::test(ShortcutsWidget::class)
        ->assertSee(EventResource::getUrl('create'), false)
        ->assertSee(Social::getUrl(), false)
        ->assertSee(Sponsorships::getUrl(), false)
        ->assertSee(route('ticketing.manage.index'), false);
});

it('dà lo stesso riepilogo agli organizzatori, senza le pagine che non hanno', function (): void {
    $organizer = Organizer::create(['city_id' => $this->scenario->city->id, 'owner_id' => $this->scenario->ownerA->id, 'name' => 'Collettivo Rapido', 'is_active' => true]);
    $this->scenario->publishedEventA->update(['organizer_id' => $organizer->id]);
    Filament::setCurrentPanel('organizer');
    Filament::setTenant($organizer);

    $this->get(OrganizerDashboard::getUrl())->assertOk()
        ->assertSee('data-dashboard-shortcuts', false)
        ->assertSee('data-dashboard-upcoming', false)
        ->assertSee($this->scenario->publishedEventA->title);

    $keys = collect(ManagementContext::shortcuts())->flatten(1)->pluck('key');

    expect($keys)->toContain('create')
        ->and($keys)->toContain('Sponsorships')
        ->and($keys)->not->toContain('Social')
        ->and($keys)->not->toContain('tickets');
});

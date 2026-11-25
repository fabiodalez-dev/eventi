<?php

declare(strict_types=1);

use App\Enums\ContentMetric;
use App\Enums\EventStatus;
use App\Enums\StatsPeriod;
use App\Enums\UserRole;
use App\Enums\VenueRole;
use App\Filament\Venue\Pages\EventShareAnalytics;
use App\Http\Controllers\Api\V1\Management\EventAnalyticsController;
use App\Http\Controllers\Api\V1\Management\EventDatesController;
use App\Models\Event;
use App\Models\EventOccurrence;
use App\Models\EventShareLink;
use App\Models\Organizer;
use App\Models\User;
use App\Models\Venue;
use App\Services\Analytics\EventAnalyticsDashboard;
use App\Services\Analytics\EventShares;
use App\Services\Analytics\ManagementAnalytics;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;
use Tests\Support\VenueIsolationScenario;

/*
 * Le statistiche dall'app di gestione (P5): le stesse pagine «Statistiche»,
 * «Statistiche in dettaglio» e la pagina di un soggetto dei pannelli, con gli
 * stessi numeri sugli stessi dati e le stesse regole su chi vede che cosa.
 */
beforeEach(function (): void {
    $this->s = VenueIsolationScenario::make();
    freezeLocal($this->s->city, '2026-09-10 18:00');
    $this->venueUrl = '/api/v1/management/venues/'.$this->s->venueA->id;
    $this->organizer = Organizer::create(['name' => 'Associazione Musica Aperta', 'city_id' => $this->s->city->id, 'owner_id' => User::factory()->create()->id, 'is_active' => true]);
    $this->member = User::factory()->create();
    $this->organizer->users()->attach($this->member);
    $this->organizerUrl = '/api/v1/management/organizers/'.$this->organizer->id;
    $this->s->publishedEventA->update(['organizer_id' => $this->organizer->id]);
    $metrics = array_column(ContentMetric::cases(), 'value');
    foreach ([[$this->s->publishedEventA, 10], [$this->s->publishedEventB, 100]] as [$event, $value]) {
        DB::table('event_views_daily')->insert(['event_id' => $event->id, 'date' => '2026-09-10', ...array_fill_keys($metrics, $value)]);
        app(EventShares::class)->links($event, null);
    }
    foreach (DB::table('event_share_links')->where('event_id', $this->s->publishedEventA->id)->get() as $link) {
        DB::table('event_share_daily')->insert(['share_link_id' => $link->id, 'date' => '2026-09-10', 'shares' => 2, 'clicks' => 3]);
    }
    DB::table('profile_views_daily')->insert(['profile_type' => 'venue', 'profile_id' => $this->s->venueA->id, 'date' => '2026-09-09', 'views' => 7]);
    DB::table('occurrence_views_daily')->insert(['occurrence_id' => $this->s->occurrenceA->id, 'date' => '2026-09-08', ...array_fill_keys($metrics, 4)]);
});

function p5Suspended(VenueIsolationScenario $s): array
{
    $venue = Venue::factory()->suspended()->create(['city_id' => $s->city->id]);
    $owner = User::factory()->create();
    $owner->assignRole(UserRole::VenueOwner->value);
    $owner->venues()->attach($venue, ['role' => VenueRole::Owner->value, 'invited_at' => CarbonImmutable::now(), 'accepted_at' => CarbonImmutable::now()]);

    return [$venue, $owner];
}

// ------------------------------------------------------------ chi vede cosa

it('apre le statistiche a referente, collaboratore e membri dell’organizzazione, a nessun altro', function (): void {
    foreach ([$this->s->ownerA, $this->s->editorA] as $user) {
        Sanctum::actingAs($user);
        $this->getJson($this->venueUrl.'/analytics/summary')->assertOk();
        $this->getJson($this->venueUrl.'/analytics/report')->assertOk();
    }
    foreach ([$this->organizer->owner, $this->member] as $user) {
        Sanctum::actingAs($user);
        $this->getJson($this->organizerUrl.'/analytics/summary')->assertOk();
        $this->getJson($this->organizerUrl.'/analytics/subjects/organizer/'.$this->organizer->id)->assertOk();
    }

    // Personale d'ingresso, estraneo, referente di un altro locale: il soggetto non esiste per loro.
    $staff = User::factory()->create();
    $this->s->occurrenceA->checkinStaff()->attach($staff);
    foreach ([$staff, $this->s->plainUser, $this->s->ownerB] as $user) {
        Sanctum::actingAs($user);
        $this->getJson($this->venueUrl.'/analytics/summary')->assertNotFound();
        $this->getJson($this->venueUrl.'/analytics/report/export?format=csv')->assertNotFound();
        $this->getJson($this->organizerUrl.'/analytics/report')->assertNotFound();
    }

    [$suspended, $owner] = p5Suspended($this->s);
    Sanctum::actingAs($owner);
    $this->getJson('/api/v1/management/venues/'.$suspended->id.'/analytics/summary')->assertNotFound();
    $this->getJson('/api/v1/management/venues/'.$suspended->id.'/analytics/channels')->assertNotFound();
});

it('non apre un evento, un locale o un organizzatore che il soggetto non vede', function (): void {
    Sanctum::actingAs($this->s->editorA);

    $this->getJson($this->venueUrl.'/analytics/subjects/event/'.$this->s->publishedEventB->id)->assertNotFound();
    $this->getJson($this->venueUrl.'/analytics/subjects/venue/'.$this->s->venueB->id)->assertNotFound();
    $this->getJson($this->venueUrl.'/analytics/subjects/event/'.$this->s->publishedEventB->id.'/table?dataset=events')->assertNotFound();
    $this->getJson($this->venueUrl.'/analytics/subjects/event/'.$this->s->publishedEventB->id.'/export?format=csv')->assertNotFound();
    $this->getJson($this->venueUrl.'/events/'.$this->s->publishedEventB->id.'/dates/analytics')->assertNotFound();
    $this->getJson($this->venueUrl.'/events/'.$this->s->publishedEventA->id.'/dates/'.$this->s->occurrenceB->id.'/analytics')->assertNotFound();
    // Il filtro evento di «Statistiche in dettaglio» lo ricontrolla il servizio del pannello: 403 come lì.
    $this->getJson($this->venueUrl.'/analytics/report?event='.$this->s->publishedEventB->id)->assertForbidden();
    // L'organizzatore del locale si apre, un altro no.
    $this->getJson($this->venueUrl.'/analytics/subjects/organizer/'.$this->organizer->id)->assertOk();
    $other = Organizer::create(['name' => 'Altro', 'city_id' => $this->s->city->id, 'owner_id' => $this->s->ownerB->id, 'is_active' => true]);
    $this->getJson($this->venueUrl.'/analytics/subjects/organizer/'.$other->id)->assertNotFound();
    $this->getJson($this->venueUrl.'/analytics/subjects/altro/1')->assertNotFound();
});

it('lascia fuori dalle statistiche dell’organizzazione gli eventi solo co-organizzati, come il pannello', function (): void {
    $this->s->publishedEventB->organizers()->attach($this->organizer);
    Sanctum::actingAs($this->member);

    $events = $this->getJson($this->organizerUrl.'/analytics/events')->assertOk()->json('data');
    expect(collect([...$events['upcoming'], ...$events['past']])->pluck('id')->all())->toBe([$this->s->publishedEventA->id]);
    $this->getJson($this->organizerUrl.'/analytics/subjects/event/'.$this->s->publishedEventB->id)->assertNotFound();
    $this->getJson($this->organizerUrl.'/analytics/report')->assertOk()->assertJsonPath('data.cards.0.value', 10);
    // La scheda dell'evento non offre «Statistiche» su un evento solo co-organizzato.
    $this->getJson($this->organizerUrl.'/events/'.$this->s->publishedEventB->id)->assertOk()->assertJsonPath('data.links.statistics', false);
    $this->getJson($this->organizerUrl.'/events/'.$this->s->publishedEventA->id)->assertOk()->assertJsonPath('data.links.statistics', true);
});

it('non dà le statistiche per data di un evento solo co-organizzato', function (): void {
    $this->s->publishedEventB->organizers()->attach($this->organizer);
    Sanctum::actingAs($this->member);
    $eventB = $this->organizerUrl.'/events/'.$this->s->publishedEventB->id;

    // Le date le vede e le gestisce, come in «Date e luoghi»; i numeri no.
    $this->getJson($eventB.'/dates')->assertOk()->assertJsonPath('data.0.id', $this->s->occurrenceB->id)->assertJsonPath('data.0.stats', null);
    $this->getJson($eventB.'/dates/'.$this->s->occurrenceB->id)->assertOk()->assertJsonPath('data.stats', null);
    $this->getJson($eventB.'/dates/analytics')->assertNotFound();
    $this->getJson($eventB.'/dates/'.$this->s->occurrenceB->id.'/analytics')->assertNotFound();

    // Sull'evento di cui è l'organizzatore principale i numeri ci sono.
    $eventA = $this->organizerUrl.'/events/'.$this->s->publishedEventA->id;
    $this->getJson($eventA.'/dates')->assertOk()->assertJsonPath('data.0.stats.views', 4);
    $this->getJson($eventA.'/dates/analytics')->assertOk()->assertJsonPath('data.0.views', 4);
});

it('manda le date (P2) e le statistiche per data (P5) ciascuna al proprio controller', function (): void {
    $base = $this->venueUrl.'/events/'.$this->s->publishedEventA->id.'/dates/';
    $action = fn (string $url): string => Route::getRoutes()->match(Request::create($url, 'GET'))->getActionName();

    expect($action($base.'analytics'))->toBe(EventAnalyticsController::class.'@dates')
        ->and($action($base.$this->s->occurrenceA->id))->toBe(EventDatesController::class.'@show')
        ->and($action($base.$this->s->occurrenceA->id.'/analytics'))->toBe(EventAnalyticsController::class.'@occurrence');

    Sanctum::actingAs($this->s->ownerA);
    $this->getJson($base.'analytics')->assertOk()->assertJsonPath('data.0.id', $this->s->occurrenceA->id)->assertJsonPath('data.0.views', 4);
    $this->getJson($base.$this->s->occurrenceA->id)->assertOk()->assertJsonPath('data.id', $this->s->occurrenceA->id)->assertJsonPath('data.stats.views', 4);
});

// --------------------------------------------------------------- «Statistiche»

it('dà gli stessi numeri della pagina «Statistiche» del pannello', function (): void {
    Sanctum::actingAs($this->s->editorA);
    $data = $this->getJson($this->venueUrl.'/analytics/summary?period=week')->assertOk()->json('data');

    expect($data['period'])->toBe(['value' => 'week', 'days' => 7, 'from' => '2026-09-04', 'until' => '2026-09-10'])
        ->and(collect($data['periods'])->pluck('value')->all())->toBe(['week', 'month', 'quarter'])
        ->and(collect($data['cards'])->pluck('value', 'key')->all())->toBe(['event_views' => 10, 'profile_views' => 7, 'clicks' => 10 * (count(ContentMetric::cases()) - 2), 'saves' => 0, 'followers' => 0])
        ->and($data['cards'][0]['hint'])->toBe(__('pannelli-statistiche.hints.event_views'))
        ->and($data['empty'])->toBeFalse()
        ->and($data['series'])->toHaveCount(7)
        ->and($data['series'][5])->toBe(['date' => '2026-09-09', 'views' => 0, 'profile_views' => 7, 'clicks' => 0])
        ->and($data['events']['data'])->toHaveCount(1)
        ->and($data['events']['data'][0]['title'])->toBe('Concerto di musica popolare')
        ->and($data['events']['data'][0]['values']['views'])->toBe(10)
        ->and($data['events']['meta'])->toBe(['page' => 1, 'next_page' => null, 'total' => 1])
        ->and($data['shares']['data'])->toHaveCount(count(EventShares::CHANNELS))
        ->and($data['shares']['data'][0]['clicks'])->toBe(3)
        ->and($data['paid'])->toMatchArray(['impressions' => 0, 'clicks' => 0, 'ctr' => null]);

    // Gli stessi numeri del servizio che disegna la pagina, nel pannello del locale.
    $this->actingAs($this->s->editorA, 'web');
    Filament::setCurrentPanel('venue');
    Filament::setTenant($this->s->venueA);
    $panel = app(ManagementAnalytics::class)->report(StatsPeriod::Week);
    expect($data['cards'][0]['value'])->toBe($panel['totals']['views'])
        ->and($data['cards'][1]['value'])->toBe($panel['profile']['views'])
        ->and(array_column($data['series'], 'views'))->toBe(array_column($panel['series'], 'views'));
});

it('rifiuta i periodi che la pagina non ha', function (): void {
    Sanctum::actingAs($this->s->ownerA);
    $this->getJson($this->venueUrl.'/analytics/summary?period=year')->assertUnprocessable()->assertJsonStructure(['error' => ['fields' => ['period']]]);
    $this->getJson($this->venueUrl.'/analytics/report?period=decade')->assertUnprocessable();
    $this->getJson($this->venueUrl.'/analytics/report?period=custom')->assertUnprocessable()->assertJsonStructure(['error' => ['fields' => ['until']]]);
    $this->getJson($this->venueUrl.'/analytics/report?period=custom&from=2026-09-10&until=2026-09-01')->assertUnprocessable();
    $this->getJson($this->venueUrl.'/analytics/report/table?dataset=utenti')->assertUnprocessable();
});

it('mette un tetto alle date a mano: «Al» non oltre oggi e al massimo 731 giorni', function (): void {
    Sanctum::actingAs($this->s->ownerA);
    // Prima del tetto questa domanda costruiva una serie di circa 120.000 mesi.
    $this->getJson($this->venueUrl.'/analytics/report?period=custom&from=0001-01-01&until=9999-12-31')
        ->assertUnprocessable()->assertJsonStructure(['error' => ['fields' => ['until']]]);
    $this->getJson($this->venueUrl.'/analytics/report?period=custom&until=2026-09-11')->assertUnprocessable()->assertJsonStructure(['error' => ['fields' => ['until']]]);
    $this->getJson($this->venueUrl.'/analytics/report?period=custom&from=2024-09-09&until=2026-09-10')->assertUnprocessable()->assertJsonStructure(['error' => ['fields' => ['until']]]);
    $this->getJson($this->venueUrl.'/analytics/report/table?period=custom&from=0001-01-01&until=2026-09-10')->assertUnprocessable();
    $this->getJson($this->venueUrl.'/analytics/report/export?period=custom&from=0001-01-01&until=2026-09-10')->assertUnprocessable();
    $this->getJson($this->venueUrl.'/analytics/subjects/venue/'.$this->s->venueA->id.'?until=9999-12-31')->assertUnprocessable();

    // 731 giorni esatti passano, e la serie resta di 25 mesi.
    $series = $this->getJson($this->venueUrl.'/analytics/report?period=custom&from=2024-09-10&until=2026-09-10')->assertOk()->json('data.series');
    expect($series['granularity'])->toBe('month')->and($series['points'])->toHaveCount(25);
    // «Tutto lo storico» resta senza giorno iniziale.
    $this->getJson($this->venueUrl.'/analytics/report?period=all')->assertOk()->assertJsonPath('data.from', null);

    // Lo stesso tetto vale nel servizio che usano anche i pannelli e le esportazioni.
    $this->actingAs($this->s->ownerA, 'web');
    Filament::setCurrentPanel('venue');
    Filament::setTenant($this->s->venueA);
    expect(fn () => app(EventAnalyticsDashboard::class)->report(['from' => '0001-01-01', 'until' => '9999-12-31']))->toThrow(ValidationException::class);
});

it('tiene «Dal» e «Al» del pannello dentro gli stessi limiti, anche quando arrivano dall’indirizzo', function (): void {
    $this->actingAs($this->s->ownerA, 'web');
    Filament::setCurrentPanel('venue');
    Filament::setTenant($this->s->venueA);

    $page = Livewire::withQueryParams(['filters' => ['from' => '0001-01-01', 'until' => '9999-12-31']])->test(EventShareAnalytics::class)
        ->assertSet('period', 'custom')->assertSet('filters.until', '2026-09-10')->assertSet('filters.from', '2024-09-10');
    expect($page->instance()->dashboard['series']['points'])->toHaveCount(25);

    $page->set('filters.until', '2030-01-01')->assertSet('filters.until', '2026-09-10')
        ->set('filters.from', '2020-01-01')->assertSet('filters.from', '2024-09-10');
});

// --------------------------------------------------- «Statistiche in dettaglio»

it('dà il rapporto di «Statistiche in dettaglio» con i sei numeri e i grafici del pannello', function (): void {
    Sanctum::actingAs($this->s->ownerA);
    $data = $this->getJson($this->venueUrl.'/analytics/report?period=month')->assertOk()->json('data');

    expect($data['period'])->toBe('month')
        ->and($data['from'])->toBe('2026-08-12')
        ->and($data['until'])->toBe('2026-09-10')
        ->and(collect($data['periods'])->pluck('value')->all())->toBe(['week', 'month', 'quarter', 'year', 'all'])
        ->and(collect($data['cards'])->pluck('key')->all())->toBe(['views', 'interactions', 'short_shares', 'short_clicks', 'saves', 'bookings_confirmed'])
        ->and(collect($data['cards'])->pluck('value', 'key')->only(['views', 'short_shares', 'short_clicks'])->all())->toBe(['views' => 10, 'short_shares' => 8, 'short_clicks' => 12])
        ->and(collect($data['charts'])->pluck('key')->all())->toBe(['events', 'channels', 'actions', 'venues', 'organizers', 'community', 'bookings', 'paid'])
        ->and($data['charts'][0]['rows'][0])->toBe(['id' => $this->s->publishedEventA->id, 'name' => 'Concerto di musica popolare', 'value' => 10, 'secondary' => 12])
        ->and($data['charts'][0]['link'])->toBe('event')
        ->and(collect($data['datasets'])->pluck('count', 'key')->all())->toMatchArray(['events' => 2, 'venues' => 1, 'links' => 4])
        ->and(collect($data['channels'])->pluck('value')->all())->toBe(EventShares::CHANNELS)
        ->and($data['series']['granularity'])->toBe('day')
        ->and($data['series']['points'])->toHaveCount(30)
        ->and($data['active_filters'])->toBe([
            ['key' => 'from', 'label' => 'Dal', 'value' => '2026-08-12'],
            ['key' => 'until', 'label' => 'Al', 'value' => '2026-09-10'],
        ]);

    // Lo stesso rapporto che il pannello calcola con gli stessi filtri.
    $this->actingAs($this->s->ownerA, 'web');
    Filament::setCurrentPanel('venue');
    Filament::setTenant($this->s->venueA);
    $panel = Livewire::test(EventShareAnalytics::class)->call('setPeriod', 'month')->instance()->dashboard;
    expect(collect($data['cards'])->pluck('value', 'key')->all())->toEqual(collect($panel['totals'])->only(['views', 'interactions', 'short_shares', 'short_clicks', 'saves', 'bookings_confirmed'])->all());
});

it('filtra per evento e canale e tiene il periodo fra due date a mano', function (): void {
    Sanctum::actingAs($this->s->editorA);
    $data = $this->getJson($this->venueUrl.'/analytics/report?period=custom&from=2026-09-01&until=2026-09-10&event='.$this->s->publishedEventA->id.'&channel=whatsapp')
        ->assertOk()->json('data');

    expect($data['period'])->toBe('custom')
        ->and($data['filters'])->toBe(['event' => $this->s->publishedEventA->id, 'channel' => 'whatsapp'])
        ->and(collect($data['cards'])->pluck('value', 'key')->only(['views', 'short_clicks'])->all())->toBe(['views' => 10, 'short_clicks' => 3])
        ->and($data['selected_event']['title'])->toBe('Concerto di musica popolare')
        ->and(collect($data['active_filters'])->pluck('value', 'key')->all())->toMatchArray(['event' => 'Concerto di musica popolare', 'channel' => 'WhatsApp']);
    $this->getJson($this->venueUrl.'/analytics/report?channel=c-altrui')->assertUnprocessable();
});

it('pagina le tabelle a venti righe con i collegamenti del pannello', function (): void {
    Sanctum::actingAs($this->s->ownerA);
    $events = $this->getJson($this->venueUrl.'/analytics/report/table?dataset=events')->assertOk()->json('data');

    expect($events['dataset'])->toBe('events')
        ->and(collect($events['columns'])->pluck('key')->all())->not->toContain('id', 'venue_id', 'organizer_id')
        ->and($events['columns'][0])->toBe(['key' => 'event', 'label' => 'Evento', 'numeric' => false])
        ->and($events['rows'])->toHaveCount(2)
        ->and($events['rows'][0]['links']['event'])->toBe(['type' => 'event', 'id' => $this->s->publishedEventA->id])
        ->and($events['rows'][0]['links']['venue'])->toBe(['type' => 'venue', 'id' => $this->s->venueA->id])
        ->and($events['rows'][0]['links']['organizer'])->toBe(['type' => 'organizer', 'id' => $this->organizer->id])
        ->and($events['meta'])->toBe(['page' => 1, 'next_page' => null, 'total' => 2])
        ->and($events['unavailable'])->toBe('n.d.');

    $links = $this->getJson($this->venueUrl.'/analytics/report/table?dataset=links&page=1')->assertOk()->json('data');
    expect($links['rows'])->toHaveCount(4)->and(collect($links['columns'])->pluck('key')->all())->toContain('url');
    expect($this->getJson($this->venueUrl.'/analytics/report/table?dataset=links&page=2')->json('data.rows'))->toBe([]);
});

it('scarica la tabella in CSV e il rapporto in Excel, come i due pulsanti del pannello', function (): void {
    Sanctum::actingAs($this->s->editorA);
    $csv = $this->get($this->venueUrl.'/analytics/report/export?format=csv&dataset=events&period=week')->assertOk();
    expect($csv->headers->get('content-type'))->toContain('text/csv')
        ->and($csv->headers->get('content-disposition'))->toContain('incitta-analytics-events-')
        ->and($csv->streamedContent())->toContain('Concerto di musica popolare')->not->toContain('Reading su poesia contemporanea');

    $xlsx = $this->get($this->venueUrl.'/analytics/subjects/event/'.$this->s->publishedEventA->id.'/export?format=xlsx&dataset=links')->assertOk();
    expect($xlsx->headers->get('content-type'))->toContain('spreadsheetml');
    $this->getJson($this->venueUrl.'/analytics/report/export?format=pdf')->assertUnprocessable();
});

// ------------------------------------------------------- la pagina di un soggetto

it('dà la pagina di un evento con testata, rimandi e date, e tiene fuori il filtro evento', function (): void {
    Sanctum::actingAs($this->s->editorA);
    $data = $this->getJson($this->venueUrl.'/analytics/subjects/event/'.$this->s->publishedEventA->id.'?period=week&channel=whatsapp&event='.$this->s->draftEventA->id)
        ->assertOk()->json('data');

    expect($data['subject']['type'])->toBe('event')
        ->and($data['subject']['name'])->toBe('Concerto di musica popolare')
        ->and($data['subject']['title'])->toBe('Statistiche · Concerto di musica popolare')
        ->and($data['subject']['event']['id'])->toBe($this->s->publishedEventA->id)
        ->and($data['subject']['event']['venue'])->toBe('Circolo Aurora')
        ->and(collect($data['subject']['related'])->pluck('type')->all())->toBe(['venue', 'organizer'])
        ->and($data['subject']['related'][0]['label'])->toBe('Statistiche del locale Circolo Aurora')
        ->and($data['subject']['profile_report'])->toBe([])
        ->and($data['filters'])->toBe(['event' => null, 'channel' => 'whatsapp'])
        ->and(collect($data['active_filters'])->pluck('key')->all())->not->toContain('event')
        ->and(collect($data['cards'])->pluck('value', 'key')->only(['views', 'short_clicks'])->all())->toBe(['views' => 10, 'short_clicks' => 3]);

    $dates = $this->getJson($this->venueUrl.'/events/'.$this->s->publishedEventA->id.'/dates/analytics')->assertOk()->json('data');
    expect($dates)->toHaveCount(1)
        ->and($dates[0])->toMatchArray(['id' => $this->s->occurrenceA->id, 'views' => 4, 'clicks' => 4 * (count(ContentMetric::cases()) - 2), 'saves' => 0, 'can_view_report' => true]);
});

it('dà il report del profilo del proprio locale, la prima pagina del pannello', function (): void {
    Sanctum::actingAs($this->s->ownerA);
    $data = $this->getJson($this->venueUrl.'/analytics/subjects/venue/'.$this->s->venueA->id.'?period=week')->assertOk()->json('data');

    expect($data['subject']['event'])->toBeNull()
        ->and($data['subject']['title'])->toBe('Statistiche · Circolo Aurora')
        ->and(collect($data['subject']['profile_report'])->pluck('value', 'key')->only(['profile_views', 'followers'])->all())->toBe(['profile_views' => 7, 'followers' => 0]);

    // L'organizzatore visto dal locale: i totali del suo profilo non si danno (n.d.), come nel pannello.
    $organizer = $this->getJson($this->venueUrl.'/analytics/subjects/organizer/'.$this->organizer->id)->assertOk()->json('data.subject.profile_report');
    expect(collect($organizer)->pluck('value', 'key')->get('followers'))->toBeNull();
});

it('dà le statistiche di una data a chi la può modificare', function (): void {
    Sanctum::actingAs($this->s->editorA);
    $data = $this->getJson($this->venueUrl.'/events/'.$this->s->publishedEventA->id.'/dates/'.$this->s->occurrenceA->id.'/analytics')->assertOk()->json('data');

    expect($data['id'])->toBe($this->s->occurrenceA->id)
        ->and($data['event']['title'])->toBe('Concerto di musica popolare')
        ->and($data['note'])->toBe(__('analytics.occurrence_note'))
        ->and(collect($data['cards'])->pluck('value', 'key')->all())->toBe(['occurrence_views' => 4, 'occurrence_clicks' => 4 * (count(ContentMetric::cases()) - 2), 'occurrence_saves' => 0])
        ->and($data['series'])->toHaveCount(30)
        ->and($data['series'][27])->toBe(['date' => '2026-09-08', 'views' => 4, 'clicks' => 4 * (count(ContentMetric::cases()) - 2)]);
});

it('sceglie gli eventi con locandina, data e locale, divisi in prossimi e passati', function (): void {
    EventOccurrence::factory()->create(['event_id' => $this->s->draftEventA->id, 'starts_at' => CarbonImmutable::parse('2026-09-20 19:00', 'UTC'), 'ends_at' => null]);
    $this->s->occurrenceA->update(['starts_at' => CarbonImmutable::parse('2026-09-01 19:00', 'UTC')]);
    Sanctum::actingAs($this->s->editorA);

    $data = $this->getJson($this->venueUrl.'/analytics/events')->assertOk()->json('data');
    expect(collect($data['upcoming'])->pluck('id')->all())->toBe([$this->s->draftEventA->id])
        ->and(collect($data['past'])->pluck('id')->all())->toBe([$this->s->publishedEventA->id])
        ->and($data['upcoming'][0])->toMatchArray(['title' => 'Bozza del circolo Aurora', 'starts_at' => '2026-09-20T19:00:00+00:00', 'timezone' => 'Europe/Rome', 'past' => false, 'more' => 0, 'venue' => 'Circolo Aurora'])
        ->and($data['past'][0]['past'])->toBeTrue();
    expect(collect($this->getJson($this->venueUrl.'/analytics/events?q=Concerto')->json('data.past'))->pluck('id')->all())->toBe([$this->s->publishedEventA->id]);
});

it('dà i link brevi tracciati di un evento pubblicato, non di una bozza', function (): void {
    Sanctum::actingAs($this->s->editorA);
    $links = $this->getJson($this->venueUrl.'/events/'.$this->s->publishedEventA->id.'/share-links')->assertOk()->json('data');
    expect($links['available'])->toBeTrue()
        ->and(collect($links['links'])->pluck('channel')->all())->toBe(EventShares::CHANNELS)
        ->and($links['links'][1]['label'])->toBe('WhatsApp')
        ->and($links['links'][1]['url'])->toContain('/s/');

    $this->getJson($this->venueUrl.'/events/'.$this->s->draftEventA->id.'/share-links')->assertOk()->assertJsonPath('data.available', false)->assertJsonPath('data.links', []);
    expect(EventShareLink::query()->where('event_id', $this->s->draftEventA->id)->exists())->toBeFalse();
});

// --------------------------------------------------------------- canali propri

it('lascia i canali propri al solo referente del locale', function (): void {
    Sanctum::actingAs($this->s->editorA);
    $this->getJson($this->venueUrl.'/analytics/channels')->assertForbidden();
    $this->postJson($this->venueUrl.'/analytics/channels', ['label' => 'Volantino'])->assertForbidden();

    // L'organizzazione non ha la sezione: la rotta non esiste.
    Sanctum::actingAs($this->member);
    $this->getJson($this->organizerUrl.'/analytics/channels')->assertNotFound();

    Sanctum::actingAs($this->s->ownerA);
    $list = $this->getJson($this->venueUrl.'/analytics/channels')->assertOk()->json();
    // I quattro link di sistema della scheda del locale ci sono dal primo accesso.
    expect($list['data'])->toHaveCount(count(EventShares::CHANNELS))
        ->and($list['data'][0]['target'])->toBe('Circolo Aurora')
        ->and($list['meta'])->toBe(['max' => EventShares::MAX_CUSTOM, 'custom' => 0]);

    $created = $this->postJson($this->venueUrl.'/analytics/channels', ['label' => 'Volantino estate', 'event' => $this->s->publishedEventA->id])
        ->assertCreated()->assertJsonPath('message', 'Canale «Volantino Estate» creato.')->json();
    $row = collect($created['data'])->firstWhere('code', $created['created']);
    expect($row)->toMatchArray(['channel' => 'Volantino Estate', 'target' => 'Concerto di musica popolare', 'shares' => 0, 'clicks' => 0])
        ->and($created['meta']['custom'])->toBe(1);
});

it('rifiuta un canale senza nome, su un evento altrui o oltre il tetto, con il messaggio del pannello', function (): void {
    Sanctum::actingAs($this->s->ownerA);
    $this->postJson($this->venueUrl.'/analytics/channels', ['label' => '!'])->assertUnprocessable()
        ->assertJsonPath('error.fields.label.0', __('event-shares.custom.invalid'));
    $this->postJson($this->venueUrl.'/analytics/channels', ['label' => 'Radio', 'event' => $this->s->publishedEventB->id])->assertUnprocessable()
        ->assertJsonStructure(['error' => ['fields' => ['event']]]);
    $this->postJson($this->venueUrl.'/analytics/channels', ['label' => str_repeat('a', 39)])->assertUnprocessable();
    foreach (range(1, EventShares::MAX_CUSTOM) as $index) {
        app(EventShares::class)->addChannel($this->s->venueA, 'Canale '.$index);
    }
    $this->postJson($this->venueUrl.'/analytics/channels', ['label' => 'Ancora uno'])->assertUnprocessable()
        ->assertJsonPath('error.fields.label.0', __('event-shares.custom.limit', ['count' => EventShares::MAX_CUSTOM]));
});

it('vede i canali propri nel filtro del locale e non quelli di un altro', function (): void {
    app(EventShares::class)->addChannel($this->s->venueB, 'Radio del teatro');
    app(EventShares::class)->addChannel($this->s->venueA, 'Volantino');
    Sanctum::actingAs($this->s->editorA);

    $channels = collect($this->getJson($this->venueUrl.'/analytics/report')->assertOk()->json('data.channels'))->pluck('label', 'value');
    expect($channels->get('c-volantino'))->toBe('Volantino')->and($channels->has('c-radio-del-teatro'))->toBeFalse();
});

// ------------------------------------------------------------- testo condiviso

it('scrive nell’app la stessa card «Cosa contano questi numeri» del pannello', function (): void {
    $xml = simplexml_load_file(base_path('android/app/src/main/res/values/management_analytics.xml'));
    $strings = [];
    foreach ($xml->string as $string) {
        $strings[(string) $string['name']] = str_replace(["\\'", '\\"'], ["'", '"'], (string) $string);
    }

    expect($strings['analytics_consent_title'] ?? null)->toBe(__('panel.analytics.consent_title'))
        ->and($strings['analytics_consent_links'] ?? null)->toBe(__('panel.analytics.consent_links'))
        ->and($strings['analytics_consent_views'] ?? null)->toBe(__('panel.analytics.consent_views'));
});

it('lascia invariata la regola dei periodi del pannello dopo l’estrazione', function (): void {
    $this->actingAs($this->s->ownerA, 'web');
    Filament::setCurrentPanel('venue');
    Filament::setTenant($this->s->venueA);
    $page = Livewire::test(EventShareAnalytics::class);
    $page->call('setPeriod', 'year')->assertSet('period', 'year')->assertSet('filters.from', '2025-09-11')->assertSet('filters.until', '2026-09-10');
    $page->call('setPeriod', 'all')->assertSet('filters.from', null);
    $page->call('setPeriod', 'altro')->assertSet('period', 'month')->assertSet('filters.from', '2026-08-12');
    $page->set('channelLabel', '!')->call('addChannel')->assertNotified(__('event-shares.custom.invalid'));
    expect(app(EventAnalyticsDashboard::class)->events()->pluck('id')->all())->toContain($this->s->publishedEventA->id);
});

it('non mostra una bozza fuori dal soggetto nemmeno come scelta', function (): void {
    $foreign = Event::factory()->create(['city_id' => $this->s->city->id, 'category_id' => $this->s->category->id, 'venue_id' => $this->s->venueB->id, 'status' => EventStatus::Draft, 'title' => 'Bozza altrui']);
    Sanctum::actingAs($this->s->ownerA);
    $data = $this->getJson($this->venueUrl.'/analytics/events?q=Bozza')->assertOk()->json('data');
    expect(collect([...$data['upcoming'], ...$data['past']])->pluck('id')->all())->not->toContain($foreign->id);
});

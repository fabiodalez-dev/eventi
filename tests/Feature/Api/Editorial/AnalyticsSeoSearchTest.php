<?php

declare(strict_types=1);

use App\Enums\ContentMetric;
use App\Enums\UserRole;
use App\Filament\Admin\Pages\EventAnalyticsDetail;
use App\Filament\Admin\Pages\EventShareAnalytics;
use App\Filament\Admin\Pages\SeoOverview;
use App\Filament\Admin\Resources\SearchMisses\Pages\ManageSearchMisses;
use App\Models\City;
use App\Models\Organizer;
use App\Models\SearchMiss;
use App\Models\User;
use App\Services\Analytics\EventShares;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\Support\EditorialSession;
use Tests\Support\VenueIsolationScenario;

/*
 * R10 — Statistiche, SEO, ricerche nell'app della redazione (R-ST-01…06):
 * «Statistiche condivisioni» con filtri, tabelle ed esportazioni, la pagina
 * di un soggetto, «SEO e qualità del catalogo» con il codice di Search
 * Console, le ricerche senza risultati. Gli stessi numeri del pannello sugli
 * stessi dati, gli stessi permessi per ruolo: statistiche e SEO ad admin e
 * super admin, le ricerche anche al moderatore.
 */
beforeEach(function (): void {
    Notification::fake();
    $this->s = VenueIsolationScenario::make();
    freezeLocal($this->s->city, '2026-09-10 18:00');
    $this->superAdmin = EditorialSession::user(UserRole::SuperAdmin);
    $this->outsiders = [$this->s->ownerA, $this->s->plainUser];
    $this->organizer = Organizer::create(['name' => 'Associazione Musica Aperta', 'city_id' => $this->s->city->id, 'owner_id' => User::factory()->create()->id, 'is_active' => true]);
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
});

afterEach(function (): void {
    Model::preventLazyLoading(false);
});

/** Lo stesso controllo per ogni indirizzo: fuori la gente comune (404), chiuso senza sblocco (423). */
function r10Gate(mixed $test, string $method, string $url, array $body = []): void
{
    foreach ($test->outsiders as $outsider) {
        EditorialSession::as($test, EditorialSession::token($outsider))->json($method, $url, $body)->assertNotFound();
    }
    EditorialSession::as($test, EditorialSession::token($test->s->moderator, unlocked: false))->json($method, $url, $body)
        ->assertStatus(423)->assertJsonPath('error.code', 'EDITORIAL_LOCKED');
    EditorialSession::as($test, EditorialSession::token($test->s->admin, unlockedHoursAgo: 12))->json($method, $url, $body)
        ->assertStatus(423)->assertJsonPath('error.code', 'EDITORIAL_LOCKED');
}

/** La richiesta con un token sbloccato della persona. */
function r10As(mixed $test, User $user): mixed
{
    return EditorialSession::as($test, EditorialSession::token($user));
}

/**
 * Il pannello come lo vede la persona: dimentica il token dell'app e la
 * guardia di Sanctum delle richieste di prima, poi entra dal sito.
 */
function r10Panel(mixed $test, User $user): void
{
    $test->flushHeaders();
    app('auth')->forgetGuards();
    $test->actingAs($user, 'web');
    Filament::setCurrentPanel('admin');
}

/** Una ricerca senza risultati nel registro. */
function r10Miss(City $city, string $term, int $count, string $last, string $first = '2026-08-01 10:00'): SearchMiss
{
    return SearchMiss::query()->create([
        'city_id' => $city->id,
        'term' => $term,
        'count' => $count,
        'first_searched_at' => CarbonImmutable::parse($first, 'Europe/Rome')->utc(),
        'last_searched_at' => CarbonImmutable::parse($last, 'Europe/Rome')->utc(),
    ]);
}

// ------------------------------------------------------------ chi vede cosa

it('apre statistiche e SEO solo ad admin e super admin, come il pannello', function (): void {
    $event = $this->s->publishedEventA->id;
    $reads = [
        '/api/v1/editorial/analytics/report',
        '/api/v1/editorial/analytics/report/table?dataset=venues',
        '/api/v1/editorial/analytics/subjects/event/'.$event,
        '/api/v1/editorial/analytics/subjects/venue/'.$this->s->venueA->id.'/table',
        '/api/v1/editorial/analytics/events',
        '/api/v1/editorial/analytics/venues',
        '/api/v1/editorial/analytics/organizers',
        '/api/v1/editorial/seo',
        '/api/v1/editorial/seo/audit',
    ];
    foreach ($reads as $url) {
        r10Gate($this, 'GET', $url);
        // Il moderatore è nella redazione ma la pagina non è sua: 403, come `canAccess`.
        r10As($this, $this->s->moderator)->getJson($url)->assertForbidden()->assertJsonPath('error.code', 'FORBIDDEN');
        foreach ([$this->s->admin, $this->superAdmin] as $staff) {
            r10As($this, $staff)->getJson($url)->assertOk();
        }
    }
    foreach (['/api/v1/editorial/analytics/report/export?format=csv', '/api/v1/editorial/analytics/subjects/event/'.$event.'/export?format=xlsx'] as $url) {
        r10Gate($this, 'GET', $url);
        r10As($this, $this->s->moderator)->getJson($url)->assertForbidden();
        r10As($this, $this->s->admin)->get($url)->assertOk();
    }
    r10Gate($this, 'PUT', '/api/v1/editorial/seo/verification', ['city_id' => $this->s->city->id, 'verification' => 'abc']);
    r10As($this, $this->s->moderator)->putJson('/api/v1/editorial/seo/verification', ['city_id' => $this->s->city->id, 'verification' => 'abc'])->assertForbidden();
    expect(City::query()->find($this->s->city->id)->seo['google_verification'] ?? null)->toBeNull();

    // Il pannello dice lo stesso: il moderatore non apre le due pagine.
    r10Panel($this, $this->s->moderator);
    $this->get(EventShareAnalytics::getUrl(panel: 'admin'))->assertForbidden();
    $this->get(SeoOverview::getUrl(panel: 'admin'))->assertForbidden();
});

it('apre le ricerche senza risultati a moderatore, admin e super admin', function (): void {
    $miss = r10Miss($this->s->city, 'burraco', 4, '2026-09-09 21:15');
    r10Gate($this, 'GET', '/api/v1/editorial/search-misses');
    r10Gate($this, 'DELETE', '/api/v1/editorial/search-misses/'.$miss->id);
    r10Gate($this, 'POST', '/api/v1/editorial/search-misses/bulk-delete', ['ids' => [$miss->id]]);
    foreach ([$this->s->moderator, $this->s->admin, $this->superAdmin] as $staff) {
        r10As($this, $staff)->getJson('/api/v1/editorial/search-misses')->assertOk()
            ->assertJsonPath('data.0.term', 'burraco')
            ->assertJsonPath('data.0.abilities.remove', true);
    }
    expect(SearchMiss::query()->count())->toBe(1);
});

// --------------------------------------------------- «Statistiche condivisioni»

it('dà gli stessi numeri della pagina «Statistiche condivisioni» del pannello', function (): void {
    $data = r10As($this, $this->s->admin)->getJson('/api/v1/editorial/analytics/report?period=month')->assertOk()->json('data');

    expect($data['period'])->toBe('month')
        ->and($data['from'])->toBe('2026-08-12')
        ->and($data['until'])->toBe('2026-09-10')
        ->and(collect($data['periods'])->pluck('value')->all())->toBe(['week', 'month', 'quarter', 'year', 'all'])
        ->and(collect($data['cards'])->pluck('key')->all())->toBe(['views', 'interactions', 'short_shares', 'short_clicks', 'saves', 'bookings_confirmed'])
        // Tutti gli eventi della piattaforma, non quelli di un locale.
        ->and(collect($data['cards'])->pluck('value', 'key')->only(['views', 'short_shares', 'short_clicks'])->all())->toBe(['views' => 110, 'short_shares' => 8, 'short_clicks' => 12])
        ->and(collect($data['charts'])->pluck('key')->all())->toBe(['events', 'channels', 'actions', 'venues', 'organizers', 'community', 'bookings', 'paid'])
        ->and($data['charts'][0]['rows'][0])->toBe(['id' => $this->s->publishedEventB->id, 'name' => 'Reading su poesia contemporanea', 'value' => 100, 'secondary' => 0])
        ->and(collect($data['datasets'])->pluck('count', 'key')->all())->toMatchArray(['events' => 4, 'venues' => 2, 'organizers' => 1, 'links' => 8])
        ->and($data['filters'])->toBe(['event' => null, 'channel' => null, 'venue' => null, 'organizer' => null]);

    // Lo stesso rapporto che il pannello calcola con gli stessi filtri.
    r10Panel($this, $this->s->admin);
    $this->get(EventShareAnalytics::getUrl(panel: 'admin'))->assertOk();
    $panel = Livewire::test(EventShareAnalytics::class)->call('setPeriod', 'month')->instance()->dashboard;
    expect(collect($data['cards'])->pluck('value', 'key')->all())->toEqual(collect($panel['totals'])->only(['views', 'interactions', 'short_shares', 'short_clicks', 'saves', 'bookings_confirmed'])->all())
        ->and(collect($data['datasets'])->pluck('count', 'key')->all())->toEqual(collect(['events', 'venues', 'organizers', 'channels', 'daily', 'links', 'occurrences'])->mapWithKeys(fn ($key) => [$key => $panel[$key]->count()])->all());
});

it('filtra per locale, organizzatore, evento e canale e ricontrolla i filtri come il pannello', function (): void {
    $venue = r10As($this, $this->s->admin)->getJson('/api/v1/editorial/analytics/report?period=custom&from=2026-09-01&until=2026-09-10&venue='.$this->s->venueA->id)
        ->assertOk()->json('data');
    expect($venue['period'])->toBe('custom')
        ->and($venue['filters'])->toMatchArray(['venue' => $this->s->venueA->id, 'organizer' => null])
        ->and(collect($venue['cards'])->pluck('value', 'key')->only(['views', 'short_clicks'])->all())->toBe(['views' => 10, 'short_clicks' => 12])
        ->and(collect($venue['active_filters'])->pluck('value', 'key')->all())->toMatchArray(['venue' => 'Circolo Aurora']);

    $organizer = r10As($this, $this->s->admin)->getJson('/api/v1/editorial/analytics/report?organizer='.$this->organizer->id.'&channel=whatsapp')->assertOk()->json('data');
    expect(collect($organizer['cards'])->pluck('value', 'key')->only(['views', 'short_clicks'])->all())->toBe(['views' => 10, 'short_clicks' => 3])
        ->and(collect($organizer['active_filters'])->pluck('value', 'key')->all())->toMatchArray(['organizer' => 'Associazione Musica Aperta', 'channel' => 'WhatsApp']);

    $event = r10As($this, $this->superAdmin)->getJson('/api/v1/editorial/analytics/report?event='.$this->s->publishedEventB->id)->assertOk()->json('data');
    expect($event['selected_event']['title'])->toBe('Reading su poesia contemporanea')
        ->and(collect($event['cards'])->pluck('value', 'key')->get('views'))->toBe(100);

    // Un locale che non c'è: 403 dal servizio del pannello; un canale sconosciuto o date fuori tetto: 422.
    r10As($this, $this->s->admin)->getJson('/api/v1/editorial/analytics/report?venue=999999')->assertForbidden();
    r10As($this, $this->s->admin)->getJson('/api/v1/editorial/analytics/report?channel=sconosciuto')->assertUnprocessable();
    r10As($this, $this->s->admin)->getJson('/api/v1/editorial/analytics/report?period=custom&from=2026-09-01&until=2026-09-20')->assertUnprocessable();
    r10As($this, $this->s->admin)->getJson('/api/v1/editorial/analytics/report?period=decade')->assertUnprocessable();

    // Il pannello, con lo stesso filtro sul locale, dà gli stessi numeri.
    r10Panel($this, $this->s->admin);
    $panel = Livewire::test(EventShareAnalytics::class)->set('filters.venue', $this->s->venueA->id)->set('filters.from', '2026-09-01')->set('filters.until', '2026-09-10')->instance()->dashboard;
    expect(collect($venue['cards'])->pluck('value', 'key')->all())->toEqual(collect($panel['totals'])->only(['views', 'interactions', 'short_shares', 'short_clicks', 'saves', 'bookings_confirmed'])->all());
});

it('pagina le tabelle a venti righe e scarica CSV ed Excel come i pulsanti del pannello', function (): void {
    $events = r10As($this, $this->s->admin)->getJson('/api/v1/editorial/analytics/report/table?dataset=events')->assertOk()->json('data');
    expect($events['dataset'])->toBe('events')
        ->and(collect($events['columns'])->pluck('key')->all())->not->toContain('id', 'venue_id', 'organizer_id')
        ->and($events['rows'])->toHaveCount(4)
        ->and($events['rows'][0]['cells'][0])->toBe('Reading su poesia contemporanea')
        ->and($events['rows'][0]['links']['venue'])->toBe(['type' => 'venue', 'id' => $this->s->venueB->id])
        ->and($events['meta'])->toBe(['page' => 1, 'next_page' => null, 'total' => 4]);
    $venues = r10As($this, $this->s->admin)->getJson('/api/v1/editorial/analytics/report/table?dataset=venues')->assertOk()->json('data');
    expect(collect($venues['rows'])->pluck('links.name.type')->unique()->all())->toBe(['venue']);
    r10As($this, $this->s->admin)->getJson('/api/v1/editorial/analytics/report/table?dataset=persone')->assertUnprocessable();

    $csv = r10As($this, $this->s->admin)->get('/api/v1/editorial/analytics/report/export?format=csv&dataset=events&venue='.$this->s->venueA->id)->assertOk();
    expect($csv->headers->get('content-type'))->toContain('text/csv')
        ->and($csv->headers->get('cache-control'))->toContain('no-store')
        ->and($csv->streamedContent())->toContain('Concerto di musica popolare')->not->toContain('Reading su poesia contemporanea');
    $xlsx = r10As($this, $this->superAdmin)->get('/api/v1/editorial/analytics/report/export?format=xlsx&dataset=links')->assertOk();
    expect($xlsx->headers->get('content-type'))->toContain('spreadsheetml');
    r10As($this, $this->s->admin)->getJson('/api/v1/editorial/analytics/report/export?format=pdf')->assertUnprocessable();
});

// ------------------------------------------------------- la pagina di un soggetto

it('dà la pagina di un evento, di un locale e di un organizzatore come il pannello', function (): void {
    $event = r10As($this, $this->s->admin)->getJson('/api/v1/editorial/analytics/subjects/event/'.$this->s->publishedEventA->id.'?period=week&venue='.$this->s->venueB->id)
        ->assertOk()->json('data');
    expect($event['subject']['type'])->toBe('event')
        ->and($event['subject']['title'])->toContain('Concerto di musica popolare')
        ->and(collect($event['subject']['related'])->pluck('type')->all())->toBe(['venue', 'organizer'])
        // Locale ed evento della rotta: un filtro «Locale» arrivato con la richiesta non sposta il soggetto.
        ->and(collect($event['cards'])->pluck('value', 'key')->get('views'))->toBe(10)
        ->and(collect($event['active_filters'])->pluck('key')->all())->not->toContain('venue', 'event');

    $venue = r10As($this, $this->s->admin)->getJson('/api/v1/editorial/analytics/subjects/venue/'.$this->s->venueA->id.'?period=month')->assertOk()->json('data');
    // La redazione vede il report del profilo di ogni locale, come `ownProfile` nel pannello.
    expect(collect($venue['subject']['profile_report'])->pluck('value', 'key')->get('profile_views'))->toBe(7);

    $organizer = r10As($this, $this->superAdmin)->getJson('/api/v1/editorial/analytics/subjects/organizer/'.$this->organizer->id)->assertOk()->json('data');
    expect($organizer['subject']['name'])->toBe('Associazione Musica Aperta');

    r10As($this, $this->s->admin)->getJson('/api/v1/editorial/analytics/subjects/event/999999')->assertNotFound();
    r10As($this, $this->s->admin)->getJson('/api/v1/editorial/analytics/subjects/altro/1')->assertNotFound();

    // Lo stesso report del pannello per lo stesso soggetto.
    r10Panel($this, $this->s->admin);
    $panel = Livewire::test(EventAnalyticsDetail::class, ['subjectType' => 'venue', 'subjectId' => $this->s->venueA->id])->instance()->dashboard;
    expect(collect($venue['cards'])->pluck('value', 'key')->all())->toEqual(collect($panel['totals'])->only(['views', 'interactions', 'short_shares', 'short_clicks', 'saves', 'bookings_confirmed'])->all());
});

it('dà le scelte dei filtri con le query del pannello', function (): void {
    $events = r10As($this, $this->s->admin)->getJson('/api/v1/editorial/analytics/events?venue='.$this->s->venueA->id)->assertOk()->json('data');
    $titles = collect([...$events['upcoming'], ...$events['past']])->pluck('title')->all();
    expect($titles)->toContain('Concerto di musica popolare', 'Bozza del circolo Aurora')->not->toContain('Reading su poesia contemporanea');

    $other = r10As($this, $this->s->admin)->getJson('/api/v1/editorial/analytics/events?exclude='.$this->s->publishedEventA->id.'&q=Concerto')->assertOk()->json('data');
    expect(collect([...$other['upcoming'], ...$other['past']])->pluck('id')->all())->not->toContain($this->s->publishedEventA->id);

    expect(r10As($this, $this->s->admin)->getJson('/api/v1/editorial/analytics/venues?q=belz')->assertOk()->json('data'))
        ->toBe([['id' => $this->s->venueB->id, 'name' => 'Teatro Belzoni']])
        ->and(r10As($this, $this->s->admin)->getJson('/api/v1/editorial/analytics/organizers')->assertOk()->json('data'))
        ->toBe([['id' => $this->organizer->id, 'name' => 'Associazione Musica Aperta']]);
});

it('legge «oggi» nel fuso della città di lavoro', function (): void {
    $tokyo = City::factory()->create(['slug' => 'tokyo', 'name' => 'Tokyo', 'timezone' => 'Asia/Tokyo']);
    // Alle 18 a Padova a Tokyo è già l'11 settembre.
    $data = r10As($this, $this->s->admin)->getJson('/api/v1/editorial/analytics/report?period=week&city='.$tokyo->slug)->assertOk()->json('data');
    expect($data['until'])->toBe('2026-09-11');
});

// ------------------------------------------------------------ SEO

it('dà la pagina SEO con il modulo di Search Console, i passi, gli strumenti e il controllo del pannello', function (): void {
    $this->s->city->update(['seo' => ['title' => 'Città e cultura', 'google_verification' => 'codice-di-prova']]);
    $data = r10As($this, $this->s->admin)->getJson('/api/v1/editorial/seo')->assertOk()->json('data');
    expect($data['search_console']['default_city_id'])->toBe(City::query()->first()->id)
        ->and($data['search_console']['cities'][0])->toMatchArray(['id' => $this->s->city->id, 'verification' => 'codice-di-prova'])
        ->and($data['search_console']['max'])->toBe(255)
        ->and(collect($data['tools']['links'])->pluck('key')->all())->toBe(['sitemap', 'search_console', 'rich_results', 'schema', 'pagespeed'])
        ->and($data['steps']['items'][3]['code'])->toBe(route('sitemap.index'))
        ->and(collect($data['audit']['tabs'])->pluck('key')->all())->toBe(['issues', 'all']);

    $issues = r10As($this, $this->s->admin)->getJson('/api/v1/editorial/seo/audit')->assertOk()->json();
    $all = r10As($this, $this->s->admin)->getJson('/api/v1/editorial/seo/audit?tab=all&per_page=1')->assertOk()->json();
    expect($all['meta']['total'])->toBe(2)
        ->and($all['meta']['per_page'])->toBe(1)
        ->and($all['meta']['last_page'])->toBe(2)
        ->and($all['data'][0])->toHaveKeys(['key', 'title', 'url', 'issues', 'complete'])
        ->and($issues['meta']['per_page'])->toBe(20)
        ->and(collect($issues['data'])->every(fn (array $row): bool => $row['issues'] !== [] && $row['complete'] === false))->toBeTrue();

    // Gli stessi conteggi delle schede del pannello.
    r10Panel($this, $this->s->admin);
    Livewire::test(SeoOverview::class)->assertViewHas('counts', $issues['meta']['tabs']);
});

it('salva il codice di Search Console con le regole del pannello, senza toccare il resto', function (): void {
    $this->s->city->update(['seo' => ['title' => 'Città e cultura']]);
    $response = r10As($this, $this->s->admin)->putJson('/api/v1/editorial/seo/verification', ['city_id' => $this->s->city->id, 'verification' => 'Google-test_token-123'])
        ->assertOk()->assertJsonPath('data.verification', 'Google-test_token-123')->assertJsonPath('meta.message', __('seo.search_console.saved'));
    expect($this->s->city->fresh()->seo)->toBe(['title' => 'Città e cultura', 'google_verification' => 'Google-test_token-123']);
    $this->get('/')->assertOk()->assertSee('<meta name="google-site-verification" content="Google-test_token-123">', false);

    r10As($this, $this->superAdmin)->putJson('/api/v1/editorial/seo/verification', ['city_id' => $this->s->city->id, 'verification' => '<meta>'])
        ->assertUnprocessable()->assertJsonPath('error.code', 'VALIDATION_FAILED')->assertJsonStructure(['error' => ['fields' => ['verification']]]);
    r10As($this, $this->superAdmin)->putJson('/api/v1/editorial/seo/verification', ['city_id' => $this->s->city->id, 'verification' => str_repeat('a', 256)])->assertUnprocessable();
    r10As($this, $this->superAdmin)->putJson('/api/v1/editorial/seo/verification', ['city_id' => 999999, 'verification' => 'abc'])->assertUnprocessable();

    // Vuoto: lo toglie.
    r10As($this, $this->superAdmin)->putJson('/api/v1/editorial/seo/verification', ['city_id' => $this->s->city->id, 'verification' => ''])->assertOk()->assertJsonPath('data.verification', null);
    expect($this->s->city->fresh()->seo)->toBe(['title' => 'Città e cultura', 'google_verification' => null]);
    expect($response->json('data.id'))->toBe($this->s->city->id);
});

it('lascia il modulo SEO del pannello com’era dopo l’estrazione', function (): void {
    r10Panel($this, $this->s->admin);
    $this->s->city->update(['seo' => ['title' => 'Città e cultura', 'google_verification' => 'vecchio']]);
    Livewire::test(SeoOverview::class)->assertSchemaStateSet(['city_id' => City::query()->first()->id], 'form')
        ->fillForm(['city_id' => $this->s->city->id, 'verification' => '<meta>'])->call('save')->assertHasFormErrors(['verification'])
        ->fillForm(['city_id' => $this->s->city->id, 'verification' => 'nuovo-codice'])->call('save')->assertHasNoFormErrors();
    expect($this->s->city->fresh()->seo)->toBe(['title' => 'Città e cultura', 'google_verification' => 'nuovo-codice']);
});

// ------------------------------------------------------- ricerche senza risultati

it('elenca le ricerche senza risultati con ricerca, filtro città e ordinamento della tabella', function (): void {
    $other = City::factory()->create(['slug' => 'vicenza', 'name' => 'Vicenza']);
    r10Miss($this->s->city, 'burraco', 4, '2026-09-09 21:15');
    r10Miss($this->s->city, 'karaoke', 9, '2026-09-01 10:00', '2026-07-01 09:00');
    r10Miss($other, 'burraco', 2, '2026-09-10 08:00');

    Model::preventLazyLoading();
    $data = r10As($this, $this->s->moderator)->getJson('/api/v1/editorial/search-misses')->assertOk()->json();
    // Predefinito: «Quante volte», dal più cercato.
    expect(collect($data['data'])->pluck('count')->all())->toBe([9, 4, 2])
        ->and($data['data'][1])->toMatchArray([
            'term' => 'burraco', 'count' => 4,
            'last_searched_label' => '09/09/2026 21:15', 'first_searched_label' => '01/08/2026',
            'city' => ['id' => $this->s->city->id, 'name' => 'Padova'],
        ])
        ->and($data['meta']['total'])->toBe(3)
        ->and(collect($data['choices']['cities'])->pluck('label')->all())->toBe(['Padova', 'Vicenza']);

    expect(r10As($this, $this->s->admin)->getJson('/api/v1/editorial/search-misses?q=burr&filter[city]='.$other->id)->json('data.*.count'))->toBe([2])
        ->and(r10As($this, $this->s->admin)->getJson('/api/v1/editorial/search-misses?sort=-last_searched_at')->json('data.*.count'))->toBe([2, 4, 9])
        ->and(r10As($this, $this->s->admin)->getJson('/api/v1/editorial/search-misses?sort=first_searched_at')->json('data.*.term'))->toBe(['karaoke', 'burraco', 'burraco'])
        ->and(r10As($this, $this->s->admin)->getJson('/api/v1/editorial/search-misses?per_page=2&page=2')->json('meta'))->toMatchArray(['current_page' => 2, 'last_page' => 2, 'per_page' => 2]);

    // La tabella del pannello mostra le stesse righe.
    r10Panel($this, $this->s->moderator);
    Livewire::test(ManageSearchMisses::class)->assertOk()->assertCanSeeTableRecords(SearchMiss::query()->get());
});

it('toglie una ricerca o un gruppo senza chiedere la password, come «Elimina» del pannello', function (): void {
    $a = r10Miss($this->s->city, 'burraco', 4, '2026-09-09 21:15');
    $b = r10Miss($this->s->city, 'karaoke', 9, '2026-09-01 10:00');
    $c = r10Miss($this->s->city, 'tombola', 1, '2026-09-01 10:00');

    // Non è un'azione pericolosa: basta lo sblocco, anche con la password di 20 minuti fa.
    EditorialSession::as($this, EditorialSession::token($this->s->moderator, passwordMinutesAgo: 20))
        ->deleteJson('/api/v1/editorial/search-misses/'.$a->id)->assertOk()->assertJsonPath('data.removed', true);
    expect(SearchMiss::query()->whereKey($a->id)->exists())->toBeFalse();
    r10As($this, $this->s->moderator)->deleteJson('/api/v1/editorial/search-misses/'.$a->id)->assertNotFound();

    r10As($this, $this->s->admin)->postJson('/api/v1/editorial/search-misses/bulk-delete', ['ids' => [$b->id, $c->id, 999999]])
        ->assertOk()->assertJsonPath('data.removed', 2);
    expect(SearchMiss::query()->count())->toBe(0);

    r10As($this, $this->s->admin)->postJson('/api/v1/editorial/search-misses/bulk-delete', ['ids' => []])->assertUnprocessable();
    r10As($this, $this->s->admin)->postJson('/api/v1/editorial/search-misses/bulk-delete', ['ids' => range(1, 201)])->assertUnprocessable();
    r10As($this, $this->s->admin)->postJson('/api/v1/editorial/search-misses/bulk-delete', ['ids' => ['a']])->assertUnprocessable();
});

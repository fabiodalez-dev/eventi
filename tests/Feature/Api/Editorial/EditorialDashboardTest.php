<?php

declare(strict_types=1);

use App\Enums\EventStatus;
use App\Enums\ImportRunStatus;
use App\Enums\PriceType;
use App\Enums\ReportReason;
use App\Enums\ReportStatus;
use App\Enums\SponsorshipPlacement;
use App\Enums\SponsorshipStatus;
use App\Enums\UserRole;
use App\Enums\VenueStatus;
use App\Filament\Admin\Pages\Dashboard;
use App\Filament\Admin\Resources\Events\EventResource;
use App\Filament\Admin\Resources\ImportSources\ImportSourceResource;
use App\Filament\Admin\Resources\Reports\ReportResource;
use App\Filament\Admin\Resources\Users\UserResource;
use App\Filament\Admin\Resources\VenueApplications\VenueApplicationResource;
use App\Filament\Admin\Resources\Venues\VenueResource;
use App\Filament\Admin\Widgets\ContentQualityWidget;
use App\Filament\Admin\Widgets\EditorialQueueWidget;
use App\Filament\Admin\Widgets\NewRegistrationsWidget;
use App\Filament\Admin\Widgets\PromotionSummary;
use App\Filament\Admin\Widgets\PublishingWidget;
use App\Filament\Admin\Widgets\ReturnRatesWidget;
use App\Filament\Admin\Widgets\SponsorshipHealthWidget;
use App\Filament\Admin\Widgets\SponsorshipTrendWidget;
use App\Models\City;
use App\Models\Event;
use App\Models\EventOccurrence;
use App\Models\ImportSource;
use App\Models\Report;
use App\Models\Sponsorship;
use App\Models\SponsorshipDailyStat;
use App\Models\SponsorshipGrant;
use App\Models\User;
use App\Models\Venue;
use App\Models\VenueApplication;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\Support\EditorialSession;
use Tests\Support\VenueIsolationScenario;

/*
 * R1 — il riepilogo della redazione nell'app (R-DB-01…09). La griglia dei
 * ruoli, le sezioni che ogni ruolo vede (le stesse dei `canView()` dei
 * widget, nello stesso ordine della pagina del pannello) e gli stessi
 * numeri, frasi, colori e destinazioni dei riquadri sullo stesso seme.
 */
beforeEach(function (): void {
    Notification::fake();
    $this->s = VenueIsolationScenario::make();
    $this->superAdmin = EditorialSession::user(UserRole::SuperAdmin);
});

afterEach(function (): void {
    Model::preventLazyLoading(false);
});

/** Il riepilogo come lo riceve l'app, con un token sbloccato. */
function deskDashboard(mixed $test, User $user, string $query = ''): array
{
    return EditorialSession::as($test, EditorialSession::token($user))
        ->getJson('/api/v1/editorial/dashboard'.$query)->assertOk()->json('data');
}

/** Le sezioni per chiave. */
function deskSections(array $data): array
{
    return collect($data['sections'])->keyBy('key')->all();
}

/** I riquadri di un widget del pannello, nella forma di confronto. */
function deskPanelStats(string $widget): array
{
    $stats = (fn () => $this->getStats())->call(new $widget);

    return array_map(static fn (Stat $stat): array => [
        'label' => (string) $stat->getLabel(),
        'value' => (string) $stat->getValue(),
        'description' => $stat->getDescription() === null ? null : (string) $stat->getDescription(),
        'tone' => $stat->getColor(),
        'url' => $stat->getUrl(),
    ], array_values($stats));
}

/** I riquadri di una sezione dell'API, nella stessa forma. */
function deskApiStats(array $section): array
{
    return array_map(static fn (array $stat): array => [
        'label' => $stat['label'],
        'value' => $stat['value'],
        'description' => $stat['description'],
        'tone' => $stat['tone'],
        'url' => $stat['target'] === null ? null : url($stat['target']['web_path']),
    ], $section['stats']);
}

/** Il titolo e la frase di un widget del pannello. */
function deskPanelHeading(string $widget): array
{
    $instance = new $widget;

    return [(fn () => $this->getHeading())->call($instance), (fn () => $this->getDescription())->call($instance)];
}

function deskActAsPanel(User $user): void
{
    test()->actingAs($user);
    Filament::setCurrentPanel('admin');
}

/** Un po' di tutto nella città: code, pubblicazioni, qualità, import, campagne. */
function deskSeed(VenueIsolationScenario $s): void
{
    $base = ['city_id' => $s->city->id, 'category_id' => $s->category->id, 'venue_id' => $s->venueA->id, 'description' => 'Una descrizione.', 'price_type' => PriceType::Free, 'poster' => 'locandina.jpg'];
    Event::factory()->create([...$base, 'status' => EventStatus::Pending]);
    Event::factory()->create([...$base, 'status' => EventStatus::Pending, 'description' => null]);
    Event::factory()->create([...$base, 'status' => EventStatus::Cancelled]);
    Event::factory()->create([...$base, 'status' => EventStatus::Published, 'published_at' => now(), 'poster' => null]);
    Venue::factory()->create(['city_id' => $s->city->id, 'status' => VenueStatus::Pending]);
    VenueApplication::factory()->create();
    Report::factory()->create(['reportable_id' => $s->publishedEventA->id, 'status' => ReportStatus::Pending, 'reason' => ReportReason::WrongInfo]);
    Report::factory()->create(['reportable_id' => $s->publishedEventA->id, 'status' => ReportStatus::Resolved, 'reason' => ReportReason::Spam]);
    ImportSource::factory()->create(['city_id' => $s->city->id, 'is_active' => true, 'last_status' => ImportRunStatus::Failed->value]);
    User::factory()->unverified()->create(['name' => 'Persona In Attesa', 'created_at' => now()->subDay()]);

    // Una campagna che si vede: evento pubblicato con una data ancora da venire.
    EventOccurrence::factory()->create(['event_id' => $s->publishedEventA->id, 'starts_at' => now()->addDays(2), 'ends_at' => now()->addDays(2)->addHours(2)]);
    $campaign = Sponsorship::factory()->create(['event_id' => $s->publishedEventA->id, 'city_id' => $s->city->id, 'status' => SponsorshipStatus::Active,
        'starts_at' => now()->subDay(), 'ends_at' => now()->addDays(3), 'placement' => SponsorshipPlacement::ListTop]);
    SponsorshipDailyStat::query()->create(['sponsorship_id' => $campaign->id, 'day' => CarbonImmutable::now('Europe/Rome')->toDateString(), 'impressions' => 400, 'clicks' => 3]);
    SponsorshipGrant::factory()->create(['venue_id' => $s->venueA->id]);
}

it('apre il riepilogo solo alla redazione sbloccata', function (): void {
    $this->getJson('/api/v1/editorial/dashboard')->assertUnauthorized();

    foreach ([$this->s->ownerA, $this->s->editorA, $this->s->plainUser] as $outsider) {
        EditorialSession::as($this, EditorialSession::token($outsider))->getJson('/api/v1/editorial/dashboard')
            ->assertNotFound()->assertJsonPath('error.code', 'NOT_FOUND');
    }

    EditorialSession::as($this, EditorialSession::token($this->s->moderator, unlocked: false))->getJson('/api/v1/editorial/dashboard')
        ->assertStatus(423)->assertJsonPath('error.code', 'EDITORIAL_LOCKED');
    EditorialSession::as($this, EditorialSession::token($this->s->admin, unlockedHoursAgo: 12))->getJson('/api/v1/editorial/dashboard')
        ->assertStatus(423)->assertJsonPath('error.code', 'EDITORIAL_LOCKED');

    // Una lettura: la password recente non serve.
    EditorialSession::as($this, EditorialSession::token($this->s->moderator, passwordMinutesAgo: 30))->getJson('/api/v1/editorial/dashboard')->assertOk();

    foreach ([$this->s->moderator, $this->s->admin, $this->superAdmin] as $staff) {
        EditorialSession::as($this, EditorialSession::token($staff))->getJson('/api/v1/editorial/dashboard')
            ->assertOk()->assertHeader('Cache-Control', 'max-age=0, no-store, private')
            ->assertJsonPath('data.city.slug', $this->s->city->slug);
    }
});

it('dà a ogni ruolo le sezioni che il pannello gli mostra, nello stesso ordine', function (): void {
    /* Widget del riepilogo portati da R1 → sezione dell'API. «Social di oggi» e il calendario sono di R9 e R14. */
    $sections = [
        EditorialQueueWidget::class => 'queue', PublishingWidget::class => 'publishing', ContentQualityWidget::class => 'quality',
        NewRegistrationsWidget::class => 'registrations', ReturnRatesWidget::class => 'returns', PromotionSummary::class => 'promotion',
        SponsorshipHealthWidget::class => 'sponsorships', SponsorshipTrendWidget::class => 'sponsorship_trend',
    ];

    foreach ([$this->s->moderator, $this->s->admin, $this->superAdmin] as $staff) {
        deskActAsPanel($staff);
        $panel = [];
        foreach ((new Dashboard)->getWidgets() as $widget) {
            $class = is_string($widget) ? $widget : $widget->widget;
            if (isset($sections[$class]) && $class::canView()) {
                $panel[] = $sections[$class];
            }
        }

        expect(array_column(deskDashboard($this, $staff)['sections'], 'key'))->toBe($panel);
    }

    // Il moderatore non ha le abilitazioni e i pagamenti; le due sezioni delle campagne sì, come nel pannello.
    $moderator = array_column(deskDashboard($this, $this->s->moderator)['sections'], 'key');
    expect($moderator)->toBe(['queue', 'publishing', 'quality', 'registrations', 'returns', 'sponsorships', 'sponsorship_trend'])
        ->and(array_column(deskDashboard($this, $this->s->admin)['sections'], 'key'))
        ->toBe(['queue', 'publishing', 'quality', 'registrations', 'returns', 'promotion', 'sponsorships', 'sponsorship_trend']);

    // Nel pannello il moderatore apre il riepilogo e il riquadro delle campagne (caricato a parte, `isLazy`) gli si disegna.
    // Le richieste con il token hanno lasciato `sanctum` come guardia predefinita: il pannello usa `web`.
    $this->actingAs($this->s->moderator, 'web')->get('/admin')->assertOk();
    deskActAsPanel($this->s->moderator);
    expect(SponsorshipHealthWidget::canView())->toBeTrue()->and(PromotionSummary::canView())->toBeFalse();
    Livewire::test(SponsorshipHealthWidget::class)->assertOk()
        ->assertSee(__('sponsorships.widgets.heading_city', ['city' => $this->s->city->name]))
        ->assertSee(__('sponsorships.widgets.running'));
});

it('ha gli stessi numeri, frasi, colori e destinazioni dei riquadri del pannello', function (): void {
    deskSeed($this->s);
    Model::preventLazyLoading();

    foreach ([$this->s->admin, $this->s->moderator] as $staff) {
        $api = deskSections(deskDashboard($this, $staff));
        deskActAsPanel($staff);

        foreach ([EditorialQueueWidget::class => 'queue', PublishingWidget::class => 'publishing', ContentQualityWidget::class => 'quality',
            ReturnRatesWidget::class => 'returns', SponsorshipHealthWidget::class => 'sponsorships'] as $widget => $key) {
            expect(deskApiStats($api[$key]))->toBe(deskPanelStats($widget));
            [$title, $description] = deskPanelHeading($widget);
            expect($api[$key]['title'])->toBe($title)->and($api[$key]['description'])->toBe($description);
        }
    }

    $api = deskSections(deskDashboard($this, $this->s->admin));
    deskActAsPanel($this->s->admin);
    expect(deskApiStats($api['promotion']))->toBe(deskPanelStats(PromotionSummary::class))
        ->and($api['promotion']['title'])->toBe(deskPanelHeading(PromotionSummary::class)[0]);

    // I numeri del seme, scritti a mano.
    $queue = collect($api['queue']['stats'])->keyBy('key');
    expect($queue['pending_events']['count'])->toBe(2)->and($queue['pending_events']['tone'])->toBe('warning')
        ->and($queue['pending_venues']['count'])->toBe(1)
        ->and($queue['pending_applications']['count'])->toBe(1)
        ->and($queue['open_reports']['count'])->toBe(1)->and($queue['open_reports']['tone'])->toBe('danger');
    $sponsorships = collect($api['sponsorships']['stats'])->keyBy('key');
    expect($sponsorships['running']['value'])->toBe('1')
        ->and($sponsorships['week_impressions']['value'])->toBe('400')
        ->and($sponsorships['week_rate']['value'])->toBe('0,8%')->and($sponsorships['week_rate']['tone'])->toBe('warning');
});

it('porta ogni riquadro all’elenco filtrato del pannello, con i filtri per l’app', function (): void {
    $api = deskSections(deskDashboard($this, $this->s->admin));
    $targets = collect($api)->flatMap(fn (array $section): array => $section['stats'] ?? [])->keyBy('key')->map(fn (array $stat): ?array => $stat['target']);

    /* Gli indirizzi scritti come nei widget prima dell'estrazione. */
    $panel = [
        'pending_events' => EventResource::getUrl('index', ['filters' => ['status' => ['value' => EventStatus::Pending->value]]]),
        'pending_venues' => VenueResource::getUrl('index', ['filters' => ['status' => ['value' => VenueStatus::Pending->value]]]),
        'pending_applications' => VenueApplicationResource::getUrl('index', ['filters' => ['status' => ['value' => 'pending']]]),
        'open_reports' => ReportResource::getUrl('index', ['filters' => ['open' => ['isActive' => true]]]),
        'published_today' => EventResource::getUrl('index', ['filters' => ['published_today' => ['isActive' => true]]]),
        'published_this_week' => EventResource::getUrl('index', ['filters' => ['published_this_week' => ['isActive' => true]]]),
        'cancelled_events' => EventResource::getUrl('index', ['filters' => ['status' => ['value' => EventStatus::Cancelled->value]]]),
        'missing_poster' => EventResource::getUrl('index', ['filters' => ['missing_poster' => ['isActive' => true]]]),
        'incomplete' => EventResource::getUrl('index', ['filters' => ['incomplete' => ['isActive' => true]]]),
        'possible_duplicates' => EventResource::getUrl('index', ['filters' => ['possible_duplicates' => ['isActive' => true]]]),
        'inactive_venues' => VenueResource::getUrl('index', ['filters' => ['inactive' => ['isActive' => true]]]),
        'import_failures' => ImportSourceResource::getUrl('index', ['filters' => ['failed' => ['isActive' => true]]]),
    ];
    foreach ($panel as $key => $url) {
        expect(url($targets[$key]['web_path']))->toBe($url);
    }

    expect($targets['pending_events'])->toMatchArray(['area' => 'events', 'tab' => null, 'filters' => ['status' => 'pending']])
        ->and($targets['open_reports'])->toMatchArray(['area' => 'reports', 'filters' => ['open' => '1']])
        ->and($targets['import_failures'])->toMatchArray(['area' => 'import_sources', 'filters' => ['failed' => '1']])
        ->and($targets['pending_applications']['area'])->toBe('applications')
        // In programma oggi non apre un elenco; i numeri di ritorno e delle campagne nemmeno.
        ->and($targets['scheduled_today'])->toBeNull()
        ->and($targets['returning'])->toBeNull()
        ->and($targets['running'])->toBeNull();

    $links = collect($api['registrations']['links'])->keyBy('key');
    expect(url($links['pending']['target']['web_path']))->toBe(UserResource::getUrl('index', ['tab' => 'pending']))
        ->and(url($links['all']['target']['web_path']))->toBe(UserResource::getUrl('index'))
        ->and($links['pending']['target']['tab'])->toBe('pending');

    // `filters` vuoto è un oggetto, non una lista.
    $raw = EditorialSession::as($this, EditorialSession::token($this->s->admin))->getJson('/api/v1/editorial/dashboard')->getContent();
    expect($raw)->toContain('"filters":{}');
});

it('elenca le ultime iscrizioni e apre la modifica solo a chi può modificare', function (): void {
    $waiting = User::factory()->unverified()->create(['name' => 'Persona In Attesa']);
    $this->travel(1)->minute();
    $nameless = User::factory()->create(['name' => '', 'email' => 'senza.nome@example.test']);

    $admin = deskSections(deskDashboard($this, $this->s->admin))['registrations'];
    deskActAsPanel($this->s->admin);
    $recent = NewRegistrationsWidget::LIMIT;

    expect($admin['title'])->toBe(__('users.widget.heading'))
        ->and($admin['rows'])->toHaveCount($recent)
        ->and($admin['rows'][0]['name'])->toBe('senza.nome@example.test')
        ->and($admin['rows'][0]['detail'])->toBe(__('users.no_name'))
        ->and($admin['rows'][1]['id'])->toBe($waiting->id)
        ->and($admin['rows'][1]['state'])->toMatchArray(['value' => 'pending', 'label' => __('users.registration.states.pending'), 'tone' => 'warning'])
        ->and($admin['rows'][1]['abilities']['update'])->toBeTrue()
        ->and(url($admin['rows'][1]['web_path']))->toBe(UserResource::getUrl('edit', ['record' => $waiting]))
        ->and($admin['counts']['pending'])->toBeGreaterThanOrEqual(1)
        ->and($admin['links'][0]['badge'])->toBe($admin['counts']['pending']);

    // La frase è quella del widget.
    Livewire::test(NewRegistrationsWidget::class)->assertSee($admin['description']);

    // Il moderatore legge, ma nessuna riga si apre (nel pannello `recordUrl` è nullo).
    $moderator = deskSections(deskDashboard($this, $this->s->moderator))['registrations'];
    expect(collect($moderator['rows'])->pluck('abilities.update')->unique()->all())->toBe([false])
        ->and(collect($moderator['rows'])->pluck('web_path')->unique()->all())->toBe([null]);
});

it('disegna l’andamento delle campagne con la stessa serie del grafico del pannello', function (): void {
    deskSeed($this->s);
    $trend = deskSections(deskDashboard($this, $this->s->moderator))['sponsorship_trend'];
    deskActAsPanel($this->s->moderator);
    $panel = (fn () => $this->getData())->call(new SponsorshipTrendWidget);

    expect($trend['chart']['labels'])->toBe($panel['labels'])
        ->and($trend['chart']['series'][0]['values'])->toBe($panel['datasets'][0]['data'])
        ->and($trend['chart']['series'][1]['values'])->toBe($panel['datasets'][1]['data'])
        ->and($trend['chart']['days'])->toHaveCount(30)
        ->and(array_sum($trend['chart']['series'][0]['values']))->toBe(400)
        ->and($trend['title'])->toBe((new SponsorshipTrendWidget)->getHeading());
});

it('lavora sulla città chiesta, anche spenta, e senza città lascia solo le iscrizioni', function (): void {
    $vicenza = City::factory()->create(['slug' => 'vicenza', 'name' => 'Vicenza', 'is_active' => false, 'timezone' => 'Europe/Rome']);
    Event::factory()->create(['city_id' => $vicenza->id, 'category_id' => $this->s->category->id, 'status' => EventStatus::Pending]);
    Event::factory()->create(['city_id' => $this->s->city->id, 'category_id' => $this->s->category->id, 'status' => EventStatus::Pending]);
    Event::factory()->create(['city_id' => $this->s->city->id, 'category_id' => $this->s->category->id, 'status' => EventStatus::Pending]);

    $padova = deskSections(deskDashboard($this, $this->s->moderator));
    $other = deskDashboard($this, $this->s->moderator, '?city=vicenza');
    expect($padova['queue']['stats'][0]['count'])->toBe(2)
        ->and($other['city']['slug'])->toBe('vicenza')
        ->and($other['city']['is_active'])->toBeFalse()
        ->and(deskSections($other)['queue']['stats'][0]['count'])->toBe(1)
        ->and(deskSections($other)['publishing']['stats'][0]['description'])->toContain('Vicenza');

    EditorialSession::as($this, EditorialSession::token($this->s->moderator))->getJson('/api/v1/editorial/dashboard?city=non-esiste')->assertNotFound();
});

it('senza nessuna città non disegna i riquadri legati alla città', function (): void {
    // Come nel test del pannello: una città con contenuti non si cancella, va svuotata prima.
    Sponsorship::query()->forceDelete();
    Event::query()->forceDelete();
    Venue::query()->forceDelete();
    City::query()->delete();

    $data = deskDashboard($this, $this->s->admin);
    expect($data['city'])->toBeNull()
        ->and(array_column($data['sections'], 'key'))->toBe(['registrations']);
});

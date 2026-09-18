<?php

declare(strict_types=1);

use App\Enums\ContentMetric;
use App\Filament\Admin\Pages\EventAnalyticsDetail;
use App\Filament\Admin\Pages\EventShareAnalytics;
use App\Filament\Venue\Pages\Dashboard;
use App\Filament\Venue\Resources\Events\EventResource;
use App\Filament\Venue\Resources\Events\Pages\EditEvent;
use App\Filament\Venue\Resources\Events\Pages\ViewEvent;
use App\Models\EventOccurrence;
use App\Models\Organizer;
use App\Models\Sponsorship;
use App\Models\SponsorshipGrant;
use App\Services\Analytics\EventAnalyticsDashboard;
use App\Services\Analytics\EventAnalyticsExport;
use App\Services\Analytics\EventShares;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\VenueIsolationScenario;

beforeEach(function (): void {
    $this->scenario = VenueIsolationScenario::make();
    freezeLocal($this->scenario->city, '2026-09-10 18:00');
    Filament::setCurrentPanel('admin');
    Filament::setTenant(null);
    $this->actingAs($this->scenario->admin);
    $this->filters = ['from' => '2026-09-01', 'until' => '2026-09-10'];
    $this->service = app(EventAnalyticsDashboard::class);
    $this->organizer = Organizer::create(['name' => 'Associazione Musica Aperta', 'city_id' => $this->scenario->city->id, 'owner_id' => $this->scenario->ownerA->id, 'is_active' => true]);
    $this->scenario->publishedEventA->update(['organizer_id' => $this->organizer->id]);
    foreach ([$this->scenario->publishedEventA, $this->scenario->publishedEventB] as $index => $event) {
        DB::table('event_views_daily')->insert(['event_id' => $event->id, 'date' => '2026-09-10', ...array_fill_keys(array_column(ContentMetric::cases(), 'value'), $index === 0 ? 10 : 100)]);
        app(EventShares::class)->links($event, null);
    }
    $links = DB::table('event_share_links')->where('event_id', $this->scenario->publishedEventA->id)->get();
    foreach ($links as $link) {
        DB::table('event_share_daily')->insert(['share_link_id' => $link->id, 'date' => '2026-09-10', 'shares' => 2, 'clicks' => 3]);
    }
    DB::table('profile_views_daily')->insert(['profile_type' => 'organizer', 'profile_id' => $this->organizer->id, 'date' => '2026-09-10', 'views' => 90]);
});

it('filters by named venues and organizers without multiplying event totals by share channels', function (): void {
    $report = $this->service->report($this->filters + ['venue' => $this->scenario->venueA->id, 'organizer' => $this->organizer->id]);
    expect($report['events'])->toHaveCount(1)
        ->and($report['totals']['views'])->toBe(10)
        ->and($report['totals']['interactions'])->toBe(80)
        ->and($report['totals']['short_shares'])->toBe(8)
        ->and($report['totals']['short_clicks'])->toBe(12)
        ->and($report['events']->first()['venue'])->toBe('Circolo Aurora')
        ->and($report['filter_labels']['venue'])->toBe('Circolo Aurora')
        ->and($report['filter_labels']['organizer'])->toBe('Associazione Musica Aperta');
    $channel = $this->service->report($this->filters + ['event' => $this->scenario->publishedEventA->id, 'channel' => 'whatsapp']);
    expect($channel['totals']['short_clicks'])->toBe(3)->and($channel['totals']['views'])->toBe(10)->and($channel['links'])->toHaveCount(1);
    expect($this->service->report($this->filters + ['venue' => $this->scenario->venueB->id, 'organizer' => $this->organizer->id])['events'])->toBeEmpty();
});

it('shows names in every selectable filter and table without technical ID columns', function (): void {
    $page = Livewire::test(EventShareAnalytics::class)
        ->set('filters.venue', $this->scenario->venueA->id)
        ->set('filters.organizer', $this->organizer->id)
        ->assertSee('Circolo Aurora')->assertSee('Associazione Musica Aperta')
        ->assertDontSee('ID locale')->assertDontSee('ID organizzatore');
    $form = $page->instance()->getSchema('form');
    foreach (['venue' => 'Circolo Aurora', 'organizer' => 'Associazione Musica Aperta'] as $name => $label) {
        $select = collect($form->getFlatComponents())->first(fn ($component) => $component->getName() === $name);
        expect($select->getOptionLabel())->toBe($label);
    }
    $page->call('setDataset', 'venues')->assertSee('Circolo Aurora');
    expect($page->instance()->detailUrl('venue', $this->scenario->venueA->id))->toContain('/venue/'.$this->scenario->venueA->id);
});

it('isolates tenant event data and withholds other profile totals', function (): void {
    Filament::setCurrentPanel('venue');
    Filament::setTenant($this->scenario->venueA);
    $this->actingAs($this->scenario->ownerA);
    $report = $this->service->report($this->filters);
    expect($report['totals']['views'])->toBe(10)
        ->and($report['organizers']->first()['profile_views'])->toBeNull()
        ->and($report['venues']->pluck('name')->all())->toBe(['Circolo Aurora']);
    expect(fn () => $this->service->report($this->filters + ['venue' => $this->scenario->venueB->id]))->toThrow(HttpException::class);
    expect(fn () => app(EventAnalyticsExport::class)->download($this->filters + ['event' => $this->scenario->publishedEventB->id], 'csv', 'events'))->toThrow(HttpException::class);
    Filament::setCurrentPanel('organizer');
    Filament::setTenant($this->organizer);
    $report = $this->service->report($this->filters);
    expect($report['totals']['views'])->toBe(10)->and($report['organizers']->first()['profile_views'])->toBe(90)
        ->and($report['venues']->first()['profile_views'])->toBeNull();
});

it('uses city midnight for activity timestamps and fills missing trend days', function (): void {
    DB::table('saved_events')->insert(['user_id' => $this->scenario->plainUser->id, 'occurrence_id' => $this->scenario->occurrenceA->id, 'created_at' => '2026-08-31 22:30:00']);
    $report = $this->service->report($this->filters);
    expect($report['totals']['saves'])->toBe(1)->and($report['series']['points'])->toHaveCount(10)
        ->and($report['series']['points'][0]['views'])->toBe(0);
    expect($this->service->report(['from' => '2026-09-02', 'until' => '2026-09-10'])['totals']['saves'])->toBe(0);
    expect($this->service->report(['from' => '2025-01-01', 'until' => '2026-09-10'])['series']['granularity'])->toBe('month');
});

it('exports all filtered rows with readable names and protects spreadsheet formulas', function (): void {
    $this->scenario->publishedEventA->update(['title' => '=HYPERLINK("https://example.invalid")']);
    DB::table('occurrence_views_daily')->insert(['occurrence_id' => $this->scenario->occurrenceA->id, 'date' => '2026-09-10', 'views' => 17]);
    $filters = $this->filters + ['venue' => $this->scenario->venueA->id, 'organizer' => $this->organizer->id];
    $csv = app(EventAnalyticsExport::class)->download($filters, 'csv', 'events');
    $csvPath = $csv->getFile()->getPathname();
    $xlsx = app(EventAnalyticsExport::class)->download($filters, 'xlsx', 'events');
    $xlsxPath = $xlsx->getFile()->getPathname();
    try {
        $text = file_get_contents($csvPath);
        expect($text)->toContain('Circolo Aurora', 'Associazione Musica Aperta', "'=HYPERLINK")->not->toContain('ID locale', 'ID organizzatore');
        $book = IOFactory::load($xlsxPath);
        expect($book->getSheetCount())->toBe(8);
        $summary = json_encode($book->getSheet(0)->toArray());
        expect($summary)->toContain('Circolo Aurora', 'Associazione Musica Aperta');
        $sheet = $book->getSheetByName('Eventi');
        expect($sheet->getCell('A2')->getDataType())->toBeIn(['s', 'inlineStr']);
        expect((string) $sheet->getCell('A2')->getValue())->toStartWith('=HYPERLINK');
        expect($sheet->getHighestRow())->toBe(2);
        expect($sheet->getFreezePane())->toBe('A2');
        expect($sheet->getAutoFilter()->getRange())->toStartWith('A1:');
        expect($book->getSheetByName('Repliche')->getCell('D2')->getDataType())->toBe('n');
        expect($book->getSheetByName('Repliche')->getCell('D2')->getValue())->toBe(17);
        $book->disconnectWorksheets();
    } finally {
        unlink($csvPath);
        unlink($xlsxPath);
    }
});

it('renders and exports empty filtered results', function (): void {
    $filters = $this->filters + ['venue' => $this->scenario->venueB->id, 'organizer' => $this->organizer->id];
    $report = $this->service->report($filters);
    expect($report['events'])->toBeEmpty()->and($report['totals']['views'])->toBe(0);
    $export = app(EventAnalyticsExport::class)->download($filters, 'xlsx', 'events');
    $path = $export->getFile()->getPathname();
    try {
        $book = IOFactory::load($path);
        expect($book->getSheetCount())->toBe(8);
        $book->disconnectWorksheets();
    } finally {
        unlink($path);
    }
});

it('keeps booking statuses and check-in dates distinct without exporting attendee identities', function (): void {
    foreach (['confirmed', 'waitlisted', 'cancelled'] as $status) {
        $booking = DB::table('bookings')->insertGetId(['occurrence_id' => $this->scenario->occurrenceA->id, 'user_id' => $this->scenario->plainUser->id, 'request_key' => (string) Str::uuid(), 'request_hash' => str_repeat('a', 64), 'status' => $status, 'created_at' => '2026-09-02 12:00:00']);
        DB::table('admission_tickets')->insert(['booking_id' => $booking, 'code' => (string) Str::uuid(), 'attendee_name' => 'Private Attendee Name', 'status' => $status === 'confirmed' ? 'checked_in' : $status, 'created_at' => '2026-09-02 12:00:00', 'checked_in_at' => $status === 'confirmed' ? '2026-09-10 12:00:00' : null]);
    }
    $comment = DB::table('event_comments')->insertGetId(['event_id' => $this->scenario->publishedEventA->id, 'user_id' => $this->scenario->plainUser->id, 'body' => 'Private comment body', 'status' => 'hidden', 'created_at' => '2026-09-10 12:00:00']);
    DB::table('event_comment_reactions')->insert(['event_comment_id' => $comment, 'user_id' => $this->scenario->ownerA->id, 'type' => 'like', 'created_at' => '2026-09-10 12:00:00']);
    $report = $this->service->report($this->filters);
    foreach (['bookings_confirmed', 'bookings_waitlisted', 'bookings_cancelled', 'checkins', 'comments', 'hidden_comments', 'reactions'] as $key) {
        expect($report['totals'][$key])->toBe(1);
    }
    expect($report['totals']['tickets'])->toBe(3)->and(json_encode($report))->not->toContain('Private Attendee Name', 'Private comment body');
    $lastDay = $this->service->report(['from' => '2026-09-10', 'until' => '2026-09-10']);
    expect($lastDay['totals']['tickets'])->toBe(0)->and($lastDay['totals']['checkins'])->toBe(1);
});

it('restricts sponsored totals to grants owned by the venue even after an event moves', function (): void {
    $grant = SponsorshipGrant::create(['venue_id' => $this->scenario->venueB->id, 'created_by' => $this->scenario->admin->id, 'mode' => 'selected', 'placement' => 'list_top', 'enabled' => true, 'complimentary' => true, 'starts_at' => now()->subDay(), 'ends_at' => now()->addMonth()]);
    foreach ([null, $grant->id] as $grantId) {
        $campaign = Sponsorship::factory()->create(['event_id' => $this->scenario->publishedEventA->id, 'sponsorship_grant_id' => $grantId]);
        DB::table('sponsorship_daily_stats')->insert(['sponsorship_id' => $campaign->id, 'day' => '2026-09-10', 'impressions' => 20, 'clicks' => 2]);
    }
    expect($this->service->report($this->filters)['totals']['paid_impressions'])->toBe(40);
    Filament::setCurrentPanel('venue');
    Filament::setTenant($this->scenario->venueA);
    $this->actingAs($this->scenario->ownerA);
    expect($this->service->report($this->filters)['totals']['paid_impressions'])->toBe(20);
});

it('paginates long reports in Italian while exporting every row and preserving named deep links', function (): void {
    foreach (range(1, 22) as $number) {
        $event = $this->scenario->publishedEventA->replicate();
        $event->title = 'Evento aggiuntivo '.$number;
        $event->slug = 'evento-aggiuntivo-'.$number;
        $event->save();
    }
    $page = Livewire::test(EventShareAnalytics::class)
        ->set('filters.venue', $this->scenario->venueA->id)
        ->assertSee('Mostrati da 1 a 20 di 24 risultati')
        ->assertDontSee('Showing')->assertDontSee('Go to page');
    expect($page->instance()->tableRows())->toHaveCount(20);
    $page->call('nextPage', 'analyticsPage')->assertSee('Mostrati da 21 a 24 di 24 risultati');
    expect($page->instance()->tableRows())->toHaveCount(4);
    $url = $page->instance()->detailUrl('venue', $this->scenario->venueA->id);
    parse_str(parse_url($url, PHP_URL_QUERY), $query);
    Livewire::withQueryParams($query)->test(EventAnalyticsDetail::class, ['subjectType' => 'venue', 'subjectId' => $this->scenario->venueA->id])->assertSee('Analytics · Circolo Aurora');
    $export = app(EventAnalyticsExport::class)->download($this->filters + ['venue' => $this->scenario->venueA->id], 'csv', 'events');
    $path = $export->getFile()->getPathname();
    try {
        expect(file($path))->toHaveCount(25);
    } finally {
        unlink($path);
    }
});

it('opens a dedicated analytics page for each subject and pins reports to its route', function (string $type): void {
    $id = match ($type) {
        'event' => $this->scenario->publishedEventA->id, 'venue' => $this->scenario->venueA->id, default => $this->organizer->id
    };
    $name = match ($type) {
        'event' => $this->scenario->publishedEventA->title, 'venue' => $this->scenario->venueA->name, default => $this->organizer->name
    };
    $url = EventAnalyticsDetail::getUrl(['subjectType' => $type, 'subjectId' => $id]);
    $this->get($url)->assertOk()->assertSee('Analytics · '.$name)->assertSee('data-analytics-subject="'.$type.'"', false);
    $page = Livewire::test(EventAnalyticsDetail::class, ['subjectType' => $type, 'subjectId' => $id]);
    expect($page->instance()->dashboard()['totals']['views'])->toBe(10);
    $page->set('filters.event', $this->scenario->publishedEventB->id)->set('filters.venue', $this->scenario->venueB->id);
    expect($page->instance()->dashboard()['totals']['views'])->toBe(10);
    $page->call('resetFilters');
    expect($page->instance()->dashboard()['totals']['views'])->toBe(10);
    $response = $page->instance()->export('csv');
    $path = $response->getFile()->getPathname();
    try {
        expect(file_get_contents($path))->toContain($this->scenario->publishedEventA->title)->not->toContain($this->scenario->publishedEventB->title);
    } finally {
        unlink($path);
    }
})->with(['event', 'venue', 'organizer']);

it('rejects a foreign event detail and rechecks access after venue membership is removed', function (): void {
    Filament::setCurrentPanel('venue');
    Filament::setTenant($this->scenario->venueA);
    $this->actingAs($this->scenario->ownerA);
    $class = App\Filament\Venue\Pages\EventAnalyticsDetail::class;
    $this->get($class::getUrl(['subjectType' => 'event', 'subjectId' => $this->scenario->publishedEventB->id], tenant: $this->scenario->venueA))->assertNotFound();
    $page = Livewire::test($class, ['subjectType' => 'event', 'subjectId' => $this->scenario->publishedEventA->id]);
    $this->scenario->ownerA->venues()->detach($this->scenario->venueA);
    expect(fn () => $page->instance()->export('csv'))->toThrow(HttpException::class);
});

it('shows the complete tenant report on its dashboard and retains operational widgets', function (): void {
    Filament::setCurrentPanel('venue');
    Filament::setTenant($this->scenario->venueA);
    $this->actingAs($this->scenario->ownerA);
    $page = Livewire::test(Dashboard::class)->assertSee('Report del profilo')->assertSee('Tutti i dati');
    expect($page->instance()->dashboard()['totals']['views'])->toBe(10);
    expect($page->instance()->dashboard()['venues']->pluck('name')->all())->toBe(['Circolo Aurora']);
    Filament::setCurrentPanel('organizer');
    Filament::setTenant($this->organizer);
    Livewire::test(App\Filament\Organizer\Pages\Dashboard::class)->assertSee('Report del profilo')->assertSee('Associazione Musica Aperta');
});

it('separates viewing an event and all its dates from editing', function (): void {
    Filament::setCurrentPanel('venue');
    Filament::setTenant($this->scenario->venueA);
    $this->actingAs($this->scenario->ownerA);
    $later = EventOccurrence::factory()->create(['event_id' => $this->scenario->publishedEventA->id, 'starts_at' => '2026-10-11 19:00:00', 'ends_at' => '2026-10-11 21:00:00', 'highlight' => 'Seconda serata speciale']);
    EventOccurrence::factory()->create(['event_id' => $this->scenario->publishedEventA->id, 'starts_at' => '2026-08-11 19:00:00', 'ends_at' => '2026-08-11 21:00:00', 'status' => 'cancelled', 'highlight' => 'Serata precedente annullata']);
    $view = Livewire::test(ViewEvent::class, ['record' => $this->scenario->publishedEventA->getRouteKey()]);
    $view->assertSee('data-event-read-view', false)->assertSee('Seconda serata speciale')->assertSee('11/09/2026')->assertSee('11/10/2026')->assertSee('11/08/2026')->assertSee('Serata precedente annullata')->assertSee('Statistiche');
    expect($view->instance()->getRelationManagers())->toBeEmpty();
    $edit = Livewire::test(EditEvent::class, ['record' => $this->scenario->publishedEventA->getRouteKey()]);
    $edit->assertFormSet(['title' => $this->scenario->publishedEventA->title])->assertSee('Statistiche');
    $resource = EventResource::class;
    expect($resource::getUrl('view', ['record' => $later->event]))->not->toBe($resource::getUrl('edit', ['record' => $later->event]));
    $this->get($resource::getUrl('view', ['record' => $this->scenario->publishedEventB]))->assertNotFound();
});

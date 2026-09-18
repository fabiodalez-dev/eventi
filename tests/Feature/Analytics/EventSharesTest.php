<?php

declare(strict_types=1);

use App\DTOs\ConsentState;
use App\Enums\EventStatus;
use App\Enums\StatsPeriod;
use App\Filament\Admin\Pages\EventShareAnalytics;
use App\Filament\Venue\Pages\Statistics;
use App\Models\City;
use App\Models\EventShareLink;
use App\Models\Organizer;
use App\Services\Analytics\EventShareReport;
use App\Services\Analytics\EventShares;
use Carbon\Carbon;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\VenueIsolationScenario;

beforeEach(function (): void {
    $this->scenario = VenueIsolationScenario::make();
    freezeLocal($this->scenario->city, '2026-09-10 23:30');
    $this->shares = app(EventShares::class);
    $this->links = $this->shares->links($this->scenario->publishedEventA, $this->scenario->occurrenceA);
});

afterEach(fn () => Carbon::setTestNow());

function allowShareStatistics($test): void
{
    $state = new ConsentState('share-test', config('consent.version'), ['statistics' => true]);
    $test->withCredentials()->withCookie(config('consent.cookie'), $state->encode());
}

it('creates four stable short links per target without altering canonical or social metadata', function (): void {
    expect($this->shares->links($this->scenario->publishedEventA, $this->scenario->occurrenceA))->toBe($this->links);
    expect(EventShareLink::count())->toBe(4);
    $page = $this->get(route('events.occurrence', ['slug' => $this->scenario->publishedEventA->slug, 'occurrence' => $this->scenario->occurrenceA->url_number]));
    $page->assertOk()->assertSee('rel="canonical"', false)->assertSee('property="og:url"', false);
    foreach ($this->links as $channel => $link) {
        expect(parse_url($link['url'], PHP_URL_PATH))->toMatch('/^\/s\/[A-Za-z0-9]{7}$/');
        if ($channel === 'native') {
            $page->assertSee('data-share-url="'.$link['url'].'"', false);
        } else {
            $page->assertSee(urlencode($link['url']), false);
        }
    }
    $redirect = $this->get($this->links['whatsapp']['url']);
    $target = $this->get($redirect->headers->get('Location'));
    preg_match_all('/<meta[^>]+property="og:[^>]+>/', $page->getContent(), $before);
    preg_match_all('/<meta[^>]+property="og:[^>]+>/', $target->getContent(), $after);
    expect($before[0])->not->toBeEmpty()->toBe($after[0]);
    expect(EventShareLink::count())->toBe(4);
});

it('redirects without counting or setting cookies before consent and ignores supplied destinations', function (): void {
    $response = $this->get($this->links['native']['url'].'?url=https://evil.example');
    $response->assertRedirect(route('events.occurrence', ['slug' => $this->scenario->publishedEventA->slug, 'occurrence' => 1]));
    expect($response->headers->get('Cache-Control'))->toContain('no-store');
    expect($response->headers->getCookies())->toBeEmpty();
    $this->postJson($this->links['native']['metric'])->assertNoContent();
    expect(DB::table('event_share_daily')->count())->toBe(0);
});

it('counts consented share actions and opens separately on the city day without duplicating totals', function (): void {
    freezeLocal($this->scenario->city, '2026-09-11 00:30');
    allowShareStatistics($this);
    $this->postJson($this->links['telegram']['metric'], ['metric' => 'clicks'])->assertNoContent();
    $this->get($this->links['telegram']['url'])->assertRedirect();
    $this->get($this->links['telegram']['url'])->assertRedirect();
    $row = DB::table('event_share_daily')->sole();
    expect((int) $row->shares)->toBe(1)->and((int) $row->clicks)->toBe(2)->and($row->date)->toBe('2026-09-11');
    $this->assertDatabaseHas('event_views_daily', ['event_id' => $this->scenario->publishedEventA->id, 'shares' => 1]);
    $this->assertDatabaseHas('occurrence_views_daily', ['occurrence_id' => $this->scenario->occurrenceA->id, 'shares' => 1]);
});

it('does not count previews, bots, HEAD or speculative navigation', function (string $method, array $headers): void {
    allowShareStatistics($this);
    $this->withHeaders($headers)->call($method, $this->links['native']['url'])->assertRedirect();
    expect(DB::table('event_share_daily')->count())->toBe(0);
})->with([
    ['HEAD', []], ['GET', ['User-Agent' => 'WhatsApp/2.0']],
    ['GET', ['User-Agent' => 'facebookexternalhit/1.1']], ['GET', ['User-Agent' => 'TelegramBot']],
    ['GET', ['User-Agent' => 'Googlebot']], ['GET', ['Sec-Purpose' => 'prefetch;prerender']],
    ['GET', ['Purpose' => 'prefetch']],
]);

it('revokes access when an event is no longer public', function (string $status): void {
    allowShareStatistics($this);
    $this->scenario->publishedEventA->update(['status' => $status]);
    $this->get($this->links['native']['url'])->assertNotFound();
    $this->postJson($this->links['native']['metric'])->assertNotFound();
    expect(DB::table('event_share_daily')->count())->toBe(0);
})->with([EventStatus::Draft->value, EventStatus::Pending->value]);

it('handles removed events, dates, inactive cities and invalid codes without recording', function (): void {
    allowShareStatistics($this);
    $this->get('/s/zzzzzzz')->assertNotFound();
    $this->get('/s/too-long-code')->assertNotFound();
    $this->scenario->city->update(['is_active' => false]);
    $this->get($this->links['native']['url'])->assertNotFound();
    $this->scenario->city->update(['is_active' => true]);
    $this->scenario->occurrenceA->delete();
    $this->get($this->links['native']['url'])->assertNotFound();
    expect(DB::table('event_share_daily')->count())->toBe(0);
});

it('keeps links valid after a slug change and routes to the correct city', function (): void {
    $city = City::factory()->create(['slug' => 'vicenza', 'is_active' => true]);
    $this->scenario->publishedEventA->update(['slug' => 'nuovo-titolo', 'city_id' => $city->id]);
    $this->get($this->links['native']['url'])->assertRedirect(route('city.events.occurrence', ['city' => 'vicenza', 'slug' => 'nuovo-titolo', 'occurrence' => 1]));
});

it('does not generate share links for editorial previews', function (): void {
    $this->actingAs($this->scenario->ownerA)->get(route('events.preview', $this->scenario->draftEventA))->assertOk();
    expect(EventShareLink::where('event_id', $this->scenario->draftEventA->id)->exists())->toBeFalse();
});

it('isolates venue reports and allows admins to see both venues', function (): void {
    $this->shares->links($this->scenario->publishedEventB, null);
    $link = EventShareLink::where('channel', 'native')->where('event_id', $this->scenario->publishedEventA->id)->firstOrFail();
    $this->shares->record($link, true);
    $this->shares->record($link, false);
    DB::table('event_share_daily')->insert(['share_link_id' => $link->id, 'date' => '2026-08-01', 'shares' => 90, 'clicks' => 90]);
    $this->actingAs($this->scenario->ownerA);
    Filament::setCurrentPanel('venue');
    Filament::setTenant($this->scenario->venueA);
    $rows = app(EventShareReport::class)->rows(StatsPeriod::Week);
    expect($rows->total())->toBe(4)->and((int) $rows->first()->shares)->toBe(1)->and((int) $rows->first()->clicks)->toBe(1);
    Livewire::test(Statistics::class)->assertSee('Analytics condivisioni')->assertSee($this->scenario->publishedEventA->title)->assertDontSee($this->scenario->publishedEventB->title);
    Filament::setTenant($this->scenario->venueB);
    expect(fn () => app(EventShareReport::class)->rows(StatsPeriod::Month))->toThrow(HttpException::class);
    Filament::setTenant(null);
    Filament::setCurrentPanel('admin');
    expect(fn () => app(EventShareReport::class)->rows(StatsPeriod::Month))->toThrow(HttpException::class);
    $this->actingAs($this->scenario->admin);
    expect(app(EventShareReport::class)->rows(StatsPeriod::Week)->total())->toBe(8);
    Livewire::test(EventShareAnalytics::class)->assertSee($this->scenario->publishedEventA->title)->assertSee($this->scenario->publishedEventB->title)
        ->call('setPeriod', 'invalid')->assertSet('period', 'month');
});

it('keeps series and occurrence channels distinct and rejects a foreign occurrence', function (): void {
    $series = $this->shares->links($this->scenario->publishedEventA, null);
    expect($series['native']['url'])->not->toBe($this->links['native']['url']);
    $this->get($series['native']['url'])->assertRedirect(route('events.show', $this->scenario->publishedEventA));
    expect(EventShareLink::count())->toBe(8);
    expect(fn () => $this->shares->links($this->scenario->publishedEventA, $this->scenario->occurrenceB))->toThrow(HttpException::class);
});

it('retries a random code collision without overwriting another link', function (): void {
    $existing = EventShareLink::firstOrFail();
    Str::createRandomStringsUsingSequence([$existing->code, 'NewCode', 'NewCod2', 'NewCod3', 'NewCod4']);
    try {
        $other = $this->shares->links($this->scenario->publishedEventB, null);
        expect($other['native']['url'])->toEndWith('/NewCode');
        expect($existing->fresh()->event_id)->toBe($this->scenario->publishedEventA->id);
        expect(EventShareLink::count())->toBe(8);
    } finally {
        Str::createRandomStringsNormally();
    }
});

it('resolves case-sensitive codes and retains archived event links', function (): void {
    $link = EventShareLink::where('channel', 'native')->firstOrFail();
    $link->update(['code' => 'AbCd123']);
    $this->get('/s/abcd123')->assertNotFound();
    freezeLocal($this->scenario->city, '2026-09-15 12:00');
    $this->scenario->publishedEventA->update(['status' => EventStatus::Archived]);
    $this->get('/s/AbCd123')->assertRedirect();
    $this->scenario->publishedEventA->delete();
    $this->get('/s/AbCd123')->assertNotFound();
});

it('scopes organizer analytics to their own events even in a different venue', function (): void {
    $organizer = Organizer::create(['name' => 'Organizzatore test', 'city_id' => $this->scenario->city->id, 'owner_id' => $this->scenario->ownerA->id, 'is_active' => true]);
    $this->scenario->publishedEventB->update(['organizer_id' => $organizer->id]);
    $this->shares->links($this->scenario->publishedEventB, null);
    $this->actingAs($this->scenario->ownerA);
    Filament::setCurrentPanel('organizer');
    Filament::setTenant($organizer);
    $rows = app(EventShareReport::class)->rows(StatsPeriod::Month);
    expect($rows->total())->toBe(4)->and($rows->pluck('title')->unique()->all())->toBe([$this->scenario->publishedEventB->title]);
    $this->actingAs($this->scenario->plainUser);
    expect(fn () => app(EventShareReport::class)->rows(StatsPeriod::Month))->toThrow(HttpException::class);
});

it('does not let analytics consume the posting budget of other forms', function (string $kind): void {
    $this->actingAs($this->scenario->ownerA);
    allowShareStatistics($this);
    $endpoint = $kind === 'share' ? $this->links['native']['metric'] : URL::signedRoute('content.metrics', ['type' => 'event', 'id' => $this->scenario->publishedEventA->id], absolute: false);
    for ($i = 0; $i < 10; $i++) {
        $this->postJson($endpoint, ['metric' => 'views'])->assertNoContent();
    }
    $this->post(route('venues.review.store', ['slug' => $this->scenario->venueA->slug]), [
        'rating' => 4, 'body' => 'Una bella esperienza nel locale.',
    ])->assertRedirect()->assertSessionHasNoErrors();
    $this->assertDatabaseHas('venue_reviews', ['venue_id' => $this->scenario->venueA->id, 'user_id' => $this->scenario->ownerA->id, 'rating' => 4]);
})->with(['share', 'content']);

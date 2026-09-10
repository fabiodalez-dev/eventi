<?php

declare(strict_types=1);

use App\Enums\EventStatus;
use App\Enums\GoogleCalendarError;
use App\Enums\OccurrenceStatus;
use App\Jobs\SyncGoogleCalendar;
use App\Models\GoogleCalendarConnection;
use App\Models\User;
use App\Models\Venue;
use App\Services\Calendar\GoogleCalendarAuthorization;
use App\Services\Calendar\GoogleCalendarClient;
use App\Services\Calendar\GoogleCalendarSync;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\URL;
use Laravel\Sanctum\Sanctum;

beforeEach(function (): void {
    $this->city = testCity();
    $this->category = testCategory();
    $this->user = User::factory()->create();
    freezeLocal($this->city, '2026-09-09 12:00');
    config(['google-calendar.redirect_uri' => null, 'google-calendar.client_id' => 'test-client', 'google-calendar.client_secret' => 'test-secret']);
    Http::preventStrayRequests();
    Queue::fake();
});

afterEach(fn () => Carbon::setTestNow());

function googleConnection(User $user, array $overrides = []): GoogleCalendarConnection
{
    return GoogleCalendarConnection::create([
        'user_id' => $user->id, 'city_id' => test()->city->id, 'google_subject' => 'google-subject',
        'access_token' => 'private-access', 'refresh_token' => 'private-refresh',
        'expires_at' => now()->addHour(), 'calendar_id' => 'owned-calendar',
        'selection' => ['days' => 30], 'enabled' => true, ...$overrides,
    ]);
}

function googlePending(User $user, array $overrides = []): array
{
    return ['state' => hash('sha256', 'test-state'), 'verifier' => str_repeat('v', 64), 'user_id' => $user->id,
        'city_id' => test()->city->id, 'expires' => now()->addMinutes(10)->timestamp, 'selection' => ['days' => 7], ...$overrides];
}

function fakeGoogleConsent(): void
{
    Http::fake([
        'https://oauth2.googleapis.com/token' => Http::response(['access_token' => 'new-access', 'refresh_token' => 'new-refresh', 'scope' => GoogleCalendarClient::SCOPE, 'expires_in' => 3600]),
        'https://openidconnect.googleapis.com/v1/userinfo' => Http::response(['sub' => 'google-subject']),
    ]);
}

it('requires authentication for management status and consent', function (): void {
    $this->get(route('google-calendar.index'))->assertRedirect(route('login'));
    $this->post(route('google-calendar.connect'))->assertRedirect(route('login'));
    $this->getJson('/api/v1/me/calendar/google')->assertUnauthorized();
    $this->postJson('/api/v1/me/calendar/google/manage', [])->assertUnauthorized();
    Http::assertNothingSent();
});

it('shows missing setup honestly without sending the user to a broken authorization URL', function (): void {
    config(['google-calendar.client_secret' => null]);
    $this->actingAs($this->user)->get(route('google-calendar.index'))->assertOk()->assertSee(__('google_calendar.unconfigured'));
    $this->post(route('google-calendar.connect'))->assertServiceUnavailable();
    Http::assertNothingSent();
});

it('requests only app-created calendars with offline consent state and PKCE', function (): void {
    $response = $this->actingAs($this->user)->post(route('google-calendar.connect'), ['days' => 7]);
    $response->assertRedirect();
    parse_str(parse_url($response->headers->get('Location'), PHP_URL_QUERY), $query);
    expect($query['scope'])->toBe('openid '.GoogleCalendarClient::SCOPE)
        ->and($query['access_type'])->toBe('offline')
        ->and($query['code_challenge_method'])->toBe('S256')
        ->and($query['redirect_uri'])->toBe(route('google-calendar.callback'))
        ->and(session('google_calendar_oauth.state'))->toBe(hash('sha256', $query['state']));
    expect($query)->not->toHaveKey('client_secret');
});

it('rejects mismatched expired missing and cross-user callback state', function (string $scenario): void {
    $pending = googlePending($this->user);
    if ($scenario === 'expired') {
        $pending['expires'] = now()->subMinute()->timestamp;
    }
    if ($scenario === 'other-user') {
        $pending['user_id'] = User::factory()->create()->id;
    }
    $this->actingAs($this->user)->withSession($scenario === 'missing' ? [] : ['google_calendar_oauth' => $pending]);
    $this->get(route('google-calendar.callback', ['state' => $scenario === 'mismatch' ? 'wrong' : 'test-state', 'code' => 'secret-code']))->assertForbidden();
    Http::assertNothingSent();
})->with(['mismatch', 'expired', 'missing', 'other-user']);

it('handles denied consent without changing an existing connection', function (): void {
    $connection = googleConnection($this->user);
    $this->actingAs($this->user)->withSession(['google_calendar_oauth' => googlePending($this->user)])
        ->get(route('google-calendar.callback', ['state' => 'test-state', 'error' => 'access_denied']))
        ->assertRedirect(route('google-calendar.index'))->assertSessionHas('google_calendar_message', __('google_calendar.denied'));
    expect($connection->refresh()->access_token)->toBe('private-access');
    Http::assertNothingSent();
});

it('stores encrypted tokens and prevents replay of a completed callback', function (): void {
    fakeGoogleConsent();
    $this->actingAs($this->user)->withSession(['google_calendar_oauth' => googlePending($this->user)]);
    $url = route('google-calendar.callback', ['state' => 'test-state', 'code' => 'secret-code']);
    $this->get($url)->assertRedirect(route('google-calendar.index'));
    $connection = GoogleCalendarConnection::sole();
    expect($connection->refresh_token)->toBe('new-refresh')
        ->and(DB::table('google_calendar_connections')->value('refresh_token'))->not->toBe('new-refresh')
        ->and($connection->toArray())->not->toHaveKeys(['refresh_token', 'access_token', 'google_subject', 'calendar_id']);
    Queue::assertPushed(SyncGoogleCalendar::class, fn ($job) => $job->userId === $this->user->id);
    $this->get($url)->assertForbidden();
    Http::assertSentCount(2);
});

it('fails safely when Google does not grant the required permission', function (): void {
    Http::fake(['oauth2.googleapis.com/*' => Http::response(['access_token' => 'x', 'refresh_token' => 'y', 'scope' => 'openid'])]);
    $this->actingAs($this->user)->withSession(['google_calendar_oauth' => googlePending($this->user)])
        ->get(route('google-calendar.callback', ['state' => 'test-state', 'code' => 'code']))
        ->assertSessionHas('google_calendar_message', __('google_calendar.failed'));
    expect(GoogleCalendarConnection::count())->toBe(0);
    Queue::assertNothingPushed();
});

it('retains the same Google calendar when authorizing the same Google account again', function (): void {
    googleConnection($this->user);
    fakeGoogleConsent();
    $this->actingAs($this->user)->withSession(['google_calendar_oauth' => googlePending($this->user)])
        ->get(route('google-calendar.callback', ['state' => 'test-state', 'code' => 'code']))->assertRedirect();
    expect(GoogleCalendarConnection::sole()->calendar_id)->toBe('owned-calendar');
});

it('does not recreate credentials when an account was deleted during Google consent', function (): void {
    fakeGoogleConsent();
    Http::fake(['https://oauth2.googleapis.com/revoke' => Http::response('', 200)]);
    $pending = googlePending($this->user);
    $this->user->delete();
    expect(fn () => app(GoogleCalendarAuthorization::class)->complete($this->user->id, 'code', $pending))
        ->toThrow(RuntimeException::class, 'google_account_unavailable');
    expect(GoogleCalendarConnection::count())->toBe(0);
    Http::assertSent(fn ($request) => $request->url() === 'https://oauth2.googleapis.com/revoke' && $request['token'] === 'new-refresh');
    Queue::assertNothingPushed();
});

it('keeps the Google lock until the account erasure callback has completed', function (): void {
    $ran = false;
    app(GoogleCalendarSync::class)->forget($this->user->id, function () use (&$ran): void {
        $ran = true;
        expect(Cache::lock('google-calendar-'.$this->user->id, 600)->get())->toBeFalse();
    });
    expect($ran)->toBeTrue();
    $lock = Cache::lock('google-calendar-'.$this->user->id, 600);
    expect($lock->get())->toBeTrue();
    $lock->release();
});

it('exposes only the current users status and keeps it out of shared caches', function (): void {
    $other = User::factory()->create();
    googleConnection($other, ['event_count' => 99]);
    Sanctum::actingAs($this->user);
    $response = $this->getJson('/api/v1/me/calendar/google')->assertOk()->assertJsonPath('data.connected', false)->assertJsonPath('data.event_count', 0);
    expect($response->headers->get('Cache-Control'))->toContain('private', 'no-store');
    expect($response->getContent())->not->toContain('private-refresh', 'owned-calendar');
});

it('preserves all categories explicitly when changing an existing filtered calendar', function (): void {
    $connection = googleConnection($this->user, ['selection' => ['categories' => [$this->category->slug], 'days' => 7]]);
    $this->actingAs($this->user)->patch(route('google-calendar.update'), ['days' => 30, 'free' => false])->assertRedirect();
    expect($connection->refresh()->selection)->toBe(['days' => 30, 'free' => false]);
    Queue::assertPushed(SyncGoogleCalendar::class);
});

it('rejects unknown categories venues and unsupported horizons', function (): void {
    $this->actingAs($this->user)->post(route('google-calendar.connect'), ['categories' => ['absent'], 'venue' => 'absent', 'days' => 365])
        ->assertSessionHasErrors(['categories.0', 'venue', 'days']);
    Queue::assertNothingPushed();
});

it('creates an expiring mobile link that is not a login credential and rejects another user', function (): void {
    Sanctum::actingAs($this->user);
    $link = $this->postJson('/api/v1/me/calendar/google/manage', ['categories' => [$this->category->slug], 'days' => 7, 'free' => true])->assertOk()->json('data.url');
    expect($link)->toContain('signature=', 'expires=')->not->toContain('token=');
    $this->actingAs($this->user, 'web')->get($link)->assertRedirect();
    $this->actingAs(User::factory()->create(), 'web')->get($link)->assertForbidden();
});

it('rejects tampered and expired mobile links', function (): void {
    $link = URL::temporarySignedRoute('google-calendar.mobile', now()->addMinute(), ['user' => $this->user->id, 'days' => 7]);
    $this->actingAs($this->user)->get(str_replace('days=7', 'days=90', $link))->assertForbidden();
    $this->travel(2)->minutes();
    $this->get($link)->assertForbidden();
});

it('creates one dedicated calendar and stable occurrence IDs', function (): void {
    $connection = googleConnection($this->user, ['calendar_id' => null]);
    $item = occurrenceAtLocal($this->city, $this->category, '2026-09-10 21:00');
    Http::fake([
        'https://www.googleapis.com/calendar/v3/calendars' => Http::response(['id' => 'new-calendar']),
        'https://www.googleapis.com/calendar/v3/calendars/new-calendar/events*' => Http::response(['items' => []]),
    ]);
    app(GoogleCalendarSync::class)->run($this->user->id);
    expect($connection->refresh()->calendar_id)->toBe('new-calendar')->and($connection->event_count)->toBe(1)->and($connection->synced_at)->not->toBeNull();
    Http::assertSent(fn ($request) => $request->method() === 'POST' && ($request['id'] ?? null) === hash('sha256', 'incitta:'.$connection->id.':'.$item->id));
});

it('skips unchanged events and only deletes marked events across all remote pages', function (): void {
    $connection = googleConnection($this->user);
    $item = occurrenceAtLocal($this->city, $this->category, '2026-09-10 21:00');
    $sync = app(GoogleCalendarSync::class);
    $hash = hash('sha256', json_encode($sync->event($item, $connection, $this->city), JSON_THROW_ON_ERROR));
    $id = hash('sha256', 'incitta:'.$connection->id.':'.$item->id);
    Http::fake(function ($request) use ($connection, $hash, $id) {
        if ($request->method() === 'DELETE') {
            return Http::response([], 204);
        }
        if (str_contains($request->url(), 'pageToken=next')) {
            return Http::response(['items' => [
                ['id' => 'remove-me', 'extendedProperties' => ['private' => ['incitta_connection' => (string) $connection->id]]],
                ['id' => 'personal-event'],
            ]]);
        }

        return Http::response(['nextPageToken' => 'next', 'items' => [[
            'id' => $id, 'extendedProperties' => ['private' => ['incitta_connection' => (string) $connection->id, 'incitta_hash' => $hash]],
        ]]]);
    });
    $sync->run($this->user->id);
    Http::assertSentCount(3);
    Http::assertSent(fn ($request) => $request->method() === 'DELETE' && str_ends_with($request->url(), '/remove-me'));
    Http::assertNotSent(fn ($request) => str_contains($request->url(), '/personal-event'));
});

it('does not export cancelled draft or nonmatching events', function (): void {
    $connection = googleConnection($this->user, ['selection' => ['categories' => [$this->category->slug]]]);
    occurrenceAtLocal($this->city, $this->category, '2026-09-10 21:00', occurrence: ['status' => OccurrenceStatus::Cancelled]);
    occurrenceAtLocal($this->city, $this->category, '2026-09-10 21:00', event: ['status' => EventStatus::Draft]);
    occurrenceAtLocal($this->city, testCategory(['name' => 'Teatro']), '2026-09-10 21:00');
    Http::fake(['www.googleapis.com/*' => Http::response(['items' => []])]);
    app(GoogleCalendarSync::class)->run($this->user->id);
    expect($connection->refresh()->event_count)->toBe(0);
    Http::assertSentCount(1);
});

it('uses the individual dates venue and exclusive all-day end', function (): void {
    $connection = googleConnection($this->user);
    $venue = Venue::factory()->approved()->create(['city_id' => $this->city->id, 'name' => 'Luogo della replica']);
    $item = occurrenceAtLocal($this->city, $this->category, '2026-09-10 00:00', '2026-09-11 00:00', occurrence: ['venue_id' => $venue->id, 'is_all_day' => true]);
    $body = app(GoogleCalendarSync::class)->event($item, $connection, $this->city);
    expect($body['location'])->toContain('Luogo della replica')
        ->and($body['start'])->toBe(['date' => '2026-09-10'])->and($body['end'])->toBe(['date' => '2026-09-11']);
});

it('preserves the correct timezone offset across DST changes', function (string $date, string $offset): void {
    $connection = googleConnection($this->user);
    $item = occurrenceAtLocal($this->city, $this->category, $date);
    $body = app(GoogleCalendarSync::class)->event($item, $connection, $this->city);
    expect($body['start']['dateTime'])->toEndWith($offset)->and($body['start']['timeZone'])->toBe('Europe/Rome');
})->with([['2026-03-29 21:00', '+02:00'], ['2026-10-25 21:00', '+01:00']]);

it('disables revoked authorization without logging or exposing the response', function (): void {
    $connection = googleConnection($this->user, ['expires_at' => now()->subHour()]);
    Http::fake(['oauth2.googleapis.com/*' => Http::response(['error' => 'invalid_grant', 'error_description' => 'private-details'], 400)]);
    expect(fn () => app(GoogleCalendarSync::class)->run($this->user->id))->toThrow(RuntimeException::class, 'Google Calendar sync failed');
    expect($connection->refresh()->enabled)->toBeFalse()->and($connection->error_code)->toBe(GoogleCalendarError::Authorization)
        ->and($connection->refresh_token)->toBeNull();
});

it('keeps the Google calendar on disconnect and revokes only this accounts token', function (): void {
    $connection = googleConnection($this->user);
    $other = googleConnection(User::factory()->create());
    Http::fake(['oauth2.googleapis.com/revoke' => Http::response([], 200)]);
    $this->actingAs($this->user)->delete(route('google-calendar.disconnect'))->assertRedirect();
    expect($connection->refresh()->enabled)->toBeFalse()->and($connection->refresh_token)->toBeNull()
        ->and($connection->calendar_id)->toBe('owned-calendar')->and($other->refresh()->enabled)->toBeTrue();
    Http::assertSentCount(1);
    Http::assertSent(fn ($request) => $request->url() === 'https://oauth2.googleapis.com/revoke' && $request['token'] === 'private-refresh');
});

it('stops synchronization even if Google temporarily cannot confirm revocation', function (): void {
    $connection = googleConnection($this->user);
    Http::fake(['oauth2.googleapis.com/revoke' => Http::response([], 503)]);
    $this->actingAs($this->user)->delete(route('google-calendar.disconnect'))->assertSessionHas('google_calendar_message', __('google_calendar.disconnect_retry'));
    expect($connection->refresh()->enabled)->toBeFalse()->and($connection->refresh_token)->toBe('private-refresh');
    app(GoogleCalendarSync::class)->run($this->user->id);
    Http::assertSentCount(1);
});

it('erases stored Google credentials even when Google is unavailable during account erasure', function (): void {
    googleConnection($this->user);
    Http::fake(['oauth2.googleapis.com/revoke' => Http::response([], 503)]);
    app(GoogleCalendarSync::class)->forget($this->user->id);
    expect(GoogleCalendarConnection::where('user_id', $this->user->id)->exists())->toBeFalse();
});

it('marks a deleted dedicated calendar for recreation without switching to primary', function (): void {
    $connection = googleConnection($this->user);
    Http::fake(['www.googleapis.com/*' => Http::response([], 404)]);
    expect(fn () => app(GoogleCalendarSync::class)->run($this->user->id))->toThrow(RuntimeException::class);
    expect($connection->refresh()->calendar_id)->toBeNull()->and($connection->error_code)->toBe(GoogleCalendarError::Sync);
    Http::assertNotSent(fn ($request) => str_contains($request->url(), 'primary'));
});

it('forces a token refresh after an unexpected unauthorized response', function (): void {
    $connection = googleConnection($this->user);
    Http::fake(['www.googleapis.com/*' => Http::response([], 401)]);
    expect(fn () => app(GoogleCalendarSync::class)->run($this->user->id))->toThrow(RuntimeException::class);
    expect($connection->refresh()->expires_at->isPast())->toBeTrue();
});

it('does not delete existing dates after a partial remote write failure', function (): void {
    $connection = googleConnection($this->user);
    occurrenceAtLocal($this->city, $this->category, '2026-09-10 21:00');
    Http::fake(fn ($request) => $request->method() === 'GET'
        ? Http::response(['items' => [['id' => 'old-date', 'extendedProperties' => ['private' => ['incitta_connection' => (string) $connection->id]]]]])
        : Http::response([], 503));
    expect(fn () => app(GoogleCalendarSync::class)->run($this->user->id))->toThrow(RuntimeException::class);
    Http::assertNotSent(fn ($request) => $request->method() === 'DELETE');
    expect($connection->refresh()->synced_at)->toBeNull();
});

it('keeps callback authorization codes out of referrers and caches', function (): void {
    $this->actingAs($this->user)->withSession(['google_calendar_oauth' => googlePending($this->user)])
        ->get(route('google-calendar.callback', ['state' => 'test-state', 'error' => 'access_denied']))
        ->assertHeader('Referrer-Policy', 'no-referrer')->assertHeader('X-Robots-Tag', 'noindex, nofollow');
});

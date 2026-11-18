<?php

declare(strict_types=1);

use App\Enums\OccurrenceStatus;
use App\Jobs\SyncGoogleCalendar;
use App\Models\GoogleCalendarConnection;
use App\Models\User;
use App\Services\Calendar\GoogleCalendarClient;
use App\Services\RememberedLocation;
use App\Services\Ticketing\TicketingService;
use Carbon\Carbon;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

/*
 * Gli strumenti personali attorno ai salvataggi: le serate a cui si è stati
 * davvero, la posizione ricordata per «vicino a me», l'esportazione verso il
 * calendario del telefono e il collegamento con Google Calendar.
 */

beforeEach(function (): void {
    $this->city = testCity();
    $this->category = testCategory();
    freezeLocal($this->city, '2026-11-12 12:00');
    $this->user = User::factory()->create(['first_name' => 'Giulia']);
});

afterEach(fn () => Carbon::setTestNow());

it('ritrova fra le proprie serate solo le date in cui il biglietto è passato dal controllo, sul sito e nell app', function (): void {
    Notification::fake();
    (new RolesAndPermissionsSeeder)->run();
    $stasera = occurrenceAtLocal($this->city, $this->category, '2026-11-12 21:00', '2026-11-12 23:30', ['booking_enabled' => true], ['title' => 'Concerto con biglietto']);
    $stasera->event->venue->update(['ticketing_enabled' => true]);
    $assente = occurrenceAtLocal($this->city, $this->category, '2026-11-12 20:00', '2026-11-12 22:00', ['booking_enabled' => true], ['title' => 'Prenotata ma saltata'], $stasera->event->venue);
    $gestore = User::factory()->create();
    $stasera->event->venue->members()->attach($gestore, ['role' => 'owner']);

    $prenotazione = app(TicketingService::class)->reserve($this->user, $stasera, ['Giulia', 'Marco'], (string) Str::uuid(), false);
    app(TicketingService::class)->reserve($this->user, $assente, ['Giulia'], (string) Str::uuid(), false);

    $this->actingAs($this->user)->get('/le-mie-serate')->assertOk()->assertSee(__('account.attended.empty'))->assertDontSee('Concerto con biglietto');
    $this->getJson('/api/v1/me/attended')->assertOk()->assertJsonCount(0, 'data');

    // Alla porta passa solo il primo dei due biglietti.
    freezeLocal($this->city, '2026-11-12 21:10');
    app(TicketingService::class)->checkIn($stasera, $prenotazione->tickets->first()->code, $gestore);
    freezeLocal($this->city, '2026-11-13 10:00');

    $this->get('/le-mie-serate')->assertOk()->assertSee('Concerto con biglietto')->assertDontSee('Prenotata ma saltata');
    $api = $this->getJson('/api/v1/me/attended')->assertOk()->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.occurrence_id', $stasera->id)
        ->assertJsonPath('data.0.checked_in', 1)
        ->assertJsonPath('data.0.venue.slug', $stasera->event->venue->slug)
        ->assertJsonPath('meta.next_page', null);
    expect($api->json('data.0.url'))->toContain('/eventi/');

    // Un'altra persona non vede le serate di Giulia.
    $this->actingAs(User::factory()->create())->getJson('/api/v1/me/attended')->assertOk()->assertJsonCount(0, 'data');
    $this->app['auth']->forgetGuards();
    $this->get('/le-mie-serate')->assertRedirect(route('login'));
});

it('ricorda la posizione di un ospite nel browser e quella di chi accede nel suo profilo, e la dimentica a richiesta', function (): void {
    // Senza consenso esplicito la posizione non si ricorda.
    $this->postJson('/posizione-ricordata', ['lat' => 45.4064, 'lng' => 11.8768])->assertUnprocessable()->assertJsonValidationErrors('remember');
    $this->postJson('/posizione-ricordata', ['lat' => 120, 'lng' => 11.8768, 'remember' => true])->assertUnprocessable()->assertJsonValidationErrors('lat');

    $ospite = $this->postJson('/posizione-ricordata', ['lat' => 45.4064, 'lng' => 11.8768, 'remember' => true])->assertOk()
        ->assertJsonPath('data.lat', 45.4064)
        ->assertJsonPath('data.expires_at', now()->addMonthsNoOverflow(6)->timestamp)
        ->assertCookie(RememberedLocation::COOKIE)
        ->assertHeader('Cache-Control', 'no-store, private');
    $biscotti = cookiesFrom($ospite);
    requestWithCookies('GET', '/posizione-ricordata', $biscotti)->assertOk()->assertJsonPath('data.lng', 11.8768);
    requestWithCookies('GET', '/posizione-ricordata')->assertOk()->assertJsonPath('data', null);

    // Chi ha un account la ritrova anche su un altro browser, senza il cookie.
    $this->actingAs($this->user)->postJson('/posizione-ricordata', ['lat' => 45.41, 'lng' => 11.88, 'remember' => true])->assertOk();
    $salvato = $this->user->fresh();
    expect($salvato->remembered_location['lat'])->toEqual(45.41)
        ->and((float) $salvato->location_lat)->toEqual(45.41)
        ->and($salvato->location_expires_at)->not->toBeNull();
    $this->getJson('/posizione-ricordata')->assertOk()->assertJsonPath('data.lat', 45.41);

    $this->deleteJson('/posizione-ricordata')->assertOk()->assertJsonPath('data', null)->assertCookieExpired(RememberedLocation::COOKIE);
    $dimenticato = $this->user->fresh();
    expect($dimenticato->remembered_location)->toBeNull()
        ->and($dimenticato->location_lat)->toBeNull()
        ->and($dimenticato->location_lng)->toBeNull();
    $this->getJson('/posizione-ricordata')->assertOk()->assertJsonPath('data', null);

    // Dopo sei mesi una posizione ricordata non vale più.
    $this->postJson('/posizione-ricordata', ['lat' => 45.40, 'lng' => 11.87, 'remember' => true])->assertOk();
    $this->travel(7)->months();
    $this->getJson('/posizione-ricordata')->assertOk()->assertJsonPath('data', null);
});

it('sincronizza dall app la posizione ricordata senza cookie, senza sostituire una più recente, e la cancella', function (): void {
    $this->getJson('/api/v1/me/location')->assertUnauthorized();
    $this->postJson('/api/v1/me/location', ['lat' => 45.4, 'lng' => 11.8, 'remember' => true])->assertUnauthorized();

    $token = $this->user->createToken('Telefono')->plainTextToken;
    $this->withToken($token)->getJson('/api/v1/me/location')->assertOk()->assertJsonPath('data', null);

    $this->withToken($token)->postJson('/api/v1/me/location', ['lat' => 45.4064, 'lng' => 11.8768, 'remember' => true])->assertOk()
        ->assertJsonPath('data.lat', 45.4064)
        ->assertCookieMissing(RememberedLocation::COOKIE);
    $this->withToken($token)->getJson('/api/v1/me/location')->assertOk()->assertJsonPath('data.lng', 11.8768);

    // Una posizione vista due mesi fa sul telefono non sostituisce quella di oggi.
    $vecchia = now()->subMonthsNoOverflow(2)->timestamp;
    $this->withToken($token)->postJson('/api/v1/me/location', ['lat' => 44.0, 'lng' => 10.0, 'remember' => true, 'observed_at' => $vecchia])->assertOk()
        ->assertJsonPath('data.lat', 45.4064);
    $this->withToken($token)->postJson('/api/v1/me/location', ['lat' => 44.0, 'lng' => 10.0, 'remember' => true, 'observed_at' => now()->subYear()->timestamp])->assertUnprocessable();
    $this->withToken($token)->postJson('/api/v1/me/location', ['lat' => 44.0, 'lng' => 200, 'remember' => true])->assertUnprocessable();

    // Il sito vede la stessa posizione salvata dall'app.
    $this->actingAs($this->user->fresh())->getJson('/posizione-ricordata')->assertOk()->assertJsonPath('data.lat', 45.4064);

    $this->withToken($token)->deleteJson('/api/v1/me/location')->assertOk()->assertJsonPath('data', null);
    expect($this->user->fresh()->remembered_location)->toBeNull();
    $this->withToken($token)->getJson('/api/v1/me/location')->assertOk()->assertJsonPath('data', null);
});

it('esporta verso il calendario del telefono le sole date salvate o quelle filtrate, escludendo le annullate', function (): void {
    $teatro = testCategory(['name' => 'Teatro']);
    $salvata = occurrenceAtLocal($this->city, $this->category, '2026-11-14 21:00', '2026-11-14 23:00', event: ['title' => 'Concerto salvato']);
    $annullata = occurrenceAtLocal($this->city, $this->category, '2026-11-15 21:00', event: ['title' => 'Concerto annullato']);
    occurrenceAtLocal($this->city, $teatro, '2026-11-16 21:00', event: ['title' => 'Prosa non salvata']);
    $lontana = occurrenceAtLocal($this->city, $this->category, '2027-01-20 21:00', event: ['title' => 'Concerto di gennaio']);

    $this->getJson('/api/v1/me/calendar/export?saved_only=1')->assertUnauthorized();

    $this->actingAs($this->user);
    $this->postJson('/salvataggi', ['occurrence_ids' => [$salvata->id, $annullata->id, $lontana->id]])->assertOk();
    $annullata->update(['status' => OccurrenceStatus::Cancelled]);

    $solo = $this->getJson('/api/v1/me/calendar/export?saved_only=1&days=30')->assertOk()
        ->assertJsonCount(1, 'data.events')
        ->assertJsonPath('data.events.0.id', $salvata->id)
        ->assertJsonPath('data.events.0.title', 'Concerto salvato')
        ->assertJsonPath('data.events.0.start', $salvata->starts_at->getTimestamp() * 1000)
        ->assertJsonPath('data.events.0.timezone', 'Europe/Rome')
        ->assertJsonPath('data.events.0.allDay', false);
    expect($solo->json('data.ics'))->toContain('Concerto salvato')->not->toContain('Prosa non salvata', 'Concerto annullato', 'Concerto di gennaio')
        ->and($solo->headers->get('Cache-Control'))->toContain('private', 'no-store');

    // Con un orizzonte più lungo entra anche la data di gennaio.
    expect($this->getJson('/api/v1/me/calendar/export?saved_only=1&days=90')->assertOk()->json('data.events.*.id'))->toBe([$salvata->id, $lontana->id]);

    // Senza «solo salvate» valgono i filtri del catalogo.
    $filtrato = $this->getJson('/api/v1/me/calendar/export?days=7&category='.$teatro->slug)->assertOk();
    expect($filtrato->json('data.events.*.title'))->toBe(['Prosa non salvata']);
    $this->getJson('/api/v1/me/calendar/export?days=365')->assertUnprocessable();
});

it('collega Google Calendar dall app e dal sito, cambia i filtri e poi lo scollega', function (): void {
    config(['google-calendar.redirect_uri' => null, 'google-calendar.client_id' => 'test-client', 'google-calendar.client_secret' => 'test-secret']);
    Http::preventStrayRequests();
    Queue::fake();
    Http::fake([
        'https://oauth2.googleapis.com/token' => Http::response(['access_token' => 'nuovo-accesso', 'refresh_token' => 'nuovo-rinnovo', 'scope' => GoogleCalendarClient::SCOPE, 'expires_in' => 3600]),
        'https://openidconnect.googleapis.com/v1/userinfo' => Http::response(['sub' => 'google-giulia']),
        'https://oauth2.googleapis.com/revoke' => Http::response([], 200),
    ]);

    $this->get(route('google-calendar.index'))->assertRedirect(route('login'));
    $this->actingAs($this->user);
    $this->getJson('/api/v1/me/calendar/google')->assertOk()
        ->assertJsonPath('data.configured', true)->assertJsonPath('data.connected', false)->assertJsonPath('data.event_count', 0);

    // L'app chiede un collegamento firmato da aprire nel browser con lo stesso account.
    $link = $this->postJson('/api/v1/me/calendar/google/manage', ['categories' => [$this->category->slug], 'days' => 7])->assertOk()->json('data.url');
    $this->actingAs(User::factory()->create())->get($link)->assertForbidden();
    $verso = $this->actingAs($this->user)->get($link)->assertRedirect();
    expect(strtok((string) $verso->headers->get('Location'), '?'))->toBe(route('google-calendar.index'));
    parse_str((string) parse_url((string) $verso->headers->get('Location'), PHP_URL_QUERY), $scelta);
    expect($scelta)->toEqual(['categories' => [$this->category->slug], 'days' => '7']);
    $this->get(route('google-calendar.index', ['categories' => [$this->category->slug], 'days' => 7]))->assertOk()
        ->assertSee(__('google_calendar.connect'))->assertSee($this->category->name);

    // Il consenso di Google: si parte dal sito e si torna con lo stesso stato.
    $verso = $this->post(route('google-calendar.connect'), ['categories' => [$this->category->slug], 'days' => 7])->assertRedirect();
    expect($verso->headers->get('Location'))->toStartWith('https://accounts.google.com/o/oauth2/v2/auth?');
    parse_str((string) parse_url((string) $verso->headers->get('Location'), PHP_URL_QUERY), $query);
    Http::assertNothingSent();

    $this->get(route('google-calendar.callback', ['state' => $query['state'], 'code' => 'codice-di-google']))
        ->assertRedirect(route('google-calendar.index'))
        ->assertSessionHas('google_calendar_message', __('google_calendar.queued'));
    $collegamento = GoogleCalendarConnection::query()->sole();
    expect($collegamento->user_id)->toBe($this->user->id)
        ->and($collegamento->enabled)->toBeTrue()
        ->and($collegamento->refresh_token)->toBe('nuovo-rinnovo')
        ->and($collegamento->selection)->toEqual(['categories' => [$this->category->slug], 'days' => 7]);
    Queue::assertPushed(SyncGoogleCalendar::class, fn (SyncGoogleCalendar $job): bool => $job->userId === $this->user->id);
    // Lo stesso ritorno non si può riusare.
    $this->get(route('google-calendar.callback', ['state' => $query['state'], 'code' => 'codice-di-google']))->assertForbidden();

    $stato = $this->getJson('/api/v1/me/calendar/google')->assertOk()->assertJsonPath('data.connected', true)->assertJsonPath('data.selection.days', 7);
    expect($stato->getContent())->not->toContain('nuovo-rinnovo', 'nuovo-accesso');

    $this->patch(route('google-calendar.update'), ['days' => 30, 'free' => true])->assertRedirect(route('google-calendar.index'));
    expect($collegamento->fresh()->selection)->toBe(['days' => 30, 'free' => true]);
    // La sincronizzazione è già in coda (lavoro unico per persona): legge i filtri nuovi senza accodarne un doppione.
    Queue::assertPushed(SyncGoogleCalendar::class, 1);

    $this->delete(route('google-calendar.disconnect'))->assertRedirect(route('google-calendar.index'))
        ->assertSessionHas('google_calendar_message', __('google_calendar.disconnected'));
    expect($collegamento->fresh()->enabled)->toBeFalse()->and($collegamento->fresh()->refresh_token)->toBeNull();
    Http::assertSent(fn ($request): bool => $request->url() === 'https://oauth2.googleapis.com/revoke' && $request['token'] === 'nuovo-rinnovo');
    $this->getJson('/api/v1/me/calendar/google')->assertOk()->assertJsonPath('data.connected', false);
});

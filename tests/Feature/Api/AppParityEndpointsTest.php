<?php

declare(strict_types=1);

use App\Enums\BookingStatus;
use App\Enums\EventStatus;
use App\Enums\FollowableType;
use App\Models\EventShareLink;
use App\Models\Follow;
use App\Models\User;
use App\Models\Venue;
use App\Models\VenueApplication;
use App\Services\Ticketing\TicketingService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

/*
 * Le rotte e i campi che l'app riceve per stare al passo con il sito
 * (piano del 19/11/2026, pacchetto `api-app`): prenotazioni di una data per
 * chi la gestisce, misure delle schede, «Le mie richieste», calendario
 * personale e link brevi da condividere.
 */

beforeEach(function (): void {
    Notification::fake();
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->city = testCity();
    $this->category = testCategory();
});

afterEach(function (): void {
    Carbon::setTestNow();
});

function managedDate(): array
{
    $date = occurrenceAt(test()->city, test()->category, now()->addHours(2)->format('Y-m-d H:i:s'), now()->addHours(5)->format('Y-m-d H:i:s'), ['booking_enabled' => true, 'booking_capacity' => 100, 'booking_limit' => 10]);
    $date->event->venue->update(['ticketing_enabled' => true]);
    $owner = User::factory()->create();
    $date->event->venue->members()->attach($owner, ['role' => 'owner']);

    return [$date, $owner];
}

it('elenca le prenotazioni di una data a chi la gestisce, con filtro per stato, ricerca e contatori', function (): void {
    [$date, $owner] = managedDate();
    $service = app(TicketingService::class);

    $ada = User::factory()->create(['email' => 'ada@example.test']);
    $confermata = $service->reserve($ada, $date, ['Ada Lovelace'], (string) Str::uuid(), false);
    $annullata = $service->reserve(User::factory()->create(), $date, ['Bruno Bianchi'], (string) Str::uuid(), false);
    $service->cancel($annullata, $owner, staff: true);
    $entrata = $service->reserve(User::factory()->create(), $date, ['Carla Verdi'], (string) Str::uuid(), false);
    $service->checkIn($date, $entrata->tickets[0]->code, $owner);

    Sanctum::actingAs($owner);
    $url = '/api/v1/management/dates/'.$date->id.'/bookings';

    $tutte = $this->getJson($url)->assertOk()->assertHeader('Cache-Control', 'max-age=0, no-store, private');
    expect($tutte->json('data'))->toHaveCount(3)
        ->and($tutte->json('meta.counts'))->toBe(['all' => 3, 'confirmed' => 2, 'checked_in' => 1, 'waitlisted' => 0, 'cancelled' => 1])
        ->and($tutte->json('meta.next_page'))->toBeNull()
        ->and($tutte->json('data.0.id'))->toBe($entrata->id);

    $soloConfermate = $this->getJson($url.'?status=confirmed')->assertOk();
    expect(collect($soloConfermate->json('data'))->pluck('id')->sort()->values()->all())->toBe([$confermata->id, $entrata->id])
        ->and(collect($soloConfermate->json('data'))->pluck('status')->unique()->all())->toBe([BookingStatus::Confirmed->value])
        ->and($soloConfermate->json('meta.status'))->toBe('confirmed');

    $this->getJson($url.'?status=cancelled')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $annullata->id);
    $this->getJson($url.'?status=checked_in')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.tickets.0.status', 'checked_in')->assertJsonPath('data.0.tickets.0.code', null);

    $ricerca = $this->getJson($url.'?q=lovelace')->assertOk();
    expect($ricerca->json('data'))->toHaveCount(1)
        ->and($ricerca->json('data.0.email'))->toBe('ada@example.test')
        ->and($ricerca->json('data.0.tickets.0.code'))->toBe($confermata->tickets[0]->code)
        ->and($ricerca->json('data.0.tickets_count'))->toBe(1);
    $this->getJson($url.'?q=ada@example')->assertOk()->assertJsonCount(1, 'data');
    // I caratteri jolly di LIKE si cercano per quello che sono.
    $this->getJson($url.'?q=%25')->assertOk()->assertJsonCount(0, 'data');

    $this->getJson($url.'?status=sconosciuto')->assertUnprocessable();
});

it('pagina le prenotazioni a trenta per volta', function (): void {
    [$date, $owner] = managedDate();
    $service = app(TicketingService::class);

    foreach (range(1, 31) as $i) {
        $service->reserve(User::factory()->create(), $date, ['Persona '.$i], (string) Str::uuid(), false);
    }

    Sanctum::actingAs($owner);
    $url = '/api/v1/management/dates/'.$date->id.'/bookings';

    $this->getJson($url)->assertOk()->assertJsonCount(30, 'data')->assertJsonPath('meta.next_page', 2);
    $this->getJson($url.'?page=2')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('meta.next_page', null);
});

it('nega le prenotazioni a chi non gestisce la data, anche al personale degli ingressi', function (): void {
    [$date] = managedDate();
    $url = '/api/v1/management/dates/'.$date->id.'/bookings';

    $this->getJson($url)->assertUnauthorized();

    Sanctum::actingAs(User::factory()->create());
    $this->getJson($url)->assertForbidden();

    $staff = User::factory()->create();
    $date->checkinStaff()->attach($staff);
    Sanctum::actingAs($staff);
    $this->getJson($url)->assertForbidden();
});

it('registra le misure di una scheda dall\'app solo con il consenso e senza identificativi', function (): void {
    $date = occurrenceAt($this->city, $this->category, now()->addDay()->format('Y-m-d H:i:s'));
    $event = $date->event;
    $url = '/api/v1/reports/content/event/'.$event->id;

    $this->postJson($url, ['metric' => 'views'])->assertNoContent();
    expect(DB::table('event_views_daily')->where('event_id', $event->id)->sum('views'))->toBe(0);

    $this->postJson($url, ['metric' => 'views', 'consent' => true])->assertNoContent();
    $this->postJson($url, ['metric' => 'shares', 'consent' => true, 'occurrence' => $date->id])->assertNoContent();

    $riga = DB::table('event_views_daily')->where('event_id', $event->id)->first();
    expect((int) $riga->views)->toBe(1)
        ->and((int) $riga->shares)->toBe(1)
        ->and((int) DB::table('occurrence_views_daily')->where('occurrence_id', $date->id)->value('shares'))->toBe(1)
        ->and(array_keys((array) $riga))->not->toContain('ip', 'user_id', 'installation_id');

    $this->postJson($url, ['metric' => 'inventata', 'consent' => true])->assertUnprocessable();
    $this->postJson($url, ['metric' => 'views', 'consent' => true, 'occurrence' => 999999])->assertNotFound();
    $this->postJson('/api/v1/reports/content/venue/'.$event->venue_id, ['metric' => 'website_clicks', 'consent' => true])->assertNoContent();
    expect((int) DB::table('profile_views_daily')->where('profile_type', 'venue')->where('profile_id', $event->venue_id)->value('website_clicks'))->toBe(1);
});

it('risponde 404 per una scheda non pubblicata e quando le misure dall\'app sono spente', function (): void {
    $date = occurrenceAt($this->city, $this->category, now()->addDay()->format('Y-m-d H:i:s'), event: ['status' => EventStatus::Draft]);

    $this->postJson('/api/v1/reports/content/event/'.$date->event_id, ['metric' => 'views'])->assertNotFound();
    $this->postJson('/api/v1/reports/content/event/'.$date->event_id, ['metric' => 'views', 'consent' => true])->assertNotFound();
    expect(DB::table('event_views_daily')->count())->toBe(0);

    $pubblicato = occurrenceAt($this->city, $this->category, now()->addDay()->format('Y-m-d H:i:s'));
    config(['eventi.analytics.app_content_metrics' => false]);
    $this->postJson('/api/v1/reports/content/event/'.$pubblicato->event_id, ['metric' => 'views', 'consent' => true])->assertNotFound();
    expect(DB::table('event_views_daily')->count())->toBe(0);
});

it('limita le misure di una scheda: tetto orario per indirizzo e throttle della rotta', function (): void {
    $event = occurrenceAt($this->city, $this->category, now()->addDay()->format('Y-m-d H:i:s'))->event;
    $url = '/api/v1/reports/content/event/'.$event->id;

    foreach (range(1, 35) as $i) {
        $this->postJson($url, ['metric' => 'views', 'consent' => true])->assertNoContent();
    }
    expect((int) DB::table('event_views_daily')->where('event_id', $event->id)->value('views'))->toBe(30);

    foreach (range(36, 60) as $i) {
        $this->postJson($url, ['metric' => 'poster_clicks'])->assertNoContent();
    }
    $this->postJson($url, ['metric' => 'poster_clicks'])->assertTooManyRequests();
});

it('mostra all\'app soltanto le proprie richieste, anche quelle da confermare, senza note interne', function (): void {
    $user = User::factory()->create();
    $mia = VenueApplication::factory()->create(['user_id' => $user->id, 'venue_name' => 'Circolo Mio']);
    $daConfermare = VenueApplication::factory()->create(['user_id' => $user->id, 'venue_name' => 'Bozza Mia', 'email_confirmed_at' => null]);
    $accolta = VenueApplication::factory()->approved()->create(['user_id' => $user->id, 'venue_name' => 'Locale Accolto']);
    $accolta->venue->members()->attach($user, ['role' => 'owner', 'accepted_at' => now()]);
    $rifiutata = VenueApplication::factory()->rejected()->create(['user_id' => $user->id, 'venue_name' => 'Respinta']);
    VenueApplication::factory()->create(['venue_name' => 'Di un altro']);

    $this->getJson('/api/v1/me/venue-applications')->assertUnauthorized();

    Sanctum::actingAs($user);
    $risposta = $this->getJson('/api/v1/me/venue-applications')->assertOk()->assertHeader('Cache-Control', 'no-store, private');
    $perId = collect($risposta->json('data'))->keyBy('id');

    expect($perId->keys()->sort()->values()->all())->toBe(collect([$mia->id, $daConfermare->id, $accolta->id, $rifiutata->id])->sort()->values()->all())
        ->and($perId[$mia->id]['state'])->toBe('pending')
        ->and($perId[$mia->id]['state_label'])->toBe(__('venue_applications.account.state.pending'))
        ->and($perId[$daConfermare->id]['state'])->toBe('awaiting')
        ->and($perId[$daConfermare->id]['can_confirm'])->toBeTrue()
        ->and($perId[$accolta->id]['state'])->toBe('approved')
        ->and($perId[$accolta->id]['panel_url'])->toContain('/gestione/'.$accolta->venue->slug)
        ->and($perId[$accolta->id]['public_url'])->toContain($accolta->venue->slug)
        ->and($perId[$rifiutata->id]['state'])->toBe('rejected')
        ->and($perId[$rifiutata->id]['panel_url'])->toBeNull()
        ->and($risposta->json('meta.manage_url'))->toBe(route('account.applications'))
        ->and($risposta->getContent())->not->toContain('Documentazione insufficiente', 'contact_phone', 'contact_email', 'Di un altro');
});

it('restituisce il calendario personale secondo ciò che la persona segue', function (): void {
    freezeLocal($this->city, '2026-12-01 09:00:00');
    $seguito = Venue::factory()->approved()->create(['city_id' => $this->city->id]);
    occurrenceAtLocal($this->city, $this->category, '2026-12-05 21:00:00', event: ['title' => 'Serata del locale seguito'], venue: $seguito);
    occurrenceAtLocal($this->city, $this->category, '2026-12-06 21:00:00', event: ['title' => 'Serata qualunque']);

    $generale = $this->getJson('/api/v1/calendar?month=2026-12&personal=1')->assertOk();
    expect($generale->json('meta.total'))->toBe(2)
        ->and($generale->json('meta.personal'))->toBeFalse();

    $user = User::factory()->create();
    Sanctum::actingAs($user);

    // Nessun interesse espresso: il mese della città, e il client lo sa.
    $senza = $this->getJson('/api/v1/calendar?month=2026-12&personal=1')->assertOk();
    expect($senza->json('meta.total'))->toBe(2)
        ->and($senza->json('meta.personal'))->toBeTrue()
        ->and($senza->json('meta.interests'))->toBeFalse()
        ->and($senza->headers->get('Cache-Control'))->toContain('no-store');

    Follow::query()->create(['user_id' => $user->id, 'followable_type' => FollowableType::Venue->value, 'followable_id' => $seguito->id, 'notify' => true]);

    $personale = $this->getJson('/api/v1/calendar?month=2026-12&personal=1')->assertOk();
    expect($personale->json('meta.total'))->toBe(1)
        ->and($personale->json('meta.interests'))->toBeTrue()
        ->and($personale->json('data.0.business_date'))->toBe('2026-12-05')
        ->and($personale->json('data.0.titles'))->toBe(['Serata del locale seguito']);

    // Senza `personal` resta il calendario di tutti, anche con un token.
    expect($this->getJson('/api/v1/calendar?month=2026-12')->assertOk()->json('meta.total'))->toBe(2);
    $this->getJson('/api/v1/calendar?month=2026-12&personal=forse')->assertUnprocessable();
});

it('mette nella scheda evento i link brevi da condividere, stabili fra una richiesta e l\'altra', function (): void {
    $event = occurrenceAt($this->city, $this->category, now()->addDay()->format('Y-m-d H:i:s'))->event;

    $links = $this->getJson('/api/v1/events/'.$event->slug)->assertOk()->json('data.share_links');

    expect(array_keys($links))->toBe(['native', 'whatsapp', 'telegram', 'email'])
        ->and($links['whatsapp']['url'])->toMatch('#/s/[A-Za-z0-9]{7}$#');

    $code = Str::afterLast($links['native']['url'], '/s/');
    expect(EventShareLink::query()->where('code', $code)->value('event_id'))->toBe($event->id);

    $this->flushHeaders();
    expect($this->getJson('/api/v1/events/'.$event->slug.'?city='.$this->city->slug)->json('data.share_links'))->toBe($links);
});

it('dice nell\'elenco dei locali quali si possono rivendicare senza una query per locale', function (): void {
    $libero = Venue::factory()->approved()->create(['city_id' => $this->city->id]);
    $gestito = Venue::factory()->approved()->create(['city_id' => $this->city->id]);
    $gestito->members()->attach(User::factory()->create(), ['role' => 'owner']);

    DB::enableQueryLog();
    $data = collect($this->getJson('/api/v1/venues')->assertOk()->json('data'))->keyBy('id');
    $memberQueries = collect(DB::getQueryLog())->filter(fn (array $query): bool => str_contains($query['query'], 'venue_user'))->count();
    DB::disableQueryLog();

    expect($data[$libero->id]['claimable'])->toBeTrue()
        ->and($data[$libero->id]['claim_url'])->toContain('/rivendica')
        ->and($data[$gestito->id]['claimable'])->toBeFalse()
        ->and($memberQueries)->toBeLessThanOrEqual(1);
});

it('dice nelle date con il locale incluso quali locali si possono rivendicare, senza una query per data', function (): void {
    $gestito = null;
    foreach (range(1, 6) as $n) {
        $venue = Venue::factory()->approved()->create(['city_id' => $this->city->id]);
        if ($n === 1) {
            $venue->members()->attach(User::factory()->create(), ['role' => 'owner']);
            $gestito = $venue;
        }
        occurrenceAt($this->city, $this->category, now()->addDays($n)->format('Y-m-d H:i:s'), venue: $venue);
    }

    DB::enableQueryLog();
    $data = $this->getJson('/api/v1/events?include=venue&per_page=30')->assertOk()->json('data');
    $memberQueries = collect(DB::getQueryLog())->filter(fn (array $query): bool => str_contains($query['query'], 'venue_user'))->count();
    DB::disableQueryLog();

    $venues = collect($data)->pluck('venue')->keyBy('id');
    expect($venues)->toHaveCount(6)
        ->and($venues[$gestito->id]['claimable'])->toBeFalse()
        ->and($venues->where('claimable', true))->toHaveCount(5)
        ->and($memberQueries)->toBeLessThanOrEqual(1);
});

it('scrive i messaggi dell\'API in prima persona singolare', function (): void {
    expect(__('api.auth.registration_sent'))->not->toContain('abbiamo')
        ->and(__('api.errors.email_confirmation_pending'))->not->toContain('abbiamo');
});

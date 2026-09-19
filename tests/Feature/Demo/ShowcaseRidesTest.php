<?php

declare(strict_types=1);

use App\Console\Commands\InvestorDemoCommand;
use App\Console\Commands\ShowcaseDemoCommand;
use App\Enums\EventStatus;
use App\Enums\RideRequestStatus;
use App\Enums\RideStatus;
use App\Enums\VenueType;
use App\Models\Category;
use App\Models\Event;
use App\Models\EventOccurrence;
use App\Models\RideConversation;
use App\Models\RideOffer;
use App\Models\RideRequest;
use App\Models\RideReview;
use App\Models\User;
use App\Models\Venue;
use App\Services\Carpool\CarpoolAccess;
use App\Services\Carpool\RideChat;
use App\Services\Carpool\RideDiscovery;
use App\Services\Carpool\RideReviews;
use Carbon\CarbonImmutable;
use Database\Seeders\CategorySeeder;
use Database\Seeders\EventFeatureSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

/** @return list<int> */
function showcaseRideUserIds(): array
{
    return User::withTrashed()->whereIn('email', ShowcaseDemoCommand::emails(ShowcaseDemoCommand::catalog()))->pluck('id')->map(fn ($id): int => (int) $id)->all();
}

/** @return list<int> */
function showcaseRideDates(): array
{
    return EventOccurrence::query()->whereIn('event_id', Event::query()->where('source_ref', 'like', ShowcaseDemoCommand::PREFIX.'%')->select('id'))
        ->pluck('id')->map(fn ($id): int => (int) $id)->all();
}

function showcaseRidePerson(int $index): User
{
    return User::query()->where('email', ShowcaseDemoCommand::emails(ShowcaseDemoCommand::catalog())[$index])->firstOrFail();
}

/** Una data del catalogo investitori, all'ora locale indicata. */
function showcaseInvestorDate(string $ref, string $local): EventOccurrence
{
    $event = Event::factory()->create(['city_id' => test()->city->id, 'category_id' => Category::query()->where('slug', 'cinema')->firstOrFail()->id,
        'venue_id' => Venue::query()->first()->id, 'is_demo' => true, 'status' => EventStatus::Published, 'published_at' => now(),
        'source_ref' => InvestorDemoCommand::PREFIX.$ref]);

    return EventOccurrence::factory()->create(['event_id' => $event->id, 'starts_at' => CarbonImmutable::parse($local, 'Europe/Rome')->utc(), 'ends_at' => null, 'doors_at' => null]);
}

beforeEach(function (): void {
    Queue::fake();
    Storage::fake('public');
    Mail::fake();
    Notification::fake();
    config(['community.enabled' => true, 'community.phone_hash_key' => 'test-fingerprint-key', 'carpool.enabled' => true, 'carpool.new_rides' => true, 'carpool.chat_enabled' => true]);
    $this->city = testCity(['is_active' => true]);
    $this->seed([CategorySeeder::class, EventFeatureSeeder::class]);
    foreach (VenueType::cases() as $type) {
        Venue::factory()->create(['city_id' => $this->city->id, 'type' => $type, 'status' => 'approved']);
    }
    // Mercoledì 16 settembre: la vetrina va da lunedì 21 a domenica 27.
    $this->travelTo(CarbonImmutable::parse('2026-09-16 12:00', 'Europe/Rome'));
});

it('offers rides that an eligible viewer finds with the same query as the rides page', function (): void {
    $this->artisan('demo:showcase')->assertSuccessful();
    $catalog = ShowcaseDemoCommand::catalog();
    $ids = showcaseRideUserIds();

    $offers = RideOffer::query()->whereIn('driver_id', $ids)->get();
    expect($offers)->toHaveCount(count($catalog['rides']))
        ->and($offers->pluck('driver_id')->unique())->toHaveCount(6)
        ->and($offers->pluck('occurrence_id')->unique())->toHaveCount(9)
        ->and($offers->where('leg.value', 'return'))->toHaveCount(4)
        ->and($offers->every(fn (RideOffer $offer) => $offer->status === RideStatus::Open && in_array($offer->occurrence_id, showcaseRideDates(), true)
            && str_contains((string) $offer->note, 'Passaggio dimostrativo')))->toBeTrue();

    // Ogni persona demo soddisfa i requisiti del servizio; chi guida ha dichiarato di farlo.
    $access = app(CarpoolAccess::class);
    $people = User::query()->whereIn('id', $ids)->with('carpoolProfile')->get();
    expect($people->every(fn (User $user) => $access->eligible($user) && $user->carpoolProfile->push_enabled === false))->toBeTrue()
        ->and($people->whereIn('id', $offers->pluck('driver_id'))->every(fn (User $user) => $user->carpoolProfile->driver_declared_at !== null))->toBeTrue();

    // Una persona vera e idonea vede tutte le offerte, data per data, con la query della pagina.
    $viewer = carpoolPerson();
    $found = 0;
    foreach ($offers->groupBy('occurrence_id') as $dateId => $group) {
        $listed = app(RideDiscovery::class)->offers($viewer, EventOccurrence::query()->findOrFail($dateId), [])->pluck('id')->sort()->values()->all();
        expect($listed)->toBe($group->pluck('id')->sort()->values()->all());
        $found += count($listed);
    }
    expect($found)->toBe($offers->count());

    // La pagina dei passaggi della data li mostra, e la scheda dell'evento porta lì.
    $jam = $offers->firstWhere('zone', 'Arcella, piazzale Azzurri d’Italia');
    $this->actingAs($viewer)->get(route('carpool.dates', $jam->occurrence_id))->assertOk()
        ->assertSee('Arcella, piazzale Azzurri', false)->assertSee('Marco Zanon');
    $event = EventOccurrence::query()->findOrFail($jam->occurrence_id)->event;
    $this->get(route('events.show', ['slug' => $event->slug]))->assertOk()->assertSee(route('carpool.dates', $jam->occurrence_id), false);
});

it('keeps seats, occupancies and chats consistent with the service rules', function (): void {
    $this->artisan('demo:showcase')->assertSuccessful();
    $catalog = ShowcaseDemoCommand::catalog();
    $ids = showcaseRideUserIds();
    $requests = RideRequest::query()->whereIn('user_id', $ids)->get();

    $expected = array_count_values(array_column($catalog['ride_requests'], 3));
    expect($requests->countBy(fn (RideRequest $r) => $r->status->value)->all())->toEqualCanonicalizing($expected);

    foreach (RideOffer::query()->whereIn('driver_id', $ids)->with('requests')->get() as $offer) {
        $accepted = $offer->requests->where('status', RideRequestStatus::Accepted);
        $passengers = DB::table('ride_occupancies')->where('ride_offer_id', $offer->id)->whereNotNull('ride_request_id')->pluck('ride_request_id')->sort()->values()->all();
        expect($accepted->sum('seats'))->toBeLessThan($offer->capacity)
            ->and($passengers)->toBe($accepted->pluck('id')->sort()->values()->all())
            ->and(DB::table('ride_occupancies')->where('ride_offer_id', $offer->id)->whereNull('ride_request_id')->where('user_id', $offer->driver_id)->count())->toBe(1)
            ->and($offer->active_key)->toBe($offer->driver_id.':'.$offer->occurrence_id.':'.$offer->leg->value);
    }
    foreach ($requests as $request) {
        $active = in_array($request->status, [RideRequestStatus::Pending, RideRequestStatus::Accepted], true);
        expect($request->active_key)->toBe($active ? $request->user_id.':'.$request->ride_offer_id : null)
            ->and($request->companions_adult)->toBe($request->seats > 1)
            ->and($request->closed_at === null)->toBe($active);
    }

    // Una chat per ogni richiesta accettata, con i due partecipanti; i messaggi si leggono cifrati come quelli veri.
    $accepted = $requests->where('status', RideRequestStatus::Accepted);
    expect(RideConversation::query()->whereIn('ride_request_id', $requests->pluck('id'))->count())->toBe($accepted->count())
        ->and(DB::table('ride_chat_preferences')->whereIn('user_id', $ids)->count())->toBe($accepted->count() * 2);
    $first = $accepted->firstWhere('user_id', showcaseRidePerson(12)->id);
    $messages = app(RideChat::class)->messages(showcaseRidePerson(12), $first->conversation);
    expect(array_column($messages, 'body'))->toBe(['Ciao Marco! Ci troviamo al piazzale alle 20:50?', 'Sì, sono con una Panda grigia davanti all’edicola. A dopo!'])
        ->and(array_column($messages, 'mine'))->toBe([true, false])
        ->and(DB::table('chat_messages')->where('is_encrypted', false)->count())->toBe(0)
        ->and(app(RideChat::class)->writable(showcaseRidePerson(12), $first->conversation))->toBeTrue();
});

it('survives carpool:maintain and never delivers anything, even when reminders come due', function (): void {
    $this->artisan('demo:showcase')->assertSuccessful();
    $ids = showcaseRideUserIds();
    $state = fn (): array => [
        RideOffer::query()->whereIn('driver_id', $ids)->orderBy('id')->pluck('status', 'id')->map(fn ($s) => $s->value)->all(),
        RideRequest::query()->whereIn('user_id', $ids)->orderBy('id')->pluck('status', 'id')->map(fn ($s) => $s->value)->all(),
    ];
    $before = $state();
    // Gli avvisi in app della parte social entrano nella coda già chiusi.
    expect(DB::table('community_delivery_outbox')->whereNull('delivered_at')->count())->toBe(0);

    $this->artisan('carpool:maintain')->assertSuccessful();
    expect($state())->toBe($before)
        ->and(DB::table('community_delivery_outbox')->whereNull('delivered_at')->count())->toBe(0)
        ->and(DB::table('ride_offers')->where('status', 'cancelled')->count())->toBe(0);

    // Lunedì sera, un'ora prima della jam: la manutenzione prepara promemoria e solleciti, ma solo per persone demo, e li chiude senza inviarli.
    $this->travelTo(CarbonImmutable::parse('2026-09-21 19:50', 'Europe/Rome'));
    $this->artisan('carpool:maintain')->assertSuccessful();
    $queued = DB::table('community_delivery_outbox')->get();
    expect($queued->pluck('user_id')->unique()->diff($ids)->all())->toBe([])
        ->and($queued->filter(fn ($row) => str_starts_with($row->dedupe_key, 'reminder:'))->isNotEmpty())->toBeTrue()
        ->and($queued->whereNull('delivered_at'))->toHaveCount(0)
        ->and($state())->toBe($before);
    Notification::assertNothingSent();
    Mail::assertNothingSent();
});

it('reviews the drivers on concluded investor dates, and only there', function (): void {
    // Due date già concluse del catalogo investitori: bastano per le prime due recensioni, le altre si saltano.
    showcaseInvestorDate('0100', '2026-09-05 21:00');
    showcaseInvestorDate('0101', '2026-09-08 20:00');
    // Una data di ieri sera non è ancora chiusa: la chat del viaggio è aperta per 24 ore.
    showcaseInvestorDate('0102', '2026-09-15 21:00');

    $this->artisan('demo:showcase', ['--dry-run' => true])->expectsOutputToContain('Recensioni ai conducenti')->assertSuccessful();
    $this->artisan('demo:showcase')->expectsOutputToContain('Recensioni saltate')->assertSuccessful();
    $reviews = RideReview::query()->whereIn('user_id', showcaseRideUserIds())->with('rideRequest.offer')->get();

    expect($reviews)->toHaveCount(2)
        ->and($reviews->every(fn (RideReview $review) => $review->rideRequest->offer->status === RideStatus::Completed
            && $review->rideRequest->offer->departure_at->isPast() && $review->rideRequest->passenger_confirmed_at !== null))->toBeTrue()
        ->and(DB::table('ride_occupancies')->whereIn('ride_offer_id', $reviews->map(fn ($r) => $r->rideRequest->ride_offer_id))->count())->toBe(0);

    // Il voto compare accanto al conducente per chi è idoneo, come sulle schede dei passaggi.
    $viewer = carpoolPerson();
    expect(app(RideReviews::class)->summary($viewer, showcaseRidePerson(5)))->toBe(['count' => 1, 'average' => 5.0])
        ->and(app(RideReviews::class)->summary($viewer, showcaseRidePerson(7)))->toBe(['count' => 1, 'average' => 5.0]);

    $this->artisan('carpool:maintain')->assertSuccessful();
    $this->artisan('demo:showcase')->assertSuccessful();
    expect(RideReview::query()->whereIn('user_id', showcaseRideUserIds())->count())->toBe(2)
        ->and(RideOffer::query()->whereIn('driver_id', showcaseRideUserIds())->where('status', RideStatus::Completed)->count())->toBe(2);
});

it('is idempotent across runs and leaves a cancelled ride alone', function (): void {
    $this->artisan('demo:showcase')->assertSuccessful();
    $count = fn (): array => [RideOffer::query()->count(), RideRequest::query()->count(), DB::table('ride_occupancies')->count(),
        RideConversation::query()->count(), DB::table('chat_messages')->count(), DB::table('carpool_profiles')->count()];
    $before = $count();

    $this->travel(2)->days();
    $this->artisan('demo:showcase')->assertSuccessful();
    expect($count())->toBe($before);
});

it('purges every ride and says which rows of real people went with them', function (): void {
    $investor = showcaseInvestorDate('0200', '2026-09-22 18:00');
    $this->artisan('demo:showcase')->assertSuccessful();
    $real = carpoolPerson();
    $offer = RideOffer::query()->where('zone', 'Guizza, capolinea del tram')->orderBy('id')->firstOrFail();

    // Una persona vera chiede un posto a un conducente demo e offre un passaggio a un evento della vetrina.
    cpAction($this, $real, 'request', ['offer_id' => $offer->id, 'revision' => $offer->revision, 'seats' => 1])->assertOk();
    cpAction($this, $real, 'offer', ['occurrence_id' => $offer->occurrence_id, 'leg' => 'return', 'zone' => 'Portello', 'departure_at' => '2026-09-24T22:30',
        'capacity' => 2, 'accessibility' => 'not_specified', 'driver_declaration' => true])->assertOk();
    // Il suo passaggio verso un evento che non è della vetrina resta dov'è.
    cpAction($this, $real, 'offer', ['occurrence_id' => $investor->id, 'leg' => 'outbound', 'zone' => 'Portello', 'departure_at' => '2026-09-22T17:30',
        'capacity' => 2, 'accessibility' => 'not_specified', 'driver_declaration' => true])->assertOk();

    $this->artisan('demo:showcase', ['--purge' => true, '--dry-run' => true])
        ->expectsOutputToContain('Con loro spariranno righe di altri utenti: 1 passaggi offerti, 1 richieste di passaggio.')->assertSuccessful();
    $this->artisan('demo:showcase', ['--purge' => true])
        ->expectsOutputToContain('Con loro sono sparite righe di altri utenti: 1 passaggi offerti, 1 richieste di passaggio.')->assertSuccessful();

    expect(showcaseRideUserIds())->toBe([])
        ->and(RideOffer::query()->pluck('occurrence_id')->all())->toBe([$investor->id])
        ->and(RideRequest::query()->count())->toBe(0)
        ->and(DB::table('ride_occupancies')->pluck('user_id')->all())->toBe([$real->id])
        ->and(RideConversation::query()->count())->toBe(0)
        ->and(DB::table('chat_conversations')->count())->toBe(0)
        ->and(DB::table('chat_messages')->count())->toBe(0)
        ->and(DB::table('carpool_profiles')->pluck('user_id')->all())->toBe([$real->id])
        ->and(DB::table('community_delivery_outbox')->where('user_id', '!=', $real->id)->count())->toBe(0);

    // La vetrina si ricrea da capo con tutti i suoi passaggi.
    $this->artisan('demo:showcase')->assertSuccessful();
    expect(RideOffer::query()->whereIn('driver_id', showcaseRideUserIds())->count())->toBe(count(ShowcaseDemoCommand::catalog()['rides']));
});

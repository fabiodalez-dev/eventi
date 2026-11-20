<?php

declare(strict_types=1);

use App\Enums\CommunityStatus;
use App\Models\CarpoolCase;
use App\Models\RideReview;
use App\Services\Carpool\RideReviews;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

/*
 * Le recensioni dei conducenti dopo il viaggio: ripubblicare dopo una rimozione, media su più
 * passeggeri e più date, chi non ha viaggiato davvero, segnalazione e oscuramento,
 * sospensione dell'autore, scadenza dei dodici mesi. Protegge che il voto pubblico conti solo
 * viaggi veri e recensioni visibili, e che la pagina dia al passeggero la revisione giusta.
 */
require_once __DIR__.'/percorsi-carpool.php';

beforeEach(fn () => cpSetup($this));

afterEach(function (): void {
    Carbon::setTestNow();
    CarbonImmutable::setTestNow();
});

it('dopo aver rimosso la recensione il passeggero ne pubblica una nuova con la revisione che la pagina gli restituisce', function (): void {
    $ride = cpAccepted($this);
    freezeLocal($this->city, '2026-10-11 12:00');
    flussoCpReview($this, $this->passenger, $ride, 'confirm', ['confirm' => true])->assertOk();
    flussoCpReview($this, $this->passenger, $ride, 'save', ['rating' => 2, 'body' => 'Ritardo di mezz\'ora', 'revision' => 0])->assertOk();
    flussoCpReview($this, $this->passenger, $ride, 'remove', ['revision' => 1])->assertOk();

    flussoCpWeb($this, $this->passenger);
    $revision = $this->getJson(route('carpool.request', $ride))->assertJsonPath('data.ride.review', null)->assertJsonPath('data.ride.can_review', true)
        ->json('data.ride.review_revision');
    expect($revision)->toBe(2);
    $this->get(route('carpool.request', $ride))->assertOk()->assertSee('name="revision" value="2"', false)->assertDontSee('Ritardo di mezz');

    $this->post(route('carpool.review.action', [$ride, 'save']), ['request_key' => (string) Str::uuid(), 'rating' => 5, 'body' => 'Ci ho ripensato: tutto bene', 'revision' => $revision])
        ->assertRedirect(route('carpool.request', $ride))->assertSessionHasNoErrors();
    $review = RideReview::firstOrFail();
    expect($review->rating)->toBe(5)->and($review->status)->toBe(CommunityStatus::Published)->and($review->revision)->toBe(3)
        ->and(RideReview::withTrashed()->count())->toBe(1)
        ->and($this->driver->notifications()->where('type', 'carpool.review')->count())->toBe(2);
    Sanctum::actingAs(carpoolPerson());
    $this->getJson('/api/v1/carpool/drivers/'.$this->driver->id.'/reviews')->assertJsonPath('data.summary.count', 1)->assertJsonPath('data.summary.average', 5)
        ->assertJsonPath('data.reviews.0.body', 'Ci ho ripensato: tutto bene');
});

it('la media del conducente unisce passeggeri e date diverse e compare a chi guarda la sua offerta successiva', function (): void {
    $second = occurrenceAt($this->city, $this->category, '2026-10-17 19:00:00', '2026-10-17 22:00:00');
    $anna = cpAccepted($this);
    $bruno = carpoolPerson();
    $brunoRide = cpRequest($this, $anna->offer, $bruno);
    cpAction($this, $this->driver, 'accept', ['request_id' => $brunoRide->id])->assertOk();
    $later = cpOffer($this, data: ['occurrence_id' => $second->id, 'departure_at' => '2026-10-17T18:00:00Z']);
    $annaAgain = cpRequest($this, $later);
    cpAction($this, $this->driver, 'accept', ['request_id' => $annaAgain->id])->assertOk();

    freezeLocal($this->city, '2026-10-18 12:00');
    foreach ([[$this->passenger, $anna, 5], [$bruno, $brunoRide, 3], [$this->passenger, $annaAgain, 4]] as [$person, $ride, $rating]) {
        flussoCpReview($this, $person, $ride, 'confirm', ['confirm' => true])->assertOk();
        flussoCpReview($this, $person, $ride, 'save', ['rating' => $rating, 'revision' => 0])->assertOk();
    }

    $third = occurrenceAt($this->city, $this->category, '2026-10-24 19:00:00', '2026-10-24 22:00:00');
    $next = cpOffer($this, data: ['occurrence_id' => $third->id, 'departure_at' => '2026-10-24T18:00:00Z']);
    $reader = carpoolPerson();
    flussoCpWeb($this, $reader);
    $this->getJson(route('carpool.reviews', $this->driver))->assertJsonPath('data.summary.count', 3)->assertJsonPath('data.summary.average', 4)
        ->assertJsonCount(3, 'data.reviews');
    $this->getJson(route('carpool.dates', $third))->assertJsonPath('data.offers.0.id', $next->id)
        ->assertJsonPath('data.offers.0.reviews.count', 3)->assertJsonPath('data.offers.0.reviews.average', 4);
    $this->get(route('carpool.offer', $next))->assertOk()->assertSee(__('carpool.reviews.summary', ['average' => '4,0', 'count' => 3]));
    // Il conducente legge la propria pagina.
    flussoCpWeb($this, $this->driver);
    $this->get(route('carpool.reviews', $this->driver))->assertOk()->assertSee(__('carpool.reviews.title'));
});

it('chi è stato rifiutato, ha rinunciato o ha visto annullare il viaggio non può recensire; chi ha viaggiato sì', function (): void {
    $offer = cpOffer($this);
    [$declined, $withdrew, $travelled] = [carpoolPerson(), carpoolPerson(), $this->passenger];
    $a = cpRequest($this, $offer, $declined);
    cpAction($this, $this->driver, 'decline', ['request_id' => $a->id])->assertOk();
    $b = cpRequest($this, $offer, $withdrew);
    cpAction($this, $this->driver, 'accept', ['request_id' => $b->id])->assertOk();
    cpAction($this, $withdrew, 'withdraw', ['request_id' => $b->id])->assertOk();
    $c = cpRequest($this, $offer, $travelled);
    cpAction($this, $this->driver, 'accept', ['request_id' => $c->id])->assertOk();
    $other = cpOffer($this, carpoolPerson(), ['leg' => 'return', 'zone' => 'Centro', 'departure_at' => '2026-10-10T23:00:00Z']);
    $cancelled = cpRequest($this, $other, $travelled);
    cpAction($this, $other->driver, 'accept', ['request_id' => $cancelled->id])->assertOk();
    cpAction($this, $other->driver, 'cancel', ['offer_id' => $other->id])->assertOk();

    freezeLocal($this->city, '2026-10-11 12:00');
    foreach ([[$declined, $a], [$withdrew, $b], [$travelled, $cancelled]] as [$person, $ride]) {
        flussoCpWeb($this, $person);
        $this->getJson(route('carpool.request', $ride))->assertJsonPath('data.ride.can_review', false);
        flussoCpReview($this, $person, $ride, 'confirm', ['confirm' => true])->assertForbidden()->assertJsonPath('error.message', __('carpool.errors.review_trip'));
    }
    flussoCpWeb($this, $travelled);
    $this->getJson(route('carpool.request', $c))->assertJsonPath('data.ride.can_review', true);
    flussoCpReview($this, $travelled, $c, 'confirm', ['confirm' => true])->assertOk();
    expect(RideReview::count())->toBe(0)->and($c->fresh()->passenger_confirmed_at)->not->toBeNull()
        ->and($cancelled->fresh()->passenger_confirmed_at)->toBeNull();
});

it('un lettore segnala dal sito una recensione: il caso ne conserva la copia, l\'autore non lo vede e lo staff la oscura', function (): void {
    $ride = cpAccepted($this);
    freezeLocal($this->city, '2026-10-11 12:00');
    flussoCpReview($this, $this->passenger, $ride, 'confirm', ['confirm' => true])->assertOk();
    flussoCpReview($this, $this->passenger, $ride, 'save', ['rating' => 1, 'body' => 'Guida pericolosa, numero 333 1234567', 'revision' => 0])->assertOk();
    $review = RideReview::firstOrFail();

    $reader = carpoolPerson();
    flussoCpWeb($this, $reader);
    $this->get(route('carpool.reviews', $this->driver))->assertOk()->assertSee('name="review_id" value="'.$review->id.'"', false);
    $this->post(route('carpool.report'), ['request_key' => (string) Str::uuid(), 'review_id' => $review->id, 'reason' => 'other', 'body' => 'Contiene un numero di telefono'])
        ->assertRedirect();
    $case = CarpoolCase::firstOrFail();
    expect($case->reporter_id)->toBe($reader->id)->and($case->social_type)->toBe('ride_review')->and($case->social_id)->toBe($review->id)
        ->and($case->review_snapshot['body'])->toBe('Guida pericolosa, numero 333 1234567');
    flussoCpWeb($this, $this->passenger);
    $this->getJson(route('carpool.cases'))->assertJsonCount(0, 'data.cases');

    app(RideReviews::class)->moderate(cpStaff(), $review, false, 'Contiene dati di contatto');
    flussoCpWeb($this, $reader);
    $this->getJson(route('carpool.reviews', $this->driver))->assertJsonPath('data.summary.count', 0)->assertJsonCount(0, 'data.reviews');
    expect($this->passenger->notifications()->where('type', 'carpool.review_moderated')->count())->toBe(1);
    flussoCpWeb($this, $this->passenger);
    $this->get(route('carpool.request', $ride))->assertOk()->assertSee(__('carpool.reviews.hidden'));
    // Correggerla non la rimette in pubblico.
    flussoCpReview($this, $this->passenger, $ride, 'save', ['rating' => 2, 'body' => 'Guida pericolosa', 'revision' => $review->fresh()->revision])->assertOk();
    expect($review->fresh()->status)->toBe(CommunityStatus::Hidden);
});

it('le recensioni di un autore sospeso escono dalla media e tornano quando la sospensione viene tolta', function (): void {
    $ride = cpAccepted($this);
    freezeLocal($this->city, '2026-10-11 12:00');
    flussoCpReview($this, $this->passenger, $ride, 'confirm', ['confirm' => true])->assertOk();
    flussoCpReview($this, $this->passenger, $ride, 'save', ['rating' => 5, 'body' => 'Bravissimo', 'revision' => 0])->assertOk();
    $reader = carpoolPerson();
    $count = function () use ($reader): int {
        Sanctum::actingAs($reader->fresh());

        return $this->getJson('/api/v1/carpool/drivers/'.$this->driver->id.'/reviews')->assertOk()->json('data.summary.count');
    };
    expect($count())->toBe(1);

    $this->passenger->forceFill(['carpool_suspended_at' => now()])->save();
    expect($count())->toBe(0);
    $this->getJson('/api/v1/carpool/drivers/'.$this->driver->id.'/reviews')->assertJsonCount(0, 'data.reviews');

    $this->passenger->forceFill(['carpool_suspended_at' => null])->save();
    expect($count())->toBe(1)->and(RideReview::firstOrFail()->body)->toBe('Bravissimo');
});

it('la conferma del viaggio si può ripetere senza avvisare il conducente; solo il voto pubblicato lo avvisa', function (): void {
    $ride = cpAccepted($this);
    freezeLocal($this->city, '2026-10-11 12:00');
    flussoCpReview($this, $this->passenger, $ride, 'confirm', ['confirm' => true])->assertOk();
    $confirmed = $ride->fresh()->passenger_confirmed_at;
    freezeLocal($this->city, '2026-10-11 13:00');
    flussoCpReview($this, $this->passenger, $ride, 'confirm', ['confirm' => true])->assertOk();
    flussoCpReview($this, $this->passenger, $ride, 'confirm', [])->assertUnprocessable();

    expect($ride->fresh()->passenger_confirmed_at->equalTo($confirmed))->toBeTrue()
        ->and($this->driver->notifications()->where('type', 'carpool.review')->count())->toBe(0);
    $reader = carpoolPerson();
    flussoCpWeb($this, $reader);
    $this->getJson(route('carpool.reviews', $this->driver))->assertJsonPath('data.summary.count', 0);

    flussoCpReview($this, $this->passenger, $ride, 'save', ['rating' => 4, 'revision' => 0])->assertOk();
    expect($this->driver->notifications()->where('type', 'carpool.review')->count())->toBe(1)
        ->and($this->driver->notifications()->where('type', 'carpool.review')->first()->data['url'])->toBe(route('carpool.reviews', $this->driver->id));
    flussoCpWeb($this, $this->driver);
    $this->get($this->driver->notifications()->where('type', 'carpool.review')->first()->data['url'])->assertOk();
});

it('dopo dodici mesi dalla partenza la recensione esce dalla pagina e il passeggero non può più scriverne', function (): void {
    $ride = cpAccepted($this);
    freezeLocal($this->city, '2026-10-11 12:00');
    flussoCpReview($this, $this->passenger, $ride, 'confirm', ['confirm' => true])->assertOk();
    flussoCpReview($this, $this->passenger, $ride, 'save', ['rating' => 4, 'revision' => 0])->assertOk();

    freezeLocal($this->city, '2027-10-12 12:00');
    $reader = carpoolPerson();
    flussoCpWeb($this, $reader);
    $this->getJson(route('carpool.reviews', $this->driver))->assertOk()->assertJsonPath('data.summary.count', 0)->assertJsonCount(0, 'data.reviews');
    flussoCpWeb($this, $this->passenger);
    $this->getJson(route('carpool.request', $ride))->assertJsonPath('data.ride.can_review', false);
    flussoCpReview($this, $this->passenger, $ride, 'save', ['rating' => 1, 'revision' => 1])->assertForbidden();
    expect(RideReview::firstOrFail()->rating)->toBe(4);
});

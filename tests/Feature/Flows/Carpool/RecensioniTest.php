<?php

declare(strict_types=1);

use App\Models\RideReview;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

/*
 * Dopo il viaggio il passeggero conferma, vota, corregge e rimuove la recensione del
 * conducente; la pagina pubblica delle recensioni segue ogni passo.
 */
require_once __DIR__.'/flussi-carpool.php';

beforeEach(fn () => cpSetup($this));

afterEach(function (): void {
    Carbon::setTestNow();
    CarbonImmutable::setTestNow();
});

it('il passeggero conferma il viaggio, recensisce il conducente, corregge il voto e infine rimuove la recensione', function (): void {
    $ride = cpAccepted($this);
    freezeLocal($this->city, '2026-10-11 12:00');

    flussoCpWeb($this, $this->passenger);
    $this->getJson(route('carpool.request', $ride))->assertJsonPath('data.ride.can_review', true)->assertJsonPath('data.ride.passenger_confirmed_at', null);
    $this->post(route('carpool.review.action', [$ride, 'confirm']), ['request_key' => (string) Str::uuid(), 'confirm' => '1'])
        ->assertRedirect(route('carpool.request', $ride))->assertSessionHas('status');
    expect($ride->fresh()->passenger_confirmed_at)->not->toBeNull()->and(RideReview::count())->toBe(0);

    $this->post(route('carpool.review.action', [$ride, 'save']), ['request_key' => (string) Str::uuid(), 'rating' => 5, 'body' => 'Guida tranquilla e puntuale', 'revision' => 0])
        ->assertRedirect(route('carpool.request', $ride));
    $review = RideReview::firstOrFail();
    expect($review->rating)->toBe(5)->and($review->driver_id)->toBe($this->driver->id)->and($review->status->value)->toBe('published')->and($review->revision)->toBe(1)
        ->and($this->driver->notifications()->where('type', 'carpool.review')->count())->toBe(1);

    $reader = carpoolPerson();
    flussoCpWeb($this, $reader);
    $this->get(route('carpool.reviews', $this->driver))->assertOk()->assertSee('Guida tranquilla e puntuale');
    $this->getJson(route('carpool.reviews', $this->driver))->assertJsonPath('data.summary.count', 1)->assertJsonPath('data.summary.average', 5);

    flussoCpReview($this, $this->passenger, $ride, 'save', ['rating' => 3, 'body' => 'Puntuale, ma musica altissima', 'revision' => 1])->assertOk();
    // Una revisione superata non sovrascrive.
    flussoCpReview($this, $this->passenger, $ride, 'save', ['rating' => 1, 'revision' => 1])->assertConflict();
    Sanctum::actingAs($reader->fresh());
    $this->getJson('/api/v1/carpool/drivers/'.$this->driver->id.'/reviews')->assertOk()->assertJsonPath('data.summary.average', 3)
        ->assertJsonPath('data.reviews.0.body', 'Puntuale, ma musica altissima')->assertJsonCount(1, 'data.reviews');
    expect(RideReview::count())->toBe(1)->and($this->driver->notifications()->where('type', 'carpool.review')->count())->toBe(2);

    flussoCpReview($this, $this->passenger, $ride, 'remove', ['revision' => 2])->assertOk()->assertJsonPath('data.entity', 'request');
    expect(RideReview::count())->toBe(0)->and(RideReview::withTrashed()->firstOrFail()->body)->toBeNull();
    Sanctum::actingAs($reader->fresh());
    $this->getJson('/api/v1/carpool/drivers/'.$this->driver->id.'/reviews')->assertJsonPath('data.summary.count', 0)->assertJsonCount(0, 'data.reviews');
    // Rimossa una volta, non si rimuove di nuovo.
    flussoCpReview($this, $this->passenger, $ride, 'remove', ['revision' => 3])->assertConflict();
});

it('la recensione è respinta prima della partenza, senza conferma, da chi non ha viaggiato e la pagina è chiusa a chi non può leggerla', function (): void {
    $ride = cpAccepted($this);

    flussoCpReview($this, $this->passenger, $ride, 'confirm', ['confirm' => true])->assertForbidden();
    freezeLocal($this->city, '2026-10-11 12:00');
    flussoCpReview($this, $this->passenger, $ride, 'save', ['rating' => 4, 'revision' => 0])->assertUnprocessable();
    flussoCpReview($this, $this->driver, $ride, 'confirm', ['confirm' => true])->assertForbidden();
    flussoCpReview($this, carpoolPerson(), $ride, 'confirm', ['confirm' => true])->assertForbidden();
    flussoCpReview($this, $this->passenger, $ride, 'confirm', ['confirm' => true])->assertOk();
    flussoCpReview($this, $this->passenger, $ride, 'save', ['rating' => 6, 'revision' => 0])->assertUnprocessable();
    expect(RideReview::count())->toBe(0);

    // Chi non ha mai offerto un passaggio non ha una pagina di recensioni.
    flussoCpWeb($this, carpoolPerson());
    $this->get(route('carpool.reviews', $this->passenger))->assertNotFound();
    $this->get(route('carpool.reviews', $this->driver))->assertOk()->assertSee(__('carpool.reviews.none'));
    // Chi non ha verificato il telefono non legge le recensioni.
    $unverified = carpoolPerson(false);
    $unverified->forceFill(['whatsapp_verified_at' => null])->save();
    flussoCpWeb($this, $unverified);
    $this->get(route('carpool.reviews', $this->driver))->assertForbidden();
    // E l'ospite deve accedere.
    flussoCpGuest();
    $this->get(route('carpool.reviews', $this->driver))->assertRedirect(route('login'));
    $this->post(route('carpool.review.action', [$ride, 'confirm']), ['request_key' => (string) Str::uuid(), 'confirm' => '1'])->assertRedirect(route('login'));
});

<?php

use App\Models\RideReview;
use App\Models\UserBlock;
use App\Services\Carpool\CommunitySafety;
use App\Services\Carpool\RideReviews;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

function cpReview($test, $user, $ride, $action, array $data = [])
{
    Sanctum::actingAs($user->fresh());

    return $test->postJson('/api/v1/carpool/requests/'.$ride->id.'/review/'.$action, ['request_key' => (string) Str::uuid(), ...$data]);
}
function cpReviewedRide($test, int $rating = 4)
{
    $ride = cpAccepted($test);
    freezeLocal($test->city, '2026-10-11 12:00');
    cpReview($test, $test->passenger, $ride, 'confirm', ['confirm' => true])->assertOk();
    cpReview($test, $test->passenger, $ride, 'save', ['rating' => $rating, 'body' => 'Puntuale e gentile', 'revision' => 0])->assertOk();

    return $ride;
}

it('never automatically treats acceptance as a passenger trip confirmation', function (): void {
    $ride = cpAccepted($this);
    freezeLocal($this->city, '2026-10-11 12:00');
    cpReview($this, $this->passenger, $ride, 'save', ['rating' => 5, 'revision' => 0])->assertUnprocessable();
    expect($ride->fresh()->passenger_confirmed_at)->toBeNull()->and(RideReview::count())->toBe(0);
});

it('allows trip confirmation with no vote or text', function (): void {
    $ride = cpAccepted($this);
    freezeLocal($this->city, '2026-10-11 12:00');
    cpReview($this, $this->passenger, $ride, 'confirm', ['confirm' => true])->assertOk();
    expect($ride->fresh()->passenger_confirmed_at)->not->toBeNull()->and(RideReview::count())->toBe(0);
});

it('requires an explicit confirmation and never permits it before departure', function (): void {
    $ride = cpAccepted($this);
    cpReview($this, $this->passenger, $ride, 'confirm', ['confirm' => true])->assertForbidden();
    freezeLocal($this->city, '2026-10-11 12:00');
    cpReview($this, $this->passenger, $ride, 'confirm', [])->assertUnprocessable();
    cpReview($this, $this->passenger, $ride, 'confirm', ['confirm' => false])->assertUnprocessable();
});

it('limits public driver reviews to the accepted requester, not drivers or strangers', function (string $who): void {
    $ride = cpAccepted($this);
    freezeLocal($this->city, '2026-10-11 12:00');
    cpReview($this, $who === 'driver' ? $this->driver : carpoolPerson(), $ride, 'confirm', ['confirm' => true])->assertForbidden();
})->with(['driver', 'stranger']);

it('does not count rides cancelled before departure as reviewable trips', function (): void {
    $ride = cpAccepted($this);
    cpAction($this, $this->passenger, 'withdraw', ['request_id' => $ride->id])->assertOk();
    freezeLocal($this->city, '2026-10-11 12:00');
    cpReview($this, $this->passenger, $ride, 'confirm', ['confirm' => true])->assertForbidden();
});

it('keeps ratings inside the declared scale', function ($rating): void {
    $ride = cpAccepted($this);
    freezeLocal($this->city, '2026-10-11 12:00');
    cpReview($this, $this->passenger, $ride, 'confirm', ['confirm' => true])->assertOk();
    cpReview($this, $this->passenger, $ride, 'save', ['rating' => $rating, 'revision' => 0])->assertUnprocessable();
})->with([0, 6, -1, 4.5]);

it('shows reviews only to verified email and WhatsApp readers', function (string $missing): void {
    cpReviewedRide($this);
    $viewer = carpoolPerson(false);
    $viewer->forceFill([$missing => null])->save();
    Sanctum::actingAs($viewer);
    $this->getJson('/api/v1/carpool/drivers/'.$this->driver->id.'/reviews')->assertForbidden();
    expect(app(RideReviews::class)->summary($viewer, $this->driver))->toBeNull();
})->with(['email_verified_at', 'whatsapp_verified_at', 'whatsapp_phone_hash']);

it('allows verified readers who have not activated carpool to see driver reviews', function (): void {
    cpReviewedRide($this);
    Sanctum::actingAs(carpoolPerson(false));
    $this->getJson('/api/v1/carpool/drivers/'.$this->driver->id.'/reviews')->assertOk()->assertJsonPath('data.summary.count', 1)->assertJsonPath('data.reviews.0.body', 'Puntuale e gentile')->assertJsonMissingPath('data.reviews.0.ride_request_id');
});

it('keeps a single review and correct average after editing', function (): void {
    $ride = cpReviewedRide($this);
    cpReview($this, $this->passenger, $ride, 'save', ['rating' => 2, 'body' => 'Aggiornamento', 'revision' => 1])->assertOk();
    expect(RideReview::count())->toBe(1)->and(app(RideReviews::class)->summary($this->passenger, $this->driver))->toBe(['count' => 1, 'average' => 2.0]);
    cpReview($this, $this->passenger, $ride, 'save', ['rating' => 5, 'revision' => 1])->assertConflict();
});

it('does not let a client change the reviewer driver or publication status', function (): void {
    $ride = cpReviewedRide($this);
    cpReview($this, $this->passenger, $ride, 'save', ['rating' => 3, 'revision' => 1, 'driver_id' => carpoolPerson()->id, 'user_id' => $this->driver->id, 'status' => 'hidden'])->assertOk();
    $review = RideReview::first();
    expect($review->driver_id)->toBe($this->driver->id)->and($review->user_id)->toBe($this->passenger->id)->and($review->status->value)->toBe('published');
});

it('removes a review from public counts immediately and scrubs its text', function (): void {
    $ride = cpReviewedRide($this);
    cpReview($this, $this->passenger, $ride, 'remove', ['revision' => 1])->assertOk();
    expect(app(RideReviews::class)->summary($this->passenger, $this->driver)['count'])->toBe(0)->and(RideReview::withTrashed()->first()->body)->toBeNull();
});

it('keeps moderated reviews hidden when the author edits or republishes them', function (): void {
    $ride = cpReviewedRide($this);
    $review = RideReview::first();
    $admin = cpStaff();
    app(RideReviews::class)->moderate($admin, $review, false, 'Dati privati nel testo');
    cpReview($this, $this->passenger, $ride, 'save', ['rating' => 5, 'revision' => 2, 'body' => 'Testo corretto'])->assertOk();
    expect(app(RideReviews::class)->summary($this->driver, $this->driver)['count'])->toBe(0);
    app(RideReviews::class)->moderate($admin, $review->fresh(), true, 'Dati privati rimossi');
    expect(app(RideReviews::class)->summary($this->driver, $this->driver)['count'])->toBe(1);
});

it('does not let a driver hide a negative review just by blocking its author', function (): void {
    cpReviewedRide($this, 1);
    UserBlock::create(['user_id' => $this->driver->id, 'blocked_user_id' => $this->passenger->id]);
    expect(app(RideReviews::class)->summary(carpoolPerson(), $this->driver)['average'])->toBe(1.0);
});

it('preserves the reported version privately when the review is removed', function (): void {
    $ride = cpReviewedRide($this);
    $review = RideReview::first();
    $reader = carpoolPerson();
    $case = app(CommunitySafety::class)->report($reader, ['review_id' => $review->id, 'request_key' => (string) Str::uuid(), 'reason' => 'other', 'body' => 'Chiedo la verifica di questa recensione']);
    cpReview($this, $this->passenger, $ride, 'remove', ['revision' => 1])->assertOk();
    expect($case->fresh()->review_snapshot['body'])->toBe('Puntuale e gentile')->and($case->ride_request_id)->toBeNull();
    expect(DB::table('carpool_cases')->where('id', $case->id)->value('review_snapshot'))->not->toContain('Puntuale e gentile');
});

it('does not show reviews outside the stated twelve month window', function (): void {
    cpReviewedRide($this);
    freezeLocal($this->city, '2027-10-12 12:00');
    expect(app(RideReviews::class)->summary(carpoolPerson(), $this->driver)['count'])->toBe(0);
});

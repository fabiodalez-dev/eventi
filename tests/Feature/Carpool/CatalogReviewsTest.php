<?php

declare(strict_types=1);
use App\Models\CarpoolCase;
use App\Models\CatalogRating;
use App\Models\CatalogReview;
use App\Models\Organizer;
use App\Models\User;
use App\Models\Venue;
use App\Services\Carpool\CommunityDelivery;
use App\Services\Reviews\CatalogReviews;
use Codebyray\ReviewRateable\Models\Review;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

beforeEach(function (): void {
    $this->venue = Venue::factory()->approved()->create(['city_id' => $this->city->id]);
    $this->organizer = Organizer::create(['name' => 'Suoni in città', 'city_id' => $this->city->id, 'is_active' => true]);
    $this->reviews = app(CatalogReviews::class);
});
function catalogUrl($test, string $kind): string
{
    return '/api/v1/'.($kind === 'venue' ? 'venues/'.$test->venue->slug : 'organizers/'.$test->organizer->slug).'/reviews';
}

it('requires both contact verifications for catalog writes', function (string $field): void {
    $this->passenger->forceFill([$field => null])->save();
    Sanctum::actingAs($this->passenger->fresh());
    foreach (['venue', 'organizer'] as $kind) {
        $this->postJson(catalogUrl($this, $kind), ['rating' => 5])->assertForbidden();
    }
    expect(CatalogReview::count())->toBe(0);
})->with(['email_verified_at', 'whatsapp_verified_at', 'whatsapp_phone_hash']);

it('requires no carpool adulthood declaration to review a catalog profile', function (): void {
    Sanctum::actingAs(carpoolPerson(false));
    $this->postJson(catalogUrl($this, 'organizer'), ['rating' => 4])->assertOk();
    expect(CatalogReview::sole()->reviewable_type)->toBe('organizer');
});

it('supports a vote alone or a comment alone through the package', function (array $data, ?int $rating): void {
    Sanctum::actingAs($this->passenger);
    $this->postJson(catalogUrl($this, 'organizer'), $data)->assertOk();
    $review = CatalogReview::with('ratings')->sole();
    expect($review->rating)->toBe($rating)->and($review->approved)->toBeFalse()->and($review)->toBeInstanceOf(Review::class);
    $this->reviews->moderate($review, cpStaff(), 'approved', 1, null);
    $this->getJson(catalogUrl($this, 'organizer'))->assertOk()->assertJsonPath('data.count', 1)->assertJsonPath('data.rated_count', $rating === null ? 0 : 1)->assertJsonPath('data.average', $rating);
})->with([[['rating' => 5], 5], [['body' => 'Un’organizzazione molto accurata.'], null]]);

it('rejects empty, malformed or out of range review contributions', function (array $data): void {
    Sanctum::actingAs($this->passenger);
    $this->postJson(catalogUrl($this, 'organizer'), $data)->assertUnprocessable();
    expect(CatalogReview::count())->toBe(0);
})->with([[[]], [['rating' => 0]], [['rating' => 6]], [['rating' => 3.5]], [['rating' => ['overall' => 4]]], [['body' => 'troppo']], [['body' => str_repeat('x', 3001)]]]);

it('does not accept owner or moderator fields from the client', function (): void {
    Sanctum::actingAs($this->passenger);
    $this->postJson(catalogUrl($this, 'organizer'), ['rating' => 1, 'user_id' => $this->driver->id, 'reviewable_type' => 'user', 'reviewable_id' => $this->driver->id, 'approved' => true, 'status' => 'approved', 'moderated_by' => $this->passenger->id])->assertOk();
    $review = CatalogReview::sole();
    expect($review->user_id)->toBe($this->passenger->id)->and($review->reviewable_id)->toBe($this->organizer->id)->and($review->reviewable_type)->toBe('organizer')->and($review->approved)->toBeFalse()->and($review->moderated_by)->toBeNull();
});

it('prevents venue staff and organizer owners from reviewing their own profiles', function (): void {
    $this->venue->members()->attach($this->passenger, ['role' => 'editor']);
    $this->organizer->update(['owner_id' => $this->passenger->id]);
    Sanctum::actingAs($this->passenger);
    foreach (['venue', 'organizer'] as $kind) {
        $this->postJson(catalogUrl($this, $kind), ['rating' => 5])->assertForbidden();
    }
});

it('prevents associated organizer collaborators from reviewing the organizer', function (): void {
    $this->organizer->users()->attach($this->passenger);
    Sanctum::actingAs($this->passenger);
    $this->postJson(catalogUrl($this, 'organizer'), ['rating' => 5])->assertForbidden();
});

it('hides unverified and suspended authors from both counts and review text', function (string $field): void {
    $review = $this->reviews->submit($this->organizer, $this->passenger, 5, 'Un contributo che deve sparire.');
    $this->reviews->moderate($review, cpStaff(), 'approved', 1, null);
    $this->passenger->forceFill([$field => str_ends_with($field, 'suspended_at') ? now() : null])->save();
    $this->getJson(catalogUrl($this, 'organizer'))->assertJsonPath('data.count', 0)->assertJsonPath('data.average', null)->assertJsonPath('data.reviews', []);
})->with(['email_verified_at', 'whatsapp_verified_at', 'social_suspended_at', 'community_suspended_at']);

it('rejects stale author revisions and has one review and rating per profile', function (): void {
    Sanctum::actingAs($this->passenger);
    $url = catalogUrl($this, 'organizer');
    $this->postJson($url, ['rating' => 4, 'revision' => 0])->assertOk();
    $this->postJson($url, ['rating' => 1, 'revision' => 0])->assertUnprocessable();
    $this->postJson($url, ['rating' => 3, 'revision' => 1])->assertOk();
    expect(CatalogReview::count())->toBe(1)->and(CatalogRating::count())->toBe(1)->and(CatalogRating::sole()->value)->toBe(3);
});

it('accepts revision zero when a verified author creates their first review', function (): void {
    Sanctum::actingAs($this->passenger);

    $this->postJson(catalogUrl($this, 'venue'), ['rating' => 5, 'revision' => 0])->assertOk();

    expect(CatalogReview::sole()->revision)->toBe(1);
});

it('does not increment revisions on identical retry', function (): void {
    Sanctum::actingAs($this->passenger);
    $url = catalogUrl($this, 'organizer');
    $this->postJson($url, ['rating' => 4])->assertOk();
    $this->postJson($url, ['rating' => 4])->assertOk();
    expect(CatalogReview::sole()->revision)->toBe(1);
});

it('allows withdrawing a rating while preserving the comment for new moderation', function (): void {
    $review = $this->reviews->submit($this->venue, $this->passenger, 2, 'Esperienza da migliorare.');
    $this->reviews->moderate($review, cpStaff(), 'approved', 1, null);
    $this->reviews->submit($this->venue, $this->passenger, null, 'Esperienza da migliorare.', 2);
    expect(CatalogRating::count())->toBe(0)->and($this->reviews->listing($this->venue, null)['average'])->toBeNull();
});

it('routes moderation to one deduplicated social badge with a current domain URL', function (): void {
    $review = $this->reviews->submit($this->organizer, $this->passenger, 4, null);
    $this->reviews->moderate($review, cpStaff(), 'approved', 1, null);
    expect(DB::table('community_delivery_outbox')->where('user_id', $this->passenger->id)->count())->toBe(1);
    $notice = $this->passenger->notifications()->sole();
    expect($notice->data['url'])->toContain('/organizzatori/'.$this->organizer->slug.'#recensioni');
    expect(app(CommunityDelivery::class)->allowed($this->passenger, $notice->data))->toBeTrue();
});

it('keeps reporting snapshots private after the author deletes the review', function (): void {
    $review = $this->reviews->submit($this->organizer, $this->passenger, 1, 'Testo contestato da esaminare.');
    $this->reviews->moderate($review, cpStaff(), 'approved', 1, null);
    Sanctum::actingAs($this->driver);
    $url = catalogUrl($this, 'organizer').'/'.$review->id.'/report';
    $this->postJson($url, ['body' => 'Vorrei segnalare questo testo.'])->assertOk();
    $this->postJson($url, ['body' => 'Vorrei segnalare questo testo.'])->assertOk();
    $this->reviews->remove($this->organizer, $this->passenger);
    $case = CarpoolCase::sole();
    expect($case->review_snapshot['body'])->toBe('Testo contestato da esaminare.')->and($case->ride_request_id)->toBeNull();
    expect(DB::table('carpool_cases')->value('review_snapshot'))->not->toContain('Testo contestato');
    expect(CatalogRating::count())->toBe(0);
});

it('forbids reporting through a different subject and without verification', function (): void {
    $review = $this->reviews->submit($this->organizer, $this->passenger, 4, null);
    $this->reviews->moderate($review, cpStaff(), 'approved', 1, null);
    Sanctum::actingAs($this->driver);
    $this->postJson(catalogUrl($this, 'venue').'/'.$review->id.'/report', ['body' => 'Segnalazione da verificare.'])->assertNotFound();
    Sanctum::actingAs(User::factory()->create());
    $this->postJson(catalogUrl($this, 'organizer').'/'.$review->id.'/report', ['body' => 'Segnalazione da verificare.'])->assertForbidden();
});

it('renders organizer reviews safely and offers verification to an ordinary member', function (): void {
    $review = $this->reviews->submit($this->organizer, $this->passenger, 3, '<script>alert(1)</script> Esperienza.');
    $this->reviews->moderate($review, cpStaff(), 'approved', 1, null);
    $this->actingAs(User::factory()->create())->get('/organizzatori/'.$this->organizer->slug)->assertOk()->assertSee(__('reviews.verify'))->assertSee('&lt;script&gt;', false)->assertDontSee('<script>alert(1)</script>', false);
});

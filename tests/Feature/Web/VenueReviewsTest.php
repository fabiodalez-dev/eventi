<?php

declare(strict_types=1);

use App\Enums\VenueReviewStatus;
use App\Filament\Admin\Resources\VenueReviews\Pages\ListVenueReviews;
use App\Models\User;
use App\Models\Venue;
use App\Models\VenueReview;
use App\Services\Reviews\VenueReviews;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->city = testCity();
    $this->venue = Venue::factory()->approved()->create(['city_id' => $this->city->id]);
    $this->user = User::factory()->create();
    (new RolesAndPermissionsSeeder)->run();
    $this->endpoint = '/api/v1/venues/'.$this->venue->slug.'/reviews';
    $this->form = '/locali/'.$this->venue->slug.'/recensione';
});

it('only accepts authenticated submissions and validates stars and body', function (): void {
    $this->postJson($this->endpoint, ['rating' => 5, 'body' => 'Un locale molto piacevole.'])->assertUnauthorized();
    $this->post($this->form)->assertRedirect(route('login'));
    Sanctum::actingAs($this->user);
    foreach ([0, 6, 2.5] as $rating) {
        $this->postJson($this->endpoint, ['rating' => $rating, 'body' => 'Esperienza molto piacevole.'])->assertUnprocessable();
    }
    $this->postJson($this->endpoint, ['rating' => 4, 'body' => 'breve'])->assertUnprocessable();
    expect(VenueReview::query()->count())->toBe(0);
});

it('keeps reviews and their ratings private until platform approval', function (): void {
    Sanctum::actingAs($this->user);
    $this->postJson($this->endpoint, ['rating' => 5, 'body' => '<script>alert(1)</script> Locale piacevole.', 'status' => 'approved', 'user_id' => 999])->assertOk();
    $review = VenueReview::query()->sole();
    expect($review->status)->toBe(VenueReviewStatus::Pending)->and($review->user_id)->toBe($this->user->id);
    $this->getJson($this->endpoint)->assertJsonPath('data.count', 0)->assertJsonPath('data.my_review.status', 'pending')->assertHeader('Cache-Control', 'no-store, private');
    $other = User::factory()->create();
    Sanctum::actingAs($other);
    $this->getJson($this->endpoint)->assertJsonPath('data.reviews', [])->assertJsonPath('data.my_review', null);
    $this->deleteJson($this->endpoint)->assertNotFound();
    $admin = User::factory()->create();
    $admin->assignRole('admin');
    app(VenueReviews::class)->moderate($review, $admin, 'approved', $review->revision, null);
    $this->getJson($this->endpoint)->assertJsonPath('data.count', 1)->assertJsonPath('data.average', 5)->assertJsonMissingPath('data.reviews.0.user_id')->assertJsonMissingPath('data.reviews.0.moderation_note');
    $this->get('/locali/'.$this->venue->slug)->assertOk()->assertSee('&lt;script&gt;', false)->assertDontSee('<script>alert(1)</script>', false);
});

it('denies venue owners moderation even for their own venue', function (): void {
    $owner = User::factory()->create();
    $owner->assignRole('venue_owner');
    $this->venue->members()->attach($owner, ['role' => 'owner']);
    $review = app(VenueReviews::class)->submit($this->venue, $this->user, 4, 'Esperienza molto piacevole.');
    expect(Gate::forUser($owner)->allows('viewAny', VenueReview::class))->toBeFalse();
    expect(fn () => app(VenueReviews::class)->moderate($review, $owner, 'approved', $review->revision, null))->toThrow(AuthorizationException::class);
});

it('resets edited reviews to pending and rejects stale moderation', function (): void {
    $service = app(VenueReviews::class);
    $review = $service->submit($this->venue, $this->user, 4, 'Esperienza molto piacevole.');
    $admin = User::factory()->create();
    $admin->assignRole('admin');
    $service->moderate($review, $admin, 'approved', 1, null);
    $updated = $service->submit($this->venue, $this->user, 2, 'Una nuova esperienza diversa.');
    expect(VenueReview::query()->count())->toBe(1)->and($updated->status)->toBe(VenueReviewStatus::Pending)->and($updated->moderated_at)->toBeNull();
    expect($service->listing($this->venue, null)['count'])->toBe(0);
    expect(fn () => $service->moderate($review, $admin, 'approved', 1, null))->toThrow(ValidationException::class);
    $service->moderate($updated, $admin, 'rejected', $updated->revision, 'Testo da rivedere.');
    expect($service->listing($this->venue, null)['count'])->toBe(0);
});

it('deletes only the authors review and removes it on account deletion', function (): void {
    $service = app(VenueReviews::class);
    $service->submit($this->venue, $this->user, 4, 'Esperienza molto piacevole.');
    Sanctum::actingAs($this->user);
    $this->deleteJson($this->endpoint)->assertOk();
    expect(VenueReview::query()->count())->toBe(0);
    $service->submit($this->venue, $this->user, 4, 'Esperienza molto piacevole.');
    $this->user->delete();
    expect(VenueReview::query()->count())->toBe(0);
});

it('saves web forms and redirects validation back to the venue without relying on referer', function (): void {
    $this->actingAs($this->user)->post($this->form, ['rating' => 3, 'body' => 'Locale tranquillo e piacevole.'])->assertRedirect('/locali/'.$this->venue->slug.'#recensioni');
    $this->post($this->form, ['rating' => 8, 'body' => 'no'])->assertSessionHasErrors(['rating', 'body'])->assertRedirect('/locali/'.$this->venue->slug.'#recensioni');
});

it('moderates through the platform backend and requires a rejection reason', function (): void {
    $admin = User::factory()->create();
    $admin->assignRole('admin');
    $this->actingAs($admin);
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $review = app(VenueReviews::class)->submit($this->venue, $this->user, 5, 'Accoglienza molto piacevole.');
    Livewire::test(ListVenueReviews::class)
        ->assertCanSeeTableRecords([$review])
        ->callTableAction('approved', $review, ['revision' => 1, 'note' => null])
        ->assertHasNoTableActionErrors();
    expect($review->refresh()->status)->toBe(VenueReviewStatus::Approved)->and($review->moderated_by)->toBe($admin->id);
    Livewire::test(ListVenueReviews::class)
        ->callTableAction('rejected', $review, ['revision' => $review->revision, 'note' => ''])
        ->assertHasTableActionErrors(['note' => 'required']);
    expect($review->refresh()->status)->toBe(VenueReviewStatus::Approved);
    Livewire::test(ListVenueReviews::class)
        ->callTableAction('rejected', $review, ['revision' => $review->revision, 'note' => 'Informazioni personali nel testo.'])
        ->assertHasNoTableActionErrors();
    expect($review->refresh()->status)->toBe(VenueReviewStatus::Rejected);
});

it('rejects backend access by venue staff and ordinary members', function (string $role): void {
    $this->user->assignRole($role);
    $this->actingAs($this->user)->get('/admin/venue-reviews')->assertForbidden();
})->with(['user', 'venue_owner', 'venue_editor', 'moderator']);

it('paginates approved reviews without including unpublished ratings', function (): void {
    $service = app(VenueReviews::class);
    $admin = User::factory()->create();
    $admin->assignRole('super_admin');
    foreach (range(1, 11) as $index) {
        $review = $service->submit($this->venue, User::factory()->create(), 4, 'Una visita molto piacevole.');
        $service->moderate($review, $admin, 'approved', 1, null);
    }
    $service->submit($this->venue, $this->user, 1, 'Recensione ancora in attesa.');
    $this->getJson($this->endpoint.'?page=2')->assertOk()->assertJsonPath('data.count', 11)
        ->assertJsonPath('data.average', 4)->assertJsonPath('data.last_page', 2)
        ->assertJsonCount(1, 'data.reviews')->assertJsonPath('data.my_review', null);
});

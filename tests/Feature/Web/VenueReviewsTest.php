<?php

declare(strict_types=1);

use App\Enums\CatalogReviewStatus;
use App\Filament\Admin\Resources\CatalogReviews\Pages\ListCatalogReviews;
use App\Models\CatalogReview;
use App\Models\Venue;
use App\Services\Reviews\CatalogReviews;
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
    $this->user = carpoolPerson(false);
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
    expect(CatalogReview::query()->count())->toBe(0);
});

it('keeps reviews and their ratings private until platform approval', function (): void {
    Sanctum::actingAs($this->user);
    $this->postJson($this->endpoint, ['rating' => 5, 'body' => '<script>alert(1)</script> Locale piacevole.', 'status' => 'approved', 'user_id' => 999])->assertOk();
    $review = CatalogReview::query()->sole();
    expect($review->status)->toBe(CatalogReviewStatus::Pending)->and($review->user_id)->toBe($this->user->id);
    $this->getJson($this->endpoint)->assertJsonPath('data.count', 0)->assertJsonPath('data.my_review.status', 'pending')->assertHeader('Cache-Control', 'no-store, private');
    $other = carpoolPerson(false);
    Sanctum::actingAs($other);
    $this->getJson($this->endpoint)->assertJsonPath('data.reviews', [])->assertJsonPath('data.my_review', null);
    $this->deleteJson($this->endpoint)->assertNotFound();
    $admin = carpoolPerson(false);
    $admin->assignRole('admin');
    app(CatalogReviews::class)->moderate($review, $admin, 'approved', $review->revision, null);
    $this->getJson($this->endpoint)->assertJsonPath('data.count', 1)->assertJsonPath('data.average', 5)->assertJsonMissingPath('data.reviews.0.user_id')->assertJsonMissingPath('data.reviews.0.moderation_note');
    $this->get('/locali/'.$this->venue->slug)->assertOk()->assertSee('&lt;script&gt;', false)->assertDontSee('<script>alert(1)</script>', false);
});

it('denies venue owners moderation even for their own venue', function (): void {
    $owner = carpoolPerson(false);
    $owner->assignRole('venue_owner');
    $this->venue->members()->attach($owner, ['role' => 'owner']);
    $review = app(CatalogReviews::class)->submit($this->venue, $this->user, 4, 'Esperienza molto piacevole.');
    expect(Gate::forUser($owner)->allows('viewAny', CatalogReview::class))->toBeFalse();
    expect(fn () => app(CatalogReviews::class)->moderate($review, $owner, 'approved', $review->revision, null))->toThrow(AuthorizationException::class);
});

it('resets edited reviews to pending and rejects stale moderation', function (): void {
    $service = app(CatalogReviews::class);
    $review = $service->submit($this->venue, $this->user, 4, 'Esperienza molto piacevole.');
    $admin = carpoolPerson(false);
    $admin->assignRole('admin');
    $service->moderate($review, $admin, 'approved', 1, null);
    $updated = $service->submit($this->venue, $this->user, 2, 'Una nuova esperienza diversa.');
    expect(CatalogReview::query()->count())->toBe(1)->and($updated->status)->toBe(CatalogReviewStatus::Pending)->and($updated->moderated_at)->toBeNull();
    expect($service->listing($this->venue, null)['count'])->toBe(0);
    expect(fn () => $service->moderate($review, $admin, 'approved', 1, null))->toThrow(ValidationException::class);
    $service->moderate($updated, $admin, 'rejected', $updated->revision, 'Testo da rivedere.');
    expect($service->listing($this->venue, null)['count'])->toBe(0);
});

it('deletes only the authors review and removes it on account deletion', function (): void {
    $service = app(CatalogReviews::class);
    $service->submit($this->venue, $this->user, 4, 'Esperienza molto piacevole.');
    Sanctum::actingAs($this->user);
    $this->deleteJson($this->endpoint)->assertOk();
    expect(CatalogReview::query()->count())->toBe(0);
    $service->submit($this->venue, $this->user, 4, 'Esperienza molto piacevole.');
    $this->user->delete();
    expect(CatalogReview::query()->count())->toBe(0);
});

it('saves web forms and redirects validation back to the venue without relying on referer', function (): void {
    $this->actingAs($this->user)->post($this->form, ['rating' => 3, 'body' => 'Locale tranquillo e piacevole.'])->assertRedirect('/locali/'.$this->venue->slug.'#recensioni');
    $this->post($this->form, ['rating' => 8, 'body' => 'no'])->assertSessionHasErrors(['rating', 'body'])->assertRedirect('/locali/'.$this->venue->slug.'#recensioni');
});

it('moderates through the platform backend and requires a rejection reason', function (): void {
    $admin = carpoolPerson(false);
    $admin->assignRole('admin');
    $this->actingAs($admin);
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $review = app(CatalogReviews::class)->submit($this->venue, $this->user, 5, 'Accoglienza molto piacevole.');
    Livewire::test(ListCatalogReviews::class)
        ->assertCanSeeTableRecords([$review])
        ->callTableAction('approved', $review, ['revision' => 1, 'note' => null])
        ->assertHasNoTableActionErrors();
    expect($review->refresh()->status)->toBe(CatalogReviewStatus::Approved)->and($review->moderated_by)->toBe($admin->id);
    Livewire::test(ListCatalogReviews::class)
        ->callTableAction('rejected', $review, ['revision' => $review->revision, 'note' => ''])
        ->assertHasTableActionErrors(['note' => 'required']);
    expect($review->refresh()->status)->toBe(CatalogReviewStatus::Approved);
    Livewire::test(ListCatalogReviews::class)
        ->callTableAction('rejected', $review, ['revision' => $review->revision, 'note' => 'Informazioni personali nel testo.'])
        ->assertHasNoTableActionErrors();
    expect($review->refresh()->status)->toBe(CatalogReviewStatus::Rejected);
});

it('rejects backend access by venue staff and ordinary members', function (string $role): void {
    $this->user->assignRole($role);
    $this->actingAs($this->user)->get('/admin/catalog-reviews')->assertForbidden();
})->with(['user', 'venue_owner', 'venue_editor', 'moderator']);

it('paginates approved reviews without including unpublished ratings', function (): void {
    $service = app(CatalogReviews::class);
    $admin = carpoolPerson(false);
    $admin->assignRole('super_admin');
    foreach (range(1, 11) as $index) {
        $review = $service->submit($this->venue, carpoolPerson(false), 4, 'Una visita molto piacevole.');
        $service->moderate($review, $admin, 'approved', 1, null);
    }
    $service->submit($this->venue, $this->user, 1, 'Recensione ancora in attesa.');
    $this->getJson($this->endpoint.'?page=2')->assertOk()->assertJsonPath('data.count', 11)
        ->assertJsonPath('data.average', 4)->assertJsonPath('data.last_page', 2)
        ->assertJsonCount(1, 'data.reviews')->assertJsonPath('data.my_review', null);
});

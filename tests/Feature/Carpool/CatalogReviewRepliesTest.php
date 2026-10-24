<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Enums\VenueRole;
use App\Filament\Organizer\Resources\CatalogReviews\Pages\ListCatalogReviews as OrganizerReviews;
use App\Filament\Venue\Resources\CatalogReviews\Pages\ListCatalogReviews as VenueReviews;
use App\Models\CatalogReview;
use App\Models\Organizer;
use App\Models\User;
use App\Models\Venue;
use App\Services\Reviews\CatalogReviews;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;

afterEach(function (): void {
    Filament\Facades\Filament::setTenant(null);
    Filament\Facades\Filament::setCurrentPanel(null);
});

beforeEach(function (): void {
    (new RolesAndPermissionsSeeder)->run();
    $this->venue = Venue::factory()->approved()->create(['city_id' => $this->city->id, 'name' => 'Osteria del Ponte']);
    $this->otherVenue = Venue::factory()->approved()->create(['city_id' => $this->city->id]);
    $this->owner = User::factory()->create();
    $this->owner->assignRole(UserRole::VenueOwner->value);
    $this->owner->venues()->attach($this->venue, ['role' => VenueRole::Owner->value, 'invited_at' => now(), 'accepted_at' => now()]);
    $this->reviews = app(CatalogReviews::class);
});

function approvedReview(object $test, Venue|Organizer $subject, ?User $author = null, string $body = 'Cena ottima, servizio lento.'): CatalogReview
{
    $review = $test->reviews->submit($subject, $author ?? $test->passenger, 4, $body);
    $test->reviews->moderate($review, cpStaff(), 'approved', $review->revision, null);

    return $review->fresh();
}

it('lets the venue reply in public, notifies the author once and shows the reply on the site and the API', function (): void {
    $review = approvedReview($this, $this->venue);

    $this->reviews->reply($review, $this->owner, 'Grazie, stiamo assumendo una persona in sala.');
    $this->reviews->reply($review->fresh(), $this->owner, 'Grazie, abbiamo assunto una persona in sala.');

    $row = $review->fresh();
    expect($row->owner_reply)->toBe('Grazie, abbiamo assunto una persona in sala.')->and($row->owner_reply_by)->toBe($this->owner->id)
        ->and(DB::table('community_delivery_outbox')->where('user_id', $this->passenger->id)->where('payload', 'like', '%catalog_review_replied%')->count())->toBe(1);
    $this->getJson('/api/v1/venues/'.$this->venue->slug.'/reviews')->assertOk()
        ->assertJsonPath('data.reviews.0.reply.author', 'Osteria del Ponte')
        ->assertJsonPath('data.reviews.0.reply.body', 'Grazie, abbiamo assunto una persona in sala.');
    $this->get(route('venues.show', $this->venue))->assertOk()
        ->assertSee(__('reviews.reply.from', ['name' => 'Osteria del Ponte']))->assertSee('abbiamo assunto una persona');

    $this->reviews->reply($row, $this->owner, null);
    expect($row->fresh()->owner_reply)->toBeNull();
    $this->getJson('/api/v1/venues/'.$this->venue->slug.'/reviews')->assertJsonPath('data.reviews.0.reply', null);
});

it('refuses replies from people who do not manage the subject, to unpublished reviews and out of bounds', function (): void {
    $pending = $this->reviews->submit($this->venue, $this->passenger, 3, 'Ancora da approvare, questa.');
    expect(fn () => $this->reviews->reply($pending, $this->owner, 'Risposta'))->toThrow(HttpException::class);

    $published = approvedReview($this, $this->otherVenue, $this->driver);
    expect(fn () => $this->reviews->reply($published, $this->owner, 'Non è il mio locale'))->toThrow(HttpException::class)
        ->and(fn () => $this->reviews->reply($published, $this->passenger, 'Nemmeno mio'))->toThrow(HttpException::class);

    $mine = approvedReview($this, $this->venue, $this->driver);
    expect(fn () => $this->reviews->reply($mine, $this->owner, 'x'))->toThrow(ValidationException::class)
        ->and(fn () => $this->reviews->reply($mine, $this->owner, str_repeat('x', 2001)))->toThrow(ValidationException::class);
});

it('shows the venue panel only its own published reviews and lets it reply', function (): void {
    $own = approvedReview($this, $this->venue);
    $foreign = approvedReview($this, $this->otherVenue, $this->driver, 'Recensione di un altro locale.');
    $pending = $this->reviews->submit($this->venue, $this->driver, 2, 'In attesa di moderazione.');

    $this->actingAs($this->owner)->get('/gestione/'.$this->venue->slug.'/recensioni')->assertOk()
        ->assertSee('Cena ottima')->assertDontSee('Recensione di un altro locale')->assertDontSee('In attesa di moderazione');
    Livewire::test(VenueReviews::class)->assertCanSeeTableRecords([$own])->assertCanNotSeeTableRecords([$foreign, $pending])
        ->callTableAction('reply', $own, ['reply' => 'Grazie della visita!'])->assertHasNoTableActionErrors();
    expect($own->fresh()->owner_reply)->toBe('Grazie della visita!');
});

it('shows the organizer panel only its own published reviews and lets it reply', function (): void {
    $organizer = Organizer::create(['name' => 'Suoni in città', 'city_id' => $this->city->id, 'is_active' => true, 'owner_id' => $this->owner->id]);
    $venueReview = approvedReview($this, $this->venue);
    $organizerReview = approvedReview($this, $organizer, $this->driver, 'Organizzazione impeccabile.');

    $this->actingAs($this->owner)->get('/organizza/'.$organizer->slug.'/recensioni')->assertOk()
        ->assertSee('Organizzazione impeccabile')->assertDontSee('Cena ottima');
    Livewire::test(OrganizerReviews::class)->assertCanSeeTableRecords([$organizerReview])->assertCanNotSeeTableRecords([$venueReview])
        ->callTableAction('reply', $organizerReview, ['reply' => 'Alla prossima!'])->assertHasNoTableActionErrors();
    expect($organizerReview->fresh()->owner_reply)->toBe('Alla prossima!');
});

it('lets the editorial staff remove a reply but not write one', function (): void {
    $review = approvedReview($this, $this->venue);
    $this->reviews->reply($review, $this->owner, 'Risposta da togliere.');
    $admin = cpStaff();

    expect(fn () => $this->reviews->reply($review->fresh(), $admin, 'Scrivo io'))->toThrow(HttpException::class);
    $this->reviews->reply($review->fresh(), $admin, null);
    expect($review->fresh()->owner_reply)->toBeNull();
    Sanctum::actingAs($this->passenger);
    $this->getJson('/api/v1/venues/'.$this->venue->slug.'/reviews')->assertJsonPath('data.reviews.0.reply', null);
});

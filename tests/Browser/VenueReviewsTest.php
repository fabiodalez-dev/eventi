<?php

declare(strict_types=1);

use App\Enums\VenueReviewStatus;
use App\Models\User;
use App\Models\Venue;
use App\Models\VenueReview;
use App\Services\Reviews\VenueReviews;
use Database\Seeders\RolesAndPermissionsSeeder;

it('submits stars and text then requires moderation again after editing', function (string $device): void {
    $city = testCity();
    (new RolesAndPermissionsSeeder)->run();
    $venue = Venue::factory()->approved()->create(['city_id' => $city->id]);
    $user = User::factory()->create();
    $this->actingAs($user);
    $page = visit('/locali/'.$venue->slug)->on()->{$device}()
        ->click('[data-consent-banner] button[value="reject_all"]')
        ->click('input[name="rating"][value="4"]')->fill('body', 'Un locale accogliente e tranquillo.')
        ->click('#recensioni form:first-of-type button[type="submit"]')->assertSee(__('reviews.pending'));
    $review = VenueReview::query()->sole();
    expect($review->rating)->toBe(4)->and($review->status)->toBe(VenueReviewStatus::Pending);
    $admin = User::factory()->create();
    $admin->assignRole('admin');
    app(VenueReviews::class)->moderate($review, $admin, 'approved', 1, null);
    $page->navigate('/locali/'.$venue->slug)->assertSee(__('reviews.approved'))
        ->assertSee('Un locale accogliente e tranquillo.');
    expect($page->script('document.querySelectorAll("#recensioni article").length'))->toBe(1);
    $page->fill('body', 'Aggiorno la mia esperienza nel locale.')
        ->click('#recensioni form:first-of-type button[type="submit"]')->assertSee(__('reviews.pending'));
    expect($page->script('document.querySelectorAll("#recensioni article").length'))->toBe(0);
    expect($page->script('document.documentElement.scrollWidth <= innerWidth'))->toBeTrue();
    $page->assertNoJavascriptErrors()->screenshot(filename: 'venue-reviews-'.$device);
})->with(['desktop', 'mobile']);

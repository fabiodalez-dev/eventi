<?php

declare(strict_types=1);

use App\Enums\CatalogReviewStatus;
use App\Models\CatalogReview;
use App\Models\Venue;
use App\Services\Reviews\CatalogReviews;
use Database\Seeders\RolesAndPermissionsSeeder;

it('submits stars and text then requires moderation again after editing', function (string $device, bool $busyBrowser): void {
    $city = testCity();
    (new RolesAndPermissionsSeeder)->run();
    $venue = Venue::factory()->approved()->create(['city_id' => $city->id]);
    $user = carpoolPerson(false);
    $this->actingAs($user);
    $page = visit('/locali/'.$venue->slug)->on()->{$device}()
        ->click('[data-consent-banner] button[value="reject_all"]')
        ->click('input[name="rating"][value="4"]')->fill('[data-review-form] textarea[name="body"]', 'Un locale accogliente e tranquillo.');
    // Pest retries click actions after one second, potentially submitting the
    // same form repeatedly. One Playwright action must produce one revision.
    $page->page()->locator('[data-review-form] button[type="submit"]')->click(['timeout' => 5000]);
    $page->assertSee(__('reviews.pending'));
    $review = CatalogReview::query()->sole();
    expect($review->revision)->toBe(1);
    expect($review->rating)->toBe(4)->and($review->status)->toBe(CatalogReviewStatus::Pending);
    $admin = carpoolPerson(false);
    $admin->assignRole('admin');
    app(CatalogReviews::class)->moderate($review, $admin, 'approved', $review->revision, null);
    $page->navigate('/locali/'.$venue->slug)->assertSee(__('reviews.approved'))
        ->assertSee('Un locale accogliente e tranquillo.');
    expect($page->script('document.querySelectorAll("#recensioni article").length'))->toBe(1);
    if ($busyBrowser) {
        $page->script('document.querySelector("[data-review-form] button[type=submit]").addEventListener("click", () => { const until = performance.now() + 1200; while (performance.now() < until) {} });');
    }
    $page->fill('[data-review-form] textarea[name="body"]', 'Aggiorno la mia esperienza nel locale.');
    $page->page()->locator('[data-review-form] button[type="submit"]')->click(['timeout' => 5000]);
    $page->assertSee(__('reviews.pending'));
    expect($review->fresh()->revision)->toBe(3);
    expect($page->script('document.querySelectorAll("#recensioni article").length'))->toBe(0);
    expect($page->script('document.documentElement.scrollWidth <= innerWidth'))->toBeTrue();
    $page->assertNoJavascriptErrors()->screenshot(filename: 'venue-reviews-'.$device);
})->with(['desktop' => ['desktop', false], 'mobile' => ['mobile', false], 'slow mobile' => ['mobile', true]]);

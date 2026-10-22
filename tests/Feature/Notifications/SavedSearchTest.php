<?php

declare(strict_types=1);

use App\Enums\NotificationType;
use App\Models\SavedSearch;
use App\Models\ScheduledNotification;
use App\Models\User;
use App\Notifications\Scheduled\ScheduledMessage;
use App\Services\Notifications\MessageFactory;
use App\Services\Search\SavedSearchMatcher;
use Illuminate\Support\Facades\Notification;

afterEach(fn () => Carbon\Carbon::setTestNow());

it('is saved from the catalogue filters, deduplicated, limited, and listed with a way to remove it', function (): void {
    $city = testCity();
    $category = testCategory(['slug' => 'musica-dal-vivo']);
    $user = User::factory()->create(['email_verified_at' => now()]);
    $this->actingAs($user)->post(route('account.searches.store'), ['filters' => ['category' => $category->slug, 'price' => 'free', 'date' => 'weekend']])
        ->assertRedirect()->assertSessionHas('status', __('search.saved.stored'));
    $search = SavedSearch::query()->sole();
    expect($search->filters)->toBe(['category' => 'musica-dal-vivo', 'price' => 'free'])
        ->and($search->name)->toContain('musica-dal-vivo')
        ->and($search->last_checked_at)->not->toBeNull();
    $this->actingAs($user)->post(route('account.searches.store'), ['filters' => ['category' => $category->slug, 'price' => 'free']])
        ->assertSessionHas('status', __('search.saved.exists'));
    expect(SavedSearch::query()->count())->toBe(1);
    $this->actingAs($user)->post(route('account.searches.store'), ['filters' => ['date' => 'weekend']])->assertSessionHasErrors('filters');
    $this->actingAs($user)->get(route('account.notifications.interests'))->assertOk()->assertSee($search->name);
    $this->actingAs($user)->get(route('events.index', ['category' => $category->slug]))->assertOk()->assertSee(__('search.saved.cta'));
    $this->get(route('search', ['q' => 'jazz']))->assertOk()->assertSee(__('search.saved.cta'));
    $this->actingAs($user)->getJson('/api/v1/me/searches')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.filters.price', 'free');
    $other = User::factory()->create();
    $this->actingAs($other)->delete(route('account.searches.destroy', $search))->assertForbidden();
    $this->actingAs($user)->delete(route('account.searches.destroy', $search))->assertRedirect();
    expect(SavedSearch::query()->count())->toBe(0);
    for ($i = 0; $i < SavedSearch::MAX_PER_USER; $i++) {
        $this->actingAs($user)->postJson('/api/v1/me/searches', ['filters' => ['q' => 'ricerca '.$i]])->assertCreated();
    }
    $this->actingAs($user)->postJson('/api/v1/me/searches', ['filters' => ['q' => 'una di troppo']])->assertUnprocessable();
});

it('notifies only dates published after the last check and links every item', function (): void {
    $city = testCity();
    $category = testCategory(['slug' => 'musica-dal-vivo']);
    freezeLocal($city, '2026-10-23 09:00');
    $user = User::factory()->create(['email_verified_at' => now()]);
    $old = occurrenceAtLocal($city, $category, '2026-10-30 21:00', event: ['title' => 'Già in catalogo']);
    $search = $user->savedSearches()->create(['city_id' => $city->id, 'name' => 'Musica', 'filters' => ['category' => 'musica-dal-vivo'], 'last_checked_at' => null]);
    $matcher = app(SavedSearchMatcher::class);
    expect($matcher->run())->toBe(0)
        ->and($search->fresh()->last_checked_at)->not->toBeNull()
        ->and(ScheduledNotification::query()->ofType('saved_search_match')->count())->toBe(0);

    freezeLocal($city, '2026-10-23 11:00');
    $new = occurrenceAtLocal($city, $category, '2026-10-31 21:00', event: ['title' => 'Appena pubblicato']);
    $otherCategory = occurrenceAtLocal($city, testCategory(['slug' => 'teatro']), '2026-10-31 21:00', event: ['title' => 'Teatro nuovo']);
    expect($matcher->run())->toBe(1);
    $row = ScheduledNotification::query()->ofType('saved_search_match')->sole();
    expect($row->context('occurrence_ids'))->toBe([$new->id]);
    $message = app(MessageFactory::class)->build($row, $user);
    expect($message->type)->toBe(NotificationType::SavedSearchMatch)
        ->and($message->items[0]['title'])->toBe('Appena pubblicato')
        ->and($message->url)->toBe(route('events.index', ['category' => 'musica-dal-vivo']));
    Notification::fake();
    $this->artisan('notifications:send')->assertSuccessful();
    Notification::assertSentTo($user, ScheduledMessage::class, fn ($notification) => $notification->message->type === NotificationType::SavedSearchMatch);
    // Nothing new since: no second row the same day, and none the day after either.
    freezeLocal($city, '2026-10-24 11:00');
    expect($matcher->run())->toBe(0);
    unset($old, $otherCategory);
});

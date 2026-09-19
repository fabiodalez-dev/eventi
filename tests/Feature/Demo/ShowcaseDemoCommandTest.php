<?php

declare(strict_types=1);

use App\Console\Commands\InvestorDemoCommand;
use App\Console\Commands\ShowcaseDemoCommand;
use App\Enums\EventStatus;
use App\Enums\VenueType;
use App\Models\Category;
use App\Models\CommunityComment;
use App\Models\CommunityPost;
use App\Models\CommunityProfile;
use App\Models\Event;
use App\Models\EventComment;
use App\Models\EventCommentReaction;
use App\Models\EventOccurrence;
use App\Models\Follow;
use App\Models\SavedEvent;
use App\Models\ScheduledNotification;
use App\Models\User;
use App\Models\UserBlock;
use App\Models\Venue;
use App\Models\VenueReview;
use App\Services\Notifications\DigestPlanner;
use Carbon\CarbonImmutable;
use Database\Seeders\CategorySeeder;
use Database\Seeders\EventFeatureSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

/** @return list<int> */
function showcaseUserIds(): array
{
    return User::withTrashed()->whereIn('email', ShowcaseDemoCommand::emails(ShowcaseDemoCommand::catalog()))->pluck('id')->map(fn ($id): int => (int) $id)->all();
}

/** @return array<string, int> */
function showcaseCounts(): array
{
    $ids = showcaseUserIds();

    return [
        'events' => Event::withTrashed()->where('source_ref', 'like', ShowcaseDemoCommand::PREFIX.'%')->count(),
        'occurrences' => EventOccurrence::query()->whereIn('event_id', Event::withTrashed()->where('source_ref', 'like', ShowcaseDemoCommand::PREFIX.'%')->select('id'))->count(),
        'users' => count($ids),
        'profiles' => CommunityProfile::query()->whereIn('user_id', $ids)->count(),
        'posts' => CommunityPost::query()->whereIn('user_id', $ids)->count(),
        'post_comments' => CommunityComment::query()->whereIn('user_id', $ids)->count(),
        'event_comments' => EventComment::query()->whereIn('user_id', $ids)->count(),
        'reactions' => EventCommentReaction::query()->whereIn('user_id', $ids)->count(),
        'people_follows' => DB::table('followables')->whereIn('user_id', $ids)->count(),
        'catalog_follows' => Follow::query()->whereIn('user_id', $ids)->count(),
        'saved' => SavedEvent::query()->whereIn('user_id', $ids)->count(),
        'blocks' => UserBlock::query()->whereIn('user_id', $ids)->count(),
        'reviews' => VenueReview::query()->whereIn('user_id', $ids)->count(),
        'notifications' => DB::table('notifications')->whereIn('notifiable_id', $ids)->where('notifiable_type', 'user')->count(),
    ];
}

beforeEach(function (): void {
    Queue::fake();
    Storage::fake('public');
    Mail::fake();
    Notification::fake();
    config(['community.enabled' => true, 'community.phone_hash_key' => 'test-fingerprint-key']);
    $this->city = testCity(['is_active' => true]);
    $this->seed([CategorySeeder::class, EventFeatureSeeder::class]);
    foreach (VenueType::cases() as $type) {
        Venue::factory()->create(['city_id' => $this->city->id, 'type' => $type, 'status' => 'approved']);
    }
    // Mercoledì 16 settembre: la settimana prossima va da lunedì 21 a domenica 27.
    $this->travelTo(CarbonImmutable::parse('2026-09-16 12:00', 'Europe/Rome'));
    // Due date del catalogo investitori nella settimana della vetrina: i post oltre la seconda si saltano.
    $category = Category::query()->where('slug', 'cinema')->firstOrFail();
    foreach ([0, 1] as $i) {
        $event = Event::factory()->create(['city_id' => $this->city->id, 'category_id' => $category->id, 'venue_id' => Venue::query()->first()->id,
            'status' => EventStatus::Published, 'published_at' => now(), 'source_ref' => InvestorDemoCommand::PREFIX.'000'.$i]);
        EventOccurrence::factory()->create(['event_id' => $event->id, 'starts_at' => CarbonImmutable::parse('2026-09-2'.(2 + $i).' 18:00', 'Europe/Rome')->utc(), 'ends_at' => null, 'doors_at' => null]);
    }
});

it('refuses to run in production without explicit permission', function (): void {
    app()->detectEnvironment(fn () => 'production');
    $this->artisan('demo:showcase')->assertFailed();
    $this->artisan('demo:showcase', ['--purge' => true])->assertFailed();
    expect(showcaseCounts()['events'])->toBe(0)->and(showcaseCounts()['users'])->toBe(0);
});

it('prints the plan without writing on dry run', function (): void {
    $this->artisan('demo:showcase', ['--dry-run' => true])->expectsOutputToContain('Prova a vuoto')->assertSuccessful();
    expect(showcaseCounts())->each->toBe(0);
});

it('creates the next week of events and a complete verified community', function (): void {
    $this->artisan('demo:showcase')->assertSuccessful();

    $events = Event::query()->where('source_ref', 'like', ShowcaseDemoCommand::PREFIX.'%')->with('occurrences', 'media')->get();
    $days = $events->flatMap->occurrences->map(fn ($date) => CarbonImmutable::parse($date->starts_at)->timezone('Europe/Rome')->format('Y-m-d'))->unique()->sort()->values()->all();
    expect($events)->toHaveCount(25)
        ->and($events->pluck('title')->unique())->toHaveCount(25)
        ->and($events->every(fn (Event $event) => $event->is_demo && $event->hasMedia('poster') && $event->occurrences->count() === 1
            && $event->status === EventStatus::Published && ! str_contains((string) $event->description, '<')))->toBeTrue()
        ->and($days)->toBe(['2026-09-21', '2026-09-22', '2026-09-23', '2026-09-24', '2026-09-25', '2026-09-26', '2026-09-27'])
        ->and($events->where('price_type.value', 'free')->count())->toBeGreaterThan(5)
        ->and($events->where('price_type.value', 'ticket')->count())->toBeGreaterThan(5)
        ->and($events->filter(fn (Event $event) => ($event->content_details['feature_ids'] ?? []) !== [])->count())->toBeGreaterThan(15)
        ->and($events->pluck('category_id')->unique()->count())->toBeGreaterThan(10)
        ->and($events->first()->getFirstMedia('poster')->getCustomProperty('credit'))->toHaveKey('license');

    expect(showcaseCounts())->toMatchArray([
        'events' => 25, 'occurrences' => 25, 'users' => 18, 'profiles' => 18,
        'posts' => 33, // 31 sugli eventi della settimana, 2 sulle date investitori disponibili
        'post_comments' => 24, 'event_comments' => 20, 'reactions' => 17,
        'people_follows' => 63, 'blocks' => 1, 'reviews' => 6,
    ]);

    $people = User::query()->whereIn('id', showcaseUserIds())->with('communityProfile')->get();
    expect($people->every(fn (User $user) => str_ends_with($user->email, '@demo.incitta.invalid') && $user->isWhatsappVerified()
        && str_starts_with((string) $user->whatsapp_phone, '+3900000000')))->toBeTrue()
        ->and($people->pluck('communityProfile.visibility.value')->unique()->sort()->values()->all())->toBe(['members', 'public'])
        ->and($people->filter(fn (User $user) => $user->communityProfile->featured)->count())->toBe(4);

    // Relazioni reciproche: ognuno segue il vicino successivo e il precedente.
    $first = $people->firstWhere('email', 'giulia_bassan@demo.incitta.invalid');
    $second = $people->firstWhere('email', 'marco_zanon@demo.incitta.invalid');
    expect($first->isFollowing($second))->toBeTrue()->and($second->isFollowing($first))->toBeTrue();

    $posts = CommunityPost::query()->whereIn('user_id', showcaseUserIds())->with('savedEvent')->get();
    expect($posts->pluck('intent.value')->unique()->sort()->values()->all())->toBe(['attend', 'recommend'])
        ->and($posts->every(fn (CommunityPost $post) => $post->savedEvent->visibility->value === 'public'))->toBeTrue()
        ->and(SavedEvent::query()->whereIn('user_id', showcaseUserIds())->where('visibility', 'private')->count())->toBeGreaterThan(10)
        ->and(CommunityComment::query()->whereIn('user_id', showcaseUserIds())->whereNotNull('parent_id')->count())->toBe(7)
        ->and(EventComment::query()->whereIn('user_id', showcaseUserIds())->whereNotNull('parent_id')->count())->toBe(10);
});

it('never sends or plans a message for the demo people', function (): void {
    $this->artisan('demo:showcase')->assertSuccessful();
    app(DigestPlanner::class)->plan();

    Notification::assertNothingSent();
    Mail::assertNothingSent();
    $people = User::query()->whereIn('id', showcaseUserIds())->get();
    expect(ScheduledNotification::query()->whereIn('user_id', showcaseUserIds())->count())->toBe(0)
        ->and($people->contains(fn (User $user) => $user->canReceiveNotifications()))->toBeFalse()
        ->and($people->contains(fn (User $user) => $user->devices()->exists() || $user->routeNotificationForFcm() !== [] || $user->routeNotificationForWebPush()->isNotEmpty()))->toBeFalse()
        ->and($people->every(fn (User $user) => ! $user->notificationPreferences()->reminders && ! $user->notificationPreferences()->venueDigest && ! $user->notificationPreferences()->dailyDigest))->toBeTrue()
        ->and(Follow::query()->whereIn('user_id', showcaseUserIds())->where('notify', true)->exists())->toBeFalse();
    // Un account ordinario con email confermata resta raggiungibile: il filtro riguarda solo `.invalid`.
    expect(User::factory()->create()->canReceiveNotifications())->toBeTrue();
});

it('is idempotent and fills the gaps on a second run', function (): void {
    $this->artisan('demo:showcase')->assertSuccessful();
    $before = showcaseCounts();
    CommunityComment::query()->whereIn('user_id', showcaseUserIds())->whereNotNull('parent_id')->first()->delete();
    EventCommentReaction::query()->whereIn('user_id', showcaseUserIds())->first()->delete();

    $this->travel(3)->days();
    $this->artisan('demo:showcase')->assertSuccessful();
    $after = showcaseCounts();

    // Le due righe tolte tornano con i loro avvisi; tutto il resto è invariato.
    expect(array_diff_key($after, ['notifications' => 0]))->toBe(array_diff_key($before, ['notifications' => 0]))
        ->and($after['notifications'])->toBeGreaterThanOrEqual($before['notifications']);
    $days = EventOccurrence::query()->whereIn('event_id', Event::query()->where('source_ref', 'like', ShowcaseDemoCommand::PREFIX.'%')->select('id'))->get()
        ->map(fn ($date) => CarbonImmutable::parse($date->starts_at)->timezone('Europe/Rome')->format('Y-m-d'))->unique()->sort()->values();
    expect($days->first())->toBe('2026-09-21')->and($days->last())->toBe('2026-09-27');
});

it('purges exactly the demo data and nothing else', function (): void {
    $real = User::factory()->create();
    $otherDemo = User::query()->create(['name' => 'Salvataggi demo 1', 'email' => 'saved-demo-1@demo.incitta.invalid', 'password' => 'x-'.Str::random(40)]);
    $realEvent = Event::factory()->create(['city_id' => $this->city->id, 'status' => EventStatus::Published]);
    $investor = Event::query()->where('source_ref', 'like', InvestorDemoCommand::PREFIX.'%')->pluck('id')->all();
    $venues = Venue::query()->count();
    $this->artisan('demo:showcase')->assertSuccessful();

    // Una persona vera che segue una persona demo: la relazione sparisce con lei.
    $demo = User::query()->whereIn('id', showcaseUserIds())->first();
    $real->follow($demo);
    SavedEvent::query()->create(['user_id' => $real->id, 'occurrence_id' => EventOccurrence::query()->whereIn('event_id', $investor)->value('id')]);

    $this->artisan('demo:showcase', ['--purge' => true])->assertSuccessful();

    expect(showcaseCounts())->each->toBe(0)
        ->and(DB::table('followables')->count())->toBe(0)
        ->and(User::query()->whereKey([$real->id, $otherDemo->id])->count())->toBe(2)
        ->and(Event::query()->whereKey([$realEvent->id, ...$investor])->count())->toBe(3)
        ->and(SavedEvent::query()->where('user_id', $real->id)->count())->toBe(1)
        ->and(Venue::query()->count())->toBe($venues);

    // Dopo la pulizia il catalogo si può ricreare da capo.
    $this->artisan('demo:showcase')->assertSuccessful();
    expect(showcaseCounts()['users'])->toBe(18)->and(showcaseCounts()['events'])->toBe(25);
});

it('renders the public pages with the seeded data', function (): void {
    $this->artisan('demo:showcase')->assertSuccessful();

    $this->get(route('community.people'))->assertOk()->assertSee('Giulia Bassan')->assertSee('Davide Pavan');
    $this->get(route('community.profile', 'giulia_bassan'))->assertOk()->assertSee('Profilo dimostrativo');

    $post = CommunityPost::query()->where('body', 'like', 'Cabiria sul grande schermo%')->firstOrFail();
    $this->get(route('community.post', $post))->assertOk()->assertSee('Cabiria sul grande schermo')->assertSee('tienimi un posto');

    $event = Event::query()->where('source_ref', ShowcaseDemoCommand::PREFIX.'00')->firstOrFail();
    $this->get(route('events.show', ['slug' => $event->slug]))->assertOk()
        ->assertSee('Le notti di Cabiria')
        ->assertSee('Qualcuno sa se c’è l’introduzione', false)
        ->assertSee('Evento dimostrativo');

    // Da iscritto con email confermata si vedono anche i profili «solo iscritti».
    $this->actingAs(User::factory()->create())->get(route('community.people'))->assertOk()->assertSee('Sara Boscolo');
});

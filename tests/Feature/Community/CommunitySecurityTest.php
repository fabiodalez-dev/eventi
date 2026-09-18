<?php

declare(strict_types=1);

use App\Actions\Account\DeleteAccount;
use App\Actions\Account\RemoveSavedOccurrence;
use App\Actions\Account\SaveOccurrences;
use App\Enums\CommunityStatus;
use App\Enums\EventStatus;
use App\Enums\KapsoOutcome;
use App\Enums\ProfileVisibility;
use App\Enums\VenueStatus;
use App\Enums\WhatsappChallengeStatus;
use App\Filament\Admin\Resources\Users\Pages\ListUsers;
use App\Models\CommunityComment;
use App\Models\CommunityPost;
use App\Models\Page;
use App\Models\SavedEvent;
use App\Models\User;
use App\Models\UserBlock;
use App\Models\Venue;
use App\Models\WhatsappChallenge;
use App\Services\Account\AccountExport;
use App\Services\Community\Community;
use App\Services\Community\CommunityModeration;
use App\Services\Community\KapsoClient;
use App\Services\Community\WhatsappVerification;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Database\Seeders\PageSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Facades\Filament;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Promise\Create;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Symfony\Component\HttpKernel\Exception\HttpException;

function communityPerson(string $visibility = 'public'): User
{
    $user = User::factory()->create();
    $phone = '+39333'.str_pad((string) $user->id, 7, '0', STR_PAD_LEFT);
    $user->forceFill(['whatsapp_phone' => $phone, 'whatsapp_phone_hash' => app(WhatsappVerification::class)->fingerprint($phone), 'whatsapp_verified_at' => now()])->save();
    $user->communityProfile()->create(['handle' => 'persona_'.$user->id, 'display_name' => 'Persona '.$user->id, 'visibility' => $visibility]);

    return $user;
}

function communityPost(User $user, $city, $category, string $date = '2026-09-15 19:00:00'): CommunityPost
{
    $occurrence = occurrenceAt($city, $category, $date);
    SavedEvent::query()->create(['user_id' => $user->id, 'occurrence_id' => $occurrence->id]);

    return app(Community::class)->publication($user, $occurrence->id, ['visibility' => 'public', 'body' => 'Consiglio questa serata', 'intent' => 'recommend']);
}

function communityChallenge(User $user, array $attributes = []): WhatsappChallenge
{
    $phone = '+393331234567';

    return WhatsappChallenge::query()->create([...[
        'id' => (string) Str::uuid(), 'user_id' => $user->id, 'phone' => $phone,
        'phone_hash' => app(WhatsappVerification::class)->fingerprint($phone), 'code_hash' => Hash::make('123456'),
        'expires_at' => now()->addMinutes(5), 'status' => WhatsappChallengeStatus::Sent,
    ], ...$attributes]);
}

function communityLogin(User $user): void
{
    Sanctum::actingAs($user);
}

beforeEach(function (): void {
    config(['community.enabled' => true, 'community.whatsapp_enabled' => true, 'community.kapso_key' => 'test-key-not-real', 'community.phone_number_id' => '123', 'community.phone_hash_key' => 'test-fingerprint-key']);
    Http::preventStrayRequests();
    Http::fake(['api.kapso.ai/*' => Http::response(['messages' => [['id' => 'wamid.test']]])]);
    $this->city = testCity(['is_active' => true]);
    $this->category = testCategory();
    freezeLocal($this->city, '2026-09-10 18:00');
    $this->user = User::factory()->create();
});

afterEach(function (): void {
    Carbon::setTestNow();
    CarbonImmutable::setTestNow();
});

it('01 denies anonymous verification and every social mutation', function (): void {
    foreach (['whatsapp', 'whatsapp/confirm', 'profile', 'people/1/follow', 'posts/1/comments', 'reports'] as $path) {
        $this->postJson('/api/v1/community/'.$path, [])->assertUnauthorized();
    }
    Http::assertNothingSent();
});

it('02 requires confirmed email before requesting or confirming WhatsApp', function (): void {
    $this->user->forceFill(['email_verified_at' => null])->save();
    communityLogin($this->user);
    $challenge = communityChallenge($this->user);
    $this->postJson('/api/v1/community/whatsapp', ['phone' => '+393331234567'])->assertForbidden();
    $this->postJson('/api/v1/community/whatsapp/confirm', ['challenge_id' => $challenge->id, 'code' => '123456'])->assertForbidden();
    expect($this->user->fresh()->whatsapp_verified_at)->toBeNull();
    Http::assertNothingSent();
});

it('03 gates account saving and catalog following before email confirmation', function (): void {
    $this->user->forceFill(['email_verified_at' => null])->save();
    communityLogin($this->user);
    $this->postJson('/api/v1/me/saved', ['occurrence_id' => 999])->assertForbidden()->assertJsonPath('error.code', 'EMAIL_VERIFICATION_REQUIRED');
    $this->postJson('/api/v1/me/follows', ['type' => 'venue', 'id' => 999])->assertForbidden();
    $this->getJson('/api/v1/me')->assertOk();
});

it('04 validates international phone numbers without contacting Kapso', function (): void {
    communityLogin($this->user);
    foreach (['not-a-number', '3331234567', '+99912345', '<script>alert(1)</script>'] as $phone) {
        $this->postJson('/api/v1/community/whatsapp', ['phone' => $phone])->assertUnprocessable();
    }
    Http::assertNothingSent();
    expect(WhatsappChallenge::count())->toBe(0);
});

it('05 generates a cryptographic code and only stores a hash and encrypted phone', function (): void {
    communityLogin($this->user);
    $response = $this->postJson('/api/v1/community/whatsapp', ['phone' => '+393331234567'])->assertOk();
    $challenge = WhatsappChallenge::findOrFail($response->json('data.challenge_id'));
    Http::assertSent(function ($request) use ($challenge): bool {
        $code = $request['template']['components'][0]['parameters'][0]['text'];

        return preg_match('/^[0-9]{6}$/', $code) === 1 && Hash::check($code, $challenge->code_hash)
            && $request['template']['components'][1]['parameters'][0]['text'] === $code
            && $request->hasHeader('X-API-Key', 'test-key-not-real');
    });
    expect(DB::table('whatsapp_challenges')->value('phone'))->not->toBe('+393331234567');
    expect($response->getContent())->not->toContain('code_hash', 'test-key-not-real', '+393331234567');
});

it('06 limits resends across the same user and the same normalized phone', function (): void {
    communityLogin($this->user);
    $this->postJson('/api/v1/community/whatsapp', ['phone' => '+393331234567'])->assertOk();
    $this->postJson('/api/v1/community/whatsapp', ['phone' => '+39 333 1234567'])->assertUnprocessable();
    communityLogin(User::factory()->create());
    $this->postJson('/api/v1/community/whatsapp', ['phone' => '+393331234567'])->assertUnprocessable();
    Http::assertSentCount(1);
});

it('07 enforces a global daily delivery budget before calling Kapso', function (): void {
    config(['community.global_daily_send_limit' => 1]);
    RateLimiter::hit('wa-global', 86400);
    communityLogin($this->user);
    $this->postJson('/api/v1/community/whatsapp', ['phone' => '+393331234567'])->assertUnprocessable();
    Http::assertNothingSent();
    expect(WhatsappChallenge::count())->toBe(0);
});

it('08 never verifies a challenge whose delivery failed', function (): void {
    Http::swap(new Factory);
    Http::preventStrayRequests();
    Http::fake(['api.kapso.ai/*' => Http::response(['error' => ['message' => 'provider detail']], 500)]);
    communityLogin($this->user);
    $this->postJson('/api/v1/community/whatsapp', ['phone' => '+393331234567'])->assertUnprocessable()->assertDontSee('provider detail');
    $challenge = WhatsappChallenge::firstOrFail();
    expect($challenge->status)->toBe(WhatsappChallengeStatus::Failed);
    $this->postJson('/api/v1/community/whatsapp/confirm', ['challenge_id' => $challenge->id, 'code' => '123456'])->assertUnprocessable();
    expect($this->user->fresh()->isWhatsappVerified())->toBeFalse();
});

it('09 fails closed when verification configuration is disabled', function (): void {
    config(['community.whatsapp_enabled' => false]);
    communityLogin($this->user);
    $this->getJson('/api/v1/community/whatsapp')->assertJsonPath('data.available', false);
    $this->postJson('/api/v1/community/whatsapp', ['phone' => '+393331234567'])->assertUnprocessable();
    Http::assertNothingSent();
});

it('10 verifies ownership with a valid code and consumes the challenge atomically', function (): void {
    communityLogin($this->user);
    $challenge = communityChallenge($this->user);
    $this->postJson('/api/v1/community/whatsapp/confirm', ['challenge_id' => $challenge->id, 'code' => '123456'])->assertOk()->assertJsonPath('data.verified', true);
    expect($this->user->fresh()->isWhatsappVerified())->toBeTrue()->and($challenge->fresh()->consumed_at)->not->toBeNull();
    expect(DB::table('users')->where('id', $this->user->id)->value('whatsapp_phone'))->not->toBe('+393331234567');
});

it('11 persists failed attempt counters rather than rolling them back', function (): void {
    communityLogin($this->user);
    $challenge = communityChallenge($this->user);
    $this->postJson('/api/v1/community/whatsapp/confirm', ['challenge_id' => $challenge->id, 'code' => '999999'])->assertUnprocessable();
    expect($challenge->fresh()->attempts)->toBe(1)->and($this->user->fresh()->isWhatsappVerified())->toBeFalse();
});

it('12 rejects even the correct code after exhausting attempts', function (): void {
    communityLogin($this->user);
    $challenge = communityChallenge($this->user);
    for ($i = 0; $i < 5; $i++) {
        $this->postJson('/api/v1/community/whatsapp/confirm', ['challenge_id' => $challenge->id, 'code' => '999999'])->assertUnprocessable();
    }
    $this->postJson('/api/v1/community/whatsapp/confirm', ['challenge_id' => $challenge->id, 'code' => '123456'])->assertUnprocessable();
    expect($challenge->fresh()->attempts)->toBe(5)->and($this->user->fresh()->isWhatsappVerified())->toBeFalse();
});

it('13 rejects expired codes at the expiration boundary', function (): void {
    communityLogin($this->user);
    $challenge = communityChallenge($this->user, ['expires_at' => now()]);
    $this->postJson('/api/v1/community/whatsapp/confirm', ['challenge_id' => $challenge->id, 'code' => '123456'])->assertUnprocessable();
    expect($this->user->fresh()->isWhatsappVerified())->toBeFalse();
});

it('14 forbids replay of a consumed code', function (): void {
    communityLogin($this->user);
    $challenge = communityChallenge($this->user, ['consumed_at' => now()]);
    $this->postJson('/api/v1/community/whatsapp/confirm', ['challenge_id' => $challenge->id, 'code' => '123456'])->assertUnprocessable();
    expect($this->user->fresh()->isWhatsappVerified())->toBeFalse();
});

it('15 isolates challenge IDs between users even with the correct code', function (): void {
    $challenge = communityChallenge($this->user);
    $other = User::factory()->create();
    communityLogin($other);
    $this->postJson('/api/v1/community/whatsapp/confirm', ['challenge_id' => $challenge->id, 'code' => '123456'])->assertUnprocessable();
    expect($challenge->fresh()->attempts)->toBe(0)->and($other->fresh()->isWhatsappVerified())->toBeFalse();
});

it('16 invalidates earlier challenges when a new code is requested', function (): void {
    $challenge = communityChallenge($this->user, ['created_at' => now()->subMinutes(2)]);
    communityLogin($this->user);
    $this->postJson('/api/v1/community/whatsapp', ['phone' => '+393331234567'])->assertOk();
    $this->postJson('/api/v1/community/whatsapp/confirm', ['challenge_id' => $challenge->id, 'code' => '123456'])->assertUnprocessable();
    expect($challenge->fresh()->consumed_at)->not->toBeNull();
});

it('17 enforces one verified account per phone at confirmation time', function (): void {
    $owner = communityPerson();
    $challenge = communityChallenge($this->user, ['phone' => $owner->whatsapp_phone, 'phone_hash' => $owner->whatsapp_phone_hash]);
    communityLogin($this->user);
    $this->postJson('/api/v1/community/whatsapp/confirm', ['challenge_id' => $challenge->id, 'code' => '123456'])->assertUnprocessable();
    expect($this->user->fresh()->whatsapp_verified_at)->toBeNull()->and($owner->fresh()->isWhatsappVerified())->toBeTrue();
});

it('18 never exposes private numbers or hashes through public profiles or me', function (): void {
    $author = communityPerson();
    $response = $this->getJson('/api/v1/community/people/'.$author->communityProfile->handle)->assertOk();
    expect($response->getContent())->not->toContain($author->email, $author->whatsapp_phone, $author->whatsapp_phone_hash);
    communityLogin($author);
    $response = $this->getJson('/api/v1/me')->assertOk()->assertJsonPath('data.whatsapp_verified', true);
    expect($response->getContent())->not->toContain($author->whatsapp_phone, $author->whatsapp_phone_hash);
});

it('19 revocation hides profiles posts and comments immediately', function (): void {
    $author = communityPerson();
    $post = communityPost($author, $this->city, $this->category);
    communityLogin($author);
    $this->deleteJson('/api/v1/community/whatsapp')->assertOk();
    $this->getJson('/api/v1/community/posts/'.$post->id)->assertNotFound();
    $this->getJson('/api/v1/community/people/'.$author->communityProfile->handle)->assertNotFound();
    expect($author->fresh()->whatsapp_phone)->toBeNull()->and($author->savedEvents()->count())->toBe(1);
});

it('20 losing email confirmation also removes public eligibility', function (): void {
    $author = communityPerson();
    $post = communityPost($author, $this->city, $this->category);
    $author->forceFill(['email_verified_at' => null])->save();
    $this->getJson('/api/v1/community/posts/'.$post->id)->assertNotFound();
    $this->getJson('/api/v1/community/people')->assertJsonCount(0, 'data');
});

it('21 allows normal confirmed followers with one database notification on retries', function (): void {
    $author = communityPerson();
    communityLogin($this->user);
    for ($i = 0; $i < 2; $i++) {
        $this->postJson('/api/v1/community/people/'.$author->id.'/follow')->assertOk();
    }
    expect(DB::table('followables')->count())->toBe(1)->and($author->notifications()->count())->toBe(1);
    $this->getJson('/api/v1/community/people/'.$author->communityProfile->handle)->assertJsonPath('data.profile.followers_count', 1);
    expect($this->user->follows()->count())->toBe(0);
});

it('22 refuses self-follow without creating a notification or relation', function (): void {
    $author = communityPerson();
    communityLogin($author);
    $this->postJson('/api/v1/community/people/'.$author->id.'/follow')->assertUnprocessable();
    expect(DB::table('followables')->count())->toBe(0)->and($author->notifications()->count())->toBe(0);
});

it('23 refuses publication by ordinary accounts while retaining private saves', function (): void {
    $date = occurrenceAt($this->city, $this->category, '2026-09-15 19:00');
    app(SaveOccurrences::class)->one($this->user, $date);
    communityLogin($this->user);
    $this->putJson('/api/v1/community/saved/'.$date->id, ['visibility' => 'public'])->assertForbidden();
    expect(CommunityPost::count())->toBe(0)->and($this->user->savedEvents()->first()->visibility->value)->toBe('private');
});

it('24 allows ordinary accounts to read but never comment', function (): void {
    $post = communityPost(communityPerson(), $this->city, $this->category);
    communityLogin($this->user);
    $this->getJson('/api/v1/community/posts/'.$post->id)->assertOk()->assertJsonPath('data.post.can_comment', false);
    $this->postJson('/api/v1/community/posts/'.$post->id.'/comments', ['body' => 'Test'])->assertForbidden();
    expect(CommunityComment::count())->toBe(0);
});

it('25 limits anonymous discovery to explicitly public verified profiles', function (): void {
    $public = communityPerson();
    communityPerson('members');
    communityPerson('private');
    $this->getJson('/api/v1/community/people')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.user_id', $public->id);
});

it('26 members profiles require a confirmed email rather than any login', function (): void {
    $author = communityPerson('members');
    $url = '/api/v1/community/people/'.$author->communityProfile->handle;
    $this->getJson($url)->assertNotFound();
    $this->user->forceFill(['email_verified_at' => null])->save();
    communityLogin($this->user);
    $this->getJson($url)->assertNotFound();
    $this->user->forceFill(['email_verified_at' => now()])->save();
    communityLogin($this->user);
    $this->getJson($url)->assertOk();
});

it('27 private profiles and their posts never enter a followers feed', function (): void {
    $author = communityPerson();
    $post = communityPost($author, $this->city, $this->category);
    $this->user->follow($author);
    $author->communityProfile->update(['visibility' => ProfileVisibility::Private]);
    communityLogin($this->user);
    $this->getJson('/api/v1/community/feed')->assertOk()->assertJsonCount(0, 'data');
    $this->getJson('/api/v1/community/posts/'.$post->id)->assertNotFound();
    $this->getJson('/api/v1/community/people/'.$author->communityProfile->handle)->assertNotFound();
});

it('28 blocks are enforced in both directions for direct URLs discovery and writes', function (): void {
    $author = communityPerson();
    $viewer = communityPerson();
    $post = communityPost($author, $this->city, $this->category);
    app(Community::class)->block($author, $viewer, true);
    communityLogin($viewer);
    $this->getJson('/api/v1/community/posts/'.$post->id)->assertNotFound();
    $this->postJson('/api/v1/community/people/'.$author->id.'/follow')->assertNotFound();
    $this->postJson('/api/v1/community/posts/'.$post->id.'/comments', ['body' => 'Bypass'])->assertNotFound();
    communityLogin($author);
    $this->getJson('/api/v1/community/people/'.$viewer->communityProfile->handle)->assertNotFound();
});

it('29 blocking removes follows in both directions but leaves catalog follows untouched', function (): void {
    $a = communityPerson();
    $b = communityPerson();
    $a->follow($b);
    $b->follow($a);
    $venue = Venue::factory()->approved()->create(['city_id' => $this->city->id]);
    $a->follows()->create(['followable_type' => 'venue', 'followable_id' => $venue->id]);
    communityLogin($a);
    $this->postJson('/api/v1/community/people/'.$b->id.'/block')->assertOk();
    expect(DB::table('followables')->count())->toBe(0)->and($a->follows()->count())->toBe(1);
});

it('30 unblocking only removes ones own block and never refollows automatically', function (): void {
    $a = communityPerson();
    $b = communityPerson();
    app(Community::class)->block($a, $b, true);
    app(Community::class)->block($b, $a, true);
    communityLogin($a);
    $this->deleteJson('/api/v1/community/people/'.$b->id.'/block')->assertOk();
    expect(UserBlock::count())->toBe(1)->and(DB::table('followables')->count())->toBe(0);
    $this->getJson('/api/v1/community/people/'.$b->communityProfile->handle)->assertNotFound();
});

it('31 all saves default to private without creating timeline content', function (): void {
    $user = communityPerson();
    $date = occurrenceAt($this->city, $this->category, '2026-09-15 19:00');
    app(SaveOccurrences::class)->one($user, $date);
    expect($user->savedEvents()->first()->visibility->value)->toBe('private')->and(CommunityPost::count())->toBe(0);
    $this->getJson('/api/v1/community/people/'.$user->communityProfile->handle)->assertJsonCount(0, 'data.posts');
});

it('32 cannot read or publish another users saved occurrence via guessed IDs', function (): void {
    $post = communityPost(communityPerson(), $this->city, $this->category);
    communityLogin(communityPerson());
    $this->getJson('/api/v1/community/saved/'.$post->occurrence_id)->assertNotFound();
    $this->putJson('/api/v1/community/saved/'.$post->occurrence_id, ['visibility' => 'public', 'user_id' => $post->user_id])->assertNotFound();
    expect(CommunityPost::count())->toBe(1);
});

it('33 making a save private removes the public post and its replies', function (): void {
    $author = communityPerson();
    $post = communityPost($author, $this->city, $this->category);
    $comment = app(Community::class)->comment($author, $post, 'Root', null);
    app(Community::class)->comment($author, $post, 'Reply', $comment->id);
    communityLogin($author);
    $this->putJson('/api/v1/community/saved/'.$post->occurrence_id, ['visibility' => 'private'])->assertOk();
    expect(CommunityPost::count())->toBe(0)->and(CommunityComment::count())->toBe(0)->and($author->savedEvents()->count())->toBe(1);
    $this->getJson('/api/v1/community/posts/'.$post->id)->assertNotFound();
});

it('34 unsaving an occurrence cascades only its own post and comments', function (): void {
    $author = communityPerson();
    $post = communityPost($author, $this->city, $this->category);
    $other = communityPost($author, $this->city, $this->category);
    app(Community::class)->comment($author, $post, 'Root', null);
    app(RemoveSavedOccurrence::class)($author, $post->occurrence_id);
    expect(CommunityPost::query()->whereKey($post->id)->exists())->toBeFalse()->and(CommunityPost::query()->whereKey($other->id)->exists())->toBeTrue()->and(CommunityComment::count())->toBe(0);
});

it('35 publishes exactly the selected date with the full catalog event card', function (): void {
    $author = communityPerson();
    $post = communityPost($author, $this->city, $this->category);
    $response = $this->getJson('/api/v1/community/posts/'.$post->id)->assertOk()->assertJsonPath('data.post.occurrence.occurrence_id', $post->occurrence_id);
    $response->assertJsonStructure(['data' => ['post' => ['occurrence' => ['poster', 'title', 'venue', 'starts_at', 'ends_at', 'price', 'category', 'date_url']]]]);
    $post->occurrence->event->update(['status' => EventStatus::Draft]);
    $this->getJson('/api/v1/community/posts/'.$post->id)->assertNotFound();
});

it('36 feeds use followed user IDs rather than follow row primary keys', function (): void {
    $author = communityPerson();
    $excluded = communityPerson();
    $post = communityPost($author, $this->city, $this->category);
    communityPost($excluded, $this->city, $this->category);
    DB::table('followables')->insert(['id' => 700, 'user_id' => $this->user->id, 'followable_type' => 'user', 'followable_id' => $author->id, 'accepted_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
    communityLogin($this->user);
    $this->getJson('/api/v1/community/feed')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $post->id);
});

it('37 supports stable recent and event ordering with explicit pagination metadata', function (): void {
    $author = communityPerson();
    $later = communityPost($author, $this->city, $this->category, '2026-09-20 19:00');
    $sooner = communityPost($author, $this->city, $this->category, '2026-09-12 19:00');
    $later->update(['published_at' => now()->addMinute()]);
    communityLogin($this->user);
    $this->getJson('/api/v1/community/feed?scope=discover&sort=recent')->assertJsonPath('data.0.id', $later->id)->assertJsonPath('meta.has_more', false);
    $this->getJson('/api/v1/community/feed?scope=discover&sort=event')->assertJsonPath('data.0.id', $sooner->id);
});

it('38 rejects privilege injection and case-insensitive duplicate profile handles', function (): void {
    $author = communityPerson();
    $other = communityPerson();
    communityLogin($author);
    $body = ['handle' => 'new_handle', 'display_name' => 'New', 'visibility' => 'members', 'indexable' => true, 'featured' => true, 'user_id' => $other->id, 'whatsapp_verified_at' => now()];
    $this->postJson('/api/v1/community/profile', $body)->assertOk();
    expect($author->communityProfile->fresh()->featured)->toBeFalse()->and($author->communityProfile->fresh()->indexable)->toBeFalse()->and($author->communityProfile->fresh()->user_id)->toBe($author->id);
    $this->postJson('/api/v1/community/profile', [...$body, 'handle' => $other->communityProfile->handle])->assertUnprocessable();
});

it('39 escapes user generated profile post and comment text on the website', function (): void {
    $author = communityPerson();
    $post = communityPost($author, $this->city, $this->category);
    $payload = '<script>alert(document.cookie)</script>';
    $post->update(['body' => $payload]);
    $author->communityProfile->update(['display_name' => $payload, 'bio' => $payload]);
    app(Community::class)->comment($author, $post, $payload, null);
    $this->get('/bacheca/post/'.$post->id)->assertOk()->assertDontSee($payload, false)->assertSee(e($payload), false);
    $this->get('/persone/'.$author->communityProfile->handle)->assertOk()->assertDontSee($payload, false);
});

it('40 publishes only explicitly selected approved venues already followed by the author', function (): void {
    $author = communityPerson();
    $approved = Venue::factory()->approved()->create(['city_id' => $this->city->id]);
    $unfollowed = Venue::factory()->approved()->create(['city_id' => $this->city->id]);
    $author->follows()->create(['followable_type' => 'venue', 'followable_id' => $approved->id]);
    communityLogin($author);
    $this->postJson('/api/v1/community/profile', ['handle' => $author->communityProfile->handle, 'display_name' => 'Name', 'visibility' => 'public', 'venue_ids' => [$approved->id, $unfollowed->id]])->assertOk();
    $this->getJson('/api/v1/community/people/'.$author->communityProfile->handle)->assertJsonCount(1, 'data.venues');
    $approved->update(['status' => VenueStatus::Suspended]);
    $this->getJson('/api/v1/community/people/'.$author->communityProfile->handle)->assertJsonCount(0, 'data.venues');
});

it('41 rejects parent comment IDs belonging to another post', function (): void {
    $author = communityPerson();
    $a = communityPost($author, $this->city, $this->category);
    $b = communityPost($author, $this->city, $this->category);
    $parent = app(Community::class)->comment($author, $a, 'Root', null);
    communityLogin($author);
    $this->postJson('/api/v1/community/posts/'.$b->id.'/comments', ['body' => 'Wrong parent', 'parent_id' => $parent->id])->assertNotFound();
    expect(CommunityComment::count())->toBe(1);
});

it('42 permits only one reply level and rejects arbitrary nesting', function (): void {
    $author = communityPerson();
    $post = communityPost($author, $this->city, $this->category);
    $root = app(Community::class)->comment($author, $post, 'Root', null);
    $reply = app(Community::class)->comment($author, $post, 'Reply', $root->id);
    communityLogin($author);
    $this->postJson('/api/v1/community/posts/'.$post->id.'/comments', ['body' => 'Nested', 'parent_id' => $reply->id])->assertNotFound();
    expect(CommunityComment::count())->toBe(2);
});

it('43 denies deleting strangers comments while allowing the post owner', function (): void {
    $author = communityPerson();
    $writer = communityPerson();
    $stranger = communityPerson();
    $post = communityPost($author, $this->city, $this->category);
    $comment = app(Community::class)->comment($writer, $post, 'Root', null);
    communityLogin($stranger);
    $this->deleteJson('/api/v1/community/comments/'.$comment->id)->assertForbidden();
    expect($comment->fresh())->not->toBeNull();
    communityLogin($author);
    $this->deleteJson('/api/v1/community/comments/'.$comment->id)->assertOk();
    expect(CommunityComment::count())->toBe(0);
});

it('44 hides replies when their parent is hidden or its author loses verification', function (): void {
    $author = communityPerson();
    $writer = communityPerson();
    $post = communityPost($author, $this->city, $this->category);
    $root = app(Community::class)->comment($writer, $post, 'Root', null);
    app(Community::class)->comment($author, $post, 'Reply', $root->id);
    $root->update(['status' => CommunityStatus::Hidden]);
    $this->getJson('/api/v1/community/posts/'.$post->id)->assertJsonCount(0, 'data.comments');
    $root->update(['status' => CommunityStatus::Published]);
    $writer->forceFill(['whatsapp_verified_at' => null])->save();
    $this->getJson('/api/v1/community/posts/'.$post->id)->assertJsonCount(0, 'data.comments');
});

it('45 moderation cannot be bypassed by making a post private then republishing', function (): void {
    $admin = User::factory()->create();
    Role::findOrCreate('admin', 'web');
    $admin->assignRole('admin');
    $author = communityPerson();
    $post = communityPost($author, $this->city, $this->category);
    app(CommunityModeration::class)->apply($admin, 'post', $post->id, false);
    communityLogin($author);
    $this->putJson('/api/v1/community/saved/'.$post->occurrence_id, ['visibility' => 'private'])->assertOk();
    $this->putJson('/api/v1/community/saved/'.$post->occurrence_id, ['visibility' => 'public'])->assertForbidden();
    expect(DB::table('community_restrictions')->count())->toBe(1);
});

it('46 suspension hides content and refuses profile edits comments and OTP retries', function (): void {
    $author = communityPerson();
    $post = communityPost($author, $this->city, $this->category);
    $author->forceFill(['community_suspended_at' => now()])->save();
    communityLogin($author);
    $this->getJson('/api/v1/community/posts/'.$post->id)->assertNotFound();
    $this->postJson('/api/v1/community/profile', ['handle' => 'suspended_person', 'display_name' => 'Name', 'visibility' => 'public'])->assertForbidden();
    $this->postJson('/api/v1/community/posts/'.$post->id.'/comments', ['body' => 'Bypass'])->assertForbidden();
    $this->postJson('/api/v1/community/whatsapp', ['phone' => '+393331234567'])->assertForbidden();
    Http::assertNothingSent();
});

it('47 ordinary accounts cannot perform moderation or access the backend community page', function (): void {
    $author = communityPerson();
    $post = communityPost($author, $this->city, $this->category);
    expect(fn () => app(CommunityModeration::class)->apply($this->user, 'post', $post->id, false))->toThrow(HttpException::class);
    $this->actingAs($this->user)->get('/admin/community')->assertForbidden();
    expect($post->fresh()->status)->toBe(CommunityStatus::Published);
});

it('48 export includes community data and deletion removes relations and encrypted numbers', function (): void {
    $author = communityPerson();
    $other = communityPerson();
    $post = communityPost($author, $this->city, $this->category);
    app(Community::class)->comment($author, $post, 'Own comment', null);
    $other->follow($author);
    $author->follow($other);
    communityChallenge($author);
    $export = app(AccountExport::class)($author);
    expect($export['community']['posts'])->toHaveCount(1)->and($export['community']['comments'])->toHaveCount(1);
    app(DeleteAccount::class)($author);
    expect(CommunityPost::count())->toBe(0)->and(CommunityComment::count())->toBe(0)->and(DB::table('followables')->count())->toBe(0)->and(WhatsappChallenge::count())->toBe(0);
    expect(User::withTrashed()->find($author->id)->whatsapp_phone)->toBeNull();
});

it('49 marks personalized responses no-store and indexes only an explicit public opt-in', function (): void {
    $author = communityPerson();
    $url = '/persone/'.$author->communityProfile->handle;
    $this->get($url)->assertOk()->assertHeader('Cache-Control', 'no-store, private')->assertSee('noindex, follow');
    $author->communityProfile->update(['indexable' => true]);
    $this->get($url)->assertOk()->assertSee('index, follow')->assertDontSee('noindex, follow');
    communityLogin($this->user);
    $this->getJson('/api/v1/community/feed')->assertOk()->assertHeader('Cache-Control', 'no-store, private');
});

it('50 email verification sends only the same logged-in user to WhatsApp onboarding', function (): void {
    $unverified = User::factory()->unverified()->create();
    $url = URL::temporarySignedRoute('verification.verify', now()->addHour(), ['id' => $unverified->id, 'hash' => sha1($unverified->email)]);
    $this->actingAs($this->user)->get($url)->assertRedirect(route('login'));
    expect($unverified->fresh()->hasVerifiedEmail())->toBeTrue()->and($unverified->fresh()->whatsapp_verified_at)->toBeNull();
    $this->actingAs($unverified)->get($url)->assertRedirect(route('community.whatsapp'));
});

it('51 never serves a protected avatar to a guest blocked viewer or after revocation', function (): void {
    Storage::fake('local');
    $author = communityPerson('members');
    $profile = $author->communityProfile;
    $profile->addMedia(UploadedFile::fake()->image('avatar.jpg'))->toMediaCollection('avatar');
    $media = $profile->getFirstMedia('avatar');
    expect($media->disk)->toBe('local');
    $url = '/api/v1/community/avatar/'.$profile->id;
    $this->getJson($url)->assertNotFound();
    communityLogin($this->user);
    $this->getJson($url)->assertOk()->assertHeader('Cache-Control', 'no-store, private');
    app(Community::class)->block($author, $this->user, true);
    $this->getJson($url)->assertNotFound();
    app(Community::class)->block($author, $this->user, false);
    app(WhatsappVerification::class)->revoke($author);
    $this->getJson($url)->assertNotFound();
});

it('52 rejects executable avatar formats and stores a reencoded private image', function (): void {
    Storage::fake('local');
    $author = communityPerson();
    communityLogin($author);
    $body = ['display_name' => 'Autore', 'handle' => $author->communityProfile->handle, 'visibility' => 'public'];
    $this->postJson('/api/v1/community/profile', [...$body, 'avatar' => UploadedFile::fake()->create('avatar.svg', 1, 'image/svg+xml')])->assertUnprocessable();
    $this->postJson('/api/v1/community/profile', [...$body, 'avatar' => UploadedFile::fake()->image('avatar.png')])->assertOk();
    $media = $author->communityProfile->fresh()->getFirstMedia('avatar');
    expect($media->mime_type)->toBe('image/jpeg')->and($media->disk)->toBe('local');
    $this->getJson('/api/v1/community/people/'.$author->communityProfile->handle)->assertJsonPath('data.profile.avatar_url', route('community.api.avatar', ['profile' => $author->communityProfile->id, 'v' => $media->id]));
});

it('53 prunes expired verification secrets after thirty days without deleting current challenges', function (): void {
    $old = communityChallenge($this->user, ['expires_at' => now()->subDays(31)]);
    $fresh = communityChallenge($this->user);
    $this->artisan('model:prune', ['--model' => WhatsappChallenge::class])->assertSuccessful();
    expect($old->fresh())->toBeNull()->and($fresh->fresh())->not->toBeNull();
});

it('54 administrators can inspect verification history suspend and revoke without seeing OTP secrets', function (): void {
    (new RolesAndPermissionsSeeder)->run();
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $admin = User::factory()->create();
    $admin->assignRole('admin');
    $author = communityPerson();
    $challenge = communityChallenge($author);
    $this->actingAs($admin);
    $this->get('/admin/community')->assertOk();
    $this->get('/admin/users/'.$author->id.'/edit')->assertOk()->assertDontSee($author->whatsapp_phone);
    Livewire::test(ListUsers::class)
        ->mountTableAction('whatsapp_history', $author)->assertSet('mountedActions.0.name', 'whatsapp_history');
    expect(view('filament.admin.pages.whatsapp-history', ['challenges' => collect([$challenge])])->render())->toContain('4567')->not->toContain($challenge->code_hash)->not->toContain($challenge->phone);
    Livewire::test(ListUsers::class)->callTableAction('community_suspend', $author);
    expect($author->fresh()->community_suspended_at)->not->toBeNull();
    Livewire::test(ListUsers::class)->callTableAction('whatsapp_revoke', $author);
    expect($author->fresh()->whatsapp_verified_at)->toBeNull()->and($author->fresh()->whatsapp_phone)->toBeNull();
});

it('55 moderation restrictions can only be restored by staff even after the author withdraws a post', function (): void {
    Role::findOrCreate('admin', 'web');
    $admin = User::factory()->create();
    $admin->assignRole('admin');
    $author = communityPerson();
    $post = communityPost($author, $this->city, $this->category);
    $service = app(CommunityModeration::class);
    $service->apply($admin, 'post', $post->id, false);
    app(Community::class)->publication($author, $post->occurrence_id, ['visibility' => 'private']);
    $id = DB::table('community_restrictions')->value('id');
    expect(fn () => $service->restoreRestriction($author, $id))->toThrow(HttpException::class);
    $service->restoreRestriction($admin, $id);
    expect(DB::table('community_restrictions')->count())->toBe(0);
    expect(app(Community::class)->publication($author, $post->occurrence_id, ['visibility' => 'public']))->toBeInstanceOf(CommunityPost::class);
});

it('56 community privacy migration preserves editorial content and does not duplicate the notice', function (): void {
    $page = Page::factory()->create(['slug' => 'privacy', 'body' => "Testo scritto dalla redazione.\n\nNon chiediamo data di nascita, sesso, numero di telefono, indirizzo di casa.\n\nNon c'è un trasferimento di dati fuori dall'Unione europea nella configurazione attuale del servizio."]);
    $migration = require database_path('migrations/2026_09_18_110000_update_community_privacy_notice.php');
    $migration->up();
    $migration->up();
    $body = $page->fresh()->body;
    expect($body)->toContain('Testo scritto dalla redazione.')->toContain('Kapso e Meta/WhatsApp')->toContain('trenta giorni')
        ->not->toContain('sesso, numero di telefono')->not->toContain("Non c'è un trasferimento");
    expect(substr_count($body, '## Community e verifica WhatsApp'))->toBe(1);
    $migration->down();
    expect($page->fresh()->body)->toBe($body);
});

it('57 new installations disclose optional WhatsApp processing and private defaults', function (): void {
    (new PageSeeder)->run();
    $body = Page::where('slug', 'privacy')->value('body');
    expect($body)->toContain('gratuita e facoltativa')->toContain('Kapso e Meta/WhatsApp')
        ->toContain('I salvataggi nascono privati.')->toContain('trenta giorni')
        ->not->toContain('sesso, numero di telefono')->not->toContain("Non c'è un trasferimento");
});

it('58 Android one tap uses only the configured template and preserves the hashed OTP challenge', function (): void {
    config(['community.android_template' => 'approved_android_template']);
    communityLogin($this->user);
    $this->getJson('/api/v1/community/whatsapp')->assertOk()->assertJsonPath('data.autofill_available', true);
    $response = $this->postJson('/api/v1/community/whatsapp', ['phone' => '+393331234567', 'delivery' => 'one_tap', 'template' => 'attacker_template'])->assertOk();
    $challenge = WhatsappChallenge::findOrFail($response->json('data.challenge_id'));
    Http::assertSent(fn ($request) => $request['template']['name'] === 'approved_android_template'
        && Hash::check($request['template']['components'][0]['parameters'][0]['text'], $challenge->code_hash));
});

it('59 one tap falls back to copy code when the Android template is not configured', function (): void {
    config(['community.android_template' => '']);
    communityLogin($this->user);
    $this->getJson('/api/v1/community/whatsapp')->assertOk()->assertJsonPath('data.autofill_available', false);
    $this->postJson('/api/v1/community/whatsapp', ['phone' => '+393331234567', 'delivery' => 'one_tap'])->assertOk();
    Http::assertSent(fn ($request) => $request['template']['name'] === config('community.template'));
});

it('60 enabling autofill does not change web or older client delivery', function (): void {
    config(['community.android_template' => 'approved_android_template']);
    communityLogin($this->user);
    $this->postJson('/api/v1/community/whatsapp', ['phone' => '+393331234567'])->assertOk();
    Http::assertSent(fn ($request) => $request['template']['name'] === config('community.template'));
});

it('61 unknown autofill delivery and unconfirmed accounts cannot trigger provider calls', function (): void {
    communityLogin($this->user);
    $this->postJson('/api/v1/community/whatsapp', ['phone' => '+393331234567', 'delivery' => 'custom_template'])->assertUnprocessable();
    $this->user->forceFill(['email_verified_at' => null])->save();
    communityLogin($this->user->fresh());
    $this->postJson('/api/v1/community/whatsapp', ['phone' => '+393331234567', 'delivery' => 'one_tap'])->assertForbidden();
    Http::assertNothingSent();
});

function kapsoReplies(mixed $response): void
{
    Http::swap(new Factory);
    Http::preventStrayRequests();
    Http::fake(['api.kapso.ai/*' => $response]);
}

function kapsoIpKey(string $ip = '127.0.0.1'): string
{
    return 'wa-ip:'.hash('sha256', $ip);
}

it('62 a rejected delivery keeps the previous code and gives back the send budget', function (): void {
    communityLogin($this->user);
    $previous = communityChallenge($this->user, ['created_at' => now()->subMinutes(2)]);
    kapsoReplies(Http::response(['error' => ['message' => 'provider detail']], 500));

    $this->postJson('/api/v1/community/whatsapp', ['phone' => '+393331234567'])->assertUnprocessable()
        ->assertJsonPath('error.fields.phone.0', __('community.whatsapp.send_failed'));

    $failed = WhatsappChallenge::query()->whereKeyNot($previous->id)->firstOrFail();
    expect($failed->status)->toBe(WhatsappChallengeStatus::Failed)->and($failed->consumed_at)->not->toBeNull()
        ->and($previous->fresh()->consumed_at)->toBeNull()
        ->and((int) RateLimiter::attempts('wa-global'))->toBe(0)->and((int) RateLimiter::attempts(kapsoIpKey()))->toBe(0);
    $this->postJson('/api/v1/community/whatsapp/confirm', ['challenge_id' => $previous->id, 'code' => '123456'])->assertOk();
    expect($this->user->fresh()->isWhatsappVerified())->toBeTrue();
});

it('63 rejected deliveries count for the cooldown but not for the hourly cap', function (): void {
    communityChallenge($this->user, ['created_at' => now()->subMinutes(20), 'consumed_at' => now()->subMinutes(10)]);
    communityChallenge($this->user, ['created_at' => now()->subMinutes(10)]);
    $verification = app(WhatsappVerification::class);
    kapsoReplies(Http::response([], 500));
    expect(fn () => $verification->request($this->user, '+393331234567', '198.51.100.1'))->toThrow(ValidationException::class, __('community.whatsapp.send_failed'));

    kapsoReplies(Http::response(['messages' => [['id' => 'wamid.test']]]));
    expect(fn () => $verification->request($this->user, '+393331234567', '198.51.100.1'))->toThrow(ValidationException::class, __('community.whatsapp.rate_limit'));
    Http::assertNothingSent();

    $this->travel(61)->seconds();
    $result = $verification->request($this->user, '+393331234567', '198.51.100.1');
    expect($result->outcome)->toBe(KapsoOutcome::Sent)->and($result->challenge->fresh()->status)->toBe(WhatsappChallengeStatus::Sent);
});

it('64 an uncertain delivery is confirmable, replaces the previous code and stays counted', function (): void {
    communityLogin($this->user);
    $previous = communityChallenge($this->user, ['created_at' => now()->subMinutes(2)]);
    kapsoReplies(Http::failedConnection('cURL error 28: Operation timed out'));

    $response = $this->postJson('/api/v1/community/whatsapp', ['phone' => '+393331234567'])->assertOk()
        ->assertJsonPath('data.delivery', 'uncertain');
    $challenge = WhatsappChallenge::findOrFail($response->json('data.challenge_id'));
    $code = Http::recorded()->first()[0]['template']['components'][0]['parameters'][0]['text'];

    expect($challenge->status)->toBe(WhatsappChallengeStatus::Sent)->and($previous->fresh()->consumed_at)->not->toBeNull()
        ->and((int) RateLimiter::attempts('wa-global'))->toBe(1)->and((int) RateLimiter::attempts(kapsoIpKey()))->toBe(1);
    $this->postJson('/api/v1/community/whatsapp/confirm', ['challenge_id' => $challenge->id, 'code' => $code])->assertOk();
    expect($this->user->fresh()->isWhatsappVerified())->toBeTrue();
});

it('65 a connection that never left is a rejection, not an uncertain delivery', function (): void {
    communityLogin($this->user);
    kapsoReplies(fn ($request) => Create::rejectionFor(new ConnectException('cURL error 7: Failed to connect', $request->toPsrRequest(), null, ['errno' => 7])));

    $this->postJson('/api/v1/community/whatsapp', ['phone' => '+393331234567'])->assertUnprocessable();
    expect(WhatsappChallenge::firstOrFail()->status)->toBe(WhatsappChallengeStatus::Failed)
        ->and((int) RateLimiter::attempts('wa-global'))->toBe(0);
});

it('66 a delivered code consumes the previous one and reports delivery sent', function (): void {
    communityLogin($this->user);
    $previous = communityChallenge($this->user, ['created_at' => now()->subMinutes(2)]);
    $this->postJson('/api/v1/community/whatsapp', ['phone' => '+393331234567'])->assertOk()->assertJsonPath('data.delivery', 'sent');
    expect($previous->fresh()->consumed_at)->not->toBeNull();
});

it('67 the website tells the user when delivery is uncertain and keeps the confirmation open', function (): void {
    kapsoReplies(Http::failedConnection('cURL error 28: Operation timed out'));
    $this->actingAs($this->user)->from(route('community.whatsapp'))->post(route('community.whatsapp.send'), ['phone' => '+393331234567'])
        ->assertRedirect(route('community.whatsapp'))
        ->assertSessionHas('status', __('community.whatsapp.send_uncertain'))
        ->assertSessionHas('whatsapp_challenge', WhatsappChallenge::query()->value('id'));
});

it('68 revoking does not reset send limits nor leave a pending code usable', function (): void {
    communityLogin($this->user);
    $response = $this->postJson('/api/v1/community/whatsapp', ['phone' => '+393331234567'])->assertOk();
    $this->deleteJson('/api/v1/community/whatsapp')->assertOk();

    expect(WhatsappChallenge::findOrFail($response->json('data.challenge_id'))->consumed_at)->not->toBeNull();
    $this->postJson('/api/v1/community/whatsapp', ['phone' => '+393331234567'])->assertUnprocessable()
        ->assertJsonPath('error.fields.phone.0', __('community.whatsapp.rate_limit'));
    Http::assertSentCount(1);

    $pending = communityChallenge($this->user, ['created_at' => now()->subMinutes(2)]);
    $this->deleteJson('/api/v1/community/whatsapp')->assertOk();
    $this->postJson('/api/v1/community/whatsapp/confirm', ['challenge_id' => $pending->id, 'code' => '123456'])->assertUnprocessable();
    expect($this->user->fresh()->isWhatsappVerified())->toBeFalse();
});

it('69 an administrator revocation keeps the challenge history', function (): void {
    $author = communityPerson();
    $challenge = communityChallenge($author, ['phone' => $author->whatsapp_phone, 'phone_hash' => $author->whatsapp_phone_hash]);
    app(WhatsappVerification::class)->revoke($author, User::factory()->create());

    expect($challenge->fresh())->not->toBeNull()->and($challenge->fresh()->consumed_at)->not->toBeNull()
        ->and($author->fresh()->whatsapp_verified_at)->toBeNull();
});

it('70 probing numbers owned by others is limited per IP before the uniqueness check', function (): void {
    $owner = communityPerson();
    $verification = app(WhatsappVerification::class);
    foreach (range(1, 10) as $attempt) {
        expect(fn () => $verification->request($this->user, $owner->whatsapp_phone, '203.0.113.9'))
            ->toThrow(ValidationException::class, __('community.whatsapp.phone_unavailable'));
    }
    expect(fn () => $verification->request($this->user, $owner->whatsapp_phone, '203.0.113.9'))
        ->toThrow(ValidationException::class, __('community.whatsapp.rate_limit'));
    expect((int) RateLimiter::attempts('wa-global'))->toBe(0);
    Http::assertNothingSent();
});

it('71 refuses to fingerprint numbers or enable verification without a dedicated key', function (): void {
    config(['community.phone_hash_key' => '', 'app.key' => 'base64:'.base64_encode(random_bytes(32))]);

    expect(fn () => app(WhatsappVerification::class)->fingerprint('+393331234567'))->toThrow(RuntimeException::class)
        ->and(app(KapsoClient::class)->available())->toBeFalse();
});

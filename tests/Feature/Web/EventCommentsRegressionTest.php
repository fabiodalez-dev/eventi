<?php

use App\Actions\Comments\ModerateComment;
use App\Actions\Comments\PostComment;
use App\Actions\Comments\ToggleReaction;
use App\Enums\EventCommentReactionType;
use App\Enums\EventCommentStatus;
use App\Enums\NotificationChannel;
use App\Enums\NotificationStatus;
use App\Filament\Venue\Resources\EventComments\Pages\ListEventComments;
use App\Models\Event;
use App\Models\EventComment;
use App\Models\Organizer;
use App\Models\ScheduledNotification;
use App\Models\User;
use App\Notifications\Scheduled\ScheduledMessage;
use App\Services\Notifications\NotificationDispatcher;
use App\Support\Notifications\PreferenceLinks;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;
use NotificationChannels\WebPush\WebPushChannel;
use Tests\Support\VenueIsolationScenario;

beforeEach(function () {
    (new RolesAndPermissionsSeeder)->run();
    Notification::fake();
    $this->city = testCity();
    freezeLocal($this->city, '2026-09-17 12:00');
    $this->event = Event::factory()->for($this->city)->published()->create();
    $this->author = User::factory()->create();
    $this->other = User::factory()->create();
    $this->comment = app(PostComment::class)->handle($this->event, $this->author, 'Commento di review');
});
afterEach(function () {
    Filament::setTenant(null);
    Filament::setCurrentPanel(null);
});

it('opens venue comments over HTTP and isolates records and actions', function () {
    $s = VenueIsolationScenario::make();
    $own = app(PostComment::class)->handle($s->publishedEventA, $this->author, 'Commento locale A');
    $foreign = app(PostComment::class)->handle($s->publishedEventB, $this->author, 'Commento locale B');
    $this->actingAs($s->ownerA)->get('/gestione/'.$s->venueA->slug.'/event-comments')->assertOk()->assertSee('Commento locale A')->assertDontSee('Commento locale B');
    Livewire::test(ListEventComments::class)
        ->assertCanSeeTableRecords([$own])->assertCanNotSeeTableRecords([$foreign])
        ->callTableAction('nascondi', $own, ['revision' => 1, 'note' => 'Fuori tema'])->assertHasNoTableActionErrors();
    expect($own->fresh()->status)->toBe(EventCommentStatus::Hidden);
    expect($s->ownerA->can('hide', $foreign))->toBeFalse();
});
it('opens organizer comments over HTTP and isolates records and actions', function () {
    $org = Organizer::create(['city_id' => $this->city->id, 'owner_id' => $this->other->id, 'name' => 'Organizzatore Review', 'is_active' => true]);
    $this->event->update(['organizer_id' => $org->id]);
    $foreignEvent = Event::factory()->for($this->city)->published()->create();
    $foreign = app(PostComment::class)->handle($foreignEvent, $this->author, 'Conversazione estranea');
    $this->actingAs($this->other)->get('/organizza/'.$org->slug.'/event-comments')->assertOk()->assertSee('Commento di review')->assertDontSee('Conversazione estranea');
    Livewire::test(App\Filament\Organizer\Resources\EventComments\Pages\ListEventComments::class)
        ->assertCanSeeTableRecords([$this->comment])->assertCanNotSeeTableRecords([$foreign])
        ->callTableAction('nascondi', $this->comment, ['revision' => 1, 'note' => 'Fuori tema'])->assertHasNoTableActionErrors();
    expect($this->comment->fresh()->status)->toBe(EventCommentStatus::Hidden);
    expect($this->other->can('hide', $foreign))->toBeFalse();
});
it('deduplicates reactions even after withdrawal and readdition', function () {
    foreach (range(1, 3) as $i) {
        app(ToggleReaction::class)->handle($this->comment, $this->other, EventCommentReactionType::Like);
    }
    Notification::assertSentToTimes($this->author, ScheduledMessage::class, 1);
    expect(ScheduledNotification::query()->where('type', 'comment_reaction')->count())->toBe(1);
});
it('keeps counts correct after soft or hard account deletion', function (bool $hard) {
    app(ToggleReaction::class)->handle($this->comment, $this->other, EventCommentReactionType::Like);
    $reply = app(PostComment::class)->handle($this->event, $this->other, 'Risposta da rimuovere', $this->comment);
    $hard ? $this->other->forceDelete() : $this->other->delete();
    expect($this->comment->fresh()->reactions_count)->toBe(0)->and(EventComment::query()->find($reply->id))->toBeNull();
})->with([true, false]);
it('reaches old comments and paginates replies with stable permalinks', function () {
    foreach (range(1, 11) as $i) {
        app(PostComment::class)->handle($this->event, $this->other, 'Nuovo commento '.$i);
    }
    foreach (range(1, 22) as $i) {
        $reply = app(PostComment::class)->handle($this->event, $this->other, 'Risposta numero '.$i, $this->comment);
    }
    $this->get($this->comment->permalink())->assertOk()->assertSee('id="commento-'.$this->comment->id.'"', false)->assertDontSee('Risposta numero 22');
    $this->get($reply->permalink())->assertOk()->assertSee('Risposta numero 22')->assertDontSee('Risposta numero 1<', false);
    $this->get(route('events.show', ['slug' => $this->event->slug, 'commenti' => 2]))->assertOk()->assertSee(__('comments.thread'))->assertDontSee('Risposta numero 4');
});
it('does not deliver to unverified recipients', function () {
    $this->author->forceFill(['email_verified_at' => null])->save();
    app(ToggleReaction::class)->handle($this->comment, $this->other, EventCommentReactionType::Like);
    Notification::assertNothingSent();
    expect(ScheduledNotification::query()->where('type', 'comment_reaction')->sole()->last_error)->toBe('unverified');
});
it('defers quiet hours and sends later through the shared dispatcher', function () {
    freezeLocal($this->city, '2026-09-17 23:00');
    app(ToggleReaction::class)->handle($this->comment, $this->other, EventCommentReactionType::Like);
    Notification::assertNothingSent();
    expect(ScheduledNotification::query()->where('type', 'comment_reaction')->sole()->status)->toBe(NotificationStatus::Pending);
    freezeLocal($this->city, '2026-09-18 12:00');
    app(NotificationDispatcher::class)->run();
    Notification::assertSentToTimes($this->author, ScheduledMessage::class, 1);
});
it('enforces the recipient daily cap', function () {
    config(['notifications.daily_cap' => 2]);
    foreach (range(1, 3) as $i) {
        app(ToggleReaction::class)->handle($this->comment, User::factory()->create(), EventCommentReactionType::Like);
    }
    Notification::assertSentToTimes($this->author, ScheduledMessage::class, 2);
    expect(ScheduledNotification::query()->where('last_error', 'frequency_cap')->count())->toBe(1);
});
it('notifies the actual reply author even when flattening the thread', function () {
    $reply = app(PostComment::class)->handle($this->event, $this->other, 'La risposta', $this->comment);
    Notification::fake();
    app(PostComment::class)->handle($this->event, $this->author, 'Rispondo a te', $reply);
    Notification::assertSentTo($this->other, ScheduledMessage::class);
});
it('shows a private moderation placeholder and rejects hidden reactions', function () {
    $admin = User::factory()->create();
    $admin->assignRole('admin');
    app(ModerateComment::class)->nascondi($this->comment, $admin, 1, 'Nota');
    $this->get($this->comment->permalink())->assertNotFound();
    $this->actingAs($this->author)->get($this->comment->permalink())->assertOk()->assertSee(__('comments.hidden_notice'))->assertDontSee('Commento di review');
    $this->actingAs($this->other)->postJson(route('events.comments.react', ['slug' => $this->event->slug, 'comment' => $this->comment->id]), ['type' => 'like'])->assertNotFound();
});
it('notifies organizer owners without a collaborator pivot', function () {
    $org = Organizer::create(['city_id' => $this->city->id, 'owner_id' => $this->other->id, 'name' => 'Owner Review', 'is_active' => true]);
    $this->event->update(['organizer_id' => $org->id]);
    app(PostComment::class)->handle($this->event->fresh(), $this->author, 'Nuovo commento');
    Notification::assertSentTo($this->other, ScheduledMessage::class);
});
it('allows disabling comment notifications through the API', function () {
    Sanctum::actingAs($this->author);
    $this->patchJson('/api/v1/me/notification-preferences', ['comments' => false])->assertOk();
    expect($this->author->fresh()->notificationPreferences()->comments)->toBeFalse();
    app(ToggleReaction::class)->handle($this->comment, $this->other, EventCommentReactionType::Like);
    Notification::assertNothingSent();
});
it('uses configured push channels and supplies both payloads and mail preferences', function () {
    app(ToggleReaction::class)->handle($this->comment, $this->other, EventCommentReactionType::Like);
    $notification = Notification::sent($this->author, ScheduledMessage::class)->sole();
    expect($notification->toWebPush($this->author)->toArray()['data']['url'])->toBe($this->comment->permalink());
    expect($notification->toFcm($this->author)->toArray()['data']['url'])->toBe($this->comment->permalink());
    expect($notification->databaseType($this->author))->toBe('comment_reaction');
    $mail = $notification->toMail($this->author);
    expect($mail->viewData['unsubscribeUrl'])->not->toBeNull();
    config(['webpush.vapid.public_key' => 'key', 'webpush.vapid.private_key' => 'key', 'api.features.push' => false]);
    $push = new ScheduledMessage($notification->message, NotificationChannel::Push);
    expect($push->via($this->author))->toBe([WebPushChannel::class, 'database']);
    $this->comment->forceFill(['status' => EventCommentStatus::Hidden])->save();
    expect($push->shouldSend($this->author, 'database'))->toBeFalse();
});
it('renders escaped content and usable no-JavaScript forms under CSP', function () {
    $this->comment->forceFill(['body' => '<script>alert("x")</script>'])->save();
    $response = $this->actingAs($this->author)->get($this->comment->permalink());
    $response->assertOk()->assertSee('&lt;script&gt;', false)->assertDontSee('onsubmit=', false);
    expect($response->headers->get('Content-Security-Policy'))->toContain("'strict-dynamic'");
    preg_match('/<form[^>]*data-risposta="'.$this->comment->id.'"[^>]*>/s', $response->getContent(), $match);
    expect($match[0])->not->toContain(' hidden');
});

it('rejects malformed reaction payloads without a server error', function () {
    $this->actingAs($this->other)->postJson(route('events.comments.react', ['slug' => $this->event->slug, 'comment' => $this->comment->id]), ['type' => ['love']])->assertUnprocessable();
    expect($this->comment->reactions()->count())->toBe(0);
});

it('supports the web preference form and keeps reply errors in their thread', function () {
    $url = PreferenceLinks::preferences($this->author);
    $this->get($url)->assertOk()->assertSee(__('comments.preference'));
    $this->patch($url, ['comments' => '0'])->assertRedirect();
    expect($this->author->fresh()->notificationPreferences()->comments)->toBeFalse();
    $this->actingAs($this->other)->post(route('events.comments.store', ['slug' => $this->event->slug]), ['parent_id' => $this->comment->id, 'body' => 'ab'])
        ->assertSessionHasErrors('body')->assertRedirect(route('events.show', ['slug' => $this->event->slug, 'commento' => $this->comment->id]).'#commenti');
});

it('restores moderation and rejects direct cross-tenant actions', function () {
    $admin = User::factory()->create();
    $admin->assignRole('admin');
    $moderate = app(ModerateComment::class);
    expect(fn () => $moderate->nascondi($this->comment, $this->other, 1))->toThrow(AuthorizationException::class);
    $moderate->nascondi($this->comment, $admin, 1);
    expect(fn () => $moderate->ripristina($this->comment, $admin, 1))->toThrow(ValidationException::class);
    $moderate->ripristina($this->comment->fresh(), $admin, 2);
    expect($this->comment->fresh()->status)->toBe(EventCommentStatus::Published);
    $this->get($this->comment->permalink())->assertOk()->assertSee('Commento di review');
});

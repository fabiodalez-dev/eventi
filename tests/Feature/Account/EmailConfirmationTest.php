<?php

declare(strict_types=1);

use App\Actions\Comments\PostComment;
use App\Models\Event;
use App\Models\EventComment;
use App\Models\User;
use App\Notifications\VerifyEmailLink;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Auth\Events\Registered;
use Illuminate\Support\Facades\Event as Events;
use Illuminate\Support\Facades\Notification;

beforeEach(function (): void {
    (new RolesAndPermissionsSeeder)->run();
    Notification::fake();
    $city = testCity();
    $this->event = Event::factory()->for($city)->published()->create();
});

it('sends one confirmation email through the Laravel registration event', function (): void {
    Events::fakeExcept([Registered::class]);
    $this->post('/registrati', [
        'email' => 'confirmation@example.test', 'password' => 'una-password-molto-lunga',
        'password_confirmation' => 'una-password-molto-lunga',
    ])->assertRedirect(route('verification.notice'));
    $user = User::query()->where('email', 'confirmation@example.test')->firstOrFail();
    expect($user->hasVerifiedEmail())->toBeFalse();
    Notification::assertSentToTimes($user, VerifyEmailLink::class, 1);
    $this->get(route('verification.notice'))->assertOk()->assertSee($user->email);
});

it('blocks unverified comment and reaction submissions and enables them after confirmation', function (): void {
    $user = User::factory()->unverified()->create();
    $author = User::factory()->create();
    $comment = app(PostComment::class)->handle($this->event, $author, 'Conversazione pubblica');
    $this->actingAs($user);
    $url = route('events.comments.store', ['slug' => $this->event->slug]);
    $reaction = route('events.comments.react', ['slug' => $this->event->slug, 'comment' => $comment]);
    $this->post($url, ['body' => 'Non ancora verificato'])->assertRedirect(route('verification.notice'));
    $this->postJson($reaction, ['type' => 'like'])->assertForbidden();
    expect(EventComment::query()->count())->toBe(1)->and($comment->reactions()->count())->toBe(0);
    $this->get($comment->permalink())->assertOk()->assertSee(__('comments.verify_required'))->assertDontSee('data-risposta=', false);
    $destination = route('events.show', ['slug' => $this->event->slug]).'#commenti';
    $this->get(route('verification.notice', ['intended' => $destination]))->assertOk();
    $this->get(VerifyEmailLink::url($user))->assertRedirect(route('community.whatsapp'))->assertSessionHas('url.intended', $destination);
    $this->post(route('community.whatsapp.skip'))->assertRedirect($destination);
    $this->actingAs($user->fresh());
    $this->post($url, ['body' => 'Indirizzo confermato'])->assertSessionHasNoErrors()->assertRedirect();
    $this->postJson($reaction, ['type' => 'like'])->assertOk();
});

it('preserves the event destination through notice and resend', function (): void {
    $user = User::factory()->unverified()->create();
    $destination = route('events.show', ['slug' => $this->event->slug]).'#commenti';
    $this->actingAs($user)->get(route('verification.notice', ['intended' => $destination]))->assertOk();
    $this->post(route('account.verification.send'))->assertRedirect()->assertSessionHas('url.intended', $destination);
    Notification::assertSentToTimes($user, VerifyEmailLink::class, 1);
    $this->get(VerifyEmailLink::url($user))->assertRedirect(route('community.whatsapp'))->assertSessionHas('url.intended', $destination);
    $this->post(route('community.whatsapp.skip'))->assertRedirect($destination);
    $this->actingAs($user->fresh());
    $this->post(route('account.verification.send'))->assertRedirect();
    Notification::assertSentToTimes($user, VerifyEmailLink::class, 1);
});

it('rejects expired verification links', function (): void {
    $user = User::factory()->unverified()->create();
    $url = VerifyEmailLink::url($user);
    $this->travel(config()->integer('account.verification_link_minutes') + 1)->minutes();
    $this->get($url)->assertForbidden();
    expect($user->fresh()->hasVerifiedEmail())->toBeFalse();
});

it('does not consume another signed-in users destination when confirming an email', function (): void {
    $current = User::factory()->unverified()->create();
    $other = User::factory()->unverified()->create();
    $destination = route('events.show', ['slug' => $this->event->slug]).'#commenti';
    $this->actingAs($current)->withSession(['url.intended' => $destination])
        ->get(VerifyEmailLink::url($other))->assertRedirect(route('account.profile'))->assertSessionMissing('community_onboarding_user')->assertSessionHas('url.intended', $destination);
    expect($other->fresh()->hasVerifiedEmail())->toBeTrue()->and($current->fresh()->hasVerifiedEmail())->toBeFalse();
});

it('renders a branded light confirmation email with HTML and plain text alternatives', function (): void {
    $user = User::factory()->unverified()->create();
    $mail = (new VerifyEmailLink)->toMail($user);
    $html = view($mail->view[0], $mail->viewData)->render();
    $plain = view($mail->view[1], $mail->viewData)->render();
    expect($html)->toContain('name="color-scheme" content="light"', '#b54d23', e($mail->actionUrl), e(__('account.mail.verify.action')))
        ->and($plain)->toContain($mail->actionUrl)
        ->and($html)->not->toContain('<script', 'fonts.googleapis.com');
});

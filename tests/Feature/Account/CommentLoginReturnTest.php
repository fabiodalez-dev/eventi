<?php

declare(strict_types=1);

use App\Models\Event;
use App\Models\User;
use App\Notifications\MagicLoginLink;
use App\Notifications\VerifyEmailLink;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Notification;

beforeEach(function (): void {
    (new RolesAndPermissionsSeeder)->run();
    Notification::fake();
    $city = testCity();
    $this->event = Event::factory()->for($city)->published()->create();
    $this->destination = route('events.show', ['slug' => $this->event->slug, 'commenti' => 2]).'#commenti';
});

it('includes the comment destination in guest access and registration links', function (): void {
    $this->get(route('events.show', ['slug' => $this->event->slug, 'commenti' => 2]))
        ->assertOk()
        ->assertSee(route('login', ['intended' => $this->destination]))
        ->assertSee(route('account.register', ['intended' => $this->destination]));
});

it('returns to the comments after password login including after a failed attempt', function (): void {
    $user = User::factory()->create();
    $this->get(route('login', ['intended' => $this->destination]))->assertOk();
    $this->post('/accedi', ['email' => $user->email, 'password' => 'wrong'])
        ->assertSessionHasErrors('email');
    $this->get(route('login'))->assertOk()->assertSessionHas('url.intended', $this->destination);
    $this->post('/accedi', ['email' => $user->email, 'password' => 'password'])
        ->assertRedirect($this->destination)->assertSessionMissing('url.intended');
});

it('returns to the comments after registration', function (): void {
    $this->get(route('account.register', ['intended' => $this->destination]))->assertOk();
    $this->post('/registrati', [
        'email' => 'commenter@example.test',
        'password' => 'una-password-molto-lunga',
        'password_confirmation' => 'una-password-molto-lunga',
    ])->assertRedirect(route('verification.notice'))->assertSessionHas('url.intended', $this->destination);
    $user = User::query()->where('email', 'commenter@example.test')->firstOrFail();
    $this->get(VerifyEmailLink::url($user))->assertRedirect($this->destination);
});

it('preserves the comment destination when switching to magic link login in the same browser', function (): void {
    $user = User::factory()->create();
    $this->get(route('login', ['intended' => $this->destination]))->assertOk();
    $this->get(route('account.magic-link', ['intended' => $this->destination]))->assertOk();
    $this->post(route('account.magic-link.store'), ['email' => $user->email])->assertRedirect();
    $this->get(MagicLoginLink::url($user))->assertRedirect($this->destination);
});

it('ignores untrusted return URLs', function (string $destination): void {
    $user = User::factory()->create();
    $this->get(route('login', ['intended' => $destination]))
        ->assertOk()->assertSessionMissing('url.intended');
    $this->post('/accedi', ['email' => $user->email, 'password' => 'password'])
        ->assertRedirect(route('account.feed'));
})->with([
    'external' => 'https://evil.example/eventi/example',
    'protocol relative' => '//evil.example',
    'credentials' => 'http://localhost@evil.example/path',
    'prefix confusion' => 'http://localhost.evil.example/path',
    'backslash' => 'http://localhost/\\evil.example/path',
    'control characters' => "http://localhost/\r\nLocation: https://evil.example",
]);

it('sends a fresh normal login to the feed after abandoning a comments login', function (): void {
    $user = User::factory()->create();
    $this->get(route('login', ['intended' => $this->destination]))->assertOk();
    $this->get(route('login'))->assertOk()->assertSessionMissing('url.intended');
    $this->post('/accedi', ['email' => $user->email, 'password' => 'password'])
        ->assertRedirect(route('account.feed'));
});

it('preserves an explicit return when switching back from registration to password login', function (): void {
    $user = User::factory()->create();
    $this->get(route('account.register', ['intended' => $this->destination]))
        ->assertSee(route('login', ['intended' => $this->destination]));
    $this->get(route('login', ['intended' => $this->destination]))->assertOk();
    $this->post('/accedi', ['email' => $user->email, 'password' => 'password'])
        ->assertRedirect($this->destination);
});

it('sends a fresh magic login to the feed after abandoning a comments login', function (): void {
    $user = User::factory()->create();
    $this->get(route('login', ['intended' => $this->destination]))->assertOk();
    $this->get(route('account.magic-link'))->assertOk()->assertSessionMissing('url.intended');
    $this->post(route('account.magic-link.store'), ['email' => $user->email])->assertRedirect();
    $this->get(MagicLoginLink::url($user))->assertRedirect(route('account.feed'));
});

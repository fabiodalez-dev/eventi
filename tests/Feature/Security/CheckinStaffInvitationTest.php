<?php

declare(strict_types=1);

use App\Actions\Account\AssignCheckinStaff;
use App\Models\CheckinStaffInvitation;
use App\Models\User;
use App\Notifications\CheckinStaffInvited;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Notification;

require_once __DIR__.'/../Flows/Ticketing/TicketingFlowSupport.php';

/**
 * L'invito al personale di ingresso (D67): aggiungere qualcuno manda una
 * mail all'indirizzo scritto, registrato o no, e solo chi accetta con
 * quell'account entra nel personale.
 */
beforeEach(function (): void {
    Notification::fake();
    ['date' => $this->date, 'owner' => $this->owner] = tktFlowScenario();
});

function inviteToDoor(object $test, string $email): void
{
    $test->actingAs($test->owner)->post(route('ticketing.manage.staff', $test->date), ['email' => $email])->assertSessionHasNoErrors();
}

it('tratta allo stesso modo un indirizzo registrato e uno sconosciuto', function (): void {
    User::factory()->create(['email' => 'porta@example.test']);

    inviteToDoor($this, 'Porta@Example.test');
    inviteToDoor($this, 'nessuno@example.test');

    foreach (['porta@example.test', 'nessuno@example.test'] as $email) {
        Notification::assertSentTo(new AnonymousNotifiable, CheckinStaffInvited::class, fn ($n, $channels, $notifiable): bool => $notifiable->routes['mail'] === $email);
    }
    expect($this->date->checkinStaff()->count())->toBe(0);
    $mail = (new CheckinStaffInvited($this->date, 'https://example.test/invito'))->toMail(new AnonymousNotifiable);
    expect($mail->subject)->toContain('Concerto al chiostro')->and($mail->actionUrl)->toBe('https://example.test/invito')
        ->and(implode(' ', $mail->introLines))->toContain('17 ottobre 2026, 21:00');

    $page = (string) $this->get(route('ticketing.manage.show', $this->date))->getContent();
    expect(substr_count($page, __('decision.staff_pending')))->toBe(2);
});

it('chiede di accettare con un POST: aprire il collegamento non aggancia nessuno', function (): void {
    $doorman = User::factory()->create(['email' => 'porta@example.test']);
    inviteToDoor($this, 'porta@example.test');
    $url = AssignCheckinStaff::acceptUrl(CheckinStaffInvitation::query()->sole());

    $this->actingAs($doorman)->get($url)->assertOk()->assertSee('Concerto al chiostro')->assertSee(__('decision.staff_invitation.accept_action'));
    expect($this->date->checkinStaff()->count())->toBe(0);

    $this->post($url, ['decision' => 'accept'])->assertRedirect(route('ticketing.manage.scanner', $this->date));
    expect($this->date->checkinStaff()->whereKey($doorman->id)->exists())->toBeTrue()
        ->and(CheckinStaffInvitation::query()->count())->toBe(0);

    // L'invito usato non vale una seconda volta.
    $this->get($url)->assertForbidden();
});

it('rifiuta un account con un altro indirizzo, un collegamento alterato o scaduto', function (): void {
    $doorman = User::factory()->create(['email' => 'porta@example.test']);
    $other = User::factory()->create(['email' => 'altro@example.test']);
    inviteToDoor($this, 'porta@example.test');
    $url = AssignCheckinStaff::acceptUrl(CheckinStaffInvitation::query()->sole());

    $this->actingAs($other)->post($url, ['decision' => 'accept'])->assertForbidden()->assertSee('p****@example.test');
    $this->actingAs($doorman)->post($url.'x', ['decision' => 'accept'])->assertForbidden()->assertSee(__('decision.staff_invitation.invalid'));

    $this->travel(CheckinStaffInvitation::EXPIRES_DAYS + 1)->days();
    $this->actingAs($doorman)->post($url, ['decision' => 'accept'])->assertForbidden();
    expect($this->date->checkinStaff()->count())->toBe(0);
});

it('rende inutile il collegamento vecchio quando l\'invito si rimanda o si ritira', function (): void {
    $doorman = User::factory()->create(['email' => 'porta@example.test']);
    inviteToDoor($this, 'porta@example.test');
    $old = AssignCheckinStaff::acceptUrl(CheckinStaffInvitation::query()->sole());

    // Un secondo invito entro l'ora non manda un'altra mail.
    inviteToDoor($this, 'porta@example.test');
    Notification::assertSentTimes(CheckinStaffInvited::class, 1);

    $this->travel(2)->hours();
    inviteToDoor($this, 'porta@example.test');
    Notification::assertSentTimes(CheckinStaffInvited::class, 2);
    $this->actingAs($doorman)->post($old, ['decision' => 'accept'])->assertForbidden()->assertSee(__('decision.staff_invitation.gone'));

    $fresh = AssignCheckinStaff::acceptUrl(CheckinStaffInvitation::query()->sole());
    $this->actingAs($this->owner)->post(route('ticketing.manage.staff', $this->date), ['email' => 'porta@example.test', 'remove' => '1']);
    $this->actingAs($doorman)->post($fresh, ['decision' => 'accept'])->assertForbidden();
    expect($this->date->checkinStaff()->count())->toBe(0);
});

it('lascia rifiutare, e non accetta dopo la fine della data', function (): void {
    $doorman = User::factory()->create(['email' => 'porta@example.test']);
    inviteToDoor($this, 'porta@example.test');
    $url = AssignCheckinStaff::acceptUrl(CheckinStaffInvitation::query()->sole());

    $this->actingAs($doorman)->post($url, ['decision' => 'decline'])->assertRedirect(route('account.profile'));
    expect(CheckinStaffInvitation::query()->count())->toBe(0)->and($this->date->checkinStaff()->count())->toBe(0);

    $this->travel(1)->hours();
    inviteToDoor($this, 'porta@example.test');
    $url = AssignCheckinStaff::acceptUrl(CheckinStaffInvitation::query()->sole());
    // Il collegamento vale ancora (sette giorni dall'invito), ma la data è finita da un'ora.
    $this->date->forceFill(['starts_at' => now()->subHours(3), 'ends_at' => now()->subHour(), 'effective_ends_at' => null])->save();
    $this->actingAs($doorman)->post($url, ['decision' => 'accept'])->assertForbidden()->assertSee(__('decision.staff_invitation.gone'));
    expect($this->date->checkinStaff()->count())->toBe(0);
});

it('limita gli inviti in attesa per data', function (): void {
    foreach (range(1, CheckinStaffInvitation::MAX_PENDING) as $n) {
        $this->date->staffInvitations()->create(['email' => "p{$n}@example.test", 'invited_at' => now()]);
    }

    $this->actingAs($this->owner)->post(route('ticketing.manage.staff', $this->date), ['email' => 'uno-di-troppo@example.test'])->assertSessionHasErrors('email');
    Notification::assertNothingSent();
});

it('non riempie la casella di nessuno invitando e ritirando di continuo', function (): void {
    foreach (range(1, 6) as $round) {
        inviteToDoor($this, 'bersaglio@example.test');
        $this->actingAs($this->owner)->post(route('ticketing.manage.staff', $this->date), ['email' => 'bersaglio@example.test', 'remove' => '1']);
    }

    Notification::assertSentTimes(CheckinStaffInvited::class, AssignCheckinStaff::MAILS_PER_RECIPIENT_PER_DAY);
});

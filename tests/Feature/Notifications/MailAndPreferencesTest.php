<?php

declare(strict_types=1);

use App\Actions\Account\SaveOccurrences;
use App\DTOs\NotificationPreferences;
use App\Enums\NotificationType;
use App\Models\ScheduledNotification;
use App\Models\User;
use App\Notifications\Scheduled\ScheduledMessage;
use App\Services\Notifications\MessageFactory;
use App\Support\Notifications\PreferenceLinks;
use Carbon\Carbon;
use Illuminate\Support\Facades\URL;
use Symfony\Component\Mime\Email;

/**
 * §15.6 e §15.9: la forma del messaggio e i due diritti che deve portare con
 * sé — disiscrizione a un click e preferenze **raggiungibili senza accesso**.
 */
beforeEach(function (): void {
    $this->city = testCity();
    $this->category = testCategory();
    freezeLocal($this->city, '2026-09-01 12:00');

    $this->occurrence = occurrenceAtLocal($this->city, $this->category, '2026-09-05 21:00');
    $this->user = User::factory()->create();

    app(SaveOccurrences::class)->one($this->user, $this->occurrence);
});

afterEach(function (): void {
    Carbon::setTestNow();
});

function renderedReminder(User $user, ScheduledNotification $row): string
{
    $message = app(MessageFactory::class)->build($row, $user);

    return (new ScheduledMessage($message))->toMail($user)->render();
}

it('compone un email a tabelle, con il collegamento alla scheda e i due piè di pagina obbligatori', function (): void {
    $row = ScheduledNotification::query()->orderBy('send_at')->firstOrFail();

    $html = renderedReminder($this->user, $row);

    expect($html)
        // I client di posta non hanno un motore moderno: il layout è a
        // tabelle con stili in linea, non a flex.
        ->toContain('<table')
        ->toContain('style="')
        ->not->toContain('<link rel="stylesheet"')
        // §15.4: deep link diretto alla scheda evento, mai la home.
        ->toContain(route('events.show', $this->occurrence->event))
        // §15.9: disiscrizione e preferenze.
        ->toContain(__('notifications.mail.unsubscribe'))
        ->toContain(__('notifications.mail.preferences'))
        ->toContain('/notifiche/disiscriviti/')
        ->toContain('/notifiche/preferenze/');

    // Nessuna chiave di traduzione grezza (§2.1 delle convenzioni).
    expect(preg_match('/\b(?:notifications|enums|common|dates)\.[a-z_]+\.[a-z_.]+\b/', $html))->toBe(0);
});

it('non offre una disiscrizione che non disiscriverebbe', function (): void {
    // Annullamenti e spostamenti non hanno interruttore (§15.4): mostrare il
    // collegamento senza che spenga nulla sarebbe peggio che non averlo.
    expect(PreferenceLinks::unsubscribe($this->user, NotificationType::EventCancelled))->toBeNull()
        ->and(PreferenceLinks::unsubscribe($this->user, NotificationType::EventReminder))->not->toBeNull();
});

it('dichiara le intestazioni che fanno comparire il pulsante nativo del client di posta', function (): void {
    $row = ScheduledNotification::query()->orderBy('send_at')->firstOrFail();
    $message = app(MessageFactory::class)->build($row, $this->user);

    $mail = (new ScheduledMessage($message))->toMail($this->user);

    $email = new Email;

    foreach ($mail->callbacks as $callback) {
        $callback($email);
    }

    expect($email->getHeaders()->get('List-Unsubscribe'))->not->toBeNull()
        ->and($email->getHeaders()->get('List-Unsubscribe-Post')?->getBodyAsString())
        ->toContain('List-Unsubscribe=One-Click');
});

it('spegne davvero la tipologia al primo click, senza chiedere di accedere', function (): void {
    $url = PreferenceLinks::unsubscribe($this->user, NotificationType::EventReminder);

    $this->get($url)->assertOk()->assertSee(NotificationType::EventReminder->label());

    expect(NotificationPreferences::fromUser($this->user->fresh())->reminders)->toBeFalse();
});

it('accetta anche il POST del client di posta', function (): void {
    $url = PreferenceLinks::unsubscribePost($this->user, NotificationType::VenueDigest);

    $this->post($url)->assertOk();

    expect(NotificationPreferences::fromUser($this->user->fresh())->venueDigest)->toBeFalse();
});

it('cancella il consenso al marketing quando ci si disiscrive dalla newsletter', function (): void {
    $this->user->forceFill(['marketing_opt_in_at' => now()])->save();

    $this->get(PreferenceLinks::unsubscribe($this->user, NotificationType::WeekendNewsletter))->assertOk();

    expect($this->user->fresh()?->marketing_opt_in_at)->toBeNull();
});

it('rifiuta un indirizzo di disiscrizione non firmato', function (): void {
    $this->get(sprintf('/notifiche/disiscriviti/%d/event_reminder', $this->user->getKey()))->assertForbidden();
    $this->get(sprintf('/notifiche/preferenze/%d', $this->user->getKey()))->assertForbidden();
});

it('rifiuta un indirizzo firmato scaduto', function (): void {
    $url = URL::temporarySignedRoute(
        'notifications.preferences',
        now()->addMinutes(5),
        ['user' => (int) $this->user->getKey()],
    );

    Carbon::setTestNow(Carbon::now()->addMinutes(10));

    $this->get($url)->assertForbidden();
});

it('apre e salva le preferenze senza sessione', function (): void {
    $url = PreferenceLinks::preferences($this->user);

    $this->get($url)->assertOk()->assertSee(__('notifications.preferences.title'));

    $update = URL::temporarySignedRoute(
        'notifications.preferences.update',
        now()->addDay(),
        ['user' => (int) $this->user->getKey()],
    );

    $this->patch($update, [
        'sold_out' => '1',
        'quiet_from' => '23:00',
        'quiet_to' => '07:30',
        'daily_digest_time' => '18:00',
    ])->assertRedirect();

    $user = $this->user->fresh();

    expect($user)->not->toBeNull()
        ->and(NotificationPreferences::fromUser($user)->reminders)->toBeFalse()
        ->and(NotificationPreferences::fromUser($user)->soldOut)->toBeTrue()
        ->and($user->quiet_hours)->toBe(['from' => '23:00', 'to' => '07:30'])
        ->and($user->daily_digest_time)->toBe('18:00:00');
});

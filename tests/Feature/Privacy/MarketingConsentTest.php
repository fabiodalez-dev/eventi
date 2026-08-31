<?php

declare(strict_types=1);

use App\Actions\Account\SaveOccurrences;
use App\Enums\NotificationType;
use App\Enums\OccurrenceStatus;
use App\Models\ScheduledNotification;
use App\Models\User;
use App\Notifications\Scheduled\ScheduledMessage;
use App\Services\Notifications\DigestPlanner;
use Carbon\Carbon;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;

/**
 * §15.9: «Notifiche **transazionali** e **marketing** sono giuridicamente
 * diverse: consenso separato, tracciato con data e origine in
 * `marketing_opt_in_at`.»
 *
 * Separato vuol dire due cose che nessuno confonde a parole ma che il codice
 * confonde spesso:
 *
 * - togliere il consenso al marketing **non tocca** ciò che la persona ha
 *   chiesto (i promemoria di quello che ha salvato) né ciò che ha diritto di
 *   sapere (un evento annullato);
 * - spegnere tutte le notifiche **non è** revocare il consenso al marketing, e
 *   non deve cancellarne la data: quella data è la prova di quando il consenso
 *   è stato dato.
 */
beforeEach(function (): void {
    (new RolesAndPermissionsSeeder)->run();

    $this->city = testCity();
    $this->category = testCategory();
    freezeLocal($this->city, '2026-09-01 12:00');

    $this->occurrence = occurrenceAtLocal($this->city, $this->category, '2026-09-05 21:00');
});

afterEach(function (): void {
    Carbon::setTestNow();
});

it('tiene il consenso al marketing in una colonna sua, con la data in cui è stato dato', function (): void {
    $consenso = Carbon::parse('2026-08-20 09:15:00');
    Carbon::setTestNow($consenso);

    $this->post('/registrati', [
        'email' => 'iscritta@example.test',
        'password' => 'una-password-lunga',
        'password_confirmation' => 'una-password-lunga',
        'marketing_opt_in' => '1',
    ])->assertRedirect();

    $user = User::query()->where('email', 'iscritta@example.test')->firstOrFail();

    expect($user->marketing_opt_in_at)->not->toBeNull()
        ->and($user->marketing_opt_in_at?->toDateTimeString())->toBe($consenso->toDateTimeString())
        // Le preferenze di notifica sono un'altra cosa: non le tocca.
        ->and($user->notification_preferences)->toBeNull();
});

it('non dà per dato un consenso che nessuno ha dato', function (): void {
    $this->post('/registrati', [
        'email' => 'nessun-consenso@example.test',
        'password' => 'una-password-lunga',
        'password_confirmation' => 'una-password-lunga',
    ])->assertRedirect();

    expect(User::query()->where('email', 'nessun-consenso@example.test')->firstOrFail()->marketing_opt_in_at)
        ->toBeNull();
});

it('non programma la newsletter a chi non ha acconsentito, e la programma a chi sì', function (): void {
    /* La newsletter esce di giovedì alle 16:00 e la pianificazione guarda 36
       ore avanti: da mercoledì a mezzogiorno rientra nell'orizzonte, da
       martedì no — e il test direbbe «nessuna newsletter» per il motivo
       sbagliato. */
    freezeLocal($this->city, '2026-09-02 12:00');

    $senza = User::factory()->create(['marketing_opt_in_at' => null]);
    $con = User::factory()->create(['marketing_opt_in_at' => Carbon::parse('2026-08-01 10:00:00')]);

    app(DigestPlanner::class)->plan();

    expect(ScheduledNotification::query()
        ->ofType(NotificationType::WeekendNewsletter->value)
        ->pluck('user_id')
        ->map(static fn (mixed $id): int => (int) $id)
        ->all())
        ->toContain((int) $con->getKey())
        ->not->toContain((int) $senza->getKey());
});

/**
 * Disiscriversi dalla newsletter cancella **quel** consenso e nient'altro: chi
 * lo fa continua a ricevere i promemoria delle date che ha messo in agenda.
 */
it('togliere il consenso al marketing non spegne i promemoria di ciò che si è salvato', function (): void {
    $user = User::factory()->create(['marketing_opt_in_at' => Carbon::parse('2026-08-01 10:00:00')]);
    app(SaveOccurrences::class)->one($user, $this->occurrence);

    $link = URL::temporarySignedRoute('notifications.unsubscribe', Carbon::now()->addDays(30), [
        'user' => $user->getKey(),
        'type' => NotificationType::WeekendNewsletter->value,
    ]);

    $this->get($link)->assertOk();

    expect($user->fresh()?->marketing_opt_in_at)->toBeNull()
        ->and($user->fresh()?->notificationPreferences()->reminders)->toBeTrue();

    Notification::fake();
    Carbon::setTestNow(localInstant($this->city, '2026-09-05 18:30'));
    $this->artisan('notifications:send')->assertSuccessful();

    /* Due: il promemoria di 24 ore prima e quello di 3 ore prima (§15.4).
       Entrambi sono scaduti a quest'ora, ed entrambi sono transazionali. */
    Notification::assertSentTimes(ScheduledMessage::class, 2);
});

/**
 * §15.4: gli annullamenti non hanno interruttore. Nemmeno la revoca del
 * consenso al marketing è un interruttore per loro — è un consenso su un altro
 * trattamento.
 */
it('togliere il consenso al marketing non ferma gli annullamenti', function (): void {
    $user = User::factory()->create([
        'marketing_opt_in_at' => Carbon::parse('2026-08-01 10:00:00'),
        'notification_preferences' => [
            'reminders' => false,
            'sold_out' => false,
            'venue_digest' => false,
            'daily_digest' => false,
        ],
    ]);

    app(SaveOccurrences::class)->one($user, $this->occurrence);

    $user->forceFill(['marketing_opt_in_at' => null])->save();

    $this->occurrence->status = OccurrenceStatus::Cancelled;
    $this->occurrence->save();

    Notification::fake();
    Carbon::setTestNow(Carbon::now()->addMinutes(5));
    $this->artisan('notifications:send')->assertSuccessful();

    Notification::assertSentTimes(ScheduledMessage::class, 1);

    expect(ScheduledNotification::query()
        ->ofType(NotificationType::EventCancelled->value)
        ->count())->toBe(1);
});

/**
 * La direzione opposta: spegnere ogni notifica dal pannello delle preferenze
 * **non** è una revoca del consenso al marketing, e non deve cancellarne la
 * data.
 */
it('spegnere tutte le notifiche non cancella la data del consenso al marketing', function (): void {
    $consenso = Carbon::parse('2026-08-01 10:00:00');
    $user = User::factory()->create(['marketing_opt_in_at' => $consenso]);

    $this->actingAs($user)->patch('/il-mio-profilo', [
        'timezone' => 'Europe/Rome',
        'locale' => 'it',
        'marketing_opt_in' => '1',
    ])->assertRedirect();

    expect($user->fresh()?->marketing_opt_in_at?->toDateTimeString())->toBe($consenso->toDateTimeString());
});

/**
 * Riconfermare il consenso non ne riscrive la data: la data racconta **quando**
 * il consenso è stato dato, ed è la sola cosa che si possa esibire a chi
 * chiede di provarlo.
 */
it('non riscrive la data del consenso ogni volta che si salva il profilo', function (): void {
    $consenso = Carbon::parse('2026-08-01 10:00:00');
    $user = User::factory()->create(['marketing_opt_in_at' => $consenso]);

    Carbon::setTestNow(Carbon::parse('2026-09-15 08:00:00'));

    $this->withToken($user->createToken('Telefono')->plainTextToken)
        ->patchJson('/api/v1/me', ['marketing_opt_in' => true])
        ->assertOk();

    expect($user->fresh()?->marketing_opt_in_at?->toDateTimeString())->toBe($consenso->toDateTimeString());
});

it('registra una data nuova quando il consenso viene dato di nuovo dopo una revoca', function (): void {
    $user = User::factory()->create(['marketing_opt_in_at' => Carbon::parse('2026-08-01 10:00:00')]);

    $token = $user->createToken('Telefono')->plainTextToken;

    $this->withToken($token)->patchJson('/api/v1/me', ['marketing_opt_in' => false])->assertOk();
    expect($user->fresh()?->marketing_opt_in_at)->toBeNull();

    $nuovo = Carbon::parse('2026-09-20 17:30:00');
    Carbon::setTestNow($nuovo);

    $this->withToken($token)->patchJson('/api/v1/me', ['marketing_opt_in' => true])->assertOk();

    expect($user->fresh()?->marketing_opt_in_at?->toDateTimeString())->toBe($nuovo->toDateTimeString());
});

<?php

declare(strict_types=1);

use App\Actions\Account\SaveOccurrences;
use App\Enums\NotificationStatus;
use App\Enums\NotificationType;
use App\Enums\OccurrenceStatus;
use App\Models\NotificationLog;
use App\Models\ScheduledNotification;
use App\Models\User;
use App\Notifications\Scheduled\ScheduledMessage;
use Carbon\Carbon;
use Illuminate\Support\Facades\Notification;

/**
 * §18, **scenario J — annullamento**.
 *
 * «Un'occorrenza salvata da 40 utenti passa a `cancelled` → 40 notifiche di
 * annullamento entro 5 minuti, zero promemoria residui, **anche per gli utenti
 * che avevano disattivato tutte le altre notifiche**.»
 *
 * L'ultima clausola è il cuore del test e la ragione per cui gli annullamenti
 * non hanno un interruttore (§15.4): chi si presenta davanti a una porta
 * chiusa ha diritto di saperlo prima, qualunque cosa abbia spento.
 */
beforeEach(function (): void {
    $this->city = testCity();
    $this->category = testCategory();
    freezeLocal($this->city, '2026-09-01 12:00');

    $this->occurrence = occurrenceAtLocal($this->city, $this->category, '2026-09-05 21:00');

    // Quaranta persone che hanno spento tutto ciò che si può spegnere.
    $this->users = User::factory()->count(40)->create([
        'notification_preferences' => [
            'reminders' => false,
            'sold_out' => false,
            'venue_digest' => false,
            'daily_digest' => false,
        ],
    ]);

    foreach ($this->users as $user) {
        app(SaveOccurrences::class)->one($user, $this->occurrence);
    }
});

afterEach(function (): void {
    Carbon::setTestNow();
});

it('accoda quaranta annullamenti e annulla ogni promemoria residuo', function (): void {
    $this->occurrence->status = OccurrenceStatus::Cancelled;
    $this->occurrence->save();

    $cancellations = ScheduledNotification::query()
        ->ofType(NotificationType::EventCancelled->value)
        ->get();

    expect($cancellations)->toHaveCount(40)
        ->and($cancellations->pluck('status')->unique()->all())->toBe([NotificationStatus::Pending])
        ->and($cancellations->pluck('user_id')->unique())->toHaveCount(40)
        // Zero promemoria residui: nessuna riga in attesa che non sia un annullamento.
        ->and(ScheduledNotification::query()
            ->pending()
            ->where('type', '!=', NotificationType::EventCancelled->value)
            ->count())->toBe(0);
});

it('li consegna tutti entro cinque minuti, malgrado ogni preferenza spenta', function (): void {
    Notification::fake();

    $this->occurrence->status = OccurrenceStatus::Cancelled;
    $this->occurrence->save();

    // Il worker gira ogni cinque minuti: è la finestra che lo scenario J
    // dichiara accettabile.
    Carbon::setTestNow(Carbon::now()->addMinutes(5));

    $this->artisan('notifications:send')->assertSuccessful();

    Notification::assertSentTimes(ScheduledMessage::class, 40);

    expect(ScheduledNotification::query()->ofStatus(NotificationStatus::Sent)->count())->toBe(40)
        ->and(NotificationLog::query()->where('type', NotificationType::EventCancelled->value)->count())->toBe(40);
});

it('non ripete l annullamento se la data viene risalvata', function (): void {
    $this->occurrence->status = OccurrenceStatus::Cancelled;
    $this->occurrence->save();

    $this->occurrence->status_note = 'Rinviato a data da destinarsi';
    $this->occurrence->save();

    expect(ScheduledNotification::query()->ofType(NotificationType::EventCancelled->value)->count())->toBe(40);
});

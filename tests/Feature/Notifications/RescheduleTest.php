<?php

declare(strict_types=1);

use App\Actions\Account\SaveOccurrences;
use App\Enums\NotificationStatus;
use App\Enums\NotificationType;
use App\Models\ScheduledNotification;
use App\Models\User;
use Carbon\Carbon;

/**
 * §18, **scenario I — riprogrammazione**.
 *
 * «Evento salvato con promemoria a 3h; il locale sposta l'orario di 2 ore → il
 * promemoria viene riprogrammato, non duplicato. Sposta l'evento a ieri → il
 * promemoria diventa `skipped`, non viene inviato.»
 *
 * È la prova che il motore vive sulle righe e non sulla coda: riprogrammare
 * una riga è un `UPDATE`, mentre riprogrammare un lavoro accodato vorrebbe
 * dire trovarlo, ucciderlo e rifarlo — e nessuna delle tre cose è possibile
 * con una coda su database.
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

it('riprogramma il promemoria quando il locale sposta l orario di due ore, senza duplicarlo', function (): void {
    $before = ScheduledNotification::query()
        ->ofType(NotificationType::EventReminder->value)
        ->orderBy('send_at')
        ->get();

    expect($before)->toHaveCount(2);

    $this->occurrence->starts_at = $this->occurrence->starts_at->copy()->addHours(2);
    $this->occurrence->save();

    $after = ScheduledNotification::query()
        ->ofType(NotificationType::EventReminder->value)
        ->orderBy('send_at')
        ->get();

    // Stesse righe: stessi identificativi, stesse chiavi, orario spostato.
    expect($after)->toHaveCount(2)
        ->and($after->pluck('id')->all())->toBe($before->pluck('id')->all())
        ->and($after->pluck('dedupe_key')->all())->toBe($before->pluck('dedupe_key')->all())
        ->and($after->pluck('status')->all())->toBe([NotificationStatus::Pending, NotificationStatus::Pending]);

    foreach ($after as $index => $row) {
        expect($row->send_at?->utc()->format('Y-m-d H:i'))
            ->toBe($before[$index]->send_at?->utc()->addHours(2)->format('Y-m-d H:i'));
    }
});

it('avvisa chi ha salvato che la data si è spostata, una volta sola per spostamento', function (): void {
    $this->occurrence->starts_at = $this->occurrence->starts_at->copy()->addHours(2);
    $this->occurrence->save();

    // Un secondo salvataggio che non tocca l'orario non è un'altra notizia.
    $this->occurrence->touch();

    expect(ScheduledNotification::query()->ofType(NotificationType::EventMoved->value)->count())->toBe(1);
});

it('marca il promemoria come saltato quando la data viene spostata a ieri', function (): void {
    $this->occurrence->starts_at = localInstant($this->city, '2026-08-31 21:00')->utc();
    $this->occurrence->save();

    $rows = ScheduledNotification::query()
        ->ofType(NotificationType::EventReminder->value)
        ->get();

    expect($rows)->toHaveCount(2)
        ->and($rows->pluck('status')->unique()->all())->toBe([NotificationStatus::Skipped])
        ->and($rows->pluck('last_error')->unique()->all())->toBe(['occurrence_past']);
});

it('non manda un avviso di spostamento per una data finita nel passato', function (): void {
    $this->occurrence->starts_at = localInstant($this->city, '2026-08-31 21:00')->utc();
    $this->occurrence->save();

    expect(ScheduledNotification::query()->ofType(NotificationType::EventMoved->value)->count())->toBe(0);
});

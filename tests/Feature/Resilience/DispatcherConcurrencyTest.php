<?php

declare(strict_types=1);

use App\Actions\Account\SaveOccurrences;
use App\Enums\NotificationStatus;
use App\Enums\NotificationType;
use App\Models\NotificationLog;
use App\Models\ScheduledNotification;
use App\Models\User;
use App\Notifications\Scheduled\ScheduledMessage;
use App\Services\Notifications\NotificationDispatcher;
use Carbon\Carbon;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

/**
 * §15.5 — «il worker gira ogni cinque minuti». Sulla shared hosting il worker
 * è un cron rilanciato ogni minuto (`RUNBOOK.md`), quindi due esecuzioni
 * sovrapposte non sono un caso di scuola: succedono ogni volta che una gira
 * più a lungo del previsto.
 *
 * Senza Redis non ci sono lock distribuiti (D5), e la difesa è a due strati:
 * il prelievo `FOR UPDATE SKIP LOCKED`, che fa **saltare** al secondo worker le
 * righe già in mano al primo, e il vincolo `dedupe_key UNIQUE`, che regge
 * anche se il primo strato sbaglia.
 */
beforeEach(function (): void {
    $this->city = testCity();
    $this->category = testCategory();
    freezeLocal($this->city, '2026-09-01 12:00');

    $this->occurrence = occurrenceAtLocal($this->city, $this->category, '2026-09-05 21:00');

    $this->user = User::factory()->create();
    app(SaveOccurrences::class)->one($this->user, $this->occurrence);

    Carbon::setTestNow(localInstant($this->city, '2026-09-05 18:30'));
});

afterEach(function (): void {
    Carbon::setTestNow();
});

/**
 * Il prelievo deve **saltare** le righe bloccate, non aspettarle: con un
 * `FOR UPDATE` senza `SKIP LOCKED` il secondo worker si metterebbe in coda e
 * spedirebbe gli stessi messaggi appena il primo commette.
 */
it('preleva le righe in lock, saltando quelle già in mano a un altro worker', function (): void {
    $interrogazioni = [];

    DB::listen(function (QueryExecuted $query) use (&$interrogazioni): void {
        $interrogazioni[] = strtolower($query->sql);
    });

    Notification::fake();
    app(NotificationDispatcher::class)->run();

    $prelievi = array_values(array_filter(
        $interrogazioni,
        static fn (string $sql): bool => str_contains($sql, 'from `scheduled_notifications`')
            && str_contains($sql, 'for update'),
    ));

    expect($prelievi)->not->toBe([]);

    foreach ($prelievi as $sql) {
        expect($sql)->toContain('skip locked');
    }
});

it('non spedisce due volte quando il worker gira due volte di fila', function (): void {
    Notification::fake();

    app(NotificationDispatcher::class)->run();
    app(NotificationDispatcher::class)->run();

    Notification::assertSentTimes(ScheduledMessage::class, 2);

    expect(ScheduledNotification::query()->ofStatus(NotificationStatus::Sent)->count())->toBe(2)
        ->and(ScheduledNotification::query()->pending()->count())->toBe(0)
        ->and(NotificationLog::query()->count())->toBe(2);
});

/**
 * Il secondo giro non trova più niente da prendere in mano: è ciò che rende
 * innocua la sovrapposizione, e si legge nel riepilogo che il worker
 * restituisce.
 */
it('al secondo giro non prende in mano nessuna riga', function (): void {
    Notification::fake();

    $primo = app(NotificationDispatcher::class)->run();
    $secondo = app(NotificationDispatcher::class)->run();

    expect($primo['claimed'])->toBe(2)
        ->and($primo['sent'])->toBe(2)
        ->and($secondo['claimed'])->toBe(0)
        ->and($secondo['sent'])->toBe(0);
});

/**
 * L'ultima difesa: se per qualunque ragione due righe venissero programmate
 * per lo stesso invio, il database rifiuterebbe la seconda. La chiave è unica
 * **su tutta la tabella**, non per utente: due persone diverse non possono
 * fabbricare la stessa chiave.
 */
it('non lascia esistere due righe con la stessa chiave nemmeno di utenti diversi', function (): void {
    $chiave = (string) ScheduledNotification::query()->value('dedupe_key');
    $altro = User::factory()->create();

    expect(fn () => ScheduledNotification::query()->create([
        'user_id' => $altro->getKey(),
        'notifiable_type' => $this->occurrence->getMorphClass(),
        'notifiable_id' => $this->occurrence->getKey(),
        'type' => NotificationType::EventReminder->value,
        'channel' => 'mail',
        'send_at' => Carbon::now(),
        'status' => NotificationStatus::Pending->value,
        'dedupe_key' => $chiave,
    ]))->toThrow(QueryException::class);
});

/**
 * Una riga già segnata come inviata non riparte: è il caso che si presenta se
 * un worker viene ucciso dopo l'invio e prima del commit, e la riga viene
 * ripresa da un altro.
 */
it('non riprende in mano una riga già segnata come inviata', function (): void {
    Notification::fake();

    app(NotificationDispatcher::class)->run();

    /* Si rimette indietro l'orologio della riga, non il suo stato: è così che
       si distingue «l'ora è tornata utile» da «la riga è ancora da fare». */
    ScheduledNotification::query()->update(['send_at' => Carbon::now()->subHour()]);

    $secondo = app(NotificationDispatcher::class)->run();

    expect($secondo['claimed'])->toBe(0);

    Notification::assertSentTimes(ScheduledMessage::class, 2);
});

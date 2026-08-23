<?php

declare(strict_types=1);

use App\Actions\Account\RemoveSavedOccurrence;
use App\Actions\Account\SaveOccurrences;
use App\Enums\NotificationSkipReason;
use App\Enums\NotificationStatus;
use App\Enums\NotificationType;
use App\Models\EventOccurrence;
use App\Models\ScheduledNotification;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\QueryException;

/**
 * §15.5, il primo anello: **il promemoria nasce dal salvataggio**, non da una
 * scansione periodica dei salvataggi.
 *
 * Ciò che questi test difendono è la forma dell'architettura, non un dettaglio:
 * se un giorno qualcuno sostituisse le righe con un cron che ogni minuto
 * guarda `saved_events`, questi test resterebbero verdi solo riscrivendoli — ed
 * è esattamente il campanello che si vuole.
 */
beforeEach(function (): void {
    $this->city = testCity();
    $this->category = testCategory();
    freezeLocal($this->city, '2026-09-01 12:00');
});

afterEach(function (): void {
    Carbon::setTestNow();
});

it('crea i due promemoria alla prima messa in agenda, con le chiavi di §7.10', function (): void {
    $occurrence = occurrenceAtLocal($this->city, $this->category, '2026-09-05 21:00');
    $user = User::factory()->create();

    app(SaveOccurrences::class)->one($user, $occurrence);

    $rows = ScheduledNotification::query()->orderBy('send_at')->get();

    expect($rows)->toHaveCount(2)
        ->and($rows->pluck('dedupe_key')->all())->toBe([
            sprintf('reminder_24h:user_%d:occ_%d', $user->getKey(), $occurrence->getKey()),
            sprintf('reminder_3h:user_%d:occ_%d', $user->getKey(), $occurrence->getKey()),
        ])
        ->and($rows->pluck('status')->all())->toBe([NotificationStatus::Pending, NotificationStatus::Pending])
        ->and($rows->first()?->send_at?->utc()->format('Y-m-d H:i'))
        ->toBe($occurrence->starts_at->utc()->subHours(24)->format('Y-m-d H:i'))
        ->and($rows->last()?->send_at?->utc()->format('Y-m-d H:i'))
        ->toBe($occurrence->starts_at->utc()->subHours(3)->format('Y-m-d H:i'));
});

it('non crea un promemoria il cui orario è già passato', function (): void {
    // Chi salva stamattina una data di stasera riceve quello a tre ore, non
    // quello a ventiquattro: sarebbe un ritardo, non un promemoria.
    $occurrence = occurrenceAtLocal($this->city, $this->category, '2026-09-01 21:00');
    $user = User::factory()->create();

    app(SaveOccurrences::class)->one($user, $occurrence);

    $rows = ScheduledNotification::query()->get();

    expect($rows)->toHaveCount(1)
        ->and($rows->first()?->context('hours'))->toBe(3);
});

it('rispetta gli offset scelti dalla persona', function (): void {
    $occurrence = occurrenceAtLocal($this->city, $this->category, '2026-09-10 21:00');

    $user = User::factory()->create([
        'notification_preferences' => ['reminder_hours' => [48, 1]],
    ]);

    app(SaveOccurrences::class)->one($user, $occurrence);

    expect(ScheduledNotification::query()->orderBy('send_at')->pluck('dedupe_key')->all())->toBe([
        sprintf('reminder_48h:user_%d:occ_%d', $user->getKey(), $occurrence->getKey()),
        sprintf('reminder_1h:user_%d:occ_%d', $user->getKey(), $occurrence->getKey()),
    ]);
});

it('salvare due volte non produce due promemoria', function (): void {
    $occurrence = occurrenceAtLocal($this->city, $this->category, '2026-09-05 21:00');
    $user = User::factory()->create();

    app(SaveOccurrences::class)->one($user, $occurrence);
    app(SaveOccurrences::class)->one($user, $occurrence);

    expect(ScheduledNotification::query()->count())->toBe(2);
});

it('rende impossibile il doppio invio: la chiave di deduplica è unica nel database', function (): void {
    // §7.10: «`dedupe_key` è la garanzia contro il doppio invio. Vincolo di
    // unicità a livello di database, non solo applicativo.» Senza Redis e
    // senza lock distribuito (D5) è l'ultima difesa che resta.
    $occurrence = occurrenceAtLocal($this->city, $this->category, '2026-09-05 21:00');
    $user = User::factory()->create();

    app(SaveOccurrences::class)->one($user, $occurrence);

    $existing = ScheduledNotification::query()->firstOrFail();

    expect(fn () => ScheduledNotification::query()->create([
        'user_id' => $user->getKey(),
        'notifiable_type' => $occurrence->getMorphClass(),
        'notifiable_id' => $occurrence->getKey(),
        'type' => NotificationType::EventReminder->value,
        'channel' => 'mail',
        'send_at' => now(),
        'status' => NotificationStatus::Pending->value,
        'dedupe_key' => $existing->dedupe_key,
    ]))->toThrow(QueryException::class);
});

it('annulla i promemoria quando la data esce dall agenda', function (): void {
    $occurrence = occurrenceAtLocal($this->city, $this->category, '2026-09-05 21:00');
    $user = User::factory()->create();

    app(SaveOccurrences::class)->one($user, $occurrence);
    app(RemoveSavedOccurrence::class)($user, (int) $occurrence->getKey());

    expect(ScheduledNotification::query()->pending()->count())->toBe(0)
        ->and(ScheduledNotification::query()->ofStatus(NotificationStatus::Cancelled)->count())->toBe(2);
});

it('salva e programma anche per chi segue l evento, alla nascita di una data nuova', function (): void {
    // §15.3: «Segui questo evento: ogni nuova occorrenza generata viene
    // salvata automaticamente». Una data salvata così è una data salvata, e
    // porta con sé i suoi promemoria come qualunque altra.
    $first = occurrenceAtLocal($this->city, $this->category, '2026-09-05 21:00');
    $user = User::factory()->create();

    $user->follows()->create([
        'followable_type' => 'event',
        'followable_id' => $first->event_id,
        'notify' => true,
    ]);

    $second = EventOccurrence::factory()->create([
        'event_id' => $first->event_id,
        'starts_at' => localInstant($this->city, '2026-09-12 21:00')->utc(),
        'ends_at' => null,
    ]);

    expect($user->savedEvents()->where('occurrence_id', $second->getKey())->exists())->toBeTrue()
        ->and(ScheduledNotification::query()
            ->where('notifiable_id', $second->getKey())
            ->pending()
            ->count())->toBe(2);
});

it('scrive il motivo quando salta un invio', function (): void {
    $occurrence = occurrenceAtLocal($this->city, $this->category, '2026-09-05 21:00');
    $user = User::factory()->create();

    app(SaveOccurrences::class)->one($user, $occurrence);

    $row = ScheduledNotification::query()->firstOrFail();
    $row->markSkipped(NotificationSkipReason::FrequencyCap);

    expect($row->fresh()?->status)->toBe(NotificationStatus::Skipped)
        ->and($row->fresh()?->last_error)->toBe('frequency_cap');
});

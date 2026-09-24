<?php

declare(strict_types=1);

use App\DTOs\NotificationMessage;
use App\Enums\NotificationSkipReason;
use App\Enums\NotificationType;
use App\Models\ScheduledNotification;
use App\Models\User;
use App\Models\Venue;
use App\Services\Notifications\MessageFactory;
use App\Services\RememberedLocation;
use Carbon\Carbon;
use Carbon\CarbonImmutable;

/**
 * La proposta della sera: poche date che cominciano stasera, vicino a dove la
 * persona si trova di solito.
 *
 * Due cose la distinguono dagli altri riepiloghi e sono quelle che questi test
 * proteggono: parte solo nei giorni scelti, e «vicino» è un raggio vero,
 * calcolato in SQL sulle coordinate approssimate che la persona ha
 * acconsentito a salvare. Senza quelle coordinate resta la città, mai il
 * silenzio.
 */
beforeEach(function (): void {
    $this->city = testCity();
    $this->category = testCategory();
});

afterEach(function (): void {
    Carbon::setTestNow();
});

/** @param array<string, mixed> $preferences */
function tonightPerson(array $preferences = [], array $attributes = []): User
{
    return User::factory()->create(['timezone' => 'Europe/Rome',
        'notification_preferences' => ['tonight' => true, ...$preferences], ...$attributes]);
}

it('programma la proposta solo nei giorni scelti, all ora scelta e nel fuso di chi la riceve', function (): void {
    // Lunedì: il prossimo giorno utile è il venerdì successivo.
    freezeLocal($this->city, '2026-09-07 12:00');
    $user = tonightPerson(['tonight_time' => '18:30', 'tonight_days' => [5]]);

    $this->artisan('notifications:plan')->assertSuccessful();

    $row = ScheduledNotification::query()->ofType(NotificationType::TonightNearby->value)->first();
    expect($row)->toBeNull();

    // Il giovedì la riga compare: il venerdì rientra nell'orizzonte di pianificazione.
    freezeLocal($this->city, '2026-09-10 12:00');
    $this->artisan('notifications:plan')->assertSuccessful();

    $row = ScheduledNotification::query()->ofType(NotificationType::TonightNearby->value)->firstOrFail();
    expect($row->user_id)->toBe($user->getKey())
        ->and($row->send_at?->setTimezone('Europe/Rome')->format('Y-m-d H:i'))->toBe('2026-09-11 18:30')
        ->and($row->dedupe_key)->toBe(sprintf('tonight_nearby:user_%d:2026-09-11', $user->getKey()));
});

it('non programma nulla a chi non l ha accesa, e non duplica se la pianificazione si ripete', function (): void {
    freezeLocal($this->city, '2026-09-11 08:00');
    User::factory()->create(['timezone' => 'Europe/Rome']);
    tonightPerson(['tonight_days' => [5]]);

    $this->artisan('notifications:plan');
    $this->artisan('notifications:plan');

    expect(ScheduledNotification::query()->ofType(NotificationType::TonightNearby->value)->count())->toBe(1);
});

it('conta nel tetto giornaliero e rispetta le ore di silenzio', function (): void {
    expect(NotificationType::TonightNearby->countsTowardDailyCap())->toBeTrue()
        ->and(NotificationType::TonightNearby->respectsQuietHours())->toBeTrue()
        ->and(NotificationType::TonightNearby->isMandatory())->toBeFalse();
});

it('usa il raggio se la posizione c è, la città se non c è, e tace sotto il minimo', function (): void {
    freezeLocal($this->city, '2026-09-11 18:30');
    $user = tonightPerson(['tonight_days' => [5]], ['city_id' => $this->city->getKey()]);
    $near = Venue::factory()->approved()->at(45.4100, 11.8800)->create(['city_id' => $this->city->getKey()]);
    $far = Venue::factory()->approved()->at(45.6400, 12.0900)->create(['city_id' => $this->city->getKey()]);

    occurrenceAtLocal($this->city, $this->category, '2026-09-11 21:00', '2026-09-11 23:00', event: ['title' => 'Vicina uno'], venue: $near);
    occurrenceAtLocal($this->city, $this->category, '2026-09-11 21:30', '2026-09-11 23:30', event: ['title' => 'Lontana'], venue: $far);

    $factory = app(MessageFactory::class);
    $notification = new ScheduledNotification(['type' => NotificationType::TonightNearby->value]);

    // Una sola data vicina: sotto il minimo, non parte niente.
    app(RememberedLocation::class)->save(45.4064, 11.8768, $user);
    expect($factory->build($notification, $user->fresh()))->toBe(NotificationSkipReason::NothingToSend);

    // Due date vicine: il messaggio parte e non contiene quella lontana.
    occurrenceAtLocal($this->city, $this->category, '2026-09-11 22:00', '2026-09-11 23:59', event: ['title' => 'Vicina due'], venue: $near);
    $message = $factory->build($notification, $user->fresh());
    expect($message)->toBeInstanceOf(NotificationMessage::class);
    $titles = array_column($message->items, 'title');
    expect($titles)->toContain('Vicina uno')->toContain('Vicina due')->not->toContain('Lontana');

    // Senza posizione vale la città, quindi rientra anche la data lontana.
    app(RememberedLocation::class)->forget($user);
    $message = $factory->build($notification, $user->fresh());
    expect(array_column($message->items, 'title'))->toContain('Lontana');
});

it('ignora una posizione scaduta e non la considera mai come vicinanza', function (): void {
    freezeLocal($this->city, '2026-09-11 18:30');
    $user = tonightPerson(['tonight_days' => [5]], ['city_id' => $this->city->getKey()]);
    $far = Venue::factory()->approved()->at(45.6400, 12.0900)->create(['city_id' => $this->city->getKey()]);

    occurrenceAtLocal($this->city, $this->category, '2026-09-11 21:00', '2026-09-11 23:00', event: ['title' => 'Lontana uno'], venue: $far);
    occurrenceAtLocal($this->city, $this->category, '2026-09-11 21:30', '2026-09-11 23:30', event: ['title' => 'Lontana due'], venue: $far);

    app(RememberedLocation::class)->save(45.4064, 11.8768, $user);
    $user->forceFill(['location_expires_at' => CarbonImmutable::now()->subDay()])->save();

    $message = app(MessageFactory::class)
        ->build(new ScheduledNotification(['type' => NotificationType::TonightNearby->value]), $user->fresh());

    expect(array_column($message->items, 'title'))->toContain('Lontana uno');
});

it('tiene le coordinate approssimate interrogabili accanto a quelle cifrate, e le cancella insieme', function (): void {
    $user = User::factory()->create();

    app(RememberedLocation::class)->save(45.40641234, 11.87689999, $user);
    $user->refresh();

    // Due decimali: circa un chilometro, come il valore cifrato.
    expect($user->location_lat)->toBe(45.41)->and($user->location_lng)->toBe(11.88)
        ->and($user->remembered_location['lat'])->toBe(45.41);

    app(RememberedLocation::class)->forget($user);
    $user->refresh();

    expect($user->location_lat)->toBeNull()->and($user->location_lng)->toBeNull()
        ->and($user->remembered_location)->toBeNull();
});

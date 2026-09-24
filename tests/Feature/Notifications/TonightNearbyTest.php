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

/**
 * Togliere l'ultima spunta non è una conferma silenziosa.
 *
 * Le caselle dei giorni non arrivano affatto quando nessuna è spuntata, e un elenco vuoto veniva sostituito dai giorni predefiniti: il salvataggio riusciva, la pagina diceva di sì, e la proposta continuava a partire in giorni che nessuno aveva scelto. Chi non vuole più nessun giorno spegne l'interruttore, e questo glielo dice invece di deciderlo al posto suo.
 */
it('non accetta una scelta di giorni vuota finché la proposta è accesa', function (): void {
    $user = tonightPerson(['tonight_days' => [5]], ['email_verified_at' => now(), 'locale' => 'it']);
    $token = $user->createToken('Telefono')->plainTextToken;

    $this->withToken($token)->patchJson('/api/v1/me/notification-preferences', [
        'tonight' => true,
        'tonight_days' => [],
    ])->assertStatus(422)->assertJsonStructure(['error' => ['fields' => ['tonight_days']]]);

    $this->actingAs($user)->patch(route('account.profile.update'), [
        'name' => 'Chi riceve la proposta',
        'timezone' => 'Europe/Rome',
        'locale' => 'it',
        'tonight' => '1',
    ])->assertSessionHasErrors('tonight_days');

    expect($user->fresh()->notificationPreferences()->tonightDays)->toBe([5]);
});

/**
 * Una posizione lontana dalla propria città non deve zittire la proposta per sempre.
 *
 * Il raggio non toglie il vincolo della città: chi ha salvato la posizione altrove non troverebbe mai niente dentro il raggio, e smetterebbe di ricevere la proposta senza che nessuno se ne accorga — né chi la riceve, né chi guarda i registri, perché «niente da mandare» è una risposta legittima. Quando il raggio non trova proprio nulla si ricade sulla città.
 */
it('ricade sulla città quando la posizione salvata è lontana da tutto', function (): void {
    freezeLocal($this->city, '2026-09-11 18:30');
    $user = tonightPerson(['tonight_days' => [5]], ['city_id' => $this->city->getKey()]);
    $venue = Venue::factory()->approved()->at(45.4100, 11.8800)->create(['city_id' => $this->city->getKey()]);

    occurrenceAtLocal($this->city, $this->category, '2026-09-11 21:00', '2026-09-11 23:00', event: ['title' => 'In città uno'], venue: $venue);
    occurrenceAtLocal($this->city, $this->category, '2026-09-11 21:30', '2026-09-11 23:30', event: ['title' => 'In città due'], venue: $venue);

    // Una posizione a centinaia di chilometri: dentro il raggio non c'è nulla.
    app(RememberedLocation::class)->save(41.9028, 12.4964, $user);

    $message = app(MessageFactory::class)
        ->build(new ScheduledNotification(['type' => NotificationType::TonightNearby->value]), $user->fresh());

    expect($message)->toBeInstanceOf(NotificationMessage::class)
        ->and(array_column($message->items, 'title'))->toContain('In città uno')->toContain('In città due')
        ->and($message->lines[0])->toBe(__('notifications.tonight_nearby.line_city', ['count' => 2, 'city' => $this->city->name]));
});

<?php

declare(strict_types=1);

use App\Models\Category;
use App\Models\City;
use App\Models\Event;
use App\Models\EventOccurrence;
use App\Models\Follow;
use App\Models\SavedEvent;
use App\Models\User;
use App\Models\Venue;
use App\Notifications\VerifyEmailLink;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Notification;

/**
 * §15.2: «Un account non verificato può salvare, non può ricevere».
 * Le due metà della regola vanno verificate insieme, perché è la loro
 * combinazione a essere il requisito: chiudere il salvataggio sarebbe tanto
 * sbagliato quanto lasciar partire le notifiche.
 */
function futureOccurrence(): EventOccurrence
{
    $city = City::factory()->padova()->create();
    $category = Category::factory()->create([
        'name' => 'Musica dal vivo',
        'default_duration_minutes' => 180,
        'supports_ongoing' => true,
        'is_nightlife' => false,
    ]);
    $venue = Venue::factory()->approved()->create(['city_id' => $city->getKey()]);

    $event = Event::factory()->published()->create([
        'city_id' => $city->getKey(),
        'category_id' => $category->getKey(),
        'venue_id' => $venue->getKey(),
        'title' => 'Concerto di musica popolare',
    ]);

    return EventOccurrence::factory()->create([
        'event_id' => $event->getKey(),
        'starts_at' => CarbonImmutable::parse('2026-09-11 19:00:00', 'UTC'),
        'ends_at' => null,
        'doors_at' => null,
    ]);
}

it('consente a un account non verificato di salvare un evento', function (): void {
    $user = User::factory()->unverified()->create();
    $occurrence = futureOccurrence();

    expect(Gate::forUser($user)->allows('create', SavedEvent::class))->toBeTrue();

    $saved = SavedEvent::query()->create([
        'user_id' => $user->getKey(),
        'occurrence_id' => $occurrence->getKey(),
    ]);

    expect(Gate::forUser($user)->allows('view', $saved))->toBeTrue()
        ->and(Gate::forUser($user)->allows('delete', $saved))->toBeTrue()
        ->and($user->savedEvents()->count())->toBe(1);
});

it('consente a un account non verificato di seguire un locale', function (): void {
    $user = User::factory()->unverified()->create();
    $venue = Venue::factory()->approved()->create();

    expect(Gate::forUser($user)->allows('create', Follow::class))->toBeTrue();

    $follow = Follow::query()->create([
        'user_id' => $user->getKey(),
        'followable_type' => $venue->getMorphClass(),
        'followable_id' => $venue->getKey(),
    ]);

    expect(Gate::forUser($user)->allows('delete', $follow))->toBeTrue()
        ->and($user->follows()->count())->toBe(1);
});

it('esclude dalle notifiche un account non verificato', function (): void {
    $unverified = User::factory()->unverified()->create();
    $verified = User::factory()->create();

    expect($unverified->canReceiveNotifications())->toBeFalse()
        ->and($verified->canReceiveNotifications())->toBeTrue();
});

it('riammette alle notifiche l account appena verifica l email', function (): void {
    $user = User::factory()->unverified()->create();

    expect($user->canReceiveNotifications())->toBeFalse();

    $user->markEmailAsVerified();

    expect($user->fresh()?->canReceiveNotifications())->toBeTrue();
});

it('lascia comunque partire l email di verifica verso l account non verificato', function (): void {
    // È l'unico messaggio che deve raggiungerlo: bloccarlo insieme alle
    // notifiche renderebbe la verifica stessa impossibile da completare.
    Notification::fake();

    $user = User::factory()->unverified()->create();
    $user->sendEmailVerificationNotification();

    // La notifica è la nostra e non quella di Laravel: i testi del framework
    // sono in inglese, e in questo progetto ogni stringa sta in lang/it.
    Notification::assertSentTo($user, VerifyEmailLink::class);
});

<?php

declare(strict_types=1);

use App\Enums\AdmissionStatus;
use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\User;
use App\Services\Ticketing\TicketingService;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * La finestra di conferma sulla lista d'attesa.
 *
 * La coda in ordine di arrivo e la promozione a gruppi interi esistevano già e
 * sono coperte da `TicketingTest`. Qui si protegge solo ciò che è nuovo: un
 * posto promosso non è ancora un posto acquisito, e se nessuno lo conferma
 * torna a chi aspetta invece di restare vuoto.
 */
beforeEach(function (): void {
    $this->city = testCity();
    $this->category = testCategory();
    freezeLocal($this->city, '2026-09-20 12:00');
    $this->date = occurrenceAtLocal($this->city, $this->category, '2026-09-27 21:00', '2026-09-27 23:00', occurrence: [
        'booking_enabled' => true, 'booking_capacity' => 1, 'booking_waitlist' => true, 'booking_limit' => 2,
    ]);
    $this->date->effectiveVenue()->forceFill(['ticketing_enabled' => true])->save();
    Notification::fake();
});

afterEach(function (): void {
    Carbon::setTestNow();
});

function reserveSeat(User $user, bool $waitlist = true): Booking
{
    return app(TicketingService::class)->reserve($user, test()->date->fresh(), ['Nome Cognome'], (string) Str::uuid(), $waitlist);
}

it('dà al promosso una finestra per confermare, non il posto per sempre', function (): void {
    $first = reserveSeat(User::factory()->create(), false);
    $second = reserveSeat(User::factory()->create());

    expect($second->status)->toBe(BookingStatus::Waitlisted);

    app(TicketingService::class)->cancel($first, $first->user);

    $second->refresh();
    expect($second->status)->toBe(BookingStatus::Confirmed)
        ->and($second->promotion_expires_at)->not->toBeNull()
        ->and($second->promotion_expires_at->diffInHours(CarbonImmutable::now()))->toBeLessThanOrEqual(12);
});

it('libera il posto quando nessuno conferma, e la coda avanza', function (): void {
    $first = reserveSeat(User::factory()->create(), false);
    $second = reserveSeat(User::factory()->create());
    $third = reserveSeat(User::factory()->create());

    app(TicketingService::class)->cancel($first, $first->user);
    expect($second->fresh()->status)->toBe(BookingStatus::Confirmed);

    Carbon::setTestNow(CarbonImmutable::now()->addHours(13));
    expect(app(TicketingService::class)->expirePromotions())->toBe(1);

    expect($second->fresh()->status)->toBe(BookingStatus::Cancelled)
        ->and($second->fresh()->cancellation_reason)->toBe(__('ticketing.errors.promotion_expired'))
        ->and($third->fresh()->status)->toBe(BookingStatus::Confirmed)
        ->and($third->fresh()->tickets->first()->status)->toBe(AdmissionStatus::Valid);
});

it('tiene il posto a chi conferma in tempo', function (): void {
    $first = reserveSeat(User::factory()->create(), false);
    $second = reserveSeat(User::factory()->create());
    app(TicketingService::class)->cancel($first, $first->user);

    app(TicketingService::class)->confirmPromotion($second->fresh(), $second->user);

    Carbon::setTestNow(CarbonImmutable::now()->addHours(13));
    app(TicketingService::class)->expirePromotions();

    expect($second->fresh()->status)->toBe(BookingStatus::Confirmed)
        ->and($second->fresh()->promotion_expires_at)->toBeNull();
});

it('non lascia confermare a nome di un altro né dopo la scadenza', function (): void {
    $first = reserveSeat(User::factory()->create(), false);
    $second = reserveSeat(User::factory()->create());
    app(TicketingService::class)->cancel($first, $first->user);

    expect(fn () => app(TicketingService::class)->confirmPromotion($second->fresh(), User::factory()->create()))
        ->toThrow(HttpException::class);

    Carbon::setTestNow(CarbonImmutable::now()->addHours(13));
    expect(fn () => app(TicketingService::class)->confirmPromotion($second->fresh(), $second->user))
        ->toThrow(ValidationException::class);
});

it('non promuove più nessuno a ridosso dell inizio', function (): void {
    $first = reserveSeat(User::factory()->create(), false);
    $second = reserveSeat(User::factory()->create());

    Carbon::setTestNow($this->date->starts_at->copy()->subHour());
    app(TicketingService::class)->cancel($first, $first->user);

    expect($second->fresh()->status)->toBe(BookingStatus::Waitlisted);
});

it('dice a chi aspetta a che punto è la coda', function (): void {
    reserveSeat(User::factory()->create(), false);
    $second = reserveSeat(User::factory()->create());
    $third = reserveSeat(User::factory()->create());

    expect(app(TicketingService::class)->queuePosition($second))->toBe(1)
        ->and(app(TicketingService::class)->queuePosition($third))->toBe(2);
});

it('mostra il pulsante di conferma a chi è stato promosso', function (): void {
    $first = reserveSeat(User::factory()->create(), false);
    $second = reserveSeat(User::factory()->create());
    app(TicketingService::class)->cancel($first, $first->user);

    $this->actingAs($second->user)->get(route('tickets.index'))->assertOk()
        ->assertSee(__('ticketing.promotion_confirm'));

    $this->actingAs($second->user)->post(route('tickets.confirm', $second))->assertRedirect();
    expect($second->fresh()->promotion_expires_at)->toBeNull();
});

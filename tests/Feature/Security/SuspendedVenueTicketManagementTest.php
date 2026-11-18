<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Models\User;
use App\Models\Venue;
use App\Notifications\BookingMessageSent;
use App\Services\Ticketing\TicketingService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

/**
 * Un provvedimento sul locale toglie la gestione dei biglietti ovunque, non
 * solo nel pannello (D52): prima il proprietario di un locale sospeso o
 * rifiutato esportava i prenotati, scriveva a tutti e cambiava capienza e
 * personale da /gestione-biglietti e dall'API.
 */
beforeEach(function (): void {
    Notification::fake();
    $this->travelTo(now()->setDate(2026, 11, 13)->setTime(12, 0));
    (new RolesAndPermissionsSeeder)->run();
    $city = testCity();
    $this->venue = Venue::factory()->approved()->create(['city_id' => $city->getKey(), 'ticketing_enabled' => true]);
    $this->date = occurrenceAt($city, testCategory(), '2026-11-20 18:00:00', '2026-11-20 22:00:00', ['booking_enabled' => true, 'booking_capacity' => 10], ['organizer_id' => null], $this->venue);
    $this->owner = User::factory()->create();
    $this->venue->members()->attach($this->owner, ['role' => 'owner', 'accepted_at' => now()]);
    $this->booker = User::factory()->create(['email' => 'prenotato@example.test']);
    app(TicketingService::class)->reserve($this->booker, $this->date, ['Prenotato'], (string) Str::uuid(), false);
});

it('lascia gestire i biglietti al proprietario di un locale senza provvedimenti', function (): void {
    $this->actingAs($this->owner)->get(route('ticketing.manage.show', $this->date))->assertOk();
    $this->actingAs($this->owner)->get(route('ticketing.manage.export', $this->date))->assertOk();
});

it('toglie la gestione dei biglietti al proprietario di un locale sospeso o rifiutato', function (string $status): void {
    $this->venue->update(['status' => $status]);
    $owner = $this->owner->fresh();

    $this->actingAs($owner)->get(route('ticketing.manage.show', $this->date))->assertForbidden();
    $this->actingAs($owner)->get(route('ticketing.manage.export', $this->date))->assertForbidden();
    $this->actingAs($owner)->post(route('ticketing.manage.configure', $this->date), ['booking_enabled' => 1, 'booking_capacity' => 7, 'booking_limit' => 4, 'booking_waitlist' => 0])->assertForbidden();
    $this->actingAs($owner)->post(route('ticketing.manage.staff', $this->date), ['email' => 'prenotato@example.test'])->assertForbidden();
    expect($this->date->fresh()->booking_capacity)->toBe(10)
        ->and($this->date->checkinStaff()->whereKey($this->booker->id)->exists())->toBeFalse();

    Sanctum::actingAs($owner, ['*']);
    $this->postJson('/api/v1/ticketing/'.$this->date->id.'/message', ['subject' => 'Oggetto', 'body' => 'Testo'])->assertForbidden();
    expect(Notification::sent($this->booker, BookingMessageSent::class))->toBeEmpty();
    $this->getJson('/api/v1/management/dates/'.$this->date->id)->assertForbidden();
    expect($this->getJson('/api/v1/management/dates')->json('data'))->toBe([]);
})->with(['suspended', 'rejected']);

it('lascia la gestione allo staff della piattaforma anche se il locale è sospeso', function (): void {
    $this->venue->update(['status' => 'suspended']);
    $admin = User::factory()->create();
    $admin->assignRole(UserRole::Admin->value);

    $this->actingAs($admin)->get(route('ticketing.manage.show', $this->date))->assertOk();
});

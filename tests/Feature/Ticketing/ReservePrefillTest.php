<?php

declare(strict_types=1);

use App\Enums\OccurrenceStatus;
use App\Models\Booking;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

require_once __DIR__.'/../Flows/Ticketing/TicketingFlowSupport.php';

/*
 * Il modulo di prenotazione parte già compilato con i dati della stessa
 * persona, mostra il riepilogo dell'evento con la locandina e la casella
 * della lista d'attesa solo quando i posti potrebbero non bastare.
 */

beforeEach(function (): void {
    Notification::fake();
    (new RolesAndPermissionsSeeder)->run();
});

it('divide il nome dell account in nome e cognome e mette chi prenota come primo partecipante', function (): void {
    ['date' => $date] = tktFlowScenario();
    $giulia = User::factory()->create(['name' => 'Giulia Rossi', 'first_name' => null, 'last_name' => null]);

    $html = $this->actingAs($giulia)->get(route('tickets.create', $date))->assertOk()->getContent();

    expect($html)->toContain('name="booker[first_name]" value="Giulia"')
        ->toContain('name="booker[last_name]" value="Rossi"')
        ->toContain('name="attendees[0][first_name]" data-name-part="first_name" value="Giulia"')
        ->toContain('name="attendees[0][last_name]" data-name-part="last_name" value="Rossi"')
        ->toContain('data-self-toggle')
        ->toContain(e(__('ticketing.reserve_page.self_toggle')))
        ->toContain(e(__('ticketing.reserve_page.counter', ['count' => 1, 'limit' => 4])));
});

it('preferisce nome e cognome del profilo e riprende il telefono dall ultima prenotazione', function (): void {
    ['date' => $date] = tktFlowScenario(['booking_fields' => ['phone' => 'required']]);
    $user = User::factory()->create(['name' => 'Soprannome', 'first_name' => 'Anna Maria', 'last_name' => 'De Rossi']);
    $older = occurrenceAt($date->event->city, $date->event->category, '2026-09-01 19:00:00', '2026-09-01 22:00:00');
    Booking::query()->create([
        'occurrence_id' => $older->id, 'user_id' => $user->id, 'request_key' => (string) Str::uuid(), 'request_hash' => hash('sha256', 'x'),
        'status' => 'confirmed', 'booker_data' => ['first_name' => 'Anna', 'last_name' => 'Vecchia', 'phone' => '+39 333 0000000'],
    ]);
    // I dati di un'altra persona non entrano mai nel modulo.
    Booking::query()->create([
        'occurrence_id' => $older->id, 'user_id' => User::factory()->create()->id, 'request_key' => (string) Str::uuid(), 'request_hash' => hash('sha256', 'y'),
        'status' => 'confirmed', 'booker_data' => ['first_name' => 'Altra', 'last_name' => 'Persona', 'phone' => '+39 999 9999999'],
    ]);

    $html = $this->actingAs($user)->get(route('tickets.create', $date))->assertOk()->getContent();

    expect($html)->toContain('value="Anna Maria"')->toContain('value="De Rossi"')
        ->toContain('value="+39 333 0000000"')
        ->not->toContain('+39 999 9999999')
        ->not->toContain('value="Vecchia"');
});

it('mostra il riepilogo con la locandina o il segnaposto e il prezzo', function (): void {
    ['date' => $date] = tktFlowScenario();

    $this->actingAs(User::factory()->create())->get(route('tickets.create', $date))->assertOk()
        ->assertSee('data-reserve-summary', false)
        ->assertSee('data-event-row', false)
        ->assertSee('data-service-page', false)
        ->assertSee('Concerto al chiostro');
});

it('non mostra la lista d attesa con posti liberi e la mostra quando i posti non bastano', function (): void {
    ['date' => $date] = tktFlowScenario(['booking_capacity' => 10, 'booking_limit' => 4]);
    $user = User::factory()->create();

    $this->actingAs($user)->get(route('tickets.create', $date))->assertOk()
        ->assertDontSee('data-waitlist-option', false)->assertDontSee('name="waitlist"', false);

    $date->update(['booking_capacity' => 2]);
    $this->get(route('tickets.create', $date))->assertOk()->assertSee('name="waitlist"', false);

    // Senza lista d'attesa la casella non c'è nemmeno con pochi posti.
    $date->update(['booking_waitlist' => false]);
    $this->get(route('tickets.create', $date))->assertOk()->assertDontSee('name="waitlist"', false);
});

it('racconta i tre stati di vendita: aperta, non ancora aperta, chiusa', function (): void {
    ['date' => $date] = tktFlowScenario();
    $user = User::factory()->create();

    $this->actingAs($user)->get(route('tickets.create', $date))->assertOk()
        ->assertSee('data-reserve-availability="available"', false)
        ->assertSee('data-reservation-form', false);

    $date->update(['booking_opens_at' => now()->addDays(2)]);
    $this->get(route('tickets.create', $date))->assertOk()
        ->assertSee('data-reserve-availability="not_yet_on_sale"', false)
        ->assertSee(__('ticketing.reserve_page.states.not_yet_on_sale'))
        ->assertDontSee('data-reservation-form', false);

    $date->update(['booking_opens_at' => null, 'status' => OccurrenceStatus::Cancelled]);
    $this->get(route('tickets.create', $date))->assertOk()
        ->assertSee('data-reserve-availability="closed"', false)
        ->assertSee(__('ticketing.reserve_page.closed_reason'))
        ->assertDontSee('data-reservation-form', false);
});

it('accetta lo stesso invio di prima: la casella «Partecipo anch io» non cambia il contratto', function (): void {
    ['date' => $date] = tktFlowScenario();
    $user = User::factory()->create();

    $this->actingAs($user)->post(route('tickets.store', $date), tktFlowWebPayload([['Giulia', 'Rossi'], ['Marco', 'Bianchi']], ['self_attending' => '1']))
        ->assertRedirect(route('tickets.index'))->assertSessionHasNoErrors();

    expect(Booking::query()->sole()->tickets()->pluck('attendee_name')->all())->toBe(['Giulia Rossi', 'Marco Bianchi']);
});

it('espone nome e cognome in /api/v1/me e la locandina nelle prenotazioni', function (): void {
    ['date' => $date] = tktFlowScenario();
    $user = User::factory()->create(['first_name' => 'Giulia', 'last_name' => 'Rossi']);
    tktFlowBook($user, $date, ['Giulia Rossi']);
    Sanctum::actingAs($user);

    $this->getJson('/api/v1/me')->assertOk()->assertJsonPath('data.first_name', 'Giulia')->assertJsonPath('data.last_name', 'Rossi');
    $bookings = $this->getJson('/api/v1/me/bookings')->assertOk();
    expect($bookings->json('data.0'))->toHaveKey('poster');

    Sanctum::actingAs(User::factory()->create(['first_name' => null, 'last_name' => null]));
    $this->getJson('/api/v1/me')->assertOk()->assertJsonPath('data.first_name', null)->assertJsonPath('data.last_name', null);
});

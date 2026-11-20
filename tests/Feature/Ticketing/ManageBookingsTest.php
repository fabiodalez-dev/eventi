<?php

declare(strict_types=1);

use App\Enums\AdmissionStatus;
use App\Models\User;
use App\Services\Ticketing\TicketingService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;

require_once __DIR__.'/../Flows/Ticketing/TicketingFlowSupport.php';

/*
 * La gestione di una data: i cinque numeri, l'elenco delle prenotazioni con
 * il filtro per stato, i moduli che spariscono a data conclusa e un numero
 * di query che non cresce con le prenotazioni. Più i numeri e la locandina
 * che le stesse date danno all'app.
 */

beforeEach(function (): void {
    Notification::fake();
    (new RolesAndPermissionsSeeder)->run();
});

/** Tre prenotazioni: una confermata con un ingresso, una annullata, una in lista d'attesa. */
function manageBookingsScene(): array
{
    $scene = tktFlowScenario(['booking_capacity' => 3, 'booking_limit' => 4]);
    $date = $scene['date'];
    $entered = tktFlowBook(User::factory()->create(), $date, ['Anna Entrata', 'Bruno Atteso']);
    $cancelled = tktFlowBook(User::factory()->create(), $date, ['Carla Annullata']);
    app(TicketingService::class)->cancel($cancelled, $cancelled->user, null);
    $waiting = tktFlowBook(User::factory()->create(), $date, ['Dario Coda', 'Elena Coda'], true);
    $entered->tickets()->where('attendee_name', 'Anna Entrata')->update(['status' => AdmissionStatus::CheckedIn, 'checked_in_at' => now()]);

    return [...$scene, 'entered' => $entered, 'cancelled' => $cancelled, 'waiting' => $waiting];
}

it('mostra i cinque numeri della data e i posti occupati', function (): void {
    ['date' => $date, 'owner' => $owner] = manageBookingsScene();

    $counts = app(TicketingService::class)->counts([$date])[$date->id];
    expect($counts)->toBe(['confirmed' => 2, 'checked_in' => 1, 'waitlisted' => 2, 'cancelled' => 1, 'no_show' => 0, 'capacity' => 3]);

    $this->actingAs($owner)->get(route('ticketing.manage.show', $date))->assertOk()
        ->assertSee('data-ticket-statistics', false)
        ->assertSee(__('ticketing.manage_page.seats', ['used' => 2, 'capacity' => 3]))
        ->assertSeeInOrder(collect(['confirmed', 'checked_in', 'waitlisted', 'cancelled', 'no_show'])->map(fn (string $key): string => __('ticketing.manage_page.stats.'.$key))->all());
});

it('filtra le prenotazioni per stato e ignora i filtri sconosciuti', function (): void {
    ['date' => $date, 'owner' => $owner] = manageBookingsScene();
    $this->actingAs($owner);

    $this->get(route('ticketing.manage.show', ['occurrence' => $date, 'status' => 'cancelled']))->assertOk()
        ->assertSee('Carla Annullata')->assertDontSee('Anna Entrata')->assertDontSee('Dario Coda');
    $this->get(route('ticketing.manage.show', ['occurrence' => $date, 'status' => 'waitlisted']))->assertOk()
        ->assertSee('Dario Coda')->assertDontSee('Carla Annullata');
    $this->get(route('ticketing.manage.show', ['occurrence' => $date, 'status' => 'checked_in']))->assertOk()
        ->assertSee('Anna Entrata')->assertSee('Bruno Atteso')->assertDontSee('Dario Coda');
    $this->get(route('ticketing.manage.show', ['occurrence' => $date, 'status' => 'confirmed']))->assertOk()
        ->assertSee('Anna Entrata')->assertDontSee('Carla Annullata')->assertDontSee('Dario Coda');
    $this->get(route('ticketing.manage.show', ['occurrence' => $date, 'status' => 'qualunque']))->assertOk()
        ->assertSee('Anna Entrata')->assertSee('Carla Annullata')->assertSee('Dario Coda');
    // Il filtro e la ricerca si sommano, e la ricerca vuota lo dice.
    $this->get(route('ticketing.manage.show', ['occurrence' => $date, 'status' => 'waitlisted', 'q' => 'Entrata']))->assertOk()
        ->assertDontSee('Anna Entrata')->assertSee(__('ticketing.manage_page.empty_search'));
    // «Entrata» e non «Anna»: il generatore può dare a chi è in coda un nome come Giovanna.
    $this->get(route('ticketing.manage.show', ['occurrence' => $date, 'q' => 'Entrata']))->assertOk()->assertSee('Anna Entrata');
});

it('a data conclusa nasconde i moduli e lo dice', function (): void {
    ['date' => $date, 'owner' => $owner] = manageBookingsScene();
    $this->actingAs($owner);

    $open = $this->get(route('ticketing.manage.show', $date))->assertOk();
    $open->assertSee(route('ticketing.manage.configure', $date), false)->assertSee(route('ticketing.manage.guest', $date), false)
        ->assertSee(route('ticketing.manage.message', $date), false)->assertSee('data-ticket-scanner', false)
        ->assertDontSee(__('ticketing.manage_page.ended'));

    $this->travelTo($date->effective_ends_at->copy()->addHour());
    $this->get(route('ticketing.manage.show', $date))->assertOk()
        ->assertSee(__('ticketing.manage_page.ended'))
        ->assertDontSee(route('ticketing.manage.configure', $date), false)
        ->assertDontSee(route('ticketing.manage.guest', $date), false)
        ->assertDontSee(route('ticketing.manage.message', $date), false)
        ->assertDontSee(route('ticketing.manage.staff', $date), false)
        ->assertDontSee('data-ticket-scanner', false)
        // Elenco, CSV e lista da stampare restano.
        ->assertSee('Anna Entrata')->assertSee(route('ticketing.manage.export', $date), false);
    $this->get(route('ticketing.manage.scanner', $date))->assertOk()->assertSee(__('ticketing.scanner_page.ended'))->assertDontSee('data-ticket-scanner', false);
});

it('non fa più query quando le prenotazioni crescono', function (): void {
    ['date' => $date, 'owner' => $owner] = manageBookingsScene();
    $this->actingAs($owner)->get(route('ticketing.manage.show', $date))->assertOk();

    $count = function () use ($date): int {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->get(route('ticketing.manage.show', $date))->assertOk();
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $queries;
    };
    $before = $count();
    $date->update(['booking_capacity' => null]);
    foreach (range(1, 4) as $i) {
        tktFlowBook(User::factory()->create(), $date, ['Persona '.$i, 'Amica '.$i]);
    }
    expect($count())->toBe($before);
});

it('nel riepilogo mostra posti, lista d attesa e i pulsanti giusti per ruolo', function (): void {
    ['date' => $date, 'owner' => $owner] = manageBookingsScene();
    $staff = User::factory()->create();
    $date->checkinStaff()->attach($staff);

    $this->actingAs($owner)->get(route('ticketing.manage.index'))->assertOk()
        ->assertSee('data-event-row', false)
        ->assertSee(__('ticketing.dashboard_page.seats', ['used' => 2, 'capacity' => 3]))
        ->assertSee(__('ticketing.dashboard_page.waitlisted', ['count' => 2]))
        ->assertSee(route('ticketing.manage.show', $date), false)
        ->assertSee(route('ticketing.manage.scanner', $date), false);

    $this->actingAs($staff)->get(route('ticketing.manage.index'))->assertOk()
        ->assertSee(__('ticketing.dashboard_page.role_staff'))
        ->assertSee(route('ticketing.manage.scanner', $date), false)
        ->assertDontSee('href="'.route('ticketing.manage.show', $date).'"', false);
});

it('dà all app la locandina e i numeri delle date da gestire', function (): void {
    ['date' => $date, 'owner' => $owner] = manageBookingsScene();
    Sanctum::actingAs($owner);

    $list = $this->getJson('/api/v1/management/dates')->assertOk()
        ->assertJsonPath('data.0.id', $date->id)
        ->assertJsonPath('data.0.counts.confirmed', 2)
        ->assertJsonPath('data.0.counts.checked_in', 1)
        ->assertJsonPath('data.0.counts.waitlisted', 2)
        ->assertJsonPath('data.0.counts.cancelled', 1)
        ->assertJsonPath('data.0.counts.no_show', 0)
        ->assertJsonPath('data.0.counts.capacity', 3);
    expect($list->json('data.0'))->toHaveKey('poster');

    $this->getJson('/api/v1/management/dates/'.$date->id)->assertOk()
        ->assertJsonPath('data.counts.confirmed', 2)
        ->assertJsonStructure(['data' => ['poster', 'counts' => ['confirmed', 'checked_in', 'waitlisted', 'cancelled', 'no_show', 'capacity']]]);
});

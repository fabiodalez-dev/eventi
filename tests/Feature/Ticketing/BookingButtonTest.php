<?php

declare(strict_types=1);

use App\Enums\OccurrenceStatus;
use App\Models\Venue;
use App\Services\Ticketing\TicketingService;
use App\Support\EventUrl;
use Carbon\Carbon;

/**
 * Il pulsante «Prenota il tuo posto» e la pagina di prenotazione devono dire la
 * stessa cosa.
 *
 * La scheda si accontentava dei due interruttori di configurazione —
 * `booking_enabled` sulla data e `ticketing_enabled` sul locale — che dicono se
 * le prenotazioni *esistono*, non se sono aperte adesso. `isOpen()` aggiunge sei
 * condizioni, fra cui che la data non sia già iniziata: il pulsante compariva
 * su una serata di stamattina e la pagina rispondeva «Le prenotazioni non sono
 * aperte per questa data».
 *
 * Il dato leggibile dalle macchine era invece già corretto: `PublicOffers` usa
 * `sale_state` da `availability()`. Sbagliava solo la versione per le persone.
 */
beforeEach(function (): void {
    $this->city = testCity();
    $this->category = testCategory();
    freezeLocal($this->city, '2026-09-25 13:00');
    $this->locale = Venue::factory()->approved()->create(['city_id' => $this->city->getKey(), 'ticketing_enabled' => true]);
});

afterEach(fn () => Carbon::setTestNow());

function dataPrenotabile(string $inizioLocale, array $extra = []): App\Models\EventOccurrence
{
    return occurrenceAtLocal(test()->city, test()->category, $inizioLocale, null,
        ['booking_enabled' => true, 'booking_capacity' => 20, ...$extra], [], test()->locale);
}

it('mostra il pulsante solo quando la prenotazione è davvero aperta', function (): void {
    $data = dataPrenotabile('2026-09-30 21:00');

    expect(app(TicketingService::class)->isOpen($data))->toBeTrue();

    $this->get(EventUrl::occurrence($data))->assertOk()
        ->assertSee(__('ticketing.reserve'))
        ->assertDontSee(__('ticketing.card.closed'));

    // La pagina che il pulsante apre accetta davvero la richiesta.
    $this->actingAs(App\Models\User::factory()->create())->get(route('tickets.create', $data))->assertOk();
});

it('sulla data di oggi già iniziata scrive che sono chiuse, invece di offrire il pulsante', function (): void {
    /* Il caso vero: l'elenco tiene le date di oggi per tutta la giornata, quindi
       alle 13 la serata delle 10:15 è ancora in pagina. */
    $data = dataPrenotabile('2026-09-25 10:15');

    expect(app(TicketingService::class)->isOpen($data))->toBeFalse();

    $this->get(EventUrl::occurrence($data))->assertOk()
        ->assertSee(__('ticketing.card.closed'))
        ->assertDontSee(__('ticketing.reserve'));
});

it('scrive da quando si prenota se la finestra non è ancora aperta', function (): void {
    $data = dataPrenotabile('2026-10-10 21:00', ['booking_opens_at' => now()->addDays(3)]);

    $this->get(EventUrl::occurrence($data))->assertOk()
        ->assertSee(__('ticketing.card.opens_on', ['date' => app(App\Support\DateFormatter::class)->instantDate($data->booking_opens_at)]))
        ->assertDontSee(__('ticketing.reserve'));
});

it('scrive che sono chiuse quando il termine è passato pur essendo la data futura', function (): void {
    $data = dataPrenotabile('2026-10-10 21:00', ['booking_closes_at' => now()->subHour()]);

    $this->get(EventUrl::occurrence($data))->assertOk()
        ->assertSee(__('ticketing.card.closed'))
        ->assertDontSee(__('ticketing.reserve'));
});

it('scrive che i posti sono esauriti quando la data è marcata esaurita', function (): void {
    $data = dataPrenotabile('2026-10-10 21:00', ['status' => OccurrenceStatus::SoldOut]);

    $this->get(EventUrl::occurrence($data))->assertOk()
        ->assertSee(__('ticketing.card.sold_out'))
        ->assertDontSee(__('ticketing.reserve'));
});

it('non scrive niente dove le prenotazioni non esistono', function (): void {
    $senzaPrenotazioni = occurrenceAtLocal($this->city, $this->category, '2026-10-10 21:00', null, [], [], $this->locale);

    $this->get(EventUrl::occurrence($senzaPrenotazioni))->assertOk()
        ->assertDontSee(__('ticketing.reserve'))
        ->assertDontSee(__('ticketing.card.closed'))
        ->assertDontSee(__('ticketing.card.sold_out'));
});

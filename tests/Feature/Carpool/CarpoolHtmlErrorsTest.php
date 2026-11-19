<?php

declare(strict_types=1);

use App\Models\RideOffer;
use App\Models\RideRequest;
use Illuminate\Support\Str;

/*
 * Le pagine web dei passaggi: un'azione rifiutata dal servizio torna al modulo
 * con i dati scritti e il messaggio accanto al campo giusto; l'app riceve la
 * stessa risposta di sempre (409/422 in JSON). Più le scelte di pagina del
 * ridisegno: scheda predefinita, conferma della rinuncia, ritorno all'offerta
 * dopo Accetta/Rifiuta, posti al singolare e al plurale, locandina.
 */

function cpHtmlOfferForm($test, array $data = []): array
{
    return ['request_key' => (string) Str::uuid(), 'occurrence_id' => $test->date->id, 'leg' => 'outbound', 'zone' => 'Prato della Valle',
        'departure_at' => '2026-10-10T20:00', 'capacity' => 3, 'accessibility' => 'not_specified', 'driver_declaration' => '1', ...$data];
}

it('torna al modulo con il messaggio sotto la partenza e i dati scritti quando la partenza è fuori finestra', function (): void {
    $this->actingAs($this->driver->fresh(), 'web');

    $this->from(route('carpool.create', $this->date))
        ->post(route('carpool.action', 'offer'), cpHtmlOfferForm($this, ['departure_at' => '2026-10-20T20:00', 'zone' => 'Stazione FS', 'note' => 'Auto grigia']))
        ->assertRedirect(route('carpool.create', $this->date))
        ->assertSessionHasErrors(['departure_at' => __('carpool.errors.departure')])
        ->assertSessionHasInput('zone', 'Stazione FS')
        ->assertSessionHasInput('note', 'Auto grigia');

    expect(RideOffer::count())->toBe(0);

    // Seguendo il ritorno, il modulo mostra il messaggio accanto al campo, con i dati già scritti.
    $this->followingRedirects()->from(route('carpool.create', $this->date))
        ->post(route('carpool.action', 'offer'), cpHtmlOfferForm($this, ['departure_at' => '2026-10-20T20:00', 'zone' => 'Stazione FS']))
        ->assertOk()
        ->assertSee('id="offerta-partenza-errore"', false)
        ->assertSee(__('carpool.errors.departure'))
        ->assertSee('value="Stazione FS"', false)
        ->assertSee('aria-invalid="true"', false);
});

it('torna al modulo con il messaggio quando la stessa tratta ha già un passaggio', function (): void {
    cpOffer($this);
    $this->actingAs($this->driver->fresh(), 'web');

    $this->from(route('carpool.create', $this->date))
        ->post(route('carpool.action', 'offer'), cpHtmlOfferForm($this, ['zone' => 'Arcella']))
        ->assertRedirect(route('carpool.create', $this->date))
        ->assertSessionHasErrors(['form' => __('carpool.errors.occupied')])
        ->assertSessionHasInput('zone', 'Arcella');

    expect(RideOffer::count())->toBe(1);
});

it('senza un indirizzo di provenienza torna comunque al modulo della data', function (): void {
    $this->actingAs($this->driver->fresh(), 'web');

    $this->post(route('carpool.action', 'offer'), cpHtmlOfferForm($this, ['departure_at' => '2026-10-20T20:00']))
        ->assertRedirect(route('carpool.create', $this->date));
});

it('ai posti non disponibili risponde sotto il campo dei posti della richiesta', function (): void {
    $offer = cpOffer($this, data: ['capacity' => 1]);
    $this->actingAs($this->passenger->fresh(), 'web');

    $this->from(route('carpool.offer', $offer))
        ->post(route('carpool.action', 'request'), ['request_key' => (string) Str::uuid(), 'offer_id' => $offer->id, 'revision' => 1, 'seats' => 2, 'companions_adult' => '1', 'note' => 'Siamo in due'])
        ->assertRedirect(route('carpool.offer', $offer))
        ->assertSessionHasErrors(['seats' => __('carpool.errors.capacity')])
        ->assertSessionHasInput('note', 'Siamo in due');

    expect(RideRequest::count())->toBe(0);
});

it('dall\'app gli stessi rifiuti restano 422 e 409 in JSON', function (): void {
    cpAction($this, $this->driver, 'offer', ['occurrence_id' => $this->date->id, 'leg' => 'outbound', 'zone' => 'Stazione', 'departure_at' => '2026-10-20T18:00:00Z',
        'capacity' => 3, 'accessibility' => 'not_specified', 'driver_declaration' => true])
        ->assertStatus(422)->assertJsonPath('error.message', __('carpool.errors.departure'));

    cpOffer($this);
    cpAction($this, $this->driver, 'offer', ['occurrence_id' => $this->date->id, 'leg' => 'outbound', 'zone' => 'Arcella', 'departure_at' => '2026-10-10T18:00:00Z',
        'capacity' => 3, 'accessibility' => 'not_specified', 'driver_declaration' => true])
        ->assertStatus(409)->assertJsonPath('error.message', __('carpool.errors.occupied'));
});

it('il modulo dell\'offerta limita la partenza alla finestra consentita e lo spiega', function (): void {
    $this->actingAs($this->driver->fresh(), 'web');

    // La data va dalle 21 a mezzanotte del 10/10, ora di Padova (19-22 UTC): l'andata parte dal giorno
    // prima, il ritorno arriva al giorno dopo; senza script il campo accetta l'unione delle due.
    $this->get(route('carpool.create', $this->date))->assertOk()
        ->assertSee('min="2026-10-09T21:00"', false)
        ->assertSee('max="2026-10-12T00:00"', false)
        ->assertSee('data-ride-windows', false)
        ->assertSee(__('carpool.web.departure_window', ['outbound_from' => '09/10 21:00', 'outbound_to' => '11/10 00:00', 'return_from' => '10/10 21:00', 'return_to' => '12/10 00:00']));
});

it('apre la prima scheda con contenuti: con una sola richiesta, «Cerco»', function (): void {
    cpRequest($this, cpOffer($this));
    $this->actingAs($this->passenger->fresh(), 'web');

    $html = $this->get(route('carpool.index'))->assertOk()
        ->assertSee('data-request-item', false)
        ->assertDontSee(__('carpool.web.empty.offered.title'))->getContent();
    expect($html)->toMatch('~href="[^"]*tab=requested"\s+aria-current="page"~');

    // Una scheda scelta resta quella scelta, anche vuota: lì c'è l'invito a cominciare.
    $this->get(route('carpool.index', ['tab' => 'offered']))->assertOk()->assertSee(__('carpool.web.empty.offered.title'));
});

it('chiede conferma prima della rinuncia e mostra a che punto è la richiesta', function (): void {
    $ride = cpRequest($this, cpOffer($this));
    $this->actingAs($this->passenger->fresh(), 'web');

    $this->get(route('carpool.request', $ride))->assertOk()
        ->assertSee('data-confirm="'.e(__('carpool.confirm_withdraw')).'"', false)
        ->assertSee('data-step="sent" data-step-state="done"', false)
        ->assertSee('data-step="accepted" data-step-state="current"', false);
});

it('dopo Accetta e Rifiuta il conducente resta sull\'offerta', function (): void {
    $offer = cpOffer($this);
    $first = cpRequest($this, $offer);
    $other = carpoolPerson();
    $second = cpRequest($this, $offer, $other);
    $this->actingAs($this->driver->fresh(), 'web');

    $this->post(route('carpool.action', 'accept'), ['request_key' => (string) Str::uuid(), 'request_id' => $first->id])->assertRedirect(route('carpool.offer', $offer));
    $this->post(route('carpool.action', 'decline'), ['request_key' => (string) Str::uuid(), 'request_id' => $second->id])->assertRedirect(route('carpool.offer', $offer));

    $this->get(route('carpool.offer', $offer))->assertOk()
        ->assertSee(__('carpool.web.confirmed_title'))
        ->assertSee(__('carpool.web.history_title'))
        ->assertSee(__('carpool.web.requests_empty'));
});

it('scrive i posti al singolare e al plurale', function (): void {
    expect(trans_choice('carpool.web.seats_left', 1, ['count' => 1]))->toBe('1 posto libero')
        ->and(trans_choice('carpool.web.seats_left', 2, ['count' => 2]))->toBe('2 posti liberi')
        ->and(trans_choice('carpool.web.seats_left', 0, ['count' => 0]))->toBe('Nessun posto libero')
        ->and(trans_choice('carpool.web.seats_requested', 1, ['count' => 1]))->toBe('1 posto richiesto')
        ->and(trans_choice('carpool.web.seats_requested', 3, ['count' => 3]))->toBe('3 posti richiesti');

    $offer = cpOffer($this, data: ['capacity' => 2]);
    cpRequest($this, $offer);
    $this->actingAs($this->driver->fresh(), 'web');
    $this->get(route('carpool.offer', $offer))->assertOk()->assertSee('1 posto richiesto');
});

it('mette la locandina nelle pagine e la restituisce all\'app nell\'offerta', function (): void {
    $offer = cpOffer($this);
    $this->actingAs($this->passenger->fresh(), 'web');

    $this->get(route('carpool.offer', $offer))->assertOk()->assertSee('data-event-row', false);
    $this->get(route('carpool.dates', $this->date))->assertOk()->assertSee('data-event-row', false)->assertSee('data-offer-row', false);

    cpAction($this, $this->passenger, 'request', ['offer_id' => $offer->id, 'revision' => 1, 'seats' => 1])->assertOk();
    $this->getJson('/api/v1/carpool/offers/'.$offer->id)->assertOk()->assertJsonStructure(['data' => ['offer' => ['poster']]]);
});

it('usa la colonna di servizio con un solo h1 in tutte le pagine dei passaggi', function (): void {
    $offer = cpOffer($this);
    $ride = cpRequest($this, $offer);
    cpAction($this, $this->driver, 'accept', ['request_id' => $ride->id])->assertOk();
    $chat = $ride->fresh()->conversation;
    $this->actingAs($this->passenger->fresh(), 'web');

    foreach ([route('carpool.index'), route('carpool.dates', $this->date), route('carpool.create', $this->date), route('carpool.offer', $offer),
        route('carpool.request', $ride), route('carpool.chats'), route('carpool.chat', $chat), route('carpool.requirements'), route('carpool.terms'), route('carpool.cases')] as $url) {
        $html = $this->get($url)->assertOk()->getContent();
        expect(substr_count($html, '<h1'))->toBe(1, $url)
            ->and($html)->toContain('data-service-page')
            ->and($html)->toContain('data-service-layout');
    }

    // Le regole: il titolo del testo diventa l'h1 della testata, una volta sola.
    $this->get(route('carpool.terms'))->assertOk()->assertSee('<h1 class="m-0 text-hero break-words text-ink">Regole dei passaggi gratuiti</h1>', false);
});

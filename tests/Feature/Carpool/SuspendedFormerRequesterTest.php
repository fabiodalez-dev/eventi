<?php

declare(strict_types=1);

use App\Services\Carpool\CommunitySafety;
use Laravel\Sanctum\Sanctum;

/**
 * Dopo una sospensione, niente contenuti nuovi (PIANO-CARPOOLING §213): prima
 * chi aveva fatto una richiesta continuava a leggere zona, tappe e orario
 * aggiornati dal conducente dopo la revoca.
 */
it('non mostra a un passeggero sospeso i dati correnti dell\'offerta che aveva richiesto', function (): void {
    $offer = cpOffer($this);
    $ride = cpRequest($this, $offer);
    app(CommunitySafety::class)->restrict(cpStaff('moderator'), $this->passenger, 'carpool', true, 'Sospensione di prova');
    $offer->refresh();
    cpAction($this, $this->driver, 'update', ['offer_id' => $offer->id, 'revision' => $offer->revision, 'zone' => 'Zona spostata dopo',
        'departure_at' => '2026-10-10T18:20:00Z', 'capacity' => 3, 'accessibility' => 'not_specified', 'stops' => ['Tappa nuova']])->assertOk();

    Sanctum::actingAs($this->passenger->fresh());
    $this->getJson('/api/v1/carpool/offers/'.$offer->id)->assertForbidden();
    $request = $this->getJson('/api/v1/carpool/requests/'.$ride->id);
    expect($request->json('data.offer.zone'))->toBeNull()
        ->and($request->json('data.offer.stops'))->toBe([]);
    expect(collect($this->getJson('/api/v1/carpool/me')->json('data.requests'))->pluck('offer.zone')->filter()->all())->toBe([]);
});

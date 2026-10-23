<?php

declare(strict_types=1);

use App\Enums\VenueNoticeKind;
use App\Models\Venue;
use App\Models\VenueNotice;
use Carbon\Carbon;

afterEach(function (): void {
    Carbon::setTestNow();
});

it('riporta nella scheda del locale l’avviso in vista oggi', function (): void {
    $city = testCity();
    freezeLocal($city, '2026-10-23 10:00:00');
    $venue = Venue::factory()->approved()->create(['city_id' => $city->getKey()]);
    VenueNotice::query()->create(['venue_id' => $venue->getKey(), 'kind' => VenueNoticeKind::Info, 'body' => 'Vecchio avviso', 'until' => '2026-10-01']);
    VenueNotice::query()->create(['venue_id' => $venue->getKey(), 'kind' => VenueNoticeKind::Pause, 'body' => 'Chiusi per lavori.', 'until' => '2026-11-15']);

    $this->getJson('/api/v1/venues/'.$venue->slug)
        ->assertOk()
        ->assertJsonPath('data.notice.kind', 'pause')
        ->assertJsonPath('data.notice.body', 'Chiusi per lavori.')
        ->assertJsonPath('data.notice.until', '2026-11-15');
});

it('riporta notice nullo senza avvisi in corso e non lo aggiunge alle liste', function (): void {
    $city = testCity();
    freezeLocal($city, '2026-10-23 10:00:00');
    $venue = Venue::factory()->approved()->create(['city_id' => $city->getKey()]);
    VenueNotice::query()->create(['venue_id' => $venue->getKey(), 'kind' => VenueNoticeKind::Closure, 'body' => 'Scaduto', 'until' => '2026-10-20']);

    $this->getJson('/api/v1/venues/'.$venue->slug)
        ->assertOk()
        ->assertJsonPath('data.notice', null);

    expect($this->getJson('/api/v1/venues')->assertOk()->json('data.0'))->not->toHaveKey('notice');
});

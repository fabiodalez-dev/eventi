<?php

declare(strict_types=1);

use App\Models\CarpoolProfile;
use App\Models\RideOffer;
use App\Models\RideRequest;
use App\Models\RideSearch;
use App\Models\User;
use Laravel\Sanctum\Sanctum;

// Ogni requisito mancante va respinto dal server, non soltanto nascosto dall'interfaccia:
// questi test chiamano le API direttamente, come farebbe chi salta i moduli del sito.

/** @return array<string, Closure(User): void> */
function cpMissingRequirements(): array
{
    return [
        'email' => fn (User $u) => $u->forceFill(['email_verified_at' => null])->save(),
        'whatsapp' => fn (User $u) => $u->forceFill(['whatsapp_verified_at' => null, 'whatsapp_phone_hash' => null])->save(),
        'adult' => fn (User $u) => CarpoolProfile::where('user_id', $u->id)->update(['adult_declared_at' => null]),
        'terms' => fn (User $u) => CarpoolProfile::where('user_id', $u->id)->update(['terms_version' => 'old-version']),
        'carpool suspension' => fn (User $u) => $u->forceFill(['carpool_suspended_at' => now()])->save(),
        'community suspension' => fn (User $u) => $u->forceFill(['community_suspended_at' => now()])->save(),
    ];
}

it('does not let a user self-declare adulthood without verified contacts', function (string $field): void {
    $user = carpoolPerson(false);
    $user->forceFill([$field => null])->save();

    cpAction($this, $user, 'declare', ['adult' => true, 'terms' => true, 'version' => config('carpool.terms_version')])->assertForbidden();

    expect(CarpoolProfile::where('user_id', $user->id)->whereNotNull('adult_declared_at')->exists())->toBeFalse();
})->with(['email_verified_at', 'whatsapp_verified_at', 'whatsapp_phone_hash']);

it('does not let a suspended user re-enable carpooling by declaring again', function (): void {
    $user = carpoolPerson(false);
    $user->forceFill(['carpool_suspended_at' => now()])->save();

    cpAction($this, $user, 'declare', ['adult' => true, 'terms' => true, 'version' => config('carpool.terms_version')])->assertForbidden();
});

it('refuses a seat request from a passenger missing any requirement', function (string $missing): void {
    $offer = cpOffer($this);
    cpMissingRequirements()[$missing]($this->passenger);

    cpAction($this, $this->passenger, 'request', ['offer_id' => $offer->id, 'revision' => $offer->revision, 'seats' => 1])->assertForbidden();

    expect(RideRequest::count())->toBe(0);
})->with(array_keys(cpMissingRequirements()));

it('refuses a new offer from a driver missing any requirement', function (string $missing): void {
    cpMissingRequirements()[$missing]($this->driver);

    cpAction($this, $this->driver, 'offer', ['occurrence_id' => $this->date->id, 'leg' => 'outbound', 'zone' => 'Centro',
        'departure_at' => '2026-10-10T18:00:00Z', 'capacity' => 2, 'accessibility' => 'not_specified', 'driver_declaration' => true])->assertForbidden();

    expect(RideOffer::count())->toBe(0);
})->with(array_keys(cpMissingRequirements()));

it('does not publish a draft once the driver has lost a requirement', function (): void {
    $draft = cpOffer($this, data: ['draft' => true]);
    cpMissingRequirements()['whatsapp']($this->driver);

    cpAction($this, $this->driver, 'publish', ['offer_id' => $draft->id, 'driver_declaration' => true])->assertForbidden();

    expect($draft->fresh()->status->value)->toBe('draft');
});

it('refuses saved searches from users missing a requirement', function (string $missing): void {
    cpMissingRequirements()[$missing]($this->passenger);

    cpDiscovery($this, $this->passenger, 'search', ['occurrence_id' => $this->date->id, 'leg' => 'outbound', 'seats' => 1, 'accessibility' => 'not_specified',
        'earliest_at' => '2026-10-10T17:00:00Z', 'latest_at' => '2026-10-10T19:00:00Z', 'is_public' => true, 'alerts_enabled' => true])->assertForbidden();

    expect(RideSearch::count())->toBe(0);
})->with(['whatsapp', 'adult', 'terms']);

it('shows neither offers nor public searches of a date to a user who does not meet the requirements', function (): void {
    cpOffer($this);
    cpMissingRequirements()['adult']($this->passenger);
    Sanctum::actingAs($this->passenger->fresh());

    $this->getJson('/api/v1/carpool/occurrences/'.$this->date->id)->assertOk()
        ->assertJsonPath('data.access.eligible', false)->assertJsonPath('data.access.reason', 'adult')
        ->assertJsonPath('data.offers', [])->assertJsonPath('data.wanted', []);
});

it('hides an open offer page from a user who does not meet the requirements', function (): void {
    $offer = cpOffer($this);
    cpMissingRequirements()['terms']($this->passenger);
    Sanctum::actingAs($this->passenger->fresh());

    $this->getJson('/api/v1/carpool/offers/'.$offer->id)->assertForbidden();
});

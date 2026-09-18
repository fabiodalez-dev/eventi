<?php

use App\Models\CarpoolCase;
use App\Models\CarpoolProfile;
use App\Models\RideOffer;
use App\Models\RideRequest;
use App\Models\User;
use App\Services\Carpool\CommunitySafety;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

function carpoolPerson(bool $adult = true): User
{
    $user = User::factory()->create();
    $user->forceFill(['whatsapp_verified_at' => now(), 'whatsapp_phone_hash' => hash('sha256', 'cp-'.$user->id)])->save();
    if ($adult) {
        CarpoolProfile::create(['user_id' => $user->id, 'adult_declared_at' => now(), 'adult_version' => '18-plus-v1', 'terms_version' => config('carpool.terms_version'), 'terms_accepted_at' => now()]);
    }

    return $user;
}

function cpAction($test, User $user, string $action, array $data = [])
{
    Sanctum::actingAs($user->fresh());

    return $test->postJson('/api/v1/carpool/actions/'.$action, ['request_key' => (string) Str::uuid(), ...$data]);
}

function cpOffer($test, ?User $driver = null, array $data = []): RideOffer
{
    $driver ??= $test->driver;
    $response = cpAction($test, $driver, 'offer', ['occurrence_id' => $test->date->id, 'leg' => 'outbound', 'zone' => 'Prato della Valle',
        'departure_at' => '2026-10-10T18:00:00Z', 'capacity' => 3, 'accessibility' => 'not_specified', 'driver_declaration' => true, ...$data]);
    $response->assertOk();

    return RideOffer::findOrFail($response->json('data.id'));
}

function cpRequest($test, RideOffer $offer, ?User $user = null, int $seats = 1): RideRequest
{
    $user ??= $test->passenger;
    $response = cpAction($test, $user, 'request', ['offer_id' => $offer->id, 'revision' => $offer->revision, 'seats' => $seats, 'companions_adult' => $seats > 1]);
    $response->assertOk();

    return RideRequest::findOrFail($response->json('data.id'));
}

function cpSetup($test): void
{
    config(['carpool.enabled' => true, 'carpool.new_rides' => true, 'carpool.chat_enabled' => true]);
    Http::preventStrayRequests();
    $test->city = testCity();
    $test->category = testCategory();
    freezeLocal($test->city, '2026-10-01 12:00');
    $test->date = occurrenceAt($test->city, $test->category, '2026-10-10 19:00:00', '2026-10-10 22:00:00');
    $test->driver = carpoolPerson();
    $test->passenger = carpoolPerson();
}
function cpAccepted($test, int $seats = 1): RideRequest
{
    $ride = cpRequest($test, cpOffer($test), seats: $seats);
    cpAction($test, $test->driver, 'accept', ['request_id' => $ride->id])->assertOk();

    return $ride->fresh();
}
function cpStaff(string $role = 'admin'): User
{
    app(RolesAndPermissionsSeeder::class)->run();
    $user = carpoolPerson();
    $user->assignRole($role);

    return $user;
}
function cpDiscovery($test, User $user, string $action, array $data)
{
    Sanctum::actingAs($user->fresh());

    return $test->postJson('/api/v1/carpool/discovery/'.$action, ['request_key' => (string) Str::uuid(), ...$data]);
}
function cpCase($test, RideRequest $ride): CarpoolCase
{
    return app(CommunitySafety::class)->report($test->passenger, ['request_key' => (string) Str::uuid(), 'request_id' => $ride->id, 'reason' => 'safety', 'body' => 'Vorrei chiarire questo problema.']);
}

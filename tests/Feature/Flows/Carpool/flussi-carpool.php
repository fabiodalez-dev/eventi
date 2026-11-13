<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

/*
 * Helper condivisi dai test di flusso dei passaggi. Si appoggiano a quelli di
 * tests/Support/Carpool.php (cpSetup, cpOffer, cpRequest, cpAction...).
 */

/** Entra nel sito con la sessione web, come una persona dal browser. */
function flussoCpWeb($test, User $user)
{
    return $test->actingAs($user->fresh(), 'web');
}

/** Torna ospite: gli helper cp* autenticano con Sanctum, e quell'utente resterebbe attivo. */
function flussoCpGuest(): void
{
    app('auth')->forgetGuards();
    app('auth')->shouldUse('web');
}

/** I campi del modulo «Offro passaggio», con l'ora locale come la scrive il browser. */
function flussoCpOfferForm($test, array $data = []): array
{
    return ['request_key' => (string) Str::uuid(), 'occurrence_id' => $test->date->id, 'leg' => 'outbound', 'zone' => 'Prato della Valle',
        'departure_at' => '2026-10-10T20:00', 'capacity' => 3, 'accessibility' => 'not_specified', 'driver_declaration' => '1', ...$data];
}

/** Conferma, salva o rimuove la recensione dall'app, come fa il passeggero. */
function flussoCpReview($test, User $user, $ride, string $action, array $data = [])
{
    Sanctum::actingAs($user->fresh());

    return $test->postJson('/api/v1/carpool/requests/'.$ride->id.'/review/'.$action, ['request_key' => (string) Str::uuid(), ...$data]);
}

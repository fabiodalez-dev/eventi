<?php

declare(strict_types=1);

use App\Models\RideConversation;
use App\Models\RideRequest;
use App\Models\User;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;

/*
 * Helper dei test «PercorsiTest» dei passaggi. Si appoggiano a quelli di
 * tests/Support/Carpool.php (cpSetup, cpOffer, cpRequest, cpAction...) e di
 * flussi-carpool.php (flussoCpWeb, flussoCpGuest, flussoCpOfferForm...).
 */
require_once __DIR__.'/flussi-carpool.php';

/** Un modulo dei passaggi inviato dal sito, con la chiave che la pagina mette in ogni modulo. */
function percorsoCpPost($test, User $user, string $action, array $data = []): TestResponse
{
    flussoCpWeb($test, $user);

    return $test->post(route('carpool.action', $action), ['request_key' => (string) Str::uuid(), ...$data]);
}

/** I campi del modulo «Modifica il passaggio» con i valori attuali dell'offerta, come li ristampa la pagina. */
function percorsoCpUpdateForm($offer, array $data = []): array
{
    $offer = $offer->fresh();
    $local = $offer->departure_at->setTimezone($offer->occurrence->event->city->timezone)->format('Y-m-d\TH:i');
    $stops = array_pad($offer->stops ?? [], 3, '');

    return ['request_key' => (string) Str::uuid(), 'offer_id' => $offer->id, 'revision' => $offer->revision, 'zone' => $offer->zone, 'departure_at' => $local,
        'capacity' => $offer->capacity, 'accessibility' => $offer->accessibility->value, 'accessibility_note' => (string) $offer->accessibility_note,
        'note' => (string) $offer->note, 'stops' => $stops, ...$data];
}

/** La conversazione aperta dall'accettazione di una richiesta. */
function percorsoCpChat(RideRequest $ride): RideConversation
{
    return RideConversation::where('ride_request_id', $ride->id)->firstOrFail();
}

/** Un messaggio scritto dall'app. */
function percorsoCpSend($test, User $user, RideConversation $chat, string $body, ?string $key = null): TestResponse
{
    Sanctum::actingAs($user->fresh());

    return $test->postJson('/api/v1/carpool/chats/'.$chat->id.'/send', ['request_key' => $key ?? (string) Str::uuid(), 'body' => $body]);
}

/** I campi di una ricerca salvata sulla data del test, con la finestra dell'andata. */
function percorsoCpSearch($test, array $data = []): array
{
    return ['occurrence_id' => $test->date->id, 'leg' => 'outbound', 'seats' => 1, 'accessibility' => 'not_specified',
        'earliest_at' => '2026-10-10T17:00:00Z', 'latest_at' => '2026-10-10T19:00:00Z', 'is_public' => false, 'alerts_enabled' => true, ...$data];
}

/** Il campo `href` del ritorno della pagina di servizio (`data-profile-back`). */
function percorsoCpBack(TestResponse $response): ?string
{
    return preg_match('~<a href="([^"]+)" data-profile-back~', $response->getContent(), $match) === 1 ? html_entity_decode($match[1]) : null;
}

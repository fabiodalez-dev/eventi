<?php

declare(strict_types=1);

use App\Models\Booking;
use App\Models\EventOccurrence;
use App\Models\User;
use App\Models\Venue;
use App\Services\Ticketing\TicketingService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

/*
 * Aiuti condivisi dai test di flusso della biglietteria. I nomi portano il
 * prefisso `tktFlow` perché Pest carica tutti i file della suite nello stesso
 * processo: una funzione con un nome generico entrerebbe in conflitto con
 * quelle delle altre cartelle.
 */

/**
 * Lo scenario di partenza di tutte le serate: oggi è sabato 10 ottobre 2026
 * alle 12:00 UTC, la serata è sabato 17 alle 19:00 UTC (le 21 a Padova) e
 * finisce alle 22:00 UTC. Il locale ha la biglietteria abilitata e un
 * proprietario che la gestisce.
 *
 * @param  array<string, mixed>  $occurrence
 * @param  array<string, mixed>  $event
 * @return array{date: EventOccurrence, venue: Venue, owner: User}
 */
function tktFlowScenario(array $occurrence = [], array $event = [], ?Venue $venue = null): array
{
    test()->travelTo(CarbonImmutable::parse('2026-10-10 12:00:00', 'UTC'));

    $city = testCity();
    $category = testCategory();
    $venue ??= Venue::factory()->approved()->create(['city_id' => $city->getKey(), 'ticketing_enabled' => true]);

    $date = occurrenceAt($city, $category, '2026-10-17 19:00:00', '2026-10-17 22:00:00', [
        'booking_enabled' => true,
        'booking_capacity' => 10,
        'booking_limit' => 4,
        'booking_waitlist' => true,
        ...$occurrence,
    ], ['title' => 'Concerto al chiostro', ...$event], $venue);

    $owner = User::factory()->create(['name' => 'Gestore del chiostro']);
    $venue->members()->attach($owner, ['role' => 'owner']);

    return ['date' => $date->fresh(), 'venue' => $venue, 'owner' => $owner];
}

/**
 * Il corpo di una prenotazione come lo manda il modulo del sito: nome e
 * cognome separati per ogni partecipante, i dati di chi prenota, la presa
 * visione della privacy e una chiave della richiesta nuova.
 *
 * @param  list<array{0: string, 1: string}>  $people
 * @param  array<string, mixed>  $extra
 * @return array<string, mixed>
 */
function tktFlowWebPayload(array $people, array $extra = []): array
{
    return [
        'request_key' => (string) Str::uuid(),
        'booker' => ['first_name' => $people[0][0], 'last_name' => $people[0][1]],
        'attendees' => array_map(fn (array $person): array => ['first_name' => $person[0], 'last_name' => $person[1]], $people),
        'accept_terms' => '1',
        ...$extra,
    ];
}

/**
 * Il corpo di una prenotazione come lo manda l'app: nomi interi, chiave di
 * idempotenza, consenso esplicito.
 *
 * @param  list<string>  $names
 * @param  array<string, mixed>  $extra
 * @return array<string, mixed>
 */
function tktFlowApiPayload(array $names, array $extra = []): array
{
    return ['attendees' => $names, 'request_key' => (string) Str::uuid(), 'accept_terms' => true, ...$extra];
}

/**
 * Una prenotazione già fatta da una persona, attraverso lo stesso servizio che
 * usano sito e app: serve a preparare la scena quando il flusso sotto esame è
 * un altro (la porta, il pannello, la coda).
 *
 * @param  list<string>  $names
 */
function tktFlowBook(User $user, EventOccurrence $date, array $names, bool $waitlist = false): Booking
{
    return app(TicketingService::class)->reserve($user, $date->fresh(), $names, (string) Str::uuid(), $waitlist);
}

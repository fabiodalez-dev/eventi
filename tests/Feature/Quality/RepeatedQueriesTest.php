<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Models\Event;
use App\Models\EventOccurrence;
use App\Models\User;
use App\Models\Venue;
use App\Support\RequestMemo;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/*
 * Le domande ripetute trovate il 20/11/2026 visitando tutte le pagine con il
 * rilevatore di N+1: la stessa query fatta due o più volte nella stessa
 * richiesta da pezzi diversi della pagina. Ogni prova conta le query che
 * contano davvero, e fallisce se tornano a ripetersi.
 */

/**
 * Quante volte si ripete, al massimo, una stessa query della richiesta fra
 * quelle che contengono tutti i frammenti indicati.
 */
function maxRepeatOf(Closure $request, string ...$fragments): int
{
    DB::flushQueryLog();
    DB::enableQueryLog();
    $request();
    $repeats = collect(DB::getQueryLog())->pluck('query')
        ->filter(fn (string $sql): bool => collect($fragments)->every(fn (string $f): bool => str_contains($sql, $f)))
        ->countBy();
    DB::disableQueryLog();

    return (int) ($repeats->max() ?? 0);
}

it('ricorda un valore per la durata di una richiesta sola', function (): void {
    $calls = 0;
    $value = function () use (&$calls): int {
        return RequestMemo::remember('prova', function () use (&$calls): int {
            return ++$calls;
        });
    };

    expect($value())->toBe(1)->and($value())->toBe(1)->and($calls)->toBe(1);

    app()->instance('request', Request::create('/altra'));
    expect($value())->toBe(2)->and($calls)->toBe(2);
});

it('non perde un valore ricordato dentro il calcolo di un altro', function (): void {
    $inner = 0;
    $readInner = function () use (&$inner): int {
        return RequestMemo::remember('interno', function () use (&$inner): int {
            return ++$inner;
        });
    };

    RequestMemo::remember('esterno', fn (): int => $readInner() + 10);

    expect($readInner())->toBe(1)->and($inner)->toBe(1);
});

it('conta le date di un evento una volta sola nella pagina della singola data', function (): void {
    $city = testCity();
    $venue = Venue::factory()->approved()->create(['city_id' => $city->id]);
    $event = Event::factory()->published()->create(['city_id' => $city->id, 'venue_id' => $venue->id]);
    $first = EventOccurrence::factory()->create(['event_id' => $event->id, 'starts_at' => now()->addDays(3)]);
    EventOccurrence::factory()->create(['event_id' => $event->id, 'starts_at' => now()->addDays(10)]);
    $first->refresh();

    $count = maxRepeatOf(fn () => $this->get(route('events.occurrence', ['slug' => $event->slug, 'occurrence' => $first->url_number]))->assertOk(),
        'select count(*) as `aggregate` from `event_occurrences`', '`event_occurrences`.`event_id` = ?');

    expect($count)->toBe(1);
});

it('chiede una volta sola se l\'evento ha date future per «Sponsorizza questo evento»', function (): void {
    (new RolesAndPermissionsSeeder)->run();
    $city = testCity();
    $admin = User::factory()->create();
    $admin->assignRole(UserRole::Admin->value);
    $venue = Venue::factory()->approved()->create(['city_id' => $city->id]);
    $event = Event::factory()->published()->create(['city_id' => $city->id, 'venue_id' => $venue->id]);
    EventOccurrence::factory()->count(3)->create(['event_id' => $event->id, 'starts_at' => now()->addDays(5)]);
    $this->actingAs($admin)->get('/admin')->assertOk();

    $count = maxRepeatOf(fn () => $this->get('/admin/events/'.$event->slug)->assertOk(),
        'select exists(select * from `event_occurrences`', '`starts_at` >= ?');

    expect($count)->toBe(1);
});

it('legge i locali di chi gestisce una volta sola per le azioni dei biglietti di tutte le date', function (): void {
    (new RolesAndPermissionsSeeder)->run();
    $city = testCity();
    $venue = Venue::factory()->approved()->create(['city_id' => $city->id]);
    $owner = User::factory()->create();
    $owner->assignRole(UserRole::VenueOwner->value);
    $owner->venues()->attach($venue, ['role' => 'owner']);
    $event = Event::factory()->published()->create(['city_id' => $city->id, 'venue_id' => $venue->id, 'price_type' => 'free']);
    EventOccurrence::factory()->count(4)->create(['event_id' => $event->id, 'starts_at' => now()->addDays(5)]);
    $this->actingAs($owner)->get('/gestione/'.$venue->slug)->assertOk();

    $count = maxRepeatOf(fn () => $this->get('/gestione/'.$venue->slug.'/eventi/'.$event->slug.'/modifica')->assertOk(),
        'inner join `venue_user`', '`venue_user`.`user_id` = ?');

    expect($count)->toBe(1);
});

it('legge gli interessi di chi è collegato una volta sola nella home', function (): void {
    testCity();
    $user = User::factory()->create();

    $count = maxRepeatOf(fn () => $this->actingAs($user)->get('/')->assertOk(), 'from `follows`', '`followable_type` in');

    expect($count)->toBe(1);
});

it('non rilegge la città a ogni passo dello studio social', function (): void {
    (new RolesAndPermissionsSeeder)->run();
    $city = testCity();
    $admin = User::factory()->create();
    $admin->assignRole(UserRole::Admin->value);
    $venue = Venue::factory()->approved()->create(['city_id' => $city->id]);
    $event = Event::factory()->published()->create(['city_id' => $city->id, 'venue_id' => $venue->id]);
    EventOccurrence::factory()->count(3)->create(['event_id' => $event->id, 'starts_at' => now()->addDays(5)]);
    $this->actingAs($admin)->get('/admin')->assertOk();

    $count = maxRepeatOf(fn () => $this->get('/admin/social?event='.$event->id)->assertOk(), 'from `cities`');

    /* Prima erano 12 letture, una per ogni passo della pagina. Restano
       l'evento dello studio e quello del catalogo, una volta ciascuno. */
    expect($count)->toBeLessThanOrEqual(2);
});

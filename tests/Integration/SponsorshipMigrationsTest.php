<?php

declare(strict_types=1);

use App\Enums\EventStatus;
use App\Models\Event;
use App\Models\Venue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/*
 * Le quattro migrazioni della consegna delle sponsorizzazioni: su, giù di
 * quattro passi, e di nuovo su, su MariaDB. E i dati che c'erano prima:
 * peso e priorità delle righe di un'abilitazione passano all'abilitazione, e
 * tornando indietro le righe sono ancora come prima.
 *
 * Lo schema viene davvero modificato (su MariaDB il DDL non sta in una
 * transazione) e la prima prova ricrea il database da zero: il file gira solo
 * su un database di prova dedicato, mai su quello condiviso della suite.
 *
 *     DB_DATABASE=eventi_test_p0 vendor/bin/pest tests/Integration/SponsorshipMigrationsTest.php
 */
uses(TestCase::class)->beforeEach(function (): void {
    expect(config('database.connections.mariadb.database'))->toMatch('/\Aeventi_test_\w+\z/');
});

const P0_MIGRATIONS = [
    '2026_11_20_100000_add_delivery_fields_to_sponsorship_grants',
    '2026_11_20_100100_add_delivery_fields_to_sponsorships',
    '2026_11_20_100200_create_sponsorship_slot_hourly_table',
    '2026_11_20_100300_create_sponsorship_impression_nonces_table',
];

function p0SchemaPresent(): bool
{
    return Schema::hasColumns('sponsorship_grants', ['weight', 'priority', 'impressions_goal', 'pacing'])
        && Schema::hasColumns('sponsorships', ['impressions_goal', 'pacing'])
        && Schema::hasTable('sponsorship_slot_hourly')
        && Schema::hasTable('sponsorship_impression_nonces');
}

function p0SchemaAbsent(): bool
{
    return ! Schema::hasColumn('sponsorship_grants', 'weight')
        && ! Schema::hasColumn('sponsorship_grants', 'priority')
        && ! Schema::hasColumn('sponsorship_grants', 'impressions_goal')
        && ! Schema::hasColumn('sponsorship_grants', 'pacing')
        && ! Schema::hasColumn('sponsorships', 'impressions_goal')
        && ! Schema::hasColumn('sponsorships', 'pacing')
        && ! Schema::hasTable('sponsorship_slot_hourly')
        && ! Schema::hasTable('sponsorship_impression_nonces');
}

/**
 * Una riga `sponsorships` scritta direttamente: il modello conosce già le
 * colonne nuove, e sullo schema di prima non si può usare.
 *
 * @param  array<string, mixed>  $attributi
 */
function p0Riga(Event $evento, int $abilitazione, array $attributi = []): int
{
    return (int) DB::table('sponsorships')->insertGetId([
        'sponsorship_grant_id' => $abilitazione, 'city_id' => $evento->city_id, 'event_id' => $evento->getKey(),
        'placement' => 'list_top', 'status' => 'active', 'starts_at' => now()->subDay(), 'ends_at' => now()->addMonth(),
        'priority' => 0, 'weight' => 1, 'impressions' => 0, 'clicks' => 0, 'advertiser_name' => 'Locale di prova',
        'currency' => 'EUR', 'created_at' => now(), 'updated_at' => now(), ...$attributi,
    ]);
}

function p0Abilitazione(Venue $locale, string $collocazione = 'list_top'): int
{
    return (int) DB::table('sponsorship_grants')->insertGetId([
        'venue_id' => $locale->getKey(), 'mode' => 'automatic', 'placement' => $collocazione, 'enabled' => true,
        'complimentary' => true, 'notes' => 'Prova delle migrazioni', 'starts_at' => now()->subDay(), 'ends_at' => now()->addMonth(),
        'created_at' => now(), 'updated_at' => now(),
    ]);
}

it('migrates up, rolls back four steps and migrates up again on MariaDB', function (): void {
    $this->artisan('migrate:fresh', ['--force' => true])->assertSuccessful();

    /* Le quattro sono le ultime: è ciò che rende «indietro di quattro» proprio loro. */
    expect(DB::table('migrations')->orderByDesc('id')->limit(4)->pluck('migration')->reverse()->values()->all())->toBe(P0_MIGRATIONS)
        ->and(p0SchemaPresent())->toBeTrue()
        ->and(Schema::hasIndex('sponsorship_slot_hourly', ['city_id', 'placement', 'channel', 'day', 'hour'], 'unique'))->toBeTrue()
        ->and(Schema::hasIndex('sponsorship_slot_hourly', ['day']))->toBeTrue()
        ->and(Schema::hasIndex('sponsorship_impression_nonces', ['seen_at']))->toBeTrue()
        ->and(Schema::getColumnType('sponsorship_grants', 'pacing'))->toBe('varchar')
        ->and(Schema::getColumnType('sponsorships', 'pacing'))->toBe('varchar');

    $this->artisan('migrate:rollback', ['--step' => 4, '--force' => true])->assertSuccessful();
    expect(p0SchemaAbsent())->toBeTrue()
        ->and(DB::table('migrations')->whereIn('migration', P0_MIGRATIONS)->count())->toBe(0);

    $this->artisan('migrate', ['--force' => true])->assertSuccessful();
    expect(p0SchemaPresent())->toBeTrue()
        ->and(DB::table('migrations')->whereIn('migration', P0_MIGRATIONS)->count())->toBe(4);
});

it('carries weight and priority from the rows of an existing grant onto the grant, and loses nothing going back', function (): void {
    $this->artisan('migrate', ['--force' => true])->assertSuccessful();
    $this->artisan('migrate:rollback', ['--step' => 4, '--force' => true])->assertSuccessful();
    expect(p0SchemaAbsent())->toBeTrue();

    $citta = testCity();
    $locale = Venue::factory()->approved()->create(['city_id' => $citta->getKey()]);
    $evento = fn (): Event => Event::factory()->create(['city_id' => $citta->getKey(), 'venue_id' => $locale->getKey(), 'status' => EventStatus::Published]);

    /* Il caso del criterio di accettazione: righe a peso 3 e priorità 10. */
    $pesata = p0Abilitazione($locale);
    $righe = [
        p0Riga($evento(), $pesata, ['weight' => 3, 'priority' => 10]),
        p0Riga($evento(), $pesata, ['weight' => 2, 'priority' => 4]),
        /* Nel cestino: non porta il suo peso sull'abilitazione. */
        p0Riga($evento(), $pesata, ['weight' => 9, 'priority' => 900, 'deleted_at' => now()]),
    ];

    /* Le righe di oggi in modalità automatica: peso 1, priorità 0. */
    $normale = p0Abilitazione($locale, 'home_card');
    p0Riga($evento(), $normale, ['placement' => 'home_card']);
    p0Riga($evento(), $normale, ['placement' => 'home_card']);

    /* Un valore scritto a mano fuori dai limiti del pannello non li supera. */
    $fuoriLimite = p0Abilitazione($locale, 'map_sheet');
    p0Riga($evento(), $fuoriLimite, ['placement' => 'map_sheet', 'weight' => 0, 'priority' => 5000]);

    $senzaRighe = p0Abilitazione($locale, 'home_hero');

    $prima = DB::table('sponsorships')->orderBy('id')->get(['id', 'weight', 'priority', 'deleted_at'])->toArray();

    $this->artisan('migrate', ['--force' => true])->assertSuccessful();

    $abilitazioni = DB::table('sponsorship_grants')->whereIn('id', [$pesata, $normale, $fuoriLimite, $senzaRighe])
        ->get(['id', 'weight', 'priority', 'impressions_goal', 'pacing'])->keyBy('id');

    expect((int) $abilitazioni[$pesata]->weight)->toBe(3)
        ->and((int) $abilitazioni[$pesata]->priority)->toBe(10)
        ->and((int) $abilitazioni[$normale]->weight)->toBe(1)
        ->and((int) $abilitazioni[$normale]->priority)->toBe(0)
        ->and((int) $abilitazioni[$fuoriLimite]->weight)->toBe(1)
        ->and((int) $abilitazioni[$fuoriLimite]->priority)->toBe(1000)
        ->and((int) $abilitazioni[$senzaRighe]->weight)->toBe(1)
        ->and((int) $abilitazioni[$senzaRighe]->priority)->toBe(0)
        ->and($abilitazioni->pluck('impressions_goal')->filter()->all())->toBe([])
        ->and($abilitazioni->pluck('pacing')->unique()->values()->all())->toBe(['even'])
        /* Le righe figlie non si toccano. */
        ->and(DB::table('sponsorships')->orderBy('id')->get(['id', 'weight', 'priority', 'deleted_at'])->toArray())->toEqual($prima)
        ->and(DB::table('sponsorships')->whereIn('id', $righe)->pluck('pacing')->unique()->values()->all())->toBe(['even']);

    $this->artisan('migrate:rollback', ['--step' => 4, '--force' => true])->assertSuccessful();
    expect(DB::table('sponsorships')->orderBy('id')->get(['id', 'weight', 'priority', 'deleted_at'])->toArray())->toEqual($prima)
        ->and((array) DB::table('sponsorships')->where('id', $righe[0])->first(['weight', 'priority']))->toEqual(['weight' => 3, 'priority' => 10]);

    $this->artisan('migrate', ['--force' => true])->assertSuccessful();
    expect(p0SchemaPresent())->toBeTrue()
        ->and((int) DB::table('sponsorship_grants')->where('id', $pesata)->value('weight'))->toBe(3)
        ->and((int) DB::table('sponsorship_grants')->where('id', $pesata)->value('priority'))->toBe(10);
});

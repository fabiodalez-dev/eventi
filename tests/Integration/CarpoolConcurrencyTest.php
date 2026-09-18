<?php

use App\Models\RideConversation;
use App\Models\RideRequest;
use App\Models\User;
use App\Services\Carpool\CarpoolService;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

uses(TestCase::class)->beforeEach(function (): void {
    // This file is executed alone, against its dedicated MariaDB database.
    expect(config('database.connections.mysql.database'))->toBe('eventi_test_carpool_race');
    $this->artisan('migrate:fresh', ['--force' => true])->assertSuccessful();
    cpSetup($this);
})->afterEach(function (): void {
    Carbon::setTestNow();
    CarbonImmutable::setTestNow();
});

function raceCommand(User $user, string $action, array $data = []): array
{
    return ['user_id' => $user->id, 'action' => $action, 'data' => ['request_key' => (string) Str::uuid(), ...$data], 'now' => now()->toIso8601String()];
}
function carpoolRace(array $commands): array
{
    $processes = [];
    $pipes = [];
    $output = [];
    $errors = [];
    $environment = array_merge(getenv(), [
        'APP_ENV' => 'testing', 'DB_CONNECTION' => 'mysql', 'DB_DATABASE' => config('database.connections.mysql.database'),
        'DB_HOST' => config('database.connections.mysql.host'), 'DB_PORT' => (string) config('database.connections.mysql.port'),
        'DB_USERNAME' => config('database.connections.mysql.username'), 'DB_PASSWORD' => config('database.connections.mysql.password') ?? '',
        'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array', 'QUEUE_CONNECTION' => 'sync', 'TELESCOPE_ENABLED' => 'false',
    ]);
    foreach ($commands as $i => $command) {
        $processes[$i] = proc_open([PHP_BINARY, base_path('tests/Support/carpool-race-worker.php'), json_encode($command, JSON_THROW_ON_ERROR)],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes[$i], base_path(), $environment);
        expect(is_resource($processes[$i]))->toBeTrue();
        stream_set_blocking($pipes[$i][1], false);
        stream_set_blocking($pipes[$i][2], false);
        $output[$i] = '';
        $errors[$i] = '';
    }
    $deadline = microtime(true) + 40;
    $read = function () use (&$pipes, &$output, &$errors, $deadline): void {
        if (microtime(true) > $deadline) {
            throw new RuntimeException('Workers did not complete within 40 seconds');
        }
        $streams = [];
        foreach ($pipes as $worker) {
            foreach ([1, 2] as $fd) {
                if (is_resource($worker[$fd]) && ! feof($worker[$fd])) {
                    $streams[] = $worker[$fd];
                }
            }
        }
        if (! $streams) {
            return;
        }
        $write = null;
        $except = null;
        stream_select($streams, $write, $except, 1);
        foreach ($pipes as $i => $worker) {
            foreach ([1, 2] as $fd) {
                if (in_array($worker[$fd], $streams, true)) {
                    $chunk = stream_get_contents($worker[$fd]);
                    if ($fd === 1) {
                        $output[$i] .= $chunk;
                    } else {
                        $errors[$i] .= $chunk;
                    }
                }
            }
        }
    };
    try {
        while (count(array_filter($output, fn ($value) => str_contains($value, "READY\n"))) !== count($commands)) {
            $read();
        }
        // Real pipes release both barriers now, not when the parent later waits on each worker.
        foreach ($pipes as &$worker) {
            fwrite($worker[0], "GO\n");
            fclose($worker[0]);
        } unset($worker);
        do {
            $read();
            $open = false;
            foreach ($pipes as $worker) {
                $open = $open || ! feof($worker[1]) || ! feof($worker[2]);
            }
        } while ($open);
        $results = [];
        foreach ($processes as $i => $process) {
            expect(proc_close($process), $errors[$i])->toBe(0);
            $processes[$i] = null;
            $lines = array_values(array_filter(explode("\n", trim($output[$i]))));
            $results[] = json_decode(end($lines), true, flags: JSON_THROW_ON_ERROR);
        }

        return $results;
    } finally {
        foreach ($pipes as $worker) {
            foreach ($worker as $pipe) {
                if (is_resource($pipe)) {
                    fclose($pipe);
                }
            }
        }
        foreach ($processes as $process) {
            if (is_resource($process)) {
                proc_terminate($process);
                proc_close($process);
            }
        }
    }
}

it('serializes competing group acceptances against the remaining capacity', function (): void {
    $offer = cpOffer($this);
    $a = cpRequest($this, $offer, seats: 2);
    $b = cpRequest($this, $offer, carpoolPerson(), 2);
    $results = carpoolRace([raceCommand($this->driver, 'accept', ['request_id' => $a->id]), raceCommand($this->driver, 'accept', ['request_id' => $b->id])]);
    expect(collect($results)->pluck('status')->sort()->values()->all())->toBe([200, 409]);
    expect(app(CarpoolService::class)->occupied($offer))->toBe(2)->and(RideConversation::count())->toBe(1);
});

it('serializes two drivers accepting the same passenger', function (): void {
    $driverB = carpoolPerson();
    $a = cpRequest($this, cpOffer($this));
    $b = cpRequest($this, cpOffer($this, $driverB));
    $results = carpoolRace([raceCommand($this->driver, 'accept', ['request_id' => $a->id]), raceCommand($driverB, 'accept', ['request_id' => $b->id])]);
    expect(collect($results)->pluck('status')->sort()->values()->all())->toBe([200, 409]);
    expect(RideRequest::where('status', 'accepted')->count())->toBe(1)->and(RideConversation::count())->toBe(1);
    expect(DB::table('ride_occupancies')->where('user_id', $this->passenger->id)->count())->toBe(1);
});

it('does not resurrect a withdrawal racing with acceptance', function (): void {
    $ride = cpRequest($this, cpOffer($this));
    carpoolRace([raceCommand($this->driver, 'accept', ['request_id' => $ride->id]), raceCommand($this->passenger, 'withdraw', ['request_id' => $ride->id])]);
    expect($ride->fresh()->status->value)->toBe('withdrawn')->and(app(CarpoolService::class)->occupied($ride->offer))->toBe(0);
    expect(RideConversation::whereNull('read_only_at')->count())->toBe(0);
});

it('returns one result for concurrent retransmission of the same idempotency key', function (): void {
    $ride = cpRequest($this, cpOffer($this));
    $command = raceCommand($this->driver, 'accept', ['request_id' => $ride->id]);
    $results = carpoolRace([$command, $command]);
    expect(collect($results)->pluck('status')->all())->toBe([200, 200])->and(RideConversation::count())->toBe(1);
    expect(DB::table('community_delivery_outbox')->where('dedupe_key', 'like', 'ride:accepted:%')->count())->toBe(1);
});

it('does not leave a confirmed agreement after concurrent verification revocation', function (): void {
    $ride = cpRequest($this, cpOffer($this));
    carpoolRace([raceCommand($this->driver, 'accept', ['request_id' => $ride->id]), raceCommand($this->passenger, 'revoke_phone')]);
    expect($ride->fresh()->status->value)->toBe('cancelled');
    expect(DB::table('ride_occupancies')->where('user_id', $this->passenger->id)->count())->toBe(0);
});

it('keeps capacity consistent when reduction and acceptance race', function (): void {
    $offer = cpOffer($this);
    $ride = cpRequest($this, $offer, seats: 2);
    $results = carpoolRace([raceCommand($this->driver, 'accept', ['request_id' => $ride->id]), raceCommand($this->driver, 'update', [
        'offer_id' => $offer->id, 'revision' => 1, 'zone' => $offer->zone, 'capacity' => 1, 'departure_at' => $offer->departure_at->toIso8601String(), 'accessibility' => 'not_specified',
    ])]);
    expect(collect($results)->pluck('status')->sort()->values()->all())->toBe([200, 409]);
    expect(app(CarpoolService::class)->occupied($offer))->toBeLessThanOrEqual($offer->fresh()->capacity);
});

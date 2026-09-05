<?php

use App\Models\AdmissionTicket;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Symfony\Component\Process\Process;
use Tests\TestCase;

uses(TestCase::class, DatabaseMigrations::class);

it('serialises different request keys from the same account without duplicate bookings', function (): void {
    $date = occurrenceAt(testCity(), testCategory(), now()->addDay()->format('Y-m-d H:i:s'), null, ['booking_enabled' => true, 'booking_capacity' => null]);
    $date->event->venue->update(['ticketing_enabled' => true]);
    $user = User::factory()->create();
    $processes = [];
    foreach (range(1, 4) as $attempt) {
        $process = new Process([PHP_BINARY, base_path('tests/Support/reserve-concurrently.php'), (string) $user->id, (string) $date->id], base_path(), [
            'APP_ENV' => 'testing', 'DB_DATABASE' => config('database.connections.mysql.database'),
        ]);
        $process->start();
        $processes[] = $process;
    }
    $outputs = [];
    foreach ($processes as $process) {
        $process->wait();
        expect($process->isSuccessful())->toBeTrue($process->getErrorOutput());
        $outputs[] = trim($process->getOutput());
    }
    expect(array_count_values($outputs))->toEqual(['confirmed' => 1, 'already_booked' => 3]);
    expect(AdmissionTicket::count())->toBe(1);
});

it('serialises four real database connections competing for the last seat', function (): void {
    $date = occurrenceAt(testCity(), testCategory(), now()->addDay()->format('Y-m-d H:i:s'), null, ['booking_enabled' => true, 'booking_capacity' => 1]);
    $date->event->venue->update(['ticketing_enabled' => true]);
    $processes = [];
    foreach (User::factory()->count(4)->create() as $user) {
        $process = new Process([PHP_BINARY, base_path('tests/Support/reserve-concurrently.php'), (string) $user->id, (string) $date->id], base_path(), [
            'APP_ENV' => 'testing', 'DB_DATABASE' => config('database.connections.mysql.database'),
        ]);
        $process->start();
        $processes[] = $process;
    }
    $outputs = [];
    foreach ($processes as $process) {
        $process->wait();
        expect($process->isSuccessful())->toBeTrue($process->getErrorOutput());
        $outputs[] = trim($process->getOutput());
    }
    expect(array_count_values($outputs))->toEqual(['confirmed' => 1, 'full' => 3]);
    expect(AdmissionTicket::count())->toBe(1);
});

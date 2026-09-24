<?php

use App\Enums\AdmissionStatus;
use App\Models\User;
use App\Services\Ticketing\TicketingService;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\TestCase;

uses(TestCase::class, DatabaseMigrations::class);

it('admits exactly once when two staff scan the same code on separate connections', function (): void {
    Notification::fake();
    $date = occurrenceAt(testCity(), testCategory(), now()->addHour()->format('Y-m-d H:i:s'), null, ['booking_enabled' => true, 'booking_capacity' => 5]);
    $date->event->venue->update(['ticketing_enabled' => true]);
    $booking = app(TicketingService::class)->reserve(User::factory()->create(), $date, ['Ada'], (string) Str::uuid(), false);
    $ticket = $booking->tickets->first();
    $barrier = sys_get_temp_dir().'/incitta-checkin-'.Str::uuid();
    $staff = User::factory()->count(2)->create();
    $date->checkinStaff()->attach($staff->modelKeys());
    $processes = [];
    try {
        foreach ($staff as $user) {
            $process = new Process([PHP_BINARY, base_path('tests/Support/checkin-concurrently.php'), (string) $user->id, (string) $date->id, (string) $ticket->id, $barrier], base_path(), [
                'APP_ENV' => 'testing', 'DB_DATABASE' => config('database.connections.'.config('database.default').'.database'),
            ]);
            $process->setTimeout(30)->start();
            $processes[] = $process;
        }
        $deadline = microtime(true) + 20;
        while (count(glob($barrier.'.ready.*')) < 2 && microtime(true) < $deadline) {
            usleep(10000);
        }
        expect(glob($barrier.'.ready.*'))->toHaveCount(2);
        touch($barrier);
        $outputs = [];
        foreach ($processes as $process) {
            $process->wait();
            expect($process->isSuccessful())->toBeTrue($process->getErrorOutput());
            $outputs[] = trim($process->getOutput());
        }
        sort($outputs);
        expect($outputs)->toBe(['accepted', 'already_used'])
            ->and($ticket->fresh()->status)->toBe(AdmissionStatus::CheckedIn);
    } finally {
        foreach ($processes as $process) {
            $process->stop();
        }
        foreach (glob($barrier.'*') as $file) {
            unlink($file);
        }
    }
});

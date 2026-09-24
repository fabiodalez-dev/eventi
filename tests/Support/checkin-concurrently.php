<?php

use App\Models\AdmissionTicket;
use App\Models\EventOccurrence;
use App\Models\User;
use App\Services\Ticketing\TicketingService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$database = config('database.connections.'.config('database.default').'.database');
if (! $app->environment('testing') || ! preg_match('/(?:^|_)test(?:_|$)/', $database)) {
    exit(2);
}
// Both independent connections must reach the barrier before either may scan.
file_put_contents($argv[4].'.ready.'.$argv[1], 'ready');
$deadline = microtime(true) + 20;
while (! file_exists($argv[4])) {
    if (microtime(true) > $deadline) {
        exit(3);
    }
    usleep(10000);
}
try {
    app(TicketingService::class)->checkIn(
        EventOccurrence::findOrFail($argv[2]), AdmissionTicket::findOrFail($argv[3])->code,
        User::findOrFail($argv[1]), (string) Str::uuid(),
    );
    echo 'accepted';
} catch (ValidationException $error) {
    if (! in_array(__('ticketing.errors.already_used'), $error->errors()['ticketing'] ?? [], true)) {
        throw $error;
    }
    echo 'already_used';
}

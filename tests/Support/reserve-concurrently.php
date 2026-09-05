<?php

use App\Models\EventOccurrence;
use App\Models\User;
use App\Services\Ticketing\TicketingService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (! $app->environment('testing')) {
    exit(2);
}
Notification::fake();
try {
    app(TicketingService::class)->reserve(
        User::query()->findOrFail($argv[1]),
        EventOccurrence::query()->findOrFail($argv[2]),
        ['Concurrent attendee'], (string) Str::uuid(), false,
    );
    echo 'confirmed';
} catch (ValidationException $error) {
    echo 'full';
}

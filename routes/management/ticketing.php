<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\Management\EventTicketingController;
use Illuminate\Support\Facades\Route;

/*
 * Biglietteria completa (P3), sotto il soggetto. Incluso due volte da
 * `routes/api.php`, dentro `venues/{venue}` e dentro `organizers/{organizer}`:
 * `$subjectType` (`venue` o `organizer`) dice in quale dei due. Il gruppo
 * porta già `auth:sanctum`, `TicketingPrivacy` e `ResolveManagementSubject`.
 *
 * Il resto della biglietteria sta sulla data, in `ticketing-dates.php`.
 *
 * @var string $subjectType
 */
if ($subjectType === 'venue') {
    // Il riquadro «Ticketing inCittà» della modifica evento: c'è solo nel pannello del locale.
    Route::get('/events/{event}/ticketing', [EventTicketingController::class, 'show'])->whereNumber('event')->name('events.ticketing');
}

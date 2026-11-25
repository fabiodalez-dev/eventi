<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\Management\EventDatesController;
use Illuminate\Support\Facades\Route;

/*
 * Date e ricorrenze (P2): le date di un evento, sotto l'evento del soggetto.
 *
 * Incluso due volte da `routes/api.php`, dentro `venues/{venue}` e dentro
 * `organizers/{organizer}`: `$subjectType` (`venue` o `organizer`) dice in
 * quale dei due. Il gruppo porta già `auth:sanctum`, `TicketingPrivacy` e
 * `ResolveManagementSubject`.
 *
 * Le differenze sono quelle dei pannelli: il locale annulla (anche la
 * serie), segna tutto esaurito, elimina, ripete l'evento e mette l'esaurito
 * sulla prossima data dall'elenco; l'organizzazione no, e sceglie invece lo
 * stato fra i cinque nel modulo della data. Aggiungi e modifica esistono per
 * entrambi, ognuno con il suo modulo.
 *
 * Le scritture consumano il secchio `management-dates` (60 al minuto per
 * persona); la locandina quello del sito (`event-poster`).
 *
 * @var string $subjectType
 */
Route::get('/events/{event}/dates', [EventDatesController::class, 'index'])->whereNumber('event')->name('dates.index');
Route::post('/events/{event}/dates', [EventDatesController::class, 'store'])->whereNumber('event')
    ->middleware('throttle:60,1,management-dates')->name('dates.store');
Route::get('/events/{event}/dates/{occurrence}', [EventDatesController::class, 'show'])->whereNumber(['event', 'occurrence'])->name('dates.show');
Route::patch('/events/{event}/dates/{occurrence}', [EventDatesController::class, 'update'])->whereNumber(['event', 'occurrence'])
    ->middleware('throttle:60,1,management-dates')->name('dates.update');
Route::get('/events/{event}/dates/{occurrence}/poster.pdf', [EventDatesController::class, 'poster'])->whereNumber(['event', 'occurrence'])
    ->middleware('throttle:30,1,event-poster')->name('dates.poster');

if ($subjectType === 'venue') {
    Route::post('/events/{event}/dates/{occurrence}/status', [EventDatesController::class, 'status'])->whereNumber(['event', 'occurrence'])
        ->middleware('throttle:60,1,management-dates')->name('dates.status');
    Route::delete('/events/{event}/dates/{occurrence}', [EventDatesController::class, 'destroy'])->whereNumber(['event', 'occurrence'])
        ->middleware('throttle:60,1,management-dates')->name('dates.destroy');
    Route::put('/events/{event}/recurrence', [EventDatesController::class, 'recurrence'])->whereNumber('event')
        ->middleware('throttle:60,1,management-dates')->name('events.recurrence');
    Route::put('/events/{event}/next-date/sold-out', [EventDatesController::class, 'soldOutNext'])->whereNumber('event')
        ->middleware('throttle:60,1,management-dates')->name('events.next-date.sold-out');
}

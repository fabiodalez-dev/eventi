<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\Management\EventController;
use App\Http\Controllers\Api\V1\Management\EventPublicationController;
use App\Http\Controllers\Api\V1\Management\EventToolsController;
use Illuminate\Support\Facades\Route;

/*
 * Eventi (P1): elenco, scheda, creazione e modifica, pubblicazione, import da Facebook.
 *
 * Incluso due volte da `routes/api.php`, dentro `venues/{venue}` e dentro
 * `organizers/{organizer}`: `$subjectType` (`venue` o `organizer`) dice in
 * quale dei due. Il gruppo porta già `auth:sanctum`, `TicketingPrivacy` e
 * `ResolveManagementSubject`.
 *
 * Le differenze fra i due soggetti sono quelle dei pannelli e le fanno
 * rispettare le rotte: per l'organizzazione non esistono pubblica,
 * duplica, programma, elimina, nuovi tag; per il locale non esiste «invia
 * alla redazione» (il suo «Pubblica» lo fa da sé quando serve).
 *
 * Le scritture consumano il secchio `management-events` (60 al minuto per
 * persona); l'import da Facebook ha in più i suoi limiti, gli stessi del
 * sito (3 al minuto, 30 al giorno).
 *
 * @var string $subjectType
 */
Route::get('/events', [EventController::class, 'index'])->name('events.index');
Route::get('/events/form', [EventToolsController::class, 'form'])->name('events.form');
Route::post('/events/checks', [EventToolsController::class, 'checks'])->middleware('throttle:120,1,management-events-checks')->name('events.checks');
Route::post('/events/import/facebook', [EventToolsController::class, 'importFacebook'])->middleware('throttle:10,1,management-events-import')->name('events.import.facebook');
Route::get('/events/lookup/tags', [EventToolsController::class, 'tags'])->name('events.lookup.tags');
Route::get('/events/lookup/venues', [EventToolsController::class, 'venues'])->name('events.lookup.venues');
Route::get('/events/{event}', [EventController::class, 'show'])->whereNumber('event')->name('events.show');
Route::get('/events/{event}/preview', [EventToolsController::class, 'preview'])->whereNumber('event')->name('events.preview');
Route::patch('/events/{event}', [EventController::class, 'update'])->whereNumber('event')
    ->middleware('throttle:60,1,management-events')->name('events.update');

if ($subjectType === 'venue') {
    Route::post('/events', [EventController::class, 'storeVenue'])->middleware('throttle:60,1,management-events')->name('events.store');
    Route::delete('/events/{event}', [EventController::class, 'destroy'])->whereNumber('event')
        ->middleware('throttle:60,1,management-events')->name('events.destroy');
    Route::post('/events/{event}/publish', [EventPublicationController::class, 'publish'])->whereNumber('event')
        ->middleware('throttle:60,1,management-events')->name('events.publish');
    Route::post('/events/{event}/duplicate', [EventPublicationController::class, 'duplicate'])->whereNumber('event')
        ->middleware('throttle:60,1,management-events')->name('events.duplicate');
    Route::put('/events/{event}/schedule', [EventPublicationController::class, 'schedule'])->whereNumber('event')
        ->middleware('throttle:60,1,management-events')->name('events.schedule');
    Route::delete('/events/{event}/schedule', [EventPublicationController::class, 'unschedule'])->whereNumber('event')
        ->middleware('throttle:60,1,management-events')->name('events.unschedule');
    Route::post('/tags', [EventToolsController::class, 'storeTag'])->middleware('throttle:30,1,management-events')->name('tags.store');
} else {
    Route::post('/events', [EventController::class, 'storeOrganizer'])->middleware('throttle:60,1,management-events')->name('events.store');
    Route::post('/events/{event}/submit', [EventPublicationController::class, 'submit'])->whereNumber('event')
        ->middleware('throttle:60,1,management-events')->name('events.submit');
}

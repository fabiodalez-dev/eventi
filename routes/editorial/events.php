<?php

declare(strict_types=1);

/*
 * R2 — Eventi: elenco, moderazione, proposte (R-EV-01…04, 07…17, 25, R-SB-01…05, R-WB-01).
 *
 * Incluso da `routes/api.php` dentro `v1/editorial` (nome `api.v1.editorial.`),
 * nel gruppo che porta già `auth:sanctum`, `TicketingPrivacy`,
 * `ResolveEditorialDesk` e `RequireEditorialUnlock`. Percorsi di questo
 * pacchetto: events, submissions.
 *
 * Ogni rotta ha un nome (`<area>.<azione>`) e un `throttle:N,M,<nome>`; le
 * azioni pericolose (decisione 4) aggiungono
 * `->middleware(RequireRecentPassword::class)`. Le regole sono nel contratto
 * delle fondamenta e le controlla `EditorialRoutesTest`.
 */

use App\Http\Controllers\Api\V1\Editorial\EventActivityController;
use App\Http\Controllers\Api\V1\Editorial\EventController;
use App\Http\Controllers\Api\V1\Editorial\EventModerationController;
use App\Http\Controllers\Api\V1\Editorial\SubmissionController;
use App\Http\Middleware\RequireRecentPassword;
use Illuminate\Support\Facades\Route;

// Elenco, scelte dei filtri, esportazione, scheda e anteprima (lettura).
Route::get('/events', [EventController::class, 'index'])->middleware('throttle:120,1,editorial-events')->name('events.index');
Route::get('/events/filters', [EventController::class, 'filters'])->middleware('throttle:60,1,editorial-events')->name('events.filters');
Route::get('/events/export', [EventController::class, 'export'])->middleware('throttle:10,1,editorial-events-export')->name('events.export');
Route::get('/events/{event}', [EventController::class, 'show'])->whereNumber('event')->middleware('throttle:120,1,editorial-events')->name('events.show');
Route::get('/events/{event}/preview', [EventController::class, 'preview'])->whereNumber('event')->middleware('throttle:60,1,editorial-events')->name('events.preview');

// Le decisioni della redazione (stesse policy di `EventModeration`).
Route::post('/events/{event}/publish', [EventModerationController::class, 'publish'])->whereNumber('event')->middleware('throttle:60,1,editorial-events-moderate')->name('events.publish');
Route::post('/events/{event}/unpublish', [EventModerationController::class, 'unpublish'])->whereNumber('event')->middleware('throttle:60,1,editorial-events-moderate')->name('events.unpublish');
Route::post('/events/{event}/reject', [EventModerationController::class, 'reject'])->whereNumber('event')->middleware('throttle:60,1,editorial-events-moderate')->name('events.reject');
Route::post('/events/{event}/feature', [EventModerationController::class, 'feature'])->whereNumber('event')->middleware('throttle:60,1,editorial-events-moderate')->name('events.feature');
Route::post('/events/{event}/duplicate', [EventModerationController::class, 'duplicate'])->whereNumber('event')->middleware('throttle:30,1,editorial-events-duplicate')->name('events.duplicate');
Route::get('/events/{event}/merge-candidates', [EventModerationController::class, 'mergeCandidates'])->whereNumber('event')->middleware('throttle:60,1,editorial-events')->name('events.merge-candidates');
Route::post('/events/{event}/restore', [EventModerationController::class, 'restore'])->whereNumber('event')->middleware('throttle:30,1,editorial-events-moderate')->name('events.restore');

// Pericolose (decisione 4): password scritta negli ultimi 15 minuti.
Route::post('/events/{event}/cancel', [EventModerationController::class, 'cancel'])->whereNumber('event')
    ->middleware([RequireRecentPassword::class, 'throttle:30,1,editorial-events-cancel'])->name('events.cancel');
Route::post('/events/{event}/merge', [EventModerationController::class, 'merge'])->whereNumber('event')
    ->middleware([RequireRecentPassword::class, 'throttle:10,1,editorial-events-merge'])->name('events.merge');
Route::delete('/events/{event}', [EventModerationController::class, 'destroy'])->whereNumber('event')
    ->middleware([RequireRecentPassword::class, 'throttle:30,1,editorial-events-destroy'])->name('events.destroy');
Route::post('/events/bulk-delete', [EventModerationController::class, 'bulkDestroy'])
    ->middleware([RequireRecentPassword::class, 'throttle:10,1,editorial-events-destroy'])->name('events.bulk.destroy');

// Cronologia delle modifiche e «Ripristina i valori di prima» (chi può modificare l'evento).
Route::get('/events/{event}/activity', [EventActivityController::class, 'index'])->whereNumber('event')->middleware('throttle:60,1,editorial-events')->name('events.activity.index');
Route::post('/events/{event}/activity/{activity}/restore', [EventActivityController::class, 'restore'])->whereNumber(['event', 'activity'])
    ->middleware('throttle:30,1,editorial-events-moderate')->name('events.activity.restore');

// Proposte dal pubblico.
Route::get('/submissions', [SubmissionController::class, 'index'])->middleware('throttle:120,1,editorial-submissions')->name('submissions.index');
Route::get('/submissions/{submission}', [SubmissionController::class, 'show'])->whereNumber('submission')->middleware('throttle:120,1,editorial-submissions')->name('submissions.show');
Route::post('/submissions/{submission}/publish', [SubmissionController::class, 'publish'])->whereNumber('submission')->middleware('throttle:30,1,editorial-submissions-publish')->name('submissions.publish');
Route::post('/submissions/{submission}/reject', [SubmissionController::class, 'reject'])->whereNumber('submission')->middleware('throttle:60,1,editorial-submissions-reject')->name('submissions.reject');

<?php

declare(strict_types=1);

/*
 * R3 — Modulo evento e date (R-EV-05, 06, 18…24, 26).
 *
 * Incluso da `routes/api.php` dentro `v1/editorial` (nome `api.v1.editorial.`),
 * nel gruppo che porta già `auth:sanctum`, `TicketingPrivacy`,
 * `ResolveEditorialDesk` e `RequireEditorialUnlock`. Percorsi di questo
 * pacchetto: events (scrittura), events/{event}/dates.
 *
 * Ogni rotta ha un nome (`<area>.<azione>`) e un `throttle:N,M,<nome>`; le
 * azioni pericolose (decisione 4) aggiungono
 * `->middleware(RequireRecentPassword::class)`. Le regole sono nel contratto
 * delle fondamenta e le controlla `EditorialRoutesTest`.
 */

use App\Http\Controllers\Api\V1\Editorial\EventDatesController;
use App\Http\Controllers\Api\V1\Editorial\EventEditorController;
use App\Http\Middleware\RequireRecentPassword;
use Illuminate\Support\Facades\Route;

// Il modulo: scelte e valori, ricerche dei campi a scelta, «Prima di andare» quando cambia il locale.
Route::get('/events/form', [EventEditorController::class, 'create'])->middleware('throttle:60,1,editorial-event-editor')->name('events.form');
Route::get('/events/{event}/form', [EventEditorController::class, 'edit'])->whereNumber('event')->middleware('throttle:60,1,editorial-event-editor')->name('events.edit');
Route::get('/events/lookup/venues', [EventEditorController::class, 'venues'])->middleware('throttle:120,1,editorial-event-editor-lookup')->name('events.lookup.venues');
Route::get('/events/lookup/organizers', [EventEditorController::class, 'organizers'])->middleware('throttle:120,1,editorial-event-editor-lookup')->name('events.lookup.organizers');
Route::get('/events/lookup/tags', [EventEditorController::class, 'tags'])->middleware('throttle:120,1,editorial-event-editor-lookup')->name('events.lookup.tags');
Route::post('/events/lookup/tags', [EventEditorController::class, 'storeTag'])->middleware('throttle:30,1,editorial-event-editor-tags')->name('events.tags.store');
Route::post('/events/before-going', [EventEditorController::class, 'beforeGoing'])->middleware('throttle:60,1,editorial-event-editor-lookup')->name('events.before-going');

// Nuovo evento e modifica (`EventPolicy::create` e `update`).
Route::post('/events', [EventEditorController::class, 'store'])->middleware('throttle:30,1,editorial-event-editor-save')->name('events.store');
Route::patch('/events/{event}', [EventEditorController::class, 'update'])->whereNumber('event')->middleware('throttle:60,1,editorial-event-editor-save')->name('events.update');

// Locandina e galleria.
Route::post('/events/{event}/poster', [EventEditorController::class, 'storePoster'])->whereNumber('event')->middleware('throttle:20,1,editorial-event-editor-media')->name('events.poster.store');
Route::delete('/events/{event}/poster', [EventEditorController::class, 'removePoster'])->whereNumber('event')->middleware('throttle:20,1,editorial-event-editor-media')->name('events.poster.remove');
Route::post('/events/{event}/gallery', [EventEditorController::class, 'storeGallery'])->whereNumber('event')->middleware('throttle:30,1,editorial-event-editor-media')->name('events.gallery.store');
Route::put('/events/{event}/gallery/order', [EventEditorController::class, 'orderGallery'])->whereNumber('event')->middleware('throttle:30,1,editorial-event-editor-media')->name('events.gallery.order');
Route::delete('/events/{event}/gallery/{media}', [EventEditorController::class, 'removeGallery'])->whereNumber(['event', 'media'])->middleware('throttle:30,1,editorial-event-editor-media')->name('events.gallery.remove');

// La testata della pagina «Modifica»: programmazione e «Genera date».
Route::put('/events/{event}/schedule', [EventEditorController::class, 'schedule'])->whereNumber('event')->middleware('throttle:30,1,editorial-event-editor-schedule')->name('events.schedule');
Route::delete('/events/{event}/schedule', [EventEditorController::class, 'unschedule'])->whereNumber('event')->middleware('throttle:30,1,editorial-event-editor-schedule')->name('events.schedule.cancel');
Route::post('/events/{event}/occurrences/generate', [EventEditorController::class, 'generate'])->whereNumber('event')->middleware('throttle:10,1,editorial-event-editor-generate')->name('events.occurrences.generate');

// Le date (la sezione «Date» della pagina «Modifica»).
Route::get('/events/{event}/dates', [EventDatesController::class, 'index'])->whereNumber('event')->middleware('throttle:120,1,editorial-event-dates')->name('events.dates.index');
Route::post('/events/{event}/dates', [EventDatesController::class, 'store'])->whereNumber('event')->middleware('throttle:60,1,editorial-event-dates-save')->name('events.dates.store');
Route::get('/events/{event}/dates/{occurrence}', [EventDatesController::class, 'show'])->whereNumber(['event', 'occurrence'])->middleware('throttle:120,1,editorial-event-dates')->name('events.dates.show');
Route::patch('/events/{event}/dates/{occurrence}', [EventDatesController::class, 'update'])->whereNumber(['event', 'occurrence'])->middleware('throttle:60,1,editorial-event-dates-save')->name('events.dates.update');
Route::post('/events/{event}/dates/{occurrence}/shift', [EventDatesController::class, 'shift'])->whereNumber(['event', 'occurrence'])->middleware('throttle:60,1,editorial-event-dates-save')->name('events.dates.shift');
Route::post('/events/{event}/dates/{occurrence}/status', [EventDatesController::class, 'status'])->whereNumber(['event', 'occurrence'])->middleware('throttle:60,1,editorial-event-dates-save')->name('events.dates.status');

// Pericolosa (decisione 4): password scritta negli ultimi 15 minuti.
Route::delete('/events/{event}/dates/{occurrence}', [EventDatesController::class, 'destroy'])->whereNumber(['event', 'occurrence'])
    ->middleware([RequireRecentPassword::class, 'throttle:30,1,editorial-event-dates-destroy'])->name('events.dates.destroy');

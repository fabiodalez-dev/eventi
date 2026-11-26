<?php

declare(strict_types=1);

/*
 * R4 — Locali, richieste, organizzatori (R-VN-01…16, R-AP-01…06, R-OR-01…02).
 *
 * Incluso da `routes/api.php` dentro `v1/editorial` (nome `api.v1.editorial.`),
 * nel gruppo che porta già `auth:sanctum`, `TicketingPrivacy`,
 * `ResolveEditorialDesk` e `RequireEditorialUnlock`. Percorsi di questo
 * pacchetto: venues, applications, organizers.
 *
 * Ogni rotta ha un nome (`<area>.<azione>`) e un `throttle:N,M,<nome>`; le
 * azioni pericolose (decisione 4) aggiungono
 * `->middleware(RequireRecentPassword::class)`. Le regole sono nel contratto
 * delle fondamenta e le controlla `EditorialRoutesTest`.
 */

use App\Http\Controllers\Api\V1\Editorial\ApplicationController;
use App\Http\Controllers\Api\V1\Editorial\OrganizerController;
use App\Http\Controllers\Api\V1\Editorial\VenueController;
use App\Http\Controllers\Api\V1\Editorial\VenueMediaController;
use App\Http\Controllers\Api\V1\Editorial\VenueMemberController;
use App\Http\Middleware\RequireRecentPassword;
use Illuminate\Support\Facades\Route;

/*
 * Locali. `{venue}` è sempre l'id numerico (mai lo slug). `export` prima di
 * `{venue}`, che altrimenti se lo prenderebbe. Sospendere ed eliminare sono
 * azioni pericolose (decisione 4): chiedono la password recente.
 */
Route::get('/venues', [VenueController::class, 'index'])->middleware('throttle:60,1,editorial-venues')->name('venues.index');
Route::get('/venues/export', [VenueController::class, 'export'])->middleware('throttle:10,1,editorial-venues-export')->name('venues.export');
Route::get('/venues/choices', [VenueController::class, 'choices'])->middleware('throttle:60,1,editorial-venues')->name('venues.choices');
// «Trova» chiede un servizio esterno: un tetto suo, stretto.
Route::post('/venues/locate', [VenueController::class, 'locate'])->middleware('throttle:20,1,editorial-venues-locate')->name('venues.locate');
Route::post('/venues', [VenueController::class, 'store'])->middleware('throttle:20,1,editorial-venues-store')->name('venues.store');

Route::prefix('/venues/{venue}')->whereNumber('venue')->group(function (): void {
    Route::get('/', [VenueController::class, 'show'])->middleware('throttle:60,1,editorial-venues')->name('venues.show');
    Route::get('/form', [VenueController::class, 'form'])->middleware('throttle:60,1,editorial-venues')->name('venues.form');
    Route::patch('/', [VenueController::class, 'update'])->middleware('throttle:30,1,editorial-venues-update')->name('venues.update');
    Route::post('/approve', [VenueController::class, 'approve'])->middleware('throttle:30,1,editorial-venues-moderate')->name('venues.approve');
    Route::post('/reject', [VenueController::class, 'reject'])->middleware('throttle:30,1,editorial-venues-moderate')->name('venues.reject');
    Route::post('/suspend', [VenueController::class, 'suspend'])
        ->middleware([RequireRecentPassword::class, 'throttle:30,1,editorial-venues-moderate'])->name('venues.suspend');
    Route::post('/verify', [VenueController::class, 'verify'])->middleware('throttle:30,1,editorial-venues-moderate')->name('venues.verify');
    Route::put('/ticketing', [VenueController::class, 'ticketing'])->middleware('throttle:30,1,editorial-venues-update')->name('venues.ticketing');
    Route::delete('/', [VenueController::class, 'destroy'])
        ->middleware([RequireRecentPassword::class, 'throttle:10,1,editorial-venues-destroy'])->name('venues.destroy');
    Route::get('/events', [VenueController::class, 'events'])->middleware('throttle:60,1,editorial-venues')->name('venues.events');
    Route::get('/activity', [VenueController::class, 'activity'])->middleware('throttle:60,1,editorial-venues')->name('venues.activity');
    Route::post('/activity/{activity}/restore', [VenueController::class, 'restoreActivity'])->whereNumber('activity')
        ->middleware('throttle:20,1,editorial-venues-update')->name('venues.activity.restore');

    // Immagini: lo stesso secchio per tutte le scritture.
    Route::get('/media', [VenueMediaController::class, 'index'])->middleware('throttle:60,1,editorial-venues')->name('venues.media.index');
    // `gallery/order` prima di `gallery/{media}`.
    Route::put('/media/gallery/order', [VenueMediaController::class, 'orderGallery'])->middleware('throttle:20,1,editorial-venues-upload')->name('venues.media.gallery.order');
    Route::post('/media/gallery', [VenueMediaController::class, 'storeGallery'])->middleware('throttle:20,1,editorial-venues-upload')->name('venues.media.gallery.store');
    Route::delete('/media/gallery/{media}', [VenueMediaController::class, 'removeGallery'])->whereNumber('media')
        ->middleware('throttle:20,1,editorial-venues-upload')->name('venues.media.gallery.remove');
    Route::post('/media/{collection}', [VenueMediaController::class, 'store'])->whereIn('collection', ['logo', 'cover'])
        ->middleware('throttle:20,1,editorial-venues-upload')->name('venues.media.store');
    Route::delete('/media/{collection}', [VenueMediaController::class, 'remove'])->whereIn('collection', ['logo', 'cover'])
        ->middleware('throttle:20,1,editorial-venues-upload')->name('venues.media.remove');

    // Persone: togliere una persona dal locale non è eliminare un'entità (`remove`, non `destroy`).
    Route::get('/members', [VenueMemberController::class, 'index'])->middleware('throttle:60,1,editorial-venues')->name('venues.members.index');
    Route::get('/members/candidates', [VenueMemberController::class, 'candidates'])->middleware('throttle:60,1,editorial-venues')->name('venues.members.candidates');
    Route::post('/members/invite', [VenueMemberController::class, 'invite'])->middleware('throttle:10,1,editorial-venues-invite')->name('venues.members.invite');
    Route::post('/members', [VenueMemberController::class, 'attach'])->middleware('throttle:30,1,editorial-venues-members')->name('venues.members.attach');
    Route::patch('/members/{user}', [VenueMemberController::class, 'update'])->whereNumber('user')
        ->middleware('throttle:30,1,editorial-venues-members')->name('venues.members.update');
    Route::delete('/members/{user}', [VenueMemberController::class, 'remove'])->whereNumber('user')
        ->middleware('throttle:30,1,editorial-venues-members')->name('venues.members.remove');
});

/*
 * Richieste di iscrizione. Eliminare una richiesta è pericoloso: password
 * recente.
 */
Route::get('/applications', [ApplicationController::class, 'index'])->middleware('throttle:60,1,editorial-applications')->name('applications.index');
Route::prefix('/applications/{application}')->whereNumber('application')->group(function (): void {
    Route::get('/', [ApplicationController::class, 'show'])->middleware('throttle:60,1,editorial-applications')->name('applications.show');
    Route::get('/image', [ApplicationController::class, 'image'])->middleware('throttle:60,1,editorial-applications')->name('applications.image');
    Route::post('/approve', [ApplicationController::class, 'approve'])->middleware('throttle:20,1,editorial-applications-review')->name('applications.approve');
    Route::post('/reject', [ApplicationController::class, 'reject'])->middleware('throttle:20,1,editorial-applications-review')->name('applications.reject');
    Route::patch('/notes', [ApplicationController::class, 'notes'])->middleware('throttle:30,1,editorial-applications-notes')->name('applications.notes');
    Route::delete('/', [ApplicationController::class, 'destroy'])
        ->middleware([RequireRecentPassword::class, 'throttle:10,1,editorial-applications-destroy'])->name('applications.destroy');
});

/*
 * Organizzatori: nessuna eliminazione, come nel pannello.
 */
Route::get('/organizers', [OrganizerController::class, 'index'])->middleware('throttle:60,1,editorial-organizers')->name('organizers.index');
Route::get('/organizers/people', [OrganizerController::class, 'people'])->middleware('throttle:60,1,editorial-organizers')->name('organizers.people');
Route::post('/organizers', [OrganizerController::class, 'store'])->middleware('throttle:20,1,editorial-organizers-save')->name('organizers.store');
Route::get('/organizers/{organizer}', [OrganizerController::class, 'show'])->whereNumber('organizer')->middleware('throttle:60,1,editorial-organizers')->name('organizers.show');
Route::patch('/organizers/{organizer}', [OrganizerController::class, 'update'])->whereNumber('organizer')->middleware('throttle:30,1,editorial-organizers-save')->name('organizers.update');

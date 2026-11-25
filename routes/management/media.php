<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\Management\EventPosterController;
use App\Http\Controllers\Api\V1\Management\VenueMediaController;
use Illuminate\Support\Facades\Route;

/*
 * Immagini (fondamenta). Incluso due volte da `routes/api.php`, dentro
 * `venues/{venue}` e dentro `organizers/{organizer}`: `$subjectType` dice in
 * quale dei due. La locandina vale per entrambi; logo, copertina e galleria
 * sono del solo locale.
 *
 * Tutte le scritture consumano lo stesso secchio `management-upload`.
 *
 * @var string $subjectType
 */
Route::post('/events/{event}/poster', [EventPosterController::class, 'store'])->whereNumber('event')
    ->middleware('throttle:20,1,management-upload')->name('events.poster.store');
Route::delete('/events/{event}/poster', [EventPosterController::class, 'destroy'])->whereNumber('event')
    ->middleware('throttle:20,1,management-upload')->name('events.poster.destroy');

if ($subjectType === 'venue') {
    Route::get('/media', [VenueMediaController::class, 'index'])->name('media.index');
    // `gallery/order` prima di `gallery/{media}`, che altrimenti se la prenderebbe.
    Route::put('/media/gallery/order', [VenueMediaController::class, 'orderGallery'])
        ->middleware('throttle:20,1,management-upload')->name('media.gallery.order');
    Route::post('/media/gallery', [VenueMediaController::class, 'storeGallery'])
        ->middleware('throttle:20,1,management-upload')->name('media.gallery.store');
    Route::delete('/media/gallery/{media}', [VenueMediaController::class, 'destroyGallery'])->whereNumber('media')
        ->middleware('throttle:20,1,management-upload')->name('media.gallery.destroy');
    Route::post('/media/{collection}', [VenueMediaController::class, 'store'])->whereIn('collection', ['logo', 'cover'])
        ->middleware('throttle:20,1,management-upload')->name('media.store');
    Route::delete('/media/{collection}', [VenueMediaController::class, 'destroy'])->whereIn('collection', ['logo', 'cover'])
        ->middleware('throttle:20,1,management-upload')->name('media.destroy');
}

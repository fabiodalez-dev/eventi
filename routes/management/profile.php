<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\Management\ContactConfirmationController;
use App\Http\Controllers\Api\V1\Management\OrganizerProfileController;
use App\Http\Controllers\Api\V1\Management\VenueProfileController;
use Illuminate\Support\Facades\Route;

/*
 * Profilo del locale e dell'organizzazione (P4).
 *
 * Incluso due volte da `routes/api.php`, dentro `venues/{venue}` e dentro
 * `organizers/{organizer}`: `$subjectType` (`venue` o `organizer`) dice in
 * quale dei due. Il gruppo porta già `auth:sanctum`, `TicketingPrivacy` e
 * `ResolveManagementSubject`. Logo, copertina e galleria sono in `media.php`
 * (fondamenta).
 *
 * @var string $subjectType
 */
if ($subjectType === 'venue') {
    Route::get('/profile', [VenueProfileController::class, 'show'])->name('profile.show');
    Route::patch('/profile', [VenueProfileController::class, 'update'])
        ->middleware('throttle:30,1,management-profile')->name('profile.update');
    // Il tetto vero è un avviso ogni sette giorni (`PublishVenueNotice`); questo ferma solo i doppi tocchi.
    Route::post('/notices', [VenueProfileController::class, 'notice'])
        ->middleware('throttle:6,1,management-notice')->name('notices.store');
} else {
    Route::get('/profile', [OrganizerProfileController::class, 'show'])->name('profile.show');
    Route::patch('/profile', [OrganizerProfileController::class, 'update'])
        ->middleware('throttle:30,1,management-profile')->name('profile.update');
}

// I tetti giornalieri sono di `ContactEmailConfirmation`, gli stessi del pannello.
Route::post('/contact/resend', [ContactConfirmationController::class, 'resend'])
    ->middleware('throttle:6,1,management-contact')->name('contact.resend');

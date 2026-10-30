<?php

use App\Http\Controllers\TicketingController;
use App\Http\Controllers\Web\SharedTicketController;
use App\Http\Middleware\BlockWhileImpersonating;
use App\Http\Middleware\RequireConfirmedAccount;
use App\Http\Middleware\TicketingPrivacy;
use Illuminate\Support\Facades\Route;

/*
 * Prenotare, confermare un posto e spedire biglietti per email (anche a un
 * terzo) richiede l'indirizzo confermato: un account usa e getta non deve
 * occupare posti veri né far partire posta dal nostro dominio. Leggere,
 * scaricare e annullare restano liberi, così chi ha già prenotato può sempre
 * liberare il posto.
 */
Route::middleware(['auth', TicketingPrivacy::class, BlockWhileImpersonating::class])->group(function (): void {
    Route::get('/biglietti', [TicketingController::class, 'index'])->name('tickets.index');
    Route::get('/biglietti/prenotazione/{booking}', [TicketingController::class, 'show'])->name('tickets.show');
    Route::post('/biglietti/{booking}/email', [TicketingController::class, 'resend'])->middleware([RequireConfirmedAccount::class, 'throttle:3,60,tickets-resend'])->name('tickets.resend');
    Route::get('/biglietti/prenota/{occurrence}', [TicketingController::class, 'create'])->middleware(RequireConfirmedAccount::class)->name('tickets.create');
    Route::post('/biglietti/prenota/{occurrence}', [TicketingController::class, 'store'])->middleware([RequireConfirmedAccount::class, 'throttle:20,1,tickets-store'])->name('tickets.store');
    Route::post('/biglietti/{booking}/conferma', [TicketingController::class, 'confirmPromotion'])->middleware([RequireConfirmedAccount::class, 'throttle:30,1,tickets-confirm'])->name('tickets.confirm');
    Route::post('/biglietti/{booking}/annulla', [TicketingController::class, 'cancel'])->middleware('throttle:30,1,tickets-cancel')->name('tickets.cancel');
    // Il PDF porta il QR, che vale come il biglietto: sotto impersonificazione
    // non si scarica, come non si spedisce (vedi la vista ticketing.index).
    Route::get('/biglietti/pdf/{ticket}', [TicketingController::class, 'pdf'])->middleware([BlockWhileImpersonating::class.':all', 'throttle:30,1,tickets-pdf'])->name('tickets.pdf');
    Route::post('/biglietti/biglietto/{ticket}/invia', [SharedTicketController::class, 'send'])->middleware([RequireConfirmedAccount::class, 'throttle:10,60,tickets-share'])->name('tickets.share');
    Route::prefix('gestione-biglietti')->name('ticketing.manage.')->group(function (): void {
        Route::get('/', [TicketingController::class, 'dashboard'])->name('index');
        Route::get('/{occurrence}/scanner', [TicketingController::class, 'scanner'])->name('scanner');
        Route::post('/{occurrence}/staff', [TicketingController::class, 'staff'])->middleware('throttle:30,1,ticketing-staff')->name('staff');
        Route::get('/{occurrence}', [TicketingController::class, 'manage'])->name('show');
        Route::post('/{occurrence}/impostazioni', [TicketingController::class, 'configure'])->name('configure');
        Route::post('/{occurrence}/ingresso', [TicketingController::class, 'checkIn'])->middleware('throttle:120,1,tickets-checkin')->name('checkin');
        Route::get('/{occurrence}/elenco-ingressi', [TicketingController::class, 'manifest'])->middleware('throttle:30,1,tickets-manifest')->name('manifest');
        Route::post('/{occurrence}/messaggio', [TicketingController::class, 'message'])->middleware('throttle:10,1,tickets-message')->name('message');
        Route::post('/{occurrence}/prenotazione-manuale', [TicketingController::class, 'guestBooking'])->middleware('throttle:60,1,tickets-guest')->name('guest');
        Route::get('/{occurrence}/csv', [TicketingController::class, 'export'])->middleware('throttle:10,1,tickets-export')->name('export');
        Route::get('/{occurrence}/lista.pdf', [TicketingController::class, 'printList'])->middleware('throttle:10,1,tickets-print')->name('print');
        Route::post('/prenotazione/{booking}/annulla', [TicketingController::class, 'cancel'])->name('cancel');
    });
});

Route::get('/biglietto/{ticket}', [SharedTicketController::class, 'show'])->middleware('throttle:60,1,tickets-shared')->name('tickets.shared');

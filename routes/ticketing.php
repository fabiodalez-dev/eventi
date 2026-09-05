<?php

use App\Http\Controllers\TicketingController;
use App\Http\Middleware\TicketingPrivacy;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', TicketingPrivacy::class])->group(function (): void {
    Route::get('/biglietti', [TicketingController::class, 'index'])->name('tickets.index');
    Route::get('/biglietti/prenotazione/{booking}', [TicketingController::class, 'show'])->name('tickets.show');
    Route::post('/biglietti/{booking}/email', [TicketingController::class, 'resend'])->middleware('throttle:3,60')->name('tickets.resend');
    Route::get('/biglietti/prenota/{occurrence}', [TicketingController::class, 'create'])->name('tickets.create');
    Route::post('/biglietti/prenota/{occurrence}', [TicketingController::class, 'store'])->middleware('throttle:20,1')->name('tickets.store');
    Route::post('/biglietti/{booking}/annulla', [TicketingController::class, 'cancel'])->middleware('throttle:30,1')->name('tickets.cancel');
    Route::get('/biglietti/pdf/{ticket}', [TicketingController::class, 'pdf'])->middleware('throttle:30,1')->name('tickets.pdf');
    Route::prefix('gestione-biglietti')->name('ticketing.manage.')->group(function (): void {
        Route::get('/', [TicketingController::class, 'dashboard'])->name('index');
        Route::get('/{occurrence}', [TicketingController::class, 'manage'])->name('show');
        Route::post('/{occurrence}/impostazioni', [TicketingController::class, 'configure'])->name('configure');
        Route::post('/{occurrence}/ingresso', [TicketingController::class, 'checkIn'])->middleware('throttle:120,1')->name('checkin');
        Route::get('/{occurrence}/csv', [TicketingController::class, 'export'])->middleware('throttle:10,1')->name('export');
        Route::post('/prenotazione/{booking}/annulla', [TicketingController::class, 'cancel'])->name('cancel');
    });
});

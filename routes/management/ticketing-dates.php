<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\Management\DateTicketingController;
use App\Http\Controllers\TicketingController;
use Illuminate\Support\Facades\Route;

/*
 * Biglietteria completa (P3), sulla data: `/api/v1/management/dates/{data}/…`.
 *
 * Accanto alle rotte di prima (`MobileManagementController`), non sotto un
 * locale o un'organizzazione: una data si gestisce con `BookingPolicy::manage`
 * (referente di un locale attivo, chi gestisce l'organizzazione dell'evento),
 * e la stessa persona può gestire date di soggetti diversi, come nel
 * riepilogo `/gestione-biglietti`. Incluso da `routes/api.php` dentro il
 * gruppo `v1` con `auth:sanctum` e `TicketingPrivacy`.
 *
 * I due file usano gli stessi metodi e gli stessi secchi del sito
 * (`tickets-export`, `tickets-print`), con il registro attività.
 */
Route::prefix('management/dates/{occurrence}')->whereNumber('occurrence')->name('api.v1.management.dates.')->group(function (): void {
    Route::patch('/settings', [DateTicketingController::class, 'settings'])->middleware('throttle:30,1,ticketing-settings')->name('settings');
    Route::get('/messages', [DateTicketingController::class, 'messages'])->name('messages');
    Route::post('/bookings/{booking}/cancel', [DateTicketingController::class, 'cancel'])->whereNumber('booking')->scopeBindings()
        ->middleware('throttle:60,1,ticketing-cancel')->name('bookings.cancel');
    Route::get('/export.csv', [TicketingController::class, 'export'])->middleware('throttle:10,1,tickets-export')->name('export');
    Route::get('/list.pdf', [TicketingController::class, 'printList'])->middleware('throttle:10,1,tickets-print')->name('print');
});

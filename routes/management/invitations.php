<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\Management\InvitationController;
use Illuminate\Support\Facades\Route;

/*
 * Gli inviti ricevuti (P8): in un locale (W-CO-07) e al personale d'ingresso
 * di una data (W-TK-15). Montato da `routes/api.php` sotto `v1/me/invitations`
 * con `auth:sanctum`, `TicketingPrivacy` e `RequireConfirmedAccount`: senza il
 * collegamento firmato dell'email, è l'indirizzo confermato a dire che
 * risponde il titolare della casella.
 *
 * Le risposte consumano il secchio `venue-invitations` delle pagine del sito.
 */
Route::get('/', [InvitationController::class, 'index'])->name('index');
Route::post('/venues/{venue}', [InvitationController::class, 'respondVenue'])->whereNumber('venue')
    ->middleware('throttle:30,1,venue-invitations')->name('venues.respond');
Route::post('/checkin/{invitation}', [InvitationController::class, 'respondCheckin'])->whereNumber('invitation')
    ->middleware('throttle:30,1,venue-invitations')->name('checkin.respond');

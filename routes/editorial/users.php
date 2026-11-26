<?php

declare(strict_types=1);

/*
 * R5 — Utenti (R-US-01…12, R-WB-02).
 *
 * Incluso da `routes/api.php` dentro `v1/editorial` (nome `api.v1.editorial.`),
 * nel gruppo che porta già `auth:sanctum`, `TicketingPrivacy`,
 * `ResolveEditorialDesk` e `RequireEditorialUnlock`. Percorsi di questo
 * pacchetto: users.
 *
 * Ogni rotta ha un nome (`<area>.<azione>`) e un `throttle:N,M,<nome>`; le
 * azioni pericolose (decisione 4) aggiungono
 * `->middleware(RequireRecentPassword::class)`. Le regole sono nel contratto
 * delle fondamenta e le controlla `EditorialRoutesTest`.
 */

use App\Http\Controllers\Api\V1\Editorial\UserCommunityController;
use App\Http\Controllers\Api\V1\Editorial\UserController;
use App\Http\Middleware\RequireRecentPassword;
use Illuminate\Support\Facades\Route;

/*
 * Solo sul sito (decisioni 4, 8, 9): esportazione dell'elenco (R-US-03),
 * impersonificazione (R-US-07, R-WB-02), eliminazione definitiva, conferimento
 * di admin e super admin, email e password di un'altra persona.
 */
Route::get('/users', [UserController::class, 'index'])->middleware('throttle:60,1,editorial-users')->name('users.index');
Route::get('/users/options', [UserController::class, 'options'])->middleware('throttle:60,1,editorial-users-options')->name('users.options');
Route::post('/users', [UserController::class, 'store'])->middleware('throttle:10,1,editorial-users-store')->name('users.store');
Route::get('/users/{user}', [UserController::class, 'show'])->whereNumber('user')->withTrashed()->middleware('throttle:60,1,editorial-users-show')->name('users.show');
Route::patch('/users/{user}', [UserController::class, 'update'])->whereNumber('user')->withTrashed()->middleware('throttle:30,1,editorial-users-update')->name('users.update');
// Le proprie email e password: chiudono tutte le sessioni dell'app, quindi password recente.
Route::put('/users/{user}/credentials', [UserController::class, 'credentials'])->whereNumber('user')
    ->middleware([RequireRecentPassword::class, 'throttle:6,15,editorial-users-credentials'])->name('users.credentials');
Route::put('/users/{user}/roles', [UserController::class, 'roles'])->whereNumber('user')->withTrashed()
    ->middleware([RequireRecentPassword::class, 'throttle:20,1,editorial-users-roles'])->name('users.roles');
Route::delete('/users/{user}', [UserController::class, 'destroy'])->whereNumber('user')->withTrashed()
    ->middleware([RequireRecentPassword::class, 'throttle:10,1,editorial-users-destroy'])->name('users.destroy');
Route::post('/users/{user}/restore', [UserController::class, 'restore'])->whereNumber('user')->withTrashed()->middleware('throttle:10,1,editorial-users-restore')->name('users.restore');

Route::post('/users/{user}/community/suspend', [UserCommunityController::class, 'suspend'])->whereNumber('user')->withTrashed()
    ->middleware([RequireRecentPassword::class, 'throttle:20,1,editorial-users-suspend'])->name('users.community.suspend');
Route::post('/users/{user}/community/unsuspend', [UserCommunityController::class, 'unsuspend'])->whereNumber('user')->withTrashed()->middleware('throttle:20,1,editorial-users-unsuspend')->name('users.community.unsuspend');
Route::post('/users/{user}/community/handle', [UserCommunityController::class, 'handle'])->whereNumber('user')->withTrashed()->middleware('throttle:10,1,editorial-users-handle')->name('users.community.handle');
Route::delete('/users/{user}/whatsapp', [UserCommunityController::class, 'revokeWhatsapp'])->whereNumber('user')->withTrashed()->middleware('throttle:10,1,editorial-users-whatsapp')->name('users.whatsapp.revoke');
Route::get('/users/{user}/whatsapp/history', [UserCommunityController::class, 'whatsappHistory'])->whereNumber('user')->withTrashed()->middleware('throttle:30,1,editorial-users-whatsapp-history')->name('users.whatsapp.history');

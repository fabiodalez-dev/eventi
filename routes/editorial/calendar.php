<?php

declare(strict_types=1);

/*
 * R14 — Calendario, campanella (R-EV-27, R-DB-11).
 *
 * Incluso da `routes/api.php` dentro `v1/editorial` (nome `api.v1.editorial.`),
 * nel gruppo che porta già `auth:sanctum`, `TicketingPrivacy`,
 * `ResolveEditorialDesk` e `RequireEditorialUnlock`. Percorsi di questo
 * pacchetto: calendar, notifications, links.
 *
 * Ogni rotta ha un nome (`<area>.<azione>`) e un `throttle:N,M,<nome>`; le
 * azioni pericolose (decisione 4) aggiungono
 * `->middleware(RequireRecentPassword::class)`. Le regole sono nel contratto
 * delle fondamenta e le controlla `EditorialRoutesTest`.
 */

use App\Http\Controllers\Api\V1\Editorial\CalendarController;
use App\Http\Controllers\Api\V1\Editorial\EditorialLinkController;
use App\Http\Controllers\Api\V1\Editorial\EditorialNotificationController;
use Illuminate\Support\Facades\Route;

/*
 * Tutta la redazione, come la pagina «Calendario» e la campanella del
 * pannello: nessuna è pericolosa (la campanella tocca solo le notifiche di
 * chi chiede).
 */
Route::get('/calendar', [CalendarController::class, 'index'])->middleware('throttle:60,1,editorial-calendar')->name('calendar.index');

Route::prefix('notifications')->name('notifications.')->group(function (): void {
    Route::get('/', [EditorialNotificationController::class, 'index'])->middleware('throttle:60,1,editorial-notifications')->name('index');
    Route::middleware('throttle:60,1,editorial-notifications-write')->group(function (): void {
        Route::post('/read-all', [EditorialNotificationController::class, 'readAll'])->name('read_all');
        Route::post('/clear', [EditorialNotificationController::class, 'clear'])->name('clear');
        Route::post('/{notification}/read', [EditorialNotificationController::class, 'read'])->whereUuid('notification')->name('read');
        Route::post('/{notification}/unread', [EditorialNotificationController::class, 'unread'])->whereUuid('notification')->name('unread');
        Route::post('/{notification}/remove', [EditorialNotificationController::class, 'remove'])->whereUuid('notification')->name('remove');
    });
});

/*
 * Lo slug di un indirizzo del pannello (`/admin/events/{slug}`) tradotto
 * nell'id della scheda nativa, per gli indirizzi `/admin/…` che l'app
 * riceve.
 */
Route::get('/links/{type}/{slug}', [EditorialLinkController::class, 'resolve'])
    ->whereIn('type', ['events', 'venues'])->where('slug', '[A-Za-z0-9][A-Za-z0-9-]{0,190}')
    ->middleware('throttle:60,1,editorial-links')->name('links.resolve');

<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\Editorial\ImportSourceController;
use App\Http\Controllers\Api\V1\Editorial\ScheduledNotificationController;
use App\Http\Controllers\Api\V1\Editorial\ScheduledOperationsController;
use App\Http\Middleware\RequireRecentPassword;
use Illuminate\Support\Facades\Route;

/*
 * R12 — Sistema: import, invii, cron (R-SY-01…07).
 *
 * Incluso da `routes/api.php` dentro `v1/editorial` (nome `api.v1.editorial.`),
 * nel gruppo che porta già `auth:sanctum`, `TicketingPrivacy`,
 * `ResolveEditorialDesk` e `RequireEditorialUnlock`. Percorsi di questo
 * pacchetto: import-sources, scheduled-notifications, scheduled-operations.
 *
 * Ogni rotta ha un nome (`<area>.<azione>`) e un `throttle:N,M,<nome>`; le
 * azioni pericolose (decisione 4) aggiungono
 * `->middleware(RequireRecentPassword::class)`. Le regole sono nel contratto
 * delle fondamenta e le controlla `EditorialRoutesTest`.
 *
 * Pericolosa: l'eliminazione di una sorgente (definitiva: il modello non ha
 * il cestino, e con la sorgente va il suo storico). Chiedono la password
 * recente anche creazione, modifica ed «Esegui adesso» (revisione
 * dell'ondata 3): gli eventi di una sorgente senza locale, o con un locale
 * approvato, nascono pubblicati e visibili a tutti, e un indirizzo cambiato
 * decide che cosa arriva da lì in avanti. Fermare un invio
 * previsto non elimina niente (la riga resta «annullata»): nel pannello
 * chiede solo una conferma, e così nell'app.
 */

// Sorgenti di import (R-SY-01…04): admin e super admin
Route::get('/import-sources', [ImportSourceController::class, 'index'])->middleware('throttle:60,1,editorial-import-sources')->name('import-sources.index');
Route::get('/import-sources/form', [ImportSourceController::class, 'form'])->middleware('throttle:60,1,editorial-import-sources-form')->name('import-sources.form');
Route::get('/import-sources/venues', [ImportSourceController::class, 'venues'])->middleware('throttle:60,1,editorial-import-sources-venues')->name('import-sources.venues');
Route::post('/import-sources', [ImportSourceController::class, 'store'])
    ->middleware([RequireRecentPassword::class, 'throttle:20,1,editorial-import-sources-store'])->name('import-sources.store');
Route::get('/import-sources/{source}', [ImportSourceController::class, 'show'])->whereNumber('source')->middleware('throttle:60,1,editorial-import-sources-show')->name('import-sources.show');
Route::patch('/import-sources/{source}', [ImportSourceController::class, 'update'])->whereNumber('source')
    ->middleware([RequireRecentPassword::class, 'throttle:30,1,editorial-import-sources-update'])->name('import-sources.update');
Route::delete('/import-sources/{source}', [ImportSourceController::class, 'destroy'])->whereNumber('source')
    ->middleware([RequireRecentPassword::class, 'throttle:20,1,editorial-import-sources-destroy'])->name('import-sources.destroy');
// Anteprima ed esecuzione leggono un calendario esterno: un tetto più basso delle altre
// (l'anteprima ha anche il tetto globale di `ImportPreviewLimiter`, condiviso con il pannello).
Route::post('/import-sources/{source}/preview', [ImportSourceController::class, 'preview'])->whereNumber('source')->middleware('throttle:10,1,editorial-import-sources-preview')->name('import-sources.preview');
Route::post('/import-sources/{source}/run', [ImportSourceController::class, 'run'])->whereNumber('source')
    ->middleware([RequireRecentPassword::class, 'throttle:10,1,editorial-import-sources-run'])->name('import-sources.run');
Route::get('/import-sources/{source}/runs', [ImportSourceController::class, 'runs'])->whereNumber('source')->middleware('throttle:60,1,editorial-import-sources-runs')->name('import-sources.runs');

// Invii previsti (R-SY-05, R-SY-06): li guarda tutta la redazione, li ferma admin e super admin
Route::get('/scheduled-notifications', [ScheduledNotificationController::class, 'index'])->middleware('throttle:60,1,editorial-scheduled-notifications')->name('scheduled-notifications.index');
Route::post('/scheduled-notifications/{notification}/cancel', [ScheduledNotificationController::class, 'cancel'])->whereNumber('notification')->middleware('throttle:30,1,editorial-scheduled-notifications-cancel')->name('scheduled-notifications.cancel');

// Cron e programmazioni (R-SY-07): sola lettura, admin e super admin
Route::get('/scheduled-operations', [ScheduledOperationsController::class, 'show'])->middleware('throttle:20,1,editorial-scheduled-operations')->name('scheduled-operations.show');

<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\Editorial\CatalogReviewController;
use App\Http\Controllers\Api\V1\Editorial\EventCommentController;
use App\Http\Controllers\Api\V1\Editorial\ReportController;
use App\Http\Middleware\RequireRecentPassword;
use Illuminate\Support\Facades\Route;

/*
 * R6 — Moderazione dei contenuti (R-MD-01…12).
 *
 * Incluso da `routes/api.php` dentro `v1/editorial` (nome `api.v1.editorial.`),
 * nel gruppo che porta già `auth:sanctum`, `TicketingPrivacy`,
 * `ResolveEditorialDesk` e `RequireEditorialUnlock`. Percorsi di questo
 * pacchetto: reports, reviews, comments.
 *
 * Ogni rotta ha un nome (`<area>.<azione>`) e un `throttle:N,M,<nome>`; le
 * azioni pericolose (decisione 4) aggiungono
 * `->middleware(RequireRecentPassword::class)`. Le regole sono nel contratto
 * delle fondamenta e le controlla `EditorialRoutesTest`.
 *
 * Pericolose: l'eliminazione di una segnalazione e quella di un commento
 * (definitive: i due modelli non hanno il cestino). Togliere la risposta del
 * gestore a una recensione non elimina un'entità (`reviews.reply.remove`):
 * nel pannello chiede solo una conferma, e così nell'app.
 */

// Segnalazioni (R-MD-01…04)
Route::get('/reports', [ReportController::class, 'index'])->middleware('throttle:60,1,editorial-reports')->name('reports.index');
Route::get('/reports/{report}', [ReportController::class, 'show'])->whereNumber('report')->middleware('throttle:60,1,editorial-reports-show')->name('reports.show');
Route::patch('/reports/{report}', [ReportController::class, 'resolve'])->whereNumber('report')->middleware('throttle:30,1,editorial-reports-resolve')->name('reports.resolve');
Route::delete('/reports/{report}', [ReportController::class, 'destroy'])->whereNumber('report')
    ->middleware([RequireRecentPassword::class, 'throttle:20,1,editorial-reports-destroy'])->name('reports.destroy');

// Recensioni (R-MD-05…07): admin e super admin
Route::get('/reviews', [CatalogReviewController::class, 'index'])->middleware('throttle:60,1,editorial-reviews')->name('reviews.index');
Route::post('/reviews/{review}/moderate', [CatalogReviewController::class, 'moderate'])->whereNumber('review')->middleware('throttle:30,1,editorial-reviews-moderate')->name('reviews.moderate');
Route::delete('/reviews/{review}/reply', [CatalogReviewController::class, 'removeReply'])->whereNumber('review')->middleware('throttle:30,1,editorial-reviews-reply-remove')->name('reviews.reply.remove');

// Commenti agli eventi (R-MD-08…12)
Route::get('/comments', [EventCommentController::class, 'index'])->middleware('throttle:60,1,editorial-comments')->name('comments.index');
Route::get('/comments/{comment}', [EventCommentController::class, 'show'])->whereNumber('comment')->middleware('throttle:60,1,editorial-comments-show')->name('comments.show');
Route::post('/comments/{comment}/hide', [EventCommentController::class, 'hide'])->whereNumber('comment')->middleware('throttle:30,1,editorial-comments-hide')->name('comments.hide');
Route::post('/comments/{comment}/restore', [EventCommentController::class, 'restore'])->whereNumber('comment')->middleware('throttle:30,1,editorial-comments-restore')->name('comments.restore');
Route::delete('/comments/{comment}', [EventCommentController::class, 'destroy'])->whereNumber('comment')
    ->middleware([RequireRecentPassword::class, 'throttle:20,1,editorial-comments-destroy'])->name('comments.destroy');
